<?php

namespace App\Services\SelfMedia;

use App\Jobs\SubmitAiToEarnPublishFlowJob;
use App\Models\Admin;
use App\Models\Article;
use App\Models\PlatformPlan;
use App\Models\SelfMediaAccount;
use App\Models\SelfMediaPublishJob;
use App\Models\Site;
use App\Services\Billing\AdminResourceQuotaService;
use App\Support\GeoFlow\ImageUrlNormalizer;
use App\Support\SelfMedia\SelfMediaPlatformCatalog;
use App\Support\Site\ArticleHtmlPresenter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class SelfMediaArticlePublishService
{
    public function __construct(
        private readonly AdminResourceQuotaService $quotaService,
    ) {}

    /**
     * @return array<string,string>
     */
    public static function articlePlatformLabels(): array
    {
        return collect(SelfMediaPlatformCatalog::articlePlatforms())
            ->mapWithKeys(fn (string $platform): array => [$platform => SelfMediaPlatformCatalog::label($platform)])
            ->all();
    }

    /**
     * @return array<int,string>
     */
    public static function articlePlatforms(): array
    {
        return SelfMediaPlatformCatalog::articlePlatforms();
    }

    /**
     * @param  array<int,int>  $accountIds
     * @return array<int,SelfMediaPublishJob>
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
            throw new RuntimeException('请选择已授权且支持文章发布的自媒体平台');
        }

        if ($accounts->contains(fn (SelfMediaAccount $account): bool => (string) $account->platform === 'bilibili')
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

        $jobs = DB::transaction(function () use ($article, $admin, $site, $accounts, $amount): array {
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

            $payload = $this->publishPayload($article, $site);
            $job = SelfMediaPublishJob::query()->create([
                'site_id' => (int) $site->id,
                'owner_admin_id' => (int) $admin->id,
                'provider' => 'aitoearn',
                'content_type' => 'article',
                'title' => (string) $article->title,
                'content_source_type' => 'article',
                'status' => 'queued',
                'quota_ledger_id' => $ledger?->id,
                'payload' => $payload + [
                    'source' => [
                        'type' => 'article',
                        'article_id' => (int) $article->id,
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
    private function boundArticleAccountsForAdmin(Admin $admin, Site $site, array $accountIds): Collection
    {
        return SelfMediaAccount::query()
            ->whereIn('id', $accountIds)
            ->where('site_id', (int) $site->id)
            ->where('owner_admin_id', (int) $admin->id)
            ->where('provider', 'aitoearn')
            ->where('status', 'bound')
            ->where('auth_status', 'authorized')
            ->whereIn('platform', self::articlePlatforms())
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string,mixed>
     */
    private function publishPayload(Article $article, Site $site): array
    {
        $content = [
            'title' => $this->normalizeTitle((string) $article->title, 80),
            'body' => $this->contentAsHtml((string) $article->content),
        ];

        $cover = $this->articleCover($article);
        if ($cover !== '') {
            $content['cover'] = [
                'url' => $cover,
                'options' => [
                    'adaptation' => ['imageFormat' => 'auto'],
                ],
            ];
        }

        return [
            'flowId' => 'geoflow-article-'.(int) $article->id.'-'.Str::lower(Str::random(10)),
            'content' => $content,
            'publishAt' => $this->publishAt(),
            'context' => [
                'materialGroupId' => 'site:'.(int) $site->id,
                'materialId' => 'article:'.(int) $article->id,
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

        $option = $this->platformOption((string) $account->platform);
        if ($option !== []) {
            $payload['option'] = $option;
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function platformOption(string $platform): array
    {
        return match ($platform) {
            'bilibili' => [
                'tid' => max(1, (int) config('aitoearn.default_bilibili_tid', 160)),
                'copyright' => 1,
            ],
            default => [],
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

    private function publishAt(): string
    {
        return now()
            ->addSeconds(max(1, (int) config('aitoearn.publish_delay_seconds', 60)))
            ->toIso8601String();
    }
}
