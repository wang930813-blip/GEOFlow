<?php

namespace App\Services\Crebee;

use App\Models\Admin;
use App\Models\CrebeeAccount;
use App\Models\CrebeePublishJob;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Models\VideoGenerationJob;
use App\Services\Billing\AdminResourceQuotaService;
use App\Support\Crebee\SelfMediaPlatformCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class SelfMediaVideoPublishService
{
    public function __construct(
        private readonly AdminResourceQuotaService $quotaService,
    ) {}

    /**
     * @param  array<int,int>  $accountIds
     * @return array<int,CrebeePublishJob>
     */
    public function publish(VideoGenerationJob $video, Admin $admin, Site $site, array $accountIds): array
    {
        $accountIds = collect($accountIds)
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($accountIds === []) {
            throw new RuntimeException('请选择要发布的自媒体平台');
        }

        if ((int) $video->site_id !== (int) $site->id || (int) $video->owner_admin_id !== (int) $admin->id) {
            throw new RuntimeException('视频不属于当前账号');
        }

        if ((string) $video->status !== 'success' || $video->firstVideoUrl() === '') {
            throw new RuntimeException('视频生成完成后才能发布自媒体');
        }

        if (trim((string) $video->cover_image) === '') {
            throw new RuntimeException('视频发布需要封面图，请先补充封面图');
        }

        $accounts = $this->boundVideoAccountsForAdmin($admin, $site, $accountIds);
        if ($accounts->count() !== count($accountIds)) {
            throw new RuntimeException('请选择已绑定且支持视频发布的自媒体平台');
        }

        $amount = $accounts->count();
        if (! $admin->isSuperAdmin()) {
            $this->quotaService->assertCanUse(
                (int) $admin->id,
                (int) $site->id,
                PlatformPlan::RESOURCE_CREBEE_PUBLISHES,
                $amount,
                $admin
            );
        }

        return DB::transaction(function () use ($video, $admin, $site, $accounts, $amount): array {
            $ledger = null;
            if (! $admin->isSuperAdmin()) {
                $ledger = $this->quotaService->consume(
                    (int) $admin->id,
                    (int) $site->id,
                    PlatformPlan::RESOURCE_CREBEE_PUBLISHES,
                    $amount,
                    [
                        'actor_admin_id' => (int) $admin->id,
                        'subject_type' => VideoGenerationJob::class,
                        'subject_id' => (int) $video->id,
                        'idempotency_key' => 'self-media-video:'.$video->id.':'.Str::uuid(),
                        'remark' => '自媒体视频发布',
                    ]
                );
            }

            $commonForm = $this->videoCommonForm($video);
            $jobs = [];

            foreach ($accounts->groupBy('agent_id') as $agentId => $agentAccounts) {
                $job = CrebeePublishJob::query()->create([
                    'site_id' => (int) $site->id,
                    'owner_admin_id' => (int) $admin->id,
                    'agent_id' => (int) $agentId,
                    'content_type' => 'video',
                    'title' => (string) $video->title,
                    'content_source_type' => 'video_generation',
                    'status' => 'queued',
                    'quota_ledger_id' => $ledger?->id,
                    'payload' => [
                        'contentType' => 'video',
                        'commonForm' => $commonForm,
                        'assets' => [
                            [
                                'key' => 'video',
                                'type' => 'video',
                                'url' => $video->firstVideoUrl(),
                            ],
                            [
                                'key' => 'cover',
                                'type' => 'image',
                                'url' => trim((string) $video->cover_image),
                            ],
                        ],
                        'source' => [
                            'type' => 'video_generation',
                            'video_generation_job_id' => (int) $video->id,
                        ],
                    ],
                ]);

                foreach ($agentAccounts as $account) {
                    $taskId = $this->taskId((string) $account->platform);
                    $job->items()->create([
                        'crebee_account_id' => (int) $account->id,
                        'platform' => (string) $account->platform,
                        'crebee_task_id' => $taskId,
                        'status' => 'queued',
                        'payload' => [
                            'taskId' => $taskId,
                            'accountId' => (string) $account->crebee_account_id,
                            'platform' => (string) $account->platform,
                            'contentType' => 'video',
                            'params' => $this->platformParams((string) $account->platform, $commonForm, $taskId, $video),
                        ],
                    ]);
                }

                $jobs[] = $job;
            }

            return $jobs;
        });
    }

    /**
     * @param  array<int,int>  $accountIds
     * @return Collection<int,CrebeeAccount>
     */
    private function boundVideoAccountsForAdmin(Admin $admin, Site $site, array $accountIds): Collection
    {
        return CrebeeAccount::query()
            ->whereIn('id', $accountIds)
            ->where('site_id', (int) $site->id)
            ->where('owner_admin_id', (int) $admin->id)
            ->where('status', 'bound')
            ->whereIn('platform', SelfMediaPlatformCatalog::videoPlatforms())
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{title:string,desc:string,videoPath:string,coverPath:string,timing:int}
     */
    private function videoCommonForm(VideoGenerationJob $video): array
    {
        return [
            'title' => $this->normalizeTitle((string) $video->title, 80),
            'desc' => $this->description($video),
            'videoPath' => '',
            'coverPath' => '',
            'timing' => 0,
        ];
    }

    /**
     * @param  array{title:string,desc:string,videoPath:string,coverPath:string,timing:int}  $commonForm
     * @return array<string,mixed>
     */
    private function platformParams(string $platform, array $commonForm, string $taskId, VideoGenerationJob $video): array
    {
        $base = [
            'title' => $this->titleForPlatform($platform, $commonForm['title']),
            'desc' => $commonForm['desc'],
            'videoPath' => '',
            'coverPath' => '',
            'timing' => 0,
            'taskId' => $taskId,
        ];

        return match ($platform) {
            'douyin' => $base + [
                'allowDownload' => 0,
                'visibilityType' => 0,
                'topics' => [],
                'mentions' => [],
                'activities' => [],
            ],
            'bilibili' => $base + [
                'copyright' => 1,
                'tid' => ['fenqu_id' => 160, 'fenqu_name' => '生活'],
                'source' => $video->firstVideoUrl(),
                'pubType' => 1,
                'tags' => $this->tags($video, 1, 10),
                'verticalCoverPath' => '',
            ],
            'kuaishou' => $base + [
                'visibilityType' => 1,
                'topics' => [],
                'mentions' => [],
                'activities' => [],
                'allowSameFrame' => 1,
                'allowDownload' => 1,
                'nearbyShow' => 0,
            ],
            'shipinhao' => $base + [
                'shortTitle' => '',
                'topics' => [],
                'mentions' => [],
                'postFlag' => 0,
                'pubType' => 1,
                'objectType' => 0,
            ],
            'xiaohongshu' => $base + [
                'title' => $this->normalizeTitle($commonForm['title'], 20),
                'visibilityType' => 0,
                'topics' => [],
                'mentions' => [],
            ],
            'zhihu' => $base + [
                'title' => $this->normalizeTitle($commonForm['title'], 50, 5),
                'isOriginal' => 1,
                'topics' => [],
                'category' => null,
            ],
            'weibo' => $base + [
                'createType' => 0,
                'visibleType' => 0,
                'topics' => [],
                'location' => '',
            ],
            'baijiahao' => $base + [
                'verticalCoverPath' => '',
                'videoType' => $this->videoType($video),
                'pubType' => 1,
                'isAigc' => true,
                'tags' => $this->tags($video, 0, 5),
            ],
            'toutiaohao' => $base + [
                'videoType' => $this->videoType($video),
                'visibilityType' => 0,
                'pubType' => 1,
                'topics' => [],
                'externalLink' => '',
            ],
            'qiehao' => $base + [
                'title' => $this->normalizeTitle($commonForm['title'], 64, 5),
                'category' => null,
                'topics' => $this->tags($video, 2, 5),
                'pubType' => 1,
            ],
            'wangyihao' => $base + [
                'title' => $this->normalizeTitle($commonForm['title'], 30, 5),
                'category' => null,
                'tags' => $this->tags($video, 3, 5),
                'pubType' => 1,
            ],
            default => $base,
        };
    }

    private function description(VideoGenerationJob $video): string
    {
        $desc = trim((string) $video->script);
        if ($desc === '') {
            $desc = trim((string) $video->subject);
        }

        return mb_substr($desc, 0, 180, 'UTF-8');
    }

    private function titleForPlatform(string $platform, string $title): string
    {
        return match ($platform) {
            'xiaohongshu' => $this->normalizeTitle($title, 20),
            'zhihu' => $this->normalizeTitle($title, 50, 5),
            'qiehao' => $this->normalizeTitle($title, 64, 5),
            'wangyihao' => $this->normalizeTitle($title, 30, 5),
            default => $title,
        };
    }

    private function normalizeTitle(string $title, int $maxLength, int $minLength = 1): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
        if ($title === '') {
            $title = '未命名视频';
        }

        if (mb_strlen($title, 'UTF-8') > $maxLength) {
            $title = mb_substr($title, 0, $maxLength, 'UTF-8');
        }

        while (mb_strlen($title, 'UTF-8') < $minLength) {
            $title .= '分享';
        }

        return $title;
    }

    /**
     * @return array<int,string>
     */
    private function tags(VideoGenerationJob $video, int $min, int $max): array
    {
        $tags = collect(preg_split('/[,，\n]+/u', (string) $video->terms) ?: [])
            ->map(static fn (string $tag): string => trim($tag))
            ->filter(static fn (string $tag): bool => $tag !== '')
            ->take($max)
            ->values()
            ->all();

        while (count($tags) < $min) {
            $tags[] = count($tags) === 0 ? 'AI' : '科技';
        }

        return $tags;
    }

    private function videoType(VideoGenerationJob $video): string
    {
        return (string) $video->video_aspect === '9:16' ? 'vertical' : 'horizontal';
    }

    private function taskId(string $platform): string
    {
        return $platform.'-'.now()->format('YmdHis').'-'.Str::lower(Str::random(10));
    }
}
