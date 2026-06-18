<?php

namespace App\Services\Crebee;

use App\Models\Admin;
use App\Models\Article;
use App\Models\CrebeeAccount;
use App\Models\CrebeePublishJob;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Services\Billing\AdminResourceQuotaService;
use App\Support\GeoFlow\ImageUrlNormalizer;
use App\Support\Site\ArticleHtmlPresenter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class SelfMediaArticlePublishService
{
    /**
     * @var array<string,string>
     */
    private const ARTICLE_PLATFORM_LABELS = [
        'douyin' => '抖音',
        'bilibili' => 'B站',
        'zhihu' => '知乎',
        'toutiaohao' => '头条号',
        'gongzhonghao' => '公众号',
    ];

    public function __construct(
        private readonly AdminResourceQuotaService $quotaService,
    ) {}

    /**
     * @return array<string,string>
     */
    public static function articlePlatformLabels(): array
    {
        return self::ARTICLE_PLATFORM_LABELS;
    }

    /**
     * @return array<int,string>
     */
    public static function articlePlatforms(): array
    {
        return array_keys(self::ARTICLE_PLATFORM_LABELS);
    }

    /**
     * @param  array<int,int>  $accountIds
     * @return array<int,CrebeePublishJob>
     */
    public function publish(Article $article, Admin $admin, Site $site, array $accountIds): array
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

        if ((int) $article->site_id !== (int) $site->id) {
            throw new RuntimeException('文章不属于当前站点');
        }

        if (trim((string) $article->content) === '') {
            throw new RuntimeException('文章内容不能为空');
        }

        $accounts = $this->boundArticleAccountsForAdmin($admin, $site, $accountIds);
        if ($accounts->count() !== count($accountIds)) {
            throw new RuntimeException('请选择已绑定且支持文章发布的自媒体平台');
        }

        if ($accounts->contains(fn (CrebeeAccount $account): bool => (string) $account->platform === 'bilibili')
            && $this->articleCover($article) === ''
        ) {
            throw new RuntimeException('B站文章发布需要封面图，请先给文章添加封面图后再发布');
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

        return DB::transaction(function () use ($article, $admin, $site, $accounts, $amount): array {
            $ledger = null;
            if (! $admin->isSuperAdmin()) {
                $ledger = $this->quotaService->consume(
                    (int) $admin->id,
                    (int) $site->id,
                    PlatformPlan::RESOURCE_CREBEE_PUBLISHES,
                    $amount,
                    [
                        'actor_admin_id' => (int) $admin->id,
                        'subject_type' => Article::class,
                        'subject_id' => (int) $article->id,
                        'idempotency_key' => 'self-media-article:'.$article->id.':'.Str::uuid(),
                        'remark' => '自媒体文章发布',
                    ]
                );
            }

            $commonForm = $this->articleCommonForm($article);
            $jobs = [];

            foreach ($accounts->groupBy('agent_id') as $agentId => $agentAccounts) {
                $job = CrebeePublishJob::query()->create([
                    'site_id' => (int) $site->id,
                    'owner_admin_id' => (int) $admin->id,
                    'agent_id' => (int) $agentId,
                    'content_type' => 'article',
                    'title' => (string) $article->title,
                    'content_source_type' => 'article',
                    'status' => 'queued',
                    'quota_ledger_id' => $ledger?->id,
                    'payload' => [
                        'contentType' => 'article',
                        'commonForm' => $commonForm,
                        'assets' => [],
                        'source' => [
                            'type' => 'article',
                            'article_id' => (int) $article->id,
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
                            'contentType' => 'article',
                            'params' => $this->platformParams((string) $account->platform, $commonForm, $taskId, $article),
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
    private function boundArticleAccountsForAdmin(Admin $admin, Site $site, array $accountIds): Collection
    {
        return CrebeeAccount::query()
            ->whereIn('id', $accountIds)
            ->where('site_id', (int) $site->id)
            ->where('owner_admin_id', (int) $admin->id)
            ->where('status', 'bound')
            ->whereIn('platform', self::articlePlatforms())
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{title:string,content:string,covers:array<int,string>}
     */
    private function articleCommonForm(Article $article): array
    {
        $cover = $this->articleCover($article);

        return [
            'title' => $this->normalizeTitle((string) $article->title, 80),
            'content' => $this->contentAsHtml((string) $article->content),
            'covers' => $cover !== '' ? [$cover] : [],
        ];
    }

    /**
     * @param  array{title:string,content:string,covers:array<int,string>}  $commonForm
     * @return array<string,mixed>
     */
    private function platformParams(string $platform, array $commonForm, string $taskId, Article $article): array
    {
        $base = [
            'title' => $this->titleForPlatform($platform, $commonForm['title']),
            'content' => $commonForm['content'],
            'covers' => $commonForm['covers'],
            'taskId' => $taskId,
        ];

        return match ($platform) {
            'douyin' => $base + [
                'timing' => 0,
                'visibilityType' => 0,
                'topics' => [],
                'mentions' => [],
                'activities' => [],
            ],
            'bilibili' => $base + [
                'pubType' => 1,
                'original' => 1,
                'timing' => 0,
            ],
            'zhihu' => $base,
            'toutiaohao' => $base + [
                'timing' => 0,
            ],
            'gongzhonghao' => $base + [
                'pubType' => 1,
                'author' => '',
                'digest' => $this->normalizeTitle((string) ($article->excerpt ?: strip_tags($commonForm['content'])), 120),
                'need_open_comment' => 0,
                'only_fans_can_comment' => 0,
            ],
            default => $base,
        };
    }

    private function contentAsHtml(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        return preg_match('/<\/?(?:p|h[1-6]|ul|ol|li|blockquote|pre|table|div|section|article|img|figure|strong|em)\b/i', $content) === 1
            ? $content
            : ArticleHtmlPresenter::markdownToHtml($content);
    }

    private function articleCover(Article $article): string
    {
        $cover = ImageUrlNormalizer::toPublicUrl((string) ($article->cover_image ?? ''));
        if ($cover !== '') {
            return $cover;
        }

        $content = (string) $article->content;
        if (preg_match('/!\[[^\]\n]*\]\(([^)\s]+)(?:\s+"[^"]*")?\)/u', $content, $matches) === 1) {
            return ImageUrlNormalizer::toPublicUrl((string) ($matches[1] ?? ''));
        }

        if (preg_match('/<img\b[^>]*\bsrc=["\']([^"\']+)["\']/iu', $content, $matches) === 1) {
            return ImageUrlNormalizer::toPublicUrl((string) ($matches[1] ?? ''));
        }

        $articleImage = $article->articleImages()
            ->with('image:id,file_path')
            ->orderBy('position')
            ->orderBy('id')
            ->first();

        return $articleImage?->image
            ? ImageUrlNormalizer::toPublicUrl((string) $articleImage->image->file_path)
            : '';
    }

    private function titleForPlatform(string $platform, string $title): string
    {
        return match ($platform) {
            'zhihu' => $this->normalizeTitle($title, 50, 5),
            default => $title,
        };
    }

    private function normalizeTitle(string $title, int $maxLength, int $minLength = 1): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
        if ($title === '') {
            $title = '未命名内容';
        }

        if (mb_strlen($title, 'UTF-8') > $maxLength) {
            $title = mb_substr($title, 0, $maxLength, 'UTF-8');
        }

        while (mb_strlen($title, 'UTF-8') < $minLength) {
            $title .= '分享';
        }

        return $title;
    }

    private function taskId(string $platform): string
    {
        return $platform.'-'.now()->format('YmdHis').'-'.Str::lower(Str::random(10));
    }
}
