<?php

namespace Tests\Feature;

use App\Jobs\SubmitAiToEarnPublishFlowJob;
use App\Jobs\SyncAiToEarnPublishStatusJob;
use App\Models\Admin;
use App\Models\AdminResourceUsage;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\PlatformPlan;
use App\Models\SelfMediaAccount;
use App\Models\SelfMediaAuthSession;
use App\Models\SelfMediaPublishJob;
use App\Models\SelfMediaPublishJobItem;
use App\Models\Site;
use App\Models\VideoGenerationJob;
use App\Services\Billing\AdminPlanSubscriptionService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AiToEarnSelfMediaPublishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
        config([
            'aitoearn.enabled' => true,
            'aitoearn.base_url' => 'https://aitoearn.test',
            'aitoearn.api_key' => 'test-api-key',
            'aitoearn.publish_delay_seconds' => 60,
        ]);
    }

    public function test_article_publish_creates_aitoearn_job_items_and_consumes_selected_platform_count(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00', 'Asia/Shanghai'));
        Queue::fake();

        [$admin, $site] = $this->provisionSubscribedAdmin('aitoearn_article_owner', 3);
        $article = $this->article($site, $admin, 'AiToEarn 发布测试文章', "第一段\n\n第二段");
        $article->forceFill(['cover_image' => 'https://cdn.example.com/article-cover.jpg'])->save();
        $douyin = $this->selfMediaAccount($site, $admin, 'douyin', 'account-douyin-001', '抖音号');
        $wxGzh = $this->selfMediaAccount($site, $admin, 'wxGzh', 'account-wxgzh-001', '公众号');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->from(route('admin.articles.index'))
            ->post(route('admin.articles.self-media.publish', ['articleId' => (int) $article->id]), [
                'self_media_account_ids' => [
                    (int) $douyin->id,
                    (int) $wxGzh->id,
                ],
            ])
            ->assertRedirect(route('admin.articles.index'));

        $job = SelfMediaPublishJob::query()->firstOrFail();
        $this->assertSame('aitoearn', (string) $job->provider);
        $this->assertSame((int) $site->id, (int) $job->site_id);
        $this->assertSame((int) $admin->id, (int) $job->owner_admin_id);
        $this->assertSame('article', (string) $job->content_type);
        $this->assertSame('queued', (string) $job->status);
        $this->assertSame('AiToEarn 发布测试文章', (string) data_get($job->payload, 'content.title'));
        $this->assertStringContainsString('<p>第一段</p>', (string) data_get($job->payload, 'content.body'));
        $this->assertSame('https://cdn.example.com/article-cover.jpg', (string) data_get($job->payload, 'content.cover.url'));
        $this->assertSame(
            now()->addSeconds(60)->toIso8601String(),
            (string) data_get($job->payload, 'publishAt')
        );

        $this->assertSame(2, SelfMediaPublishJobItem::query()->count());
        $douyinItem = SelfMediaPublishJobItem::query()
            ->where('platform', 'douyin')
            ->firstOrFail();
        $this->assertSame('第一段'."\n\n".'第二段', (string) data_get($douyinItem->payload, 'overrides.body'));
        $this->assertSame('AiToEarn 发布测试文章', (string) data_get($douyinItem->payload, 'overrides.title'));
        $this->assertNull(data_get($douyinItem->payload, 'overrides.cover'));

        $wxGzhItem = SelfMediaPublishJobItem::query()
            ->where('platform', 'wxGzh')
            ->firstOrFail();
        $this->assertNull(data_get($wxGzhItem->payload, 'overrides.body'));
        $this->assertDatabaseHas('self_media_publish_job_items', [
            'job_id' => (int) $job->id,
            'self_media_account_id' => (int) $douyin->id,
            'platform' => 'douyin',
            'external_account_id' => 'account-douyin-001',
            'status' => 'queued',
        ]);
        $this->assertDatabaseHas('self_media_publish_job_items', [
            'job_id' => (int) $job->id,
            'self_media_account_id' => (int) $wxGzh->id,
            'platform' => 'wxGzh',
            'external_account_id' => 'account-wxgzh-001',
            'status' => 'queued',
        ]);

        $usage = AdminResourceUsage::query()
            ->where('admin_id', (int) $admin->id)
            ->where('site_id', (int) $site->id)
            ->where('resource_key', PlatformPlan::RESOURCE_CREBEE_PUBLISHES)
            ->firstOrFail();
        $this->assertSame(2, (int) $usage->used_amount);

        Queue::assertPushed(SubmitAiToEarnPublishFlowJob::class);
        Carbon::setTestNow();
    }

    public function test_article_publish_uses_plain_text_shared_content_when_only_text_editor_platforms_are_selected(): void
    {
        Queue::fake();

        [$admin, $site] = $this->provisionSubscribedAdmin('aitoearn_text_only_article_owner', 3);
        $article = $this->article($site, $admin, '抖音纯文本发布测试文章', "## 第一节\n\n正文 **加粗** 内容");
        $article->forceFill(['cover_image' => 'https://cdn.example.com/article-cover.jpg'])->save();
        $douyin = $this->selfMediaAccount($site, $admin, 'douyin', 'account-douyin-text-only', '抖音号');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->from(route('admin.articles.index'))
            ->post(route('admin.articles.self-media.publish', ['articleId' => (int) $article->id]), [
                'self_media_account_ids' => [
                    (int) $douyin->id,
                ],
            ])
            ->assertRedirect(route('admin.articles.index'));

        $job = SelfMediaPublishJob::query()->firstOrFail();
        $this->assertSame('第一节'."\n\n".'正文 加粗 内容', (string) data_get($job->payload, 'content.body'));
        $this->assertStringNotContainsString('<', (string) data_get($job->payload, 'content.body'));
        $this->assertStringNotContainsString('>', (string) data_get($job->payload, 'content.body'));
    }

    public function test_authorization_completed_status_syncs_account_and_marks_session_authorized(): void
    {
        [$admin, $site] = $this->provisionSubscribedAdmin('aitoearn_auth_owner', 3);
        $session = SelfMediaAuthSession::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'provider' => 'aitoearn',
            'platform' => 'douyin',
            'session_id' => 'session_001',
            'authorization_url' => 'https://aitoearn.test/auth/session_001',
            'status' => 'pending',
            'raw_response' => [],
        ]);

        Http::fake([
            'https://aitoearn.test/api/v2/channels/accounts/auth/douyin/status/session_001' => Http::response([
                'code' => 0,
                'message' => '请求成功',
                'data' => [
                    'status' => 'completed',
                ],
            ]),
            'https://aitoearn.test/api/v2/channels/accounts*' => Http::response([
                'code' => 0,
                'message' => '请求成功',
                'data' => [
                    'total' => 1,
                    'accounts' => [
                        [
                            'id' => 'account-douyin-auth',
                            'type' => 'douyin',
                            'nickname' => '授权完成的抖音号',
                            'avatar' => 'https://cdn.example.com/avatar.png',
                            'status' => 1,
                        ],
                    ],
                ],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.crebee-accounts.aitoearn.auth-sessions.sync', $session))
            ->assertRedirect(route('admin.crebee-accounts.index'));

        $session->refresh();
        $this->assertSame('authorized', (string) $session->status);
        $this->assertNotNull($session->confirmed_at);
        $this->assertDatabaseHas('self_media_accounts', [
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'provider' => 'aitoearn',
            'platform' => 'douyin',
            'external_account_id' => 'account-douyin-auth',
            'auth_status' => 'authorized',
        ]);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v2/channels/accounts?')
            && str_contains($request->url(), 'type=douyin'));
    }

    public function test_aitoearn_account_page_renders_remote_platforms_and_authorization_controls(): void
    {
        [$admin, $site] = $this->provisionSubscribedAdmin('aitoearn_account_page_owner', 3);

        Http::fake([
            'https://aitoearn.test/api/v2/channels/platforms' => Http::response([
                'code' => 0,
                'message' => '请求成功',
                'data' => [
                    [
                        'platform' => 'douyin',
                        'displayName' => 'Douyin',
                        'status' => 'available',
                        'contentLimits' => ['modes' => ['video', 'article']],
                    ],
                    [
                        'platform' => 'youtube',
                        'displayName' => 'YouTube',
                        'status' => 'available',
                        'contentLimits' => ['modes' => ['video']],
                    ],
                ],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.crebee-accounts.index'))
            ->assertOk()
            ->assertSee('自媒体账号授权')
            ->assertSee('Douyin')
            ->assertDontSee('YouTube')
            ->assertDontSee('数据范围')
            ->assertDontSee('AiToEarn')
            ->assertSee('去授权')
            ->assertSee(route('admin.crebee-accounts.aitoearn.authorizations.start'), false);
    }

    public function test_aitoearn_account_page_disables_authorization_when_remote_platform_does_not_support_auth(): void
    {
        \Illuminate\Support\Facades\Cache::flush();

        [$admin, $site] = $this->provisionSubscribedAdmin('aitoearn_xhs_account_page_owner', 3);

        Http::fake([
            'https://aitoearn.test/api/v2/channels/platforms' => Http::response([
                'code' => 0,
                'message' => '请求成功',
                'data' => [
                    [
                        'platform' => 'xhs',
                        'displayName' => ['zh-CN' => '小红书'],
                        'status' => 'available',
                        'contentLimits' => ['modes' => ['article', 'video']],
                        'capabilities' => [
                            'auth' => ['supported' => false],
                        ],
                    ],
                    [
                        'platform' => 'douyin',
                        'displayName' => ['zh-CN' => '抖音'],
                        'status' => 'available',
                        'contentLimits' => ['modes' => ['article', 'video']],
                        'capabilities' => [
                            'auth' => ['supported' => true],
                        ],
                    ],
                ],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.crebee-accounts.index'))
            ->assertOk()
            ->assertSee('小红书')
            ->assertSee('请先在第三方平台完成授权后点击同步账号')
            ->assertSee('同步账号后使用')
            ->assertSee('value="douyin"', false)
            ->assertDontSee('value="xhs"', false);
    }

    public function test_starting_authorization_rejects_platforms_without_remote_authorization_support(): void
    {
        \Illuminate\Support\Facades\Cache::flush();

        [$admin, $site] = $this->provisionSubscribedAdmin('aitoearn_xhs_authorization_block_owner', 3);

        Http::fake([
            'https://aitoearn.test/api/v2/channels/platforms' => Http::response([
                'code' => 0,
                'message' => '请求成功',
                'data' => [
                    [
                        'platform' => 'xhs',
                        'displayName' => ['zh-CN' => '小红书'],
                        'status' => 'available',
                        'capabilities' => [
                            'auth' => ['supported' => false],
                        ],
                    ],
                ],
            ]),
            'https://aitoearn.test/api/v2/channels/accounts/auth/xhs*' => Http::response([
                'code' => 0,
                'message' => 'should not be called',
                'data' => [],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->from(route('admin.crebee-accounts.index'))
            ->post(route('admin.crebee-accounts.aitoearn.authorizations.start'), [
                'platform' => 'xhs',
            ])
            ->assertRedirect(route('admin.crebee-accounts.index'))
            ->assertSessionHasErrors('platform');

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/v2/channels/accounts/auth/xhs'));
    }

    public function test_starting_authorization_uses_callback_url_and_keeps_qr_session_on_page(): void
    {
        [$admin, $site] = $this->provisionSubscribedAdmin('aitoearn_authorization_start_owner', 3);

        Http::fake([
            'https://aitoearn.test/api/v2/channels/platforms' => Http::response([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    [
                        'platform' => 'douyin',
                        'displayName' => 'Douyin',
                        'status' => 'available',
                    ],
                ],
            ]),
            'https://aitoearn.test/api/v2/channels/accounts/auth/douyin*' => Http::response([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'url' => 'data:image/png;base64,QR-CODE',
                    'sessionId' => 'session_douyin_001',
                    'expiresAt' => '2026-08-25T05:21:58.440Z',
                ],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.crebee-accounts.aitoearn.authorizations.start'), [
                'platform' => 'douyin',
            ])
            ->assertRedirect(route('admin.crebee-accounts.index'));

        $this->assertDatabaseHas('self_media_auth_sessions', [
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'provider' => 'aitoearn',
            'platform' => 'douyin',
            'session_id' => 'session_douyin_001',
            'authorization_url' => 'data:image/png;base64,QR-CODE',
            'status' => 'pending',
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.crebee-accounts.index'))
            ->assertOk()
            ->assertSee('data:image/png;base64,QR-CODE', false);

        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), '/api/v2/channels/accounts/auth/douyin?')
                && ($query['callbackUrl'] ?? '') === route('admin.crebee-accounts.aitoearn.authorizations.callback')
            && ! array_key_exists('redirectUri', $query);
        });
    }

    public function test_starting_authorization_stores_long_qr_code_data_url(): void
    {
        [$admin, $site] = $this->provisionSubscribedAdmin('aitoearn_authorization_long_qr_owner', 3);
        $qrCode = 'data:image/png;base64,'.str_repeat('A', 1600);

        Http::fake([
            'https://aitoearn.test/api/v2/channels/platforms' => Http::response([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    [
                        'platform' => 'douyin',
                        'displayName' => 'Douyin',
                        'status' => 'available',
                    ],
                ],
            ]),
            'https://aitoearn.test/api/v2/channels/accounts/auth/douyin*' => Http::response([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'url' => $qrCode,
                    'sessionId' => 'session_douyin_long_qr_001',
                    'expiresAt' => '2026-08-25T05:21:58.440Z',
                ],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.crebee-accounts.aitoearn.authorizations.start'), [
                'platform' => 'douyin',
            ])
            ->assertRedirect(route('admin.crebee-accounts.index'));

        $this->assertDatabaseHas('self_media_auth_sessions', [
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'provider' => 'aitoearn',
            'platform' => 'douyin',
            'session_id' => 'session_douyin_long_qr_001',
            'authorization_url' => $qrCode,
            'status' => 'pending',
        ]);
    }

    public function test_authorization_callback_accepts_external_post_and_syncs_session(): void
    {
        $this->withMiddleware(ValidateCsrfToken::class);

        [$admin, $site] = $this->provisionSubscribedAdmin('aitoearn_authorization_callback_owner', 3);
        SelfMediaAuthSession::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'provider' => 'aitoearn',
            'platform' => 'bilibili',
            'session_id' => 'session_bilibili_001',
            'authorization_url' => 'https://aitoearn.test/auth/session_bilibili_001',
            'status' => 'pending',
            'raw_response' => [],
        ]);

        Http::fake([
            'https://aitoearn.test/api/v2/channels/accounts/auth/bilibili/status/session_bilibili_001' => Http::response([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'status' => 'completed',
                ],
            ]),
            'https://aitoearn.test/api/v2/channels/accounts*' => Http::response([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'total' => 1,
                    'accounts' => [
                        [
                            'id' => 'account-bilibili-auth',
                            'type' => 'bilibili',
                            'nickname' => 'Bilibili Account',
                            'status' => 1,
                        ],
                    ],
                ],
            ]),
        ]);

        $this->post(route('admin.crebee-accounts.aitoearn.authorizations.callback'), [
            'sessionId' => 'session_bilibili_001',
        ])->assertRedirect(route('admin.crebee-accounts.index'));

        $this->assertDatabaseHas('self_media_auth_sessions', [
            'provider' => 'aitoearn',
            'platform' => 'bilibili',
            'session_id' => 'session_bilibili_001',
            'status' => 'authorized',
        ]);
        $this->assertDatabaseHas('self_media_accounts', [
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'provider' => 'aitoearn',
            'platform' => 'bilibili',
            'external_account_id' => 'account-bilibili-auth',
            'auth_status' => 'authorized',
        ]);
    }

    public function test_article_list_uses_aitoearn_accounts_in_publish_modal_when_enabled(): void
    {
        [$admin, $site] = $this->provisionSubscribedAdmin('aitoearn_article_page_owner', 3);
        $this->article($site, $admin, 'AiToEarn 页面发布文章', '正文');
        $this->selfMediaAccount($site, $admin, 'douyin', 'account-douyin-page', '页面抖音号');
        $this->selfMediaAccount($site, $admin, 'bilibili', 'account-bilibili-page', '页面B站号');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.articles.index'))
            ->assertOk()
            ->assertSee('页面抖音号')
            ->assertDontSee('页面B站号')
            ->assertSee('name="self_media_account_ids[]"', false)
            ->assertDontSee('name="crebee_account_ids[]"', false);
    }

    public function test_user_cannot_publish_aitoearn_article_to_bilibili_video_only_account(): void
    {
        Queue::fake();

        [$admin, $site] = $this->provisionSubscribedAdmin('aitoearn_article_bilibili_block_owner', 3);
        $article = $this->article($site, $admin, 'Bilibili should not receive article', '正文');
        $article->forceFill(['cover_image' => 'https://cdn.example.com/article-cover.jpg'])->save();
        $bilibili = $this->selfMediaAccount($site, $admin, 'bilibili', 'account-bilibili-blocked', 'Bilibili Account');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->from(route('admin.articles.index'))
            ->post(route('admin.articles.self-media.publish', ['articleId' => (int) $article->id]), [
                'self_media_account_ids' => [(int) $bilibili->id],
            ])
            ->assertRedirect(route('admin.articles.index'))
            ->assertSessionHasErrors('self_media_account_ids');

        $this->assertSame(0, SelfMediaPublishJob::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_aitoearn_article_publish_platforms_exclude_xhs_without_required_work_link(): void
    {
        $this->assertSame(['douyin', 'wxGzh'], \App\Support\SelfMedia\SelfMediaPlatformCatalog::articlePlatforms());
    }

    public function test_user_cannot_publish_aitoearn_article_to_image_text_platform_without_image(): void
    {
        Queue::fake();

        [$admin, $site] = $this->provisionSubscribedAdmin('aitoearn_article_no_image_owner', 3);
        $article = $this->article($site, $admin, '没有配图的文章', '正文');
        $douyin = $this->selfMediaAccount($site, $admin, 'douyin', 'account-douyin-no-image', '抖音号');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->from(route('admin.articles.index'))
            ->post(route('admin.articles.self-media.publish', ['articleId' => (int) $article->id]), [
                'self_media_account_ids' => [(int) $douyin->id],
            ])
            ->assertRedirect(route('admin.articles.index'))
            ->assertSessionHasErrors('self_media_account_ids');

        $this->assertSame(0, SelfMediaPublishJob::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_video_publish_creates_aitoearn_job_with_existing_media_and_cover(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 13:00:00', 'Asia/Shanghai'));
        Queue::fake();

        [$admin, $site] = $this->provisionSubscribedAdmin('aitoearn_video_owner', 3);
        $video = $this->completedVideo($site, $admin, 'AiToEarn 视频发布');
        $douyin = $this->selfMediaAccount($site, $admin, 'douyin', 'account-douyin-video', '抖音视频号');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->from(route('admin.video-generations.show', ['videoGeneration' => (int) $video->id]))
            ->post(route('admin.video-generations.self-media.publish', ['videoGeneration' => (int) $video->id]), [
                'self_media_account_ids' => [(int) $douyin->id],
            ])
            ->assertRedirect(route('admin.video-generations.index'));

        $job = SelfMediaPublishJob::query()->where('content_type', 'video')->firstOrFail();
        $this->assertSame('video_generation', (string) $job->content_source_type);
        $this->assertSame('AiToEarn 视频发布', (string) data_get($job->payload, 'content.title'));
        $this->assertSame(
            'https://video.example.test/tasks/video-task-aitoearn/final-1.mp4',
            (string) data_get($job->payload, 'content.media.0.url')
        );
        $this->assertSame('https://cdn.example.test/video-cover.jpg', (string) data_get($job->payload, 'content.cover.url'));
        $this->assertSame(
            now()->addSeconds(60)->toIso8601String(),
            (string) data_get($job->payload, 'publishAt')
        );
        $this->assertDatabaseHas('self_media_publish_job_items', [
            'job_id' => (int) $job->id,
            'self_media_account_id' => (int) $douyin->id,
            'platform' => 'douyin',
            'external_account_id' => 'account-douyin-video',
            'status' => 'queued',
        ]);

        Queue::assertPushed(SubmitAiToEarnPublishFlowJob::class);
        Carbon::setTestNow();
    }

    public function test_submit_job_posts_aitoearn_flow_and_stores_external_task_ids(): void
    {
        Queue::fake([SyncAiToEarnPublishStatusJob::class]);

        [$admin, $site] = $this->provisionSubscribedAdmin('aitoearn_submit_owner', 3);
        $account = $this->selfMediaAccount($site, $admin, 'wxGzh', 'account-wxgzh-002', '公众号');
        $job = SelfMediaPublishJob::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'provider' => 'aitoearn',
            'content_type' => 'article',
            'title' => '提交 flow 文章',
            'content_source_type' => 'article',
            'status' => 'queued',
            'payload' => [
                'flowId' => 'geoflow-self-media-1',
                'content' => [
                    'title' => '提交 flow 文章',
                    'body' => '<p>正文</p>',
                ],
                'publishAt' => '2026-08-25T12:00:00+08:00',
                'context' => [
                    'materialGroupId' => 'site:'.$site->id,
                    'materialId' => 'article:local-test',
                ],
            ],
        ]);
        SelfMediaPublishJobItem::query()->create([
            'job_id' => (int) $job->id,
            'self_media_account_id' => (int) $account->id,
            'provider' => 'aitoearn',
            'platform' => 'wxGzh',
            'external_account_id' => 'account-wxgzh-002',
            'status' => 'queued',
            'payload' => [
                'platform' => 'wxGzh',
                'accountId' => 'account-wxgzh-002',
            ],
        ]);

        Http::fake([
            'https://aitoearn.test/api/v2/channels/publish/flows' => Http::response([
                'code' => 0,
                'message' => '请求成功',
                'data' => [
                    'flowId' => 'flow_remote_001',
                    'tasks' => [
                        [
                            'id' => 'task_remote_001',
                            'accountId' => 'account-wxgzh-002',
                            'platform' => 'wxGzh',
                            'status' => 0,
                        ],
                    ],
                ],
            ]),
        ]);

        app()->call([app(SubmitAiToEarnPublishFlowJob::class, ['jobId' => (int) $job->id]), 'handle']);

        $this->assertDatabaseHas('self_media_publish_jobs', [
            'id' => (int) $job->id,
            'status' => 'submitted',
            'external_flow_id' => 'flow_remote_001',
        ]);
        $this->assertDatabaseHas('self_media_publish_job_items', [
            'job_id' => (int) $job->id,
            'status' => 'submitted',
            'external_task_id' => 'task_remote_001',
        ]);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://aitoearn.test/api/v2/channels/publish/flows'
            && $request['items'][0]['platform'] === 'wxGzh'
            && $request['items'][0]['accountId'] === 'account-wxgzh-002'
            && data_get($request->data(), 'context') === null);
    }

    public function test_submit_job_imports_douyin_article_cover_as_publish_media_before_creating_flow(): void
    {
        Queue::fake([SyncAiToEarnPublishStatusJob::class]);

        [$admin, $site] = $this->provisionSubscribedAdmin('aitoearn_douyin_media_import_owner', 3);
        $account = $this->selfMediaAccount($site, $admin, 'douyin', 'account-douyin-import', '抖音号');
        $job = SelfMediaPublishJob::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'provider' => 'aitoearn',
            'content_type' => 'article',
            'title' => '抖音图文发布',
            'content_source_type' => 'article',
            'status' => 'queued',
            'payload' => [
                'flowId' => 'geoflow-douyin-import-1',
                'content' => [
                    'title' => '抖音图文发布',
                    'body' => '正文',
                    'cover' => [
                        'url' => 'https://cdn.example.com/article-cover.jpg',
                    ],
                ],
                'publishAt' => '2026-08-25T12:00:00+08:00',
            ],
        ]);
        SelfMediaPublishJobItem::query()->create([
            'job_id' => (int) $job->id,
            'self_media_account_id' => (int) $account->id,
            'provider' => 'aitoearn',
            'platform' => 'douyin',
            'external_account_id' => 'account-douyin-import',
            'status' => 'queued',
            'payload' => [
                'platform' => 'douyin',
                'accountId' => 'account-douyin-import',
            ],
        ]);

        Http::fake([
            'https://cdn.example.com/article-cover.jpg' => Http::response('image-bytes', 200, [
                'Content-Type' => 'image/jpeg',
            ]),
            'https://aitoearn.test/api/assets/uploadSign' => Http::response([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'id' => 'asset-image-001',
                    'uploadUrl' => 'https://upload.aitoearn.test/asset-image-001',
                    'url' => 'https://assets.aitoearn.cn/pending/article-cover.jpg',
                ],
            ]),
            'https://upload.aitoearn.test/asset-image-001' => Http::response('', 200),
            'https://aitoearn.test/api/assets/asset-image-001/confirm' => Http::response([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'id' => 'asset-image-001',
                    'url' => 'https://assets.aitoearn.cn/confirmed/article-cover.jpg',
                    'type' => 'publishMedia',
                    'mimeType' => 'image/jpeg',
                ],
            ]),
            'https://aitoearn.test/api/v2/channels/publish/flows' => Http::response([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'flowId' => 'flow_remote_import_001',
                    'tasks' => [
                        [
                            'id' => 'task_remote_import_001',
                            'accountId' => 'account-douyin-import',
                            'platform' => 'douyin',
                            'status' => 0,
                        ],
                    ],
                ],
            ]),
        ]);

        app()->call([app(SubmitAiToEarnPublishFlowJob::class, ['jobId' => (int) $job->id]), 'handle']);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://aitoearn.test/api/assets/uploadSign'
            && $request['type'] === 'publishMedia');
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://upload.aitoearn.test/asset-image-001'
            && $request->hasHeader('Content-Type', 'image/jpeg'));
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://aitoearn.test/api/assets/asset-image-001/confirm');
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://aitoearn.test/api/v2/channels/publish/flows'
            && data_get($request->data(), 'content.media.0.url') === 'https://assets.aitoearn.cn/confirmed/article-cover.jpg'
            && data_get($request->data(), 'content.cover.url') === 'https://assets.aitoearn.cn/confirmed/article-cover.jpg'
            && data_get($request->data(), 'content.media.0.metadata') === null
            && data_get($request->data(), 'content.cover.metadata') === null);
        $this->assertDatabaseHas('self_media_publish_jobs', [
            'id' => (int) $job->id,
            'status' => 'submitted',
            'external_flow_id' => 'flow_remote_import_001',
        ]);
    }

    public function test_submit_job_sends_bilibili_video_public_urls_without_unsupported_media_options(): void
    {
        Queue::fake([SyncAiToEarnPublishStatusJob::class]);

        [$admin, $site] = $this->provisionSubscribedAdmin('aitoearn_bilibili_video_sanitized_owner', 3);
        $account = $this->selfMediaAccount($site, $admin, 'bilibili', 'account-bilibili-video-sanitized', 'Bilibili Account');
        $job = SelfMediaPublishJob::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'provider' => 'aitoearn',
            'content_type' => 'video',
            'title' => 'Bilibili 视频发布',
            'content_source_type' => 'video_generation',
            'status' => 'queued',
            'payload' => [
                'flowId' => 'geoflow-bilibili-video-sanitized-1',
                'content' => [
                    'title' => 'Bilibili 视频发布',
                    'body' => '正文',
                    'media' => [
                        [
                            'url' => 'https://video.example.test/tasks/video-task/final-1.mp4',
                            'options' => ['unused' => true],
                        ],
                    ],
                    'cover' => [
                        'url' => 'https://cdn.example.test/video-cover.jpg',
                        'options' => [
                            'adaptation' => ['imageFormat' => 'auto'],
                        ],
                    ],
                ],
                'publishAt' => '2026-08-25T12:00:00+08:00',
            ],
        ]);
        SelfMediaPublishJobItem::query()->create([
            'job_id' => (int) $job->id,
            'self_media_account_id' => (int) $account->id,
            'provider' => 'aitoearn',
            'platform' => 'bilibili',
            'external_account_id' => 'account-bilibili-video-sanitized',
            'status' => 'queued',
            'payload' => [
                'platform' => 'bilibili',
                'accountId' => 'account-bilibili-video-sanitized',
                'option' => [
                    'tid' => 21,
                    'copyright' => 1,
                ],
            ],
        ]);

        Http::fake([
            'https://aitoearn.test/api/v2/channels/accounts/account-bilibili-video-sanitized/publish-options/tid/values' => Http::response([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'items' => [
                        [
                            'value' => '160',
                            'label' => 'Life',
                            'disabled' => true,
                            'children' => [
                                [
                                    'value' => '21',
                                    'label' => 'Daily',
                                    'disabled' => false,
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
            'https://aitoearn.test/api/v2/channels/publish/flows' => Http::response([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'flowId' => 'flow_remote_video_sanitized_001',
                    'tasks' => [
                        [
                            'id' => 'task_remote_video_sanitized_001',
                            'accountId' => 'account-bilibili-video-sanitized',
                            'platform' => 'bilibili',
                            'status' => 0,
                        ],
                    ],
                ],
            ]),
        ]);

        app()->call([app(SubmitAiToEarnPublishFlowJob::class, ['jobId' => (int) $job->id]), 'handle']);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://aitoearn.test/api/v2/channels/publish/flows'
            && data_get($request->data(), 'content.media.0.url') === 'https://video.example.test/tasks/video-task/final-1.mp4'
            && data_get($request->data(), 'content.media.0.options') === null
            && data_get($request->data(), 'content.cover') === null
            && data_get($request->data(), 'items.0.option') === ['tid' => 21]);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/assets/uploadSign'));
        $this->assertDatabaseHas('self_media_publish_jobs', [
            'id' => (int) $job->id,
            'status' => 'submitted',
            'external_flow_id' => 'flow_remote_video_sanitized_001',
        ]);
    }

    public function test_submit_job_retries_bilibili_video_with_imported_assets_when_public_media_is_unreachable(): void
    {
        Queue::fake([SyncAiToEarnPublishStatusJob::class]);

        [$admin, $site] = $this->provisionSubscribedAdmin('aitoearn_bilibili_video_retry_owner', 3);
        $account = $this->selfMediaAccount($site, $admin, 'bilibili', 'account-bilibili-video-retry', 'Bilibili Account');
        $job = SelfMediaPublishJob::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'provider' => 'aitoearn',
            'content_type' => 'video',
            'title' => 'Bilibili video retry',
            'content_source_type' => 'video_generation',
            'status' => 'queued',
            'payload' => [
                'flowId' => 'geoflow-bilibili-video-retry-1',
                'content' => [
                    'title' => 'Bilibili video retry',
                    'body' => 'Body',
                    'media' => [
                        ['url' => 'https://video.example.test/tasks/video-task/final-1.mp4'],
                    ],
                    'cover' => [
                        'url' => 'https://cdn.example.test/video-cover.jpg',
                    ],
                ],
                'publishAt' => '2026-08-25T12:00:00+08:00',
            ],
        ]);
        SelfMediaPublishJobItem::query()->create([
            'job_id' => (int) $job->id,
            'self_media_account_id' => (int) $account->id,
            'provider' => 'aitoearn',
            'platform' => 'bilibili',
            'external_account_id' => 'account-bilibili-video-retry',
            'status' => 'queued',
            'payload' => [
                'platform' => 'bilibili',
                'accountId' => 'account-bilibili-video-retry',
                'option' => [
                    'tid' => 21,
                    'copyright' => 1,
                ],
            ],
        ]);

        Http::fake([
            'https://aitoearn.test/api/v2/channels/accounts/account-bilibili-video-retry/publish-options/tid/values' => Http::response([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'items' => [
                        [
                            'value' => '21',
                            'label' => 'Daily',
                            'disabled' => false,
                        ],
                    ],
                ],
            ]),
            'https://aitoearn.test/api/v2/channels/publish/flows' => Http::sequence()
                ->push([
                    'code' => 15051,
                    'message' => 'Publish content validation failed',
                    'requestId' => 'request-media-unreachable',
                ])
                ->push([
                    'code' => 0,
                    'message' => 'ok',
                    'data' => [
                        'flowId' => 'flow_remote_video_retry_001',
                        'tasks' => [
                            [
                                'id' => 'task_remote_video_retry_001',
                                'accountId' => 'account-bilibili-video-retry',
                                'platform' => 'bilibili',
                                'status' => 0,
                            ],
                        ],
                    ],
                ]),
            'https://video.example.test/tasks/video-task/final-1.mp4' => Http::response('video-bytes', 200, [
                'Content-Type' => 'video/mp4',
            ]),
            'https://cdn.example.test/video-cover.jpg' => Http::response('image-bytes', 200, [
                'Content-Type' => 'image/jpeg',
            ]),
            'https://aitoearn.test/api/assets/uploadSign' => function ($request) {
                $filename = (string) ($request['filename'] ?? '');
                $id = str_ends_with($filename, '.mp4') ? 'asset-video-001' : 'asset-cover-001';

                return Http::response([
                    'code' => 0,
                    'message' => 'ok',
                    'data' => [
                        'id' => $id,
                        'uploadUrl' => 'https://upload.aitoearn.test/'.$id,
                        'url' => 'https://assets.aitoearn.cn/pending/'.$filename,
                    ],
                ]);
            },
            'https://upload.aitoearn.test/asset-video-001' => Http::response('', 200),
            'https://upload.aitoearn.test/asset-cover-001' => Http::response('', 200),
            'https://aitoearn.test/api/assets/asset-video-001/confirm' => Http::response([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'id' => 'asset-video-001',
                    'url' => 'https://assets.aitoearn.cn/confirmed/final-1.mp4',
                    'type' => 'publishMedia',
                    'mimeType' => 'video/mp4',
                ],
            ]),
            'https://aitoearn.test/api/assets/asset-cover-001/confirm' => Http::response([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'id' => 'asset-cover-001',
                    'url' => 'https://assets.aitoearn.cn/confirmed/video-cover.jpg',
                    'type' => 'publishMedia',
                    'mimeType' => 'image/jpeg',
                ],
            ]),
        ]);

        app()->call([app(SubmitAiToEarnPublishFlowJob::class, ['jobId' => (int) $job->id]), 'handle']);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://aitoearn.test/api/assets/uploadSign'
            && str_ends_with((string) $request['filename'], '.mp4'));
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://aitoearn.test/api/assets/uploadSign'
            && ! str_ends_with((string) $request['filename'], '.mp4'));
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://aitoearn.test/api/v2/channels/publish/flows'
            && data_get($request->data(), 'content.media.0.url') === 'https://assets.aitoearn.cn/confirmed/final-1.mp4'
            && data_get($request->data(), 'content.cover') === null
            && data_get($request->data(), 'items.0.option') === ['tid' => 21]);
        $this->assertDatabaseHas('self_media_publish_jobs', [
            'id' => (int) $job->id,
            'status' => 'submitted',
            'external_flow_id' => 'flow_remote_video_retry_001',
        ]);
    }

    public function test_submit_job_refreshes_stale_publish_at_before_creating_flow(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-25 12:00:00', 'Asia/Shanghai'));
        Queue::fake([SyncAiToEarnPublishStatusJob::class]);

        [$admin, $site] = $this->provisionSubscribedAdmin('aitoearn_stale_publish_at_owner', 3);
        $account = $this->selfMediaAccount($site, $admin, 'wxGzh', 'account-wxgzh-stale-time', '公众号');
        $job = SelfMediaPublishJob::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'provider' => 'aitoearn',
            'content_type' => 'article',
            'title' => '发布时间兜底',
            'content_source_type' => 'article',
            'status' => 'queued',
            'payload' => [
                'flowId' => 'geoflow-stale-time-1',
                'content' => [
                    'title' => '发布时间兜底',
                    'body' => '<p>正文</p>',
                ],
                'publishAt' => '2026-08-25T11:59:59+08:00',
            ],
        ]);
        SelfMediaPublishJobItem::query()->create([
            'job_id' => (int) $job->id,
            'self_media_account_id' => (int) $account->id,
            'provider' => 'aitoearn',
            'platform' => 'wxGzh',
            'external_account_id' => 'account-wxgzh-stale-time',
            'status' => 'queued',
            'payload' => [
                'platform' => 'wxGzh',
                'accountId' => 'account-wxgzh-stale-time',
            ],
        ]);

        Http::fake([
            'https://aitoearn.test/api/v2/channels/publish/flows' => Http::response([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'flowId' => 'flow_remote_stale_time_001',
                    'tasks' => [
                        [
                            'id' => 'task_remote_stale_time_001',
                            'accountId' => 'account-wxgzh-stale-time',
                            'platform' => 'wxGzh',
                            'status' => 0,
                        ],
                    ],
                ],
            ]),
        ]);

        app()->call([app(SubmitAiToEarnPublishFlowJob::class, ['jobId' => (int) $job->id]), 'handle']);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://aitoearn.test/api/v2/channels/publish/flows'
            && Carbon::parse((string) $request['publishAt'])->greaterThan(now()));

        Carbon::setTestNow();
    }

    public function test_submit_job_replaces_disabled_bilibili_video_parent_tid_with_enabled_child_tid(): void
    {
        Queue::fake([SyncAiToEarnPublishStatusJob::class]);

        [$admin, $site] = $this->provisionSubscribedAdmin('aitoearn_bilibili_submit_owner', 3);
        $account = $this->selfMediaAccount($site, $admin, 'bilibili', 'account-bilibili-001', 'Bilibili Account');
        $job = SelfMediaPublishJob::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'provider' => 'aitoearn',
            'content_type' => 'video',
            'title' => 'Bilibili video',
            'content_source_type' => 'video_generation',
            'status' => 'queued',
            'payload' => [
                'flowId' => 'geoflow-bilibili-video-1',
                'content' => [
                    'title' => 'Bilibili video',
                    'body' => '<p>Body</p>',
                    'cover' => ['url' => 'https://cdn.example.com/cover.jpg'],
                    'media' => [
                        ['url' => 'https://cdn.example.com/video.mp4'],
                    ],
                ],
                'publishAt' => '2026-08-25T12:00:00+08:00',
            ],
        ]);
        SelfMediaPublishJobItem::query()->create([
            'job_id' => (int) $job->id,
            'self_media_account_id' => (int) $account->id,
            'provider' => 'aitoearn',
            'platform' => 'bilibili',
            'external_account_id' => 'account-bilibili-001',
            'status' => 'queued',
            'payload' => [
                'platform' => 'bilibili',
                'accountId' => 'account-bilibili-001',
                'option' => [
                    'tid' => 160,
                    'copyright' => 1,
                ],
            ],
        ]);

        Http::fake([
            'https://aitoearn.test/api/v2/channels/accounts/account-bilibili-001/publish-options/tid/values' => Http::response([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'items' => [
                        [
                            'value' => '160',
                            'label' => 'Life',
                            'disabled' => true,
                            'children' => [
                                [
                                    'value' => '21',
                                    'label' => 'Daily',
                                    'disabled' => false,
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
            'https://aitoearn.test/api/v2/channels/publish/flows' => Http::response([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'flowId' => 'flow_remote_bilibili_001',
                    'tasks' => [
                        [
                            'id' => 'task_remote_bilibili_001',
                            'accountId' => 'account-bilibili-001',
                            'platform' => 'bilibili',
                            'status' => 0,
                        ],
                    ],
                ],
            ]),
        ]);

        app()->call([app(SubmitAiToEarnPublishFlowJob::class, ['jobId' => (int) $job->id]), 'handle']);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://aitoearn.test/api/v2/channels/publish/flows'
            && $request['items'][0]['platform'] === 'bilibili'
            && data_get($request->data(), 'content.cover') === null
            && data_get($request->data(), 'items.0.option') === ['tid' => 21]);
        $this->assertDatabaseHas('self_media_publish_jobs', [
            'id' => (int) $job->id,
            'status' => 'submitted',
            'external_flow_id' => 'flow_remote_bilibili_001',
        ]);
    }

    public function test_sync_job_marks_publish_item_success_when_work_link_is_ready(): void
    {
        [$admin, $site] = $this->provisionSubscribedAdmin('aitoearn_sync_owner', 3);
        $account = $this->selfMediaAccount($site, $admin, 'douyin', 'account-douyin-003', '抖音号');
        $job = SelfMediaPublishJob::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'provider' => 'aitoearn',
            'content_type' => 'article',
            'title' => '同步 flow 文章',
            'content_source_type' => 'article',
            'status' => 'submitted',
            'external_flow_id' => 'flow_remote_002',
            'payload' => [],
        ]);
        SelfMediaPublishJobItem::query()->create([
            'job_id' => (int) $job->id,
            'self_media_account_id' => (int) $account->id,
            'provider' => 'aitoearn',
            'platform' => 'douyin',
            'external_account_id' => 'account-douyin-003',
            'external_task_id' => 'task_remote_002',
            'status' => 'submitted',
            'payload' => [],
        ]);

        Http::fake([
            'https://aitoearn.test/api/v2/channels/publish/flows/flow_remote_002' => Http::response([
                'code' => 0,
                'message' => '请求成功',
                'data' => [
                    'flowId' => 'flow_remote_002',
                    'tasks' => [
                        [
                            'id' => 'task_remote_002',
                            'accountId' => 'account-douyin-003',
                            'platform' => 'douyin',
                            'status' => 1,
                            'workLink' => 'https://www.douyin.com/video/123',
                            'linkStatus' => 'ready',
                        ],
                    ],
                ],
            ]),
        ]);

        app()->call([app(SyncAiToEarnPublishStatusJob::class, ['jobId' => (int) $job->id]), 'handle']);

        $this->assertDatabaseHas('self_media_publish_jobs', [
            'id' => (int) $job->id,
            'status' => 'success',
        ]);
        $this->assertDatabaseHas('self_media_publish_job_items', [
            'job_id' => (int) $job->id,
            'external_task_id' => 'task_remote_002',
            'status' => 'success',
            'published_url' => 'https://www.douyin.com/video/123',
        ]);
    }

    public function test_sync_job_marks_douyin_status_eight_as_awaiting_confirmation_and_stores_user_action(): void
    {
        Queue::fake([SyncAiToEarnPublishStatusJob::class]);

        [$admin, $site] = $this->provisionSubscribedAdmin('aitoearn_douyin_confirm_owner', 3);
        $account = $this->selfMediaAccount($site, $admin, 'douyin', 'account-douyin-confirm', '抖音号');
        $job = SelfMediaPublishJob::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'provider' => 'aitoearn',
            'content_type' => 'article',
            'title' => '抖音待确认文章',
            'content_source_type' => 'article',
            'status' => 'submitted',
            'external_flow_id' => 'flow_remote_confirm_001',
            'sync_attempts' => 39,
            'payload' => [],
        ]);
        $item = SelfMediaPublishJobItem::query()->create([
            'job_id' => (int) $job->id,
            'self_media_account_id' => (int) $account->id,
            'provider' => 'aitoearn',
            'platform' => 'douyin',
            'external_account_id' => 'account-douyin-confirm',
            'external_task_id' => 'record_douyin_confirm_001',
            'status' => 'submitted',
            'payload' => [],
        ]);

        Http::fake([
            'https://aitoearn.test/api/v2/channels/publish/flows/flow_remote_confirm_001' => Http::response([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'flowId' => 'flow_remote_confirm_001',
                    'tasks' => [
                        [
                            'id' => 'record_douyin_confirm_001',
                            'accountId' => 'account-douyin-confirm',
                            'platform' => 'douyin',
                            'status' => 8,
                            'platformWorkId' => 'share_001',
                        ],
                    ],
                ],
            ]),
            'https://aitoearn.test/api/v2/channels/publish/records/record_douyin_confirm_001/user-action' => Http::response([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'recordId' => 'record_douyin_confirm_001',
                    'platform' => 'douyin',
                    'shareId' => 'share_001',
                    'shortLink' => 'https://aitoearn.test/api/shortLink/confirm001',
                    'schemeUrl' => 'snssdk1128://openplatform/share',
                    'expiresAt' => '2026-08-26T02:09:41.217Z',
                ],
            ]),
        ]);

        app()->call([app(SyncAiToEarnPublishStatusJob::class, ['jobId' => (int) $job->id]), 'handle']);

        $job->refresh();
        $item->refresh();

        $this->assertSame('publishing', (string) $job->status);
        $this->assertSame(39, (int) $job->sync_attempts);
        $this->assertSame('awaiting_confirmation', (string) $item->status);
        $this->assertSame('待抖音确认', (string) $item->message);
        $this->assertSame('https://aitoearn.test/api/shortLink/confirm001', (string) data_get($item->raw_response, 'user_action.shortLink'));

        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://aitoearn.test/api/v2/channels/publish/records/record_douyin_confirm_001/user-action');
        Queue::assertPushed(SyncAiToEarnPublishStatusJob::class);
    }

    public function test_publish_record_page_shows_douyin_confirmation_link_when_awaiting_confirmation(): void
    {
        [$admin, $site] = $this->provisionSubscribedAdmin('aitoearn_douyin_confirm_page_owner', 3);
        $account = $this->selfMediaAccount($site, $admin, 'douyin', 'account-douyin-confirm-page', '抖音号');
        $job = SelfMediaPublishJob::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'provider' => 'aitoearn',
            'content_type' => 'article',
            'title' => '抖音待确认文章',
            'content_source_type' => 'article',
            'status' => 'publishing',
            'external_flow_id' => 'flow_remote_confirm_page_001',
            'submitted_at' => now(),
            'payload' => [],
        ]);
        SelfMediaPublishJobItem::query()->create([
            'job_id' => (int) $job->id,
            'self_media_account_id' => (int) $account->id,
            'provider' => 'aitoearn',
            'platform' => 'douyin',
            'external_account_id' => 'account-douyin-confirm-page',
            'external_task_id' => 'record_douyin_confirm_page_001',
            'status' => 'awaiting_confirmation',
            'message' => '待抖音确认',
            'payload' => [],
            'raw_response' => [
                'id' => 'record_douyin_confirm_page_001',
                'platform' => 'douyin',
                'status' => 8,
                'user_action' => [
                    'shortLink' => 'https://aitoearn.test/api/shortLink/confirm001',
                    'schemeUrl' => 'snssdk1128://openplatform/share',
                    'expiresAt' => '2026-08-26T02:09:41.217Z',
                ],
            ],
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.crebee-publish-records.index'))
            ->assertOk()
            ->assertSee('抖音待确认文章')
            ->assertSee('待抖音确认')
            ->assertSee('打开确认链接')
            ->assertSee('复制链接')
            ->assertSee('https://aitoearn.test/api/shortLink/confirm001', false)
            ->assertDontSee('暂无链接');
    }

    /**
     * @return array{0: Admin, 1: Site}
     */
    private function provisionSubscribedAdmin(string $username, int $selfMediaQuota): array
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => $username,
            'role' => 'direct_admin',
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => $username.' Site',
            'status' => 'active',
            'customer_mode' => 'direct',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'owner']);

        $plan = PlatformPlan::query()->create([
            'name' => $username.' Plan',
            'code' => $username.'_plan',
            'audience' => 'both',
            'duration_days' => 30,
            'status' => 'active',
            'sort_order' => 0,
        ]);
        $plan->entitlements()->create([
            'resource_key' => PlatformPlan::RESOURCE_CREBEE_PUBLISHES,
            'enabled' => true,
            'quota_value' => $selfMediaQuota,
            'quota_period' => 'cycle',
            'unit' => 'times',
            'meta' => [],
        ]);

        app(AdminPlanSubscriptionService::class)->openOwner(
            admin: $admin,
            site: $site,
            plan: $plan,
            mode: 'direct_owner',
            operator: $admin,
            startsAt: now()->subMinute(),
            endsAt: now()->addDays(30),
            grantCredits: false
        );

        return [$admin, $site];
    }

    private function selfMediaAccount(Site $site, Admin $owner, string $platform, string $externalAccountId, string $name): SelfMediaAccount
    {
        return SelfMediaAccount::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'provider' => 'aitoearn',
            'platform' => $platform,
            'external_account_id' => $externalAccountId,
            'account_name' => $name,
            'avatar' => '',
            'status' => 'bound',
            'auth_status' => 'authorized',
            'bound_at' => now(),
            'last_synced_at' => now(),
            'raw_account' => [],
        ]);
    }

    private function article(Site $site, Admin $owner, string $title, string $content = '正文'): Article
    {
        $category = Category::query()->create([
            'site_id' => (int) $site->id,
            'name' => $title.' 分类',
            'slug' => str()->slug($title).'-category',
        ]);
        $author = Author::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'GEOFlow',
        ]);

        return Article::withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'title' => $title,
            'slug' => str()->slug($title).'-'.str()->random(6),
            'excerpt' => '',
            'cover_image' => '',
            'content' => $content,
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
    }

    private function completedVideo(Site $site, Admin $owner, string $title): VideoGenerationJob
    {
        return VideoGenerationJob::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'created_by_admin_id' => (int) $owner->id,
            'title' => $title,
            'subject' => $title,
            'script' => '视频脚本',
            'terms' => 'AI, 科技',
            'negative_terms' => '',
            'video_source' => 'pexels',
            'video_aspect' => '9:16',
            'video_count' => 1,
            'cover_image' => 'https://cdn.example.test/video-cover.jpg',
            'status' => 'success',
            'progress' => 100,
            'api_task_id' => 'video-task-aitoearn',
            'request_payload' => [],
            'result_payload' => [],
            'videos' => ['https://video.example.test/tasks/video-task-aitoearn/final-1.mp4'],
            'combined_videos' => [],
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
        ]);
    }
}
