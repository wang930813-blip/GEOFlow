<?php

/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-18
 *
 * @Time: 15:43
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： MediaSubmissionPreviewSecurityTest.php
 *
 * @Description: 验证媒体投稿快照清洗、预览输出清洗及预览令牌状态和时效边界。
 */

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\MediaResource;
use App\Models\MediaSubmission;
use App\Models\Site;
use App\Services\MediaDistribution\MediaPlatformClient;
use App\Services\MediaDistribution\MediaPlatformClientManager;
use App\Services\MediaDistribution\MediaSubmissionHtmlSanitizer;
use App\Services\MediaDistribution\MediaSubmissionService;
use App\Support\MediaDistribution\MediaPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class MediaSubmissionPreviewSecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @Name: test_submission_snapshot_removes_dangerous_elements_attributes_and_protocols
     *
     * @Description: 验证合法标签包裹的脚本、事件属性、危险协议及禁止元素在订单创建前从投稿快照移除。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 15:43:10
     *
     * @UpdateTime: 2026-07-18 15:43:10
     *
     * @Return: void
     */
    public function test_submission_snapshot_removes_dangerous_elements_attributes_and_protocols(): void
    {
        $content = '<section><p onclick="alert(1)">安全正文<script>alert(1)</script><strong>保留加粗</strong></p>'
            .'<a href="java&#x73;cript:alert(2)" onmouseover="alert(3)">危险链接</a>'
            .'<img src="data:image/png;base64,AAAA" onerror="alert(4)" alt="危险图片">'
            .'<img src="https://cdn.example.com/safe.png" alt="安全图片" loading="lazy">'
            .'<p>&lt;script&gt;alert(5)&lt;/script&gt;</p>'
            .'<iframe src="https://example.com"></iframe><style>body{display:none}</style>'
            .'<form action="https://example.com"><input name="secret"></form></section>';
        [$admin, $article, $resource] = $this->createSubmissionContext($content);
        $client = Mockery::mock(MediaPlatformClient::class);
        $client->shouldReceive('submit')
            ->once()
            ->andReturn(['data' => ['order_nid' => 'security-order']]);
        $clients = Mockery::mock(MediaPlatformClientManager::class);
        $clients->shouldReceive('forPlatform')
            ->once()
            ->with(MediaPlatform::CEYING_MEDIA_1)
            ->andReturn($client);
        $this->app->instance(MediaPlatformClientManager::class, $clients);

        $submission = app(MediaSubmissionService::class)->submit($article, $resource, $admin);
        $snapshot = (string) $submission->content_snapshot;
        $sanitizer = app(MediaSubmissionHtmlSanitizer::class);

        $this->assertSame($sanitizer->sanitize($content), $snapshot);
        $this->assertSame($snapshot, $sanitizer->sanitize($snapshot));
        $this->assertStringContainsString('<p>安全正文<strong>保留加粗</strong></p>', $snapshot);
        $this->assertStringContainsString('<a>危险链接</a>', $snapshot);
        $this->assertStringContainsString('src="https://cdn.example.com/safe.png"', $snapshot);
        $this->assertStringContainsString('&lt;script&gt;alert(5)&lt;/script&gt;', $snapshot);
        $this->assertStringNotContainsString('<script', strtolower($snapshot));
        $this->assertStringNotContainsString('<iframe', strtolower($snapshot));
        $this->assertStringNotContainsString('<style', strtolower($snapshot));
        $this->assertStringNotContainsString('<form', strtolower($snapshot));
        $this->assertStringNotContainsString('onclick', strtolower($snapshot));
        $this->assertStringNotContainsString('onerror', strtolower($snapshot));
        $this->assertStringNotContainsString('onmouseover', strtolower($snapshot));
        $this->assertStringNotContainsString('javascript:', strtolower($snapshot));
        $this->assertStringNotContainsString('data:', strtolower($snapshot));

        $entityScript = $sanitizer->sanitize('&lt;script&gt;alert(6)&lt;/script&gt;');
        $this->assertStringNotContainsString('<script', strtolower($entityScript));
        $this->assertSame($entityScript, $sanitizer->sanitize($entityScript));
    }

    /**
     * @Name: test_preview_sanitizes_legacy_snapshot_without_entity_double_decode_bypass
     *
     * @Description: 验证历史未清洗快照在输出时仍使用统一入口，实体脚本仅作为文本且不会因重复解码成为可执行标签。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 15:43:10
     *
     * @UpdateTime: 2026-07-18 15:43:10
     *
     * @Return: void
     */
    public function test_preview_sanitizes_legacy_snapshot_without_entity_double_decode_bypass(): void
    {
        [$article, $resource] = $this->createPreviewContext();
        $submission = $this->createPreviewSubmission(
            $article,
            $resource,
            'submitted',
            'legacy-security-token',
            '<p onload="alert(1)">预览正文<script>alert(2)</script>'
                .'&lt;script&gt;alert(3)&lt;/script&gt;'
                .'<a href="javascript:alert(4)">危险链接</a></p>',
            now()
        );

        $response = $this->get($this->previewUrl($submission));

        $response
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; img-src 'self' http: https:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'self' https://vip.chaojimeijie.com")
            ->assertSee('<p>预览正文&lt;script&gt;alert(3)&lt;/script&gt;<a>危险链接</a></p>', false)
            ->assertDontSee('<script', false)
            ->assertDontSee('onload=', false)
            ->assertDontSee('javascript:', false);
    }

    /**
     * @Name: test_preview_allows_fresh_submitting_record_and_denies_expired_or_cancelled_record
     *
     * @Description: 验证第三方可在提交期间按 created_at 抓取，同时 submitted_at 已过期或订单已取消时统一返回 404。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 15:43:10
     *
     * @UpdateTime: 2026-07-18 15:43:10
     *
     * @Return: void
     */
    public function test_preview_allows_fresh_submitting_record_and_denies_expired_or_cancelled_record(): void
    {
        config()->set('media_distribution.preview_ttl_minutes', 60);
        $this->travelTo(now()->startOfMinute());
        [$article, $resource] = $this->createPreviewContext();

        $submitting = $this->createPreviewSubmission(
            $article,
            $resource,
            'submitting',
            'fresh-submitting-token',
            '<p>第三方抓取正文</p>',
            null
        );
        $submitting->forceFill(['created_at' => now()->subMinutes(30)])->save();

        $expired = $this->createPreviewSubmission(
            $article,
            $resource,
            'submitted',
            'expired-submitted-token',
            '<p>过期正文</p>',
            now()->subMinutes(60)
        );
        $cancelled = $this->createPreviewSubmission(
            $article,
            $resource,
            'cancelled',
            'cancelled-submission-token',
            '<p>取消正文</p>',
            now()
        );

        $this->get($this->previewUrl($submitting))->assertOk()->assertSee('第三方抓取正文');
        $this->get($this->previewUrl($expired))->assertNotFound();
        $this->get($this->previewUrl($cancelled))->assertNotFound();
    }

    public function test_published_submission_preview_remains_accessible_after_capture_window(): void
    {
        config()->set('media_distribution.preview_ttl_minutes', 60);
        $this->travelTo(now()->startOfMinute());
        [$article, $resource] = $this->createPreviewContext();

        $published = $this->createPreviewSubmission(
            $article,
            $resource,
            'published',
            'published-preview-token',
            '<p>发布后仍可打开的正文</p>',
            now()->subDays(2)
        );

        $this->get($this->previewUrl($published))
            ->assertOk()
            ->assertSee('发布后仍可打开的正文');
    }

    /**
     * @Name: createSubmissionContext
     *
     * @Description: 创建可直接执行投稿服务的超级管理员、站点、文章和媒体资源，避免额度逻辑干扰安全边界验证。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 15:43:10
     *
     * @UpdateTime: 2026-07-18 15:43:10
     *
     * @Param: string $content 投稿正文
     *
     * @Return: array{Admin,Article,MediaResource} 投稿上下文
     */
    private function createSubmissionContext(string $content): array
    {
        $admin = Admin::query()->create([
            'username' => 'submission_security_'.Str::lower(Str::random(8)),
            'password' => 'secure-password',
            'email' => Str::lower(Str::random(8)).'@example.com',
            'display_name' => '投稿安全管理员',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        [$site, $category, $author] = $this->createArticleRelations($admin);
        $article = Article::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'title' => '投稿安全文章',
            'slug' => 'submission-security-'.Str::lower(Str::random(8)),
            'content' => $content,
            'status' => 'published',
            'review_status' => 'approved',
        ]);
        $resource = $this->createMediaResource();

        return [$admin, $article, $resource];
    }

    /**
     * @Name: createPreviewContext
     *
     * @Description: 创建预览记录依赖的站点、栏目、作者、文章和媒体资源。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 15:43:10
     *
     * @UpdateTime: 2026-07-18 15:43:10
     *
     * @Return: array{Article,MediaResource} 预览上下文
     */
    private function createPreviewContext(): array
    {
        [$site, $category, $author] = $this->createArticleRelations();
        $article = Article::query()->create([
            'site_id' => (int) $site->id,
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'title' => '投稿预览安全文章',
            'slug' => 'preview-security-'.Str::lower(Str::random(8)),
            'content' => '<p>安全正文</p>',
            'status' => 'published',
            'review_status' => 'approved',
        ]);

        return [$article, $this->createMediaResource()];
    }

    /**
     * @Name: createArticleRelations
     *
     * @Description: 创建文章所需站点、栏目与作者，可选绑定站点所有者。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 15:43:10
     *
     * @UpdateTime: 2026-07-18 15:43:10
     *
     * @Param: Admin|null $owner 站点所有者
     *
     * @Return: array{Site,Category,Author} 文章关联模型
     */
    private function createArticleRelations(?Admin $owner = null): array
    {
        $suffix = Str::lower(Str::random(8));
        $site = Site::query()->create([
            'owner_admin_id' => $owner?->id,
            'name' => '投稿预览站点 '.$suffix,
            'domain' => $suffix.'.example.test',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'site_id' => (int) $site->id,
            'name' => '投稿安全栏目',
            'slug' => 'submission-category-'.$suffix,
            'status' => 'active',
        ]);
        $author = Author::query()->create([
            'site_id' => (int) $site->id,
            'name' => '投稿安全作者',
            'slug' => 'submission-author-'.$suffix,
            'status' => 'active',
        ]);

        return [$site, $category, $author];
    }

    /**
     * @Name: createMediaResource
     *
     * @Description: 创建启用状态的媒体资源并保证第三方资源编号唯一。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 15:43:10
     *
     * @UpdateTime: 2026-07-18 15:43:10
     *
     * @Return: MediaResource 媒体资源
     */
    private function createMediaResource(): MediaResource
    {
        $suffix = Str::lower(Str::random(8));

        return MediaResource::query()->create([
            'platform_id' => MediaPlatform::CEYING_MEDIA_1,
            'source_type' => MediaResource::SOURCE_WEBSITE,
            'external_resource_id' => 'security-resource-'.$suffix,
            'title' => '投稿安全媒体 '.$suffix,
            'status' => 'active',
            'cost_price' => '0.00',
            'sale_price' => '0.00',
        ]);
    }

    /**
     * @Name: createPreviewSubmission
     *
     * @Description: 创建指定状态和计时基准的媒体投稿预览记录。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 15:43:10
     *
     * @UpdateTime: 2026-07-18 15:43:10
     *
     * @Param: Article       $article     投稿文章
     *
     * @Param: MediaResource $resource    媒体资源
     *
     * @Param: string        $status      投稿状态
     *
     * @Param: string        $token       预览令牌
     *
     * @Param: string        $content     快照正文
     *
     * @Param: mixed         $submittedAt 第三方受理时间
     *
     * @Return: MediaSubmission 投稿记录
     */
    private function createPreviewSubmission(
        Article $article,
        MediaResource $resource,
        string $status,
        string $token,
        string $content,
        mixed $submittedAt
    ): MediaSubmission {
        return MediaSubmission::query()->create([
            'site_id' => (int) $article->site_id,
            'article_id' => (int) $article->id,
            'media_resource_id' => (int) $resource->id,
            'platform_id' => MediaPlatform::CEYING_MEDIA_1,
            'source_type' => (string) $resource->source_type,
            'external_order_nid' => 'security-order-'.Str::lower(Str::random(8)),
            'agent_order_sn' => 'security-agent-'.Str::lower(Str::random(8)),
            'preview_token' => $token,
            'title_snapshot' => (string) $article->title,
            'content_snapshot' => $content,
            'cost_price_snapshot' => '0.00',
            'sale_price_snapshot' => '0.00',
            'points_amount' => '0.00',
            'status' => $status,
            'submitted_at' => $submittedAt,
        ]);
    }

    /**
     * @Name: previewUrl
     *
     * @Description: 生成指定投稿记录的真实预览路由地址。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 15:43:10
     *
     * @UpdateTime: 2026-07-18 15:43:10
     *
     * @Param: MediaSubmission $submission 投稿记录
     *
     * @Return: string 预览地址
     */
    private function previewUrl(MediaSubmission $submission): string
    {
        return route('media-submission-preview.show', [
            'submission' => (int) $submission->id,
            'token' => (string) $submission->preview_token,
        ]);
    }
}
