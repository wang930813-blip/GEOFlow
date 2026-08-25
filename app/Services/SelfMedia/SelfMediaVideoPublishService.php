<?php

namespace App\Services\SelfMedia;

use App\Jobs\SubmitAiToEarnPublishFlowJob;
use App\Models\Admin;
use App\Models\PlatformPlan;
use App\Models\SelfMediaAccount;
use App\Models\SelfMediaPublishJob;
use App\Models\Site;
use App\Models\VideoGenerationJob;
use App\Services\Billing\AdminResourceQuotaService;
use App\Support\GeoFlow\ImageUrlNormalizer;
use App\Support\SelfMedia\SelfMediaPlatformCatalog;
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
     * @return array<int,SelfMediaPublishJob>
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
            throw new RuntimeException('请选择已授权且支持视频发布的自媒体平台');
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

        $jobs = DB::transaction(function () use ($video, $admin, $site, $accounts, $amount): array {
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

            $payload = $this->publishPayload($video, $site);
            $job = SelfMediaPublishJob::query()->create([
                'site_id' => (int) $site->id,
                'owner_admin_id' => (int) $admin->id,
                'provider' => 'aitoearn',
                'content_type' => 'video',
                'title' => (string) $video->title,
                'content_source_type' => 'video_generation',
                'status' => 'queued',
                'quota_ledger_id' => $ledger?->id,
                'payload' => $payload + [
                    'source' => [
                        'type' => 'video_generation',
                        'video_generation_job_id' => (int) $video->id,
                    ],
                ],
            ]);

            foreach ($accounts as $account) {
                $job->items()->create([
                    'self_media_account_id' => (int) $account->id,
                    'provider' => 'aitoearn',
                    'platform' => (string) $account->platform,
                    'external_account_id' => (string) $account->external_account_id,
                    'status' => 'queued',
                    'payload' => $this->itemPayload($account),
                ]);
            }

            return [$job];
        });

        foreach ($jobs as $job) {
            SubmitAiToEarnPublishFlowJob::dispatch((int) $job->id)->onQueue('self-media');
        }

        return $jobs;
    }

    /**
     * @param  array<int,int>  $accountIds
     * @return Collection<int,SelfMediaAccount>
     */
    private function boundVideoAccountsForAdmin(Admin $admin, Site $site, array $accountIds): Collection
    {
        return SelfMediaAccount::query()
            ->whereIn('id', $accountIds)
            ->where('site_id', (int) $site->id)
            ->where('owner_admin_id', (int) $admin->id)
            ->where('provider', 'aitoearn')
            ->where('status', 'bound')
            ->where('auth_status', 'authorized')
            ->whereIn('platform', SelfMediaPlatformCatalog::videoPlatforms())
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string,mixed>
     */
    private function publishPayload(VideoGenerationJob $video, Site $site): array
    {
        return [
            'flowId' => 'geoflow-video-'.(int) $video->id.'-'.Str::lower(Str::random(10)),
            'content' => [
                'title' => $this->normalizeTitle((string) $video->title, 80),
                'body' => $this->description($video),
                'media' => [
                    [
                        'url' => ImageUrlNormalizer::toPublicUrl($video->firstVideoUrl()),
                    ],
                ],
                'cover' => [
                    'url' => ImageUrlNormalizer::toPublicUrl((string) $video->cover_image),
                    'options' => [
                        'adaptation' => ['imageFormat' => 'auto'],
                    ],
                ],
            ],
            'publishAt' => $this->publishAt(),
            'context' => [
                'materialGroupId' => 'site:'.(int) $site->id,
                'materialId' => 'video_generation:'.(int) $video->id,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function itemPayload(SelfMediaAccount $account): array
    {
        $payload = [
            'platform' => (string) $account->platform,
            'accountId' => (string) $account->external_account_id,
        ];

        if ((string) $account->platform === 'bilibili') {
            $payload['option'] = [
                'tid' => max(1, (int) config('aitoearn.default_bilibili_tid', 160)),
                'copyright' => 1,
            ];
        }

        return $payload;
    }

    private function description(VideoGenerationJob $video): string
    {
        $desc = trim((string) $video->script);
        if ($desc === '') {
            $desc = trim((string) $video->subject);
        }

        return mb_substr($desc, 0, 500, 'UTF-8');
    }

    private function publishAt(): string
    {
        return now()
            ->addSeconds(max(1, (int) config('aitoearn.publish_delay_seconds', 60)))
            ->toIso8601String();
    }

    private function normalizeTitle(string $title, int $maxLength): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
        if ($title === '') {
            $title = '未命名内容';
        }

        return mb_strlen($title, 'UTF-8') > $maxLength
            ? mb_substr($title, 0, $maxLength, 'UTF-8')
            : $title;
    }
}
