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

        if ($accounts->contains(fn (SelfMediaAccount $account): bool => $this->requiresImageMedia((string) $account->platform))
            && $this->articleCover($article) === ''
        ) {
            throw new RuntimeException('抖音/小红书图文发布需要至少一张封面图或配图，请先给文章补充图片后再发布');
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

            $payload = $this->publishPayload($article, $site, $accounts);
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
                    'payload' => $this->itemPayload($account, $article),
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
     * @param  Collection<int,SelfMediaAccount>  $accounts
     * @return array<string,mixed>
     */
    private function publishPayload(Article $article, Site $site, Collection $accounts): array
    {
        $platforms = $accounts
            ->pluck('platform')
            ->map(static fn ($platform): string => (string) $platform)
            ->values()
            ->all();

        $content = [
            'title' => $this->normalizeTitle((string) $article->title, $this->sharedTitleMaxLength($platforms)),
            'body' => $this->sharedBody($article, $platforms),
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
    private function itemPayload(SelfMediaAccount $account, Article $article): array
    {
        $payload = [
            'platform' => (string) $account->platform,
            'accountId' => (string) $account->external_account_id,
        ];

        $overrides = $this->platformOverrides((string) $account->platform, $article);
        if ($overrides !== []) {
            $payload['overrides'] = $overrides;
        }

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
        return [];
    }

    /**
     * @return array<string,mixed>
     */
    private function platformOverrides(string $platform, Article $article): array
    {
        return match ($platform) {
            'douyin' => [
                'title' => $this->normalizeTitle((string) $article->title, 30),
                'body' => $this->contentAsText((string) $article->content, 1000),
            ],
            'xhs' => [
                'title' => $this->normalizeTitle((string) $article->title, 20),
                'body' => $this->contentAsText((string) $article->content, 1000),
            ],
            default => [],
        };
    }

    /**
     * @param  array<int,string>  $platforms
     */
    private function sharedTitleMaxLength(array $platforms): int
    {
        $limits = collect($platforms)
            ->map(fn (string $platform): int => $this->platformTitleMaxLength($platform))
            ->filter(static fn (int $limit): bool => $limit > 0)
            ->values();

        return $limits->isEmpty() ? 64 : (int) $limits->min();
    }

    private function platformTitleMaxLength(string $platform): int
    {
        return match ($platform) {
            'douyin' => 30,
            'xhs' => 20,
            'wxGzh' => 64,
            default => 64,
        };
    }

    /**
     * @param  array<int,string>  $platforms
     */
    private function sharedBody(Article $article, array $platforms): string
    {
        $hasHtmlEditorPlatform = collect($platforms)
            ->contains(fn (string $platform): bool => $this->usesHtmlEditor($platform));

        return $hasHtmlEditorPlatform
            ? $this->contentAsHtml((string) $article->content)
            : $this->contentAsText((string) $article->content, $this->sharedBodyMaxLength($platforms));
    }

    /**
     * @param  array<int,string>  $platforms
     */
    private function sharedBodyMaxLength(array $platforms): int
    {
        $limits = collect($platforms)
            ->map(fn (string $platform): int => $this->platformBodyMaxLength($platform))
            ->filter(static fn (int $limit): bool => $limit > 0)
            ->values();

        return $limits->isEmpty() ? 20000 : (int) $limits->min();
    }

    private function platformBodyMaxLength(string $platform): int
    {
        return match ($platform) {
            'douyin', 'xhs' => 1000,
            'wxGzh' => 20000,
            default => 20000,
        };
    }

    private function usesHtmlEditor(string $platform): bool
    {
        return $platform === 'wxGzh';
    }

    private function requiresImageMedia(string $platform): bool
    {
        return in_array($platform, ['douyin', 'xhs'], true);
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

    private function contentAsText(string $content, int $maxLength): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        $html = preg_match('/<\/?[a-z][\s\S]*>/i', $content) === 1
            ? $content
            : ArticleHtmlPresenter::markdownToHtml($content);

        $html = preg_replace('/<li\b[^>]*>/iu', "\n- ", $html) ?? $html;
        $html = preg_replace('/<(?:br|\/(?:p|div|section|article|h[1-6]|li|ul|ol|blockquote|pre|tr|table))\b[^>]*>/iu', "\n", $html) ?? $html;

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/[ \t]+/u', ' ', $text) ?? $text);
        $text = preg_replace('/[ \t]*\n[ \t]*/u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return mb_strlen($text, 'UTF-8') > $maxLength
            ? mb_substr($text, 0, $maxLength, 'UTF-8')
            : $text;
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
