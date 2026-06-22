<?php

namespace Tests\Feature;

use App\Jobs\ProcessMediaResourceSyncJob;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\AdminCreditAccount;
use App\Models\AdminCreditLedger;
use App\Models\MediaApiSetting;
use App\Models\MediaResource;
use App\Models\MediaResourceSyncRun;
use App\Models\MediaSubmission;
use App\Models\Site;
use App\Models\SiteCreditAccount;
use App\Models\SiteCreditLedger;
use App\Services\MediaDistribution\MediaResourceSyncService;
use App\Support\MediaDistribution\MediaPlatform;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminMediaDistributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_distribution_replaces_old_distribution_navigation(): void
    {
        [$admin] = $this->createAdminWithSite('media_nav_admin', 'admin');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.media-distribution.resources.index'), false)
            ->assertSee('官媒发布')
            ->assertDontSee(route('admin.distribution.index'), false);
    }

    public function test_super_admin_can_configure_api_and_sync_media_resources(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$superAdmin] = $this->createAdminWithSite('media_root_admin', 'super_admin');

        Http::fake([
            '*/api/media/media_list' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => [
                    [
                        'resource_id' => 73880,
                        'title' => '中华网生活',
                        'remarks' => '图片涉及版权问题默认删',
                        'case_link' => 'http://life.china.com/example.html',
                        'status' => 1,
                        'price' => '27.00',
                    ],
                ],
            ]),
            '*/api/zi_media_api/media_list' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => [
                    [
                        'resource_id' => 90001,
                        'title' => '第三方账号A',
                        'remarks' => '自媒体资源',
                        'case_link' => '',
                        'status' => 1,
                        'price' => '40.00',
                    ],
                ],
            ]),
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.media-distribution.settings.update'), [
                'api_base_url' => 'http://8.138.187.158:8082',
                'api_key' => 'test-api-key',
                'status' => 'active',
            ])
            ->assertRedirect(route('admin.media-distribution.settings.index'));

        $run = MediaResourceSyncRun::query()->create([
            'status' => 'pending',
            'started_by_admin_id' => (int) $superAdmin->id,
        ]);

        (new ProcessMediaResourceSyncJob((int) $run->id))
            ->handle(app(MediaResourceSyncService::class));

        $this->assertDatabaseHas('media_resources', [
            'source_type' => 'website_media',
            'external_resource_id' => '73880',
            'title' => '中华网生活',
            'cost_price' => '27.00',
            'sale_price' => '27.00',
        ]);
        $this->assertDatabaseHas('media_resources', [
            'source_type' => 'zi_media',
            'external_resource_id' => '90001',
            'title' => '第三方账号A',
            'cost_price' => '40.00',
            'sale_price' => '40.00',
        ]);
    }

    public function test_super_admin_can_sync_chaojimeijie_media_resources(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$superAdmin] = $this->createAdminWithSite('media_cjmj_root_admin', 'super_admin');

        Http::fake([
            '*/media/resource*' => Http::response([
                'code' => 200,
                'message' => 'success',
                'data' => [
                    'total' => 1,
                    'items' => [[
                        'id' => 50001,
                        'name' => '超级新闻媒体',
                        'case_link' => 'https://example.com/case.html',
                        'remark' => '超级媒介新闻资源',
                        'status' => 2,
                        'price' => '31.00',
                        'published_rate' => '93%',
                        'pc_weight' => 4,
                        'mobile_weight' => 5,
                    ]],
                ],
            ]),
            '*/we-media/resource*' => Http::response([
                'code' => 200,
                'message' => 'success',
                'data' => [
                    'total' => 1,
                    'items' => [[
                        'id' => 60001,
                        'name' => '超级自媒体账号',
                        'platform' => '头条',
                        'status' => 2,
                        'price' => '42.00',
                        'video_price' => '80.00',
                        'trend_price' => '20.00',
                    ]],
                ],
            ]),
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.media-distribution.settings.update'), [
                'platform_id' => MediaPlatform::CEYING_MEDIA_2,
                'api_base_url' => 'https://vip.chaojimeijie.com/api',
                'app_id' => 'app-id',
                'api_secret' => 'secret',
                'status' => 'active',
            ])
            ->assertRedirect(route('admin.media-distribution.settings.index'));

        $run = MediaResourceSyncRun::query()->create([
            'platform_id' => MediaPlatform::CEYING_MEDIA_2,
            'status' => 'pending',
            'started_by_admin_id' => (int) $superAdmin->id,
        ]);

        (new ProcessMediaResourceSyncJob((int) $run->id))
            ->handle(app(MediaResourceSyncService::class));

        $this->assertDatabaseHas('media_resources', [
            'platform_id' => MediaPlatform::CEYING_MEDIA_2,
            'source_type' => 'website_media',
            'external_resource_id' => '50001',
            'title' => '超级新闻媒体',
            'status' => 'active',
            'cost_price' => '31.00',
            'sale_price' => '31.00',
        ]);
        $websiteResource = MediaResource::query()
            ->where('platform_id', MediaPlatform::CEYING_MEDIA_2)
            ->where('external_resource_id', '50001')
            ->firstOrFail();
        $this->assertSame('93%', $websiteResource->apiField('publish_rate'));
        $this->assertSame('4', $websiteResource->apiField('pc_weigh'));
        $this->assertSame('5', $websiteResource->apiField('wap_weight'));
        $this->assertDatabaseHas('media_resources', [
            'platform_id' => MediaPlatform::CEYING_MEDIA_2,
            'source_type' => 'zi_media',
            'external_resource_id' => '60001',
            'title' => '超级自媒体账号',
            'category' => '头条',
            'cost_price' => '42.00',
        ]);
    }

    public function test_media_resource_sync_marks_missing_resources_inactive(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$superAdmin] = $this->createAdminWithSite('media_missing_root_admin', 'super_admin');

        MediaResource::query()->create([
            'platform_id' => MediaPlatform::CEYING_MEDIA_2,
            'source_type' => MediaResource::SOURCE_WEBSITE,
            'external_resource_id' => 'missing-website-resource',
            'title' => 'Missing Website Resource',
            'status' => 'active',
            'cost_price' => '10.00',
            'sale_price' => '10.00',
            'last_synced_at' => now()->subDay(),
        ]);
        MediaResource::query()->create([
            'platform_id' => MediaPlatform::CEYING_MEDIA_1,
            'source_type' => MediaResource::SOURCE_WEBSITE,
            'external_resource_id' => 'other-platform-resource',
            'title' => 'Other Platform Resource',
            'status' => 'active',
            'cost_price' => '10.00',
            'sale_price' => '10.00',
            'last_synced_at' => now()->subDay(),
        ]);

        Http::fake([
            '*/media/resource*' => Http::response([
                'code' => 200,
                'message' => 'success',
                'data' => [
                    'items' => [[
                        'id' => 50001,
                        'name' => 'Current Website Resource',
                        'status' => 2,
                        'price' => '31.00',
                    ]],
                ],
            ]),
            '*/we-media/resource*' => Http::response([
                'code' => 200,
                'message' => 'success',
                'data' => ['items' => []],
            ]),
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.media-distribution.settings.update'), [
                'platform_id' => MediaPlatform::CEYING_MEDIA_2,
                'api_base_url' => 'https://vip.chaojimeijie.com/api',
                'app_id' => 'app-id',
                'api_secret' => 'secret',
                'status' => 'active',
            ])
            ->assertRedirect(route('admin.media-distribution.settings.index'));

        $run = MediaResourceSyncRun::query()->create([
            'platform_id' => MediaPlatform::CEYING_MEDIA_2,
            'status' => 'pending',
            'started_by_admin_id' => (int) $superAdmin->id,
        ]);

        (new ProcessMediaResourceSyncJob((int) $run->id))
            ->handle(app(MediaResourceSyncService::class));

        $this->assertDatabaseHas('media_resources', [
            'platform_id' => MediaPlatform::CEYING_MEDIA_2,
            'source_type' => MediaResource::SOURCE_WEBSITE,
            'external_resource_id' => 'missing-website-resource',
            'status' => 'inactive',
        ]);
        $this->assertDatabaseHas('media_resources', [
            'platform_id' => MediaPlatform::CEYING_MEDIA_1,
            'source_type' => MediaResource::SOURCE_WEBSITE,
            'external_resource_id' => 'other-platform-resource',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('media_resources', [
            'platform_id' => MediaPlatform::CEYING_MEDIA_2,
            'source_type' => MediaResource::SOURCE_WEBSITE,
            'external_resource_id' => '50001',
            'status' => 'active',
        ]);
    }

    public function test_super_admin_sync_request_creates_run_and_dispatches_job(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Queue::fake();
        [$superAdmin] = $this->createAdminWithSite('media_async_root_admin', 'super_admin');

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.media-distribution.resources.sync'))
            ->assertRedirect(route('admin.media-distribution.resources.index'));

        $run = MediaResourceSyncRun::query()->first();
        $this->assertNotNull($run);
        $this->assertSame('pending', (string) $run->status);
        $this->assertSame((int) $superAdmin->id, (int) $run->started_by_admin_id);
        Queue::assertPushed(ProcessMediaResourceSyncJob::class, 1);
    }

    public function test_media_resource_sync_error_details_are_hidden_from_resources_page(): void
    {
        [$superAdmin] = $this->createAdminWithSite('media_sync_error_root_admin', 'super_admin');

        MediaResourceSyncRun::query()->create([
            'platform_id' => MediaPlatform::CEYING_MEDIA_2,
            'status' => 'failed',
            'last_error_message' => 'cURL error 28 for https://vip.chaojimeijie.com/api/media/resource?appid=app-id&signature=secret-signature',
            'completed_at' => now(),
            'started_by_admin_id' => (int) $superAdmin->id,
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.media-distribution.resources.index'))
            ->assertOk()
            ->assertSee('同步失败，请检查接口配置或网络连通性。')
            ->assertDontSee('https://vip.chaojimeijie.com/api/media/resource', false)
            ->assertDontSee('signature=secret-signature', false)
            ->assertDontSee('appid=app-id', false)
            ->assertDontSee('cURL error 28', false);
    }

    public function test_media_resource_sync_reads_following_pages_until_exhausted(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$superAdmin] = $this->createAdminWithSite('media_paged_root_admin', 'super_admin');
        config(['media_distribution.page_size' => 100]);

        $firstPage = [];
        for ($i = 1; $i <= 100; $i++) {
            $firstPage[] = [
                'resource_id' => 80000 + $i,
                'title' => 'Paged Media '.$i,
                'status' => 1,
                'price' => '10.00',
            ];
        }

        Http::fake([
            '*/api/media/media_list' => Http::sequence()
                ->push(['code' => 1, 'msg' => 'success', 'data' => $firstPage])
                ->push(['code' => 1, 'msg' => 'success', 'data' => [
                    [
                        'resource_id' => 81001,
                        'media_name' => '红安网',
                        'field' => '新闻资讯',
                        'status' => 1,
                        'price' => '19.00',
                    ],
                ]]),
            '*/api/zi_media_api/media_list' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => [],
            ]),
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.media-distribution.settings.update'), [
                'api_base_url' => 'http://8.138.187.158:8082',
                'api_key' => 'test-api-key',
                'status' => 'active',
            ])
            ->assertRedirect(route('admin.media-distribution.settings.index'));

        $run = MediaResourceSyncRun::query()->create([
            'status' => 'pending',
            'started_by_admin_id' => (int) $superAdmin->id,
        ]);

        (new ProcessMediaResourceSyncJob((int) $run->id))
            ->handle(app(MediaResourceSyncService::class));

        $this->assertDatabaseHas('media_resources', [
            'source_type' => 'website_media',
            'external_resource_id' => '81001',
            'title' => '红安网',
            'category' => '新闻资讯',
            'cost_price' => '19.00',
        ]);
        $this->assertSame(101, MediaResource::query()->where('source_type', 'website_media')->count());
        $run = MediaResourceSyncRun::query()->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame('completed', (string) $run->status);
        $this->assertSame(101, (int) $run->website_synced);
        $this->assertSame(101, (int) $run->total_synced);
        $this->assertNotNull($run->completed_at);
    }

    public function test_ceying_media_one_sync_caps_page_size_to_remote_limit(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$superAdmin] = $this->createAdminWithSite('media_page_size_root_admin', 'super_admin');
        config(['media_distribution.page_size' => 200]);

        Http::fake([
            '*/api/media/media_list' => function ($request) {
                $payload = $this->httpRequestPayload($request);

                $this->assertSame('100', (string) ($payload['page_size'] ?? ''));
                $this->assertSame('100', (string) ($payload['limit'] ?? ''));

                return Http::response([
                    'code' => 1,
                    'msg' => 'success',
                    'data' => [],
                ]);
            },
            '*/api/zi_media_api/media_list' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => [],
            ]),
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.media-distribution.settings.update'), [
                'api_base_url' => 'http://8.138.187.158:8082',
                'api_key' => 'test-api-key',
                'status' => 'active',
            ])
            ->assertRedirect(route('admin.media-distribution.settings.index'));

        $run = MediaResourceSyncRun::query()->create([
            'status' => 'pending',
            'started_by_admin_id' => (int) $superAdmin->id,
        ]);

        (new ProcessMediaResourceSyncJob((int) $run->id))
            ->handle(app(MediaResourceSyncService::class));

        $this->assertSame('completed', (string) $run->fresh()->status);
    }

    public function test_media_resource_sync_truncates_case_link_for_storage(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$superAdmin] = $this->createAdminWithSite('media_long_case_link_root_admin', 'super_admin');
        $longCaseLink = 'https://example.com/article?'.str_repeat('token=long-value&', 40);

        Http::fake([
            '*/api/media/media_list' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => [[
                    'resource_id' => 91001,
                    'title' => 'Long Case Link Media',
                    'case_link' => $longCaseLink,
                    'status' => 1,
                    'price' => '10.00',
                ]],
            ]),
            '*/api/zi_media_api/media_list' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => [],
            ]),
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.media-distribution.settings.update'), [
                'api_base_url' => 'http://8.138.187.158:8082',
                'api_key' => 'test-api-key',
                'status' => 'active',
            ])
            ->assertRedirect(route('admin.media-distribution.settings.index'));

        $run = MediaResourceSyncRun::query()->create([
            'status' => 'pending',
            'started_by_admin_id' => (int) $superAdmin->id,
        ]);

        (new ProcessMediaResourceSyncJob((int) $run->id))
            ->handle(app(MediaResourceSyncService::class));

        $resource = MediaResource::query()
            ->where('external_resource_id', '91001')
            ->firstOrFail();

        $this->assertSame(500, mb_strlen((string) $resource->case_link));
        $this->assertSame($longCaseLink, $resource->apiField('case_link'));
        $this->assertSame('completed', (string) $run->fresh()->status);
    }

    public function test_media_resource_sync_sends_pagination_aliases_when_fetching_more_than_default_remote_page(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$superAdmin] = $this->createAdminWithSite('media_paged_alias_root_admin', 'super_admin');

        $firstPage = [];
        for ($i = 1; $i <= 200; $i++) {
            $firstPage[] = [
                'resource_id' => 82000 + $i,
                'title' => 'Alias Paged Media '.$i,
                'status' => 1,
                'price' => '10.00',
            ];
        }

        $secondPage = [];
        for ($i = 1; $i <= 50; $i++) {
            $secondPage[] = [
                'resource_id' => 83000 + $i,
                'title' => 'Alias Paged Media Continued '.$i,
                'status' => 1,
                'price' => '12.00',
            ];
        }

        Http::fake([
            '*/api/media/media_list' => function ($request) use ($firstPage, $secondPage) {
                $payload = $this->httpRequestPayload($request);
                $page = (int) ($payload['p'] ?? 1);

                return Http::response([
                    'code' => 1,
                    'msg' => 'success',
                    'data' => $page === 2 ? $secondPage : $firstPage,
                ]);
            },
            '*/api/zi_media_api/media_list' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => [],
            ]),
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.media-distribution.settings.update'), [
                'api_base_url' => 'http://8.138.187.158:8082',
                'api_key' => 'test-api-key',
                'status' => 'active',
            ])
            ->assertRedirect(route('admin.media-distribution.settings.index'));

        $run = MediaResourceSyncRun::query()->create([
            'status' => 'pending',
            'started_by_admin_id' => (int) $superAdmin->id,
        ]);

        (new ProcessMediaResourceSyncJob((int) $run->id))
            ->handle(app(MediaResourceSyncService::class));

        $this->assertDatabaseHas('media_resources', [
            'source_type' => 'website_media',
            'external_resource_id' => '83050',
            'title' => 'Alias Paged Media Continued 50',
        ]);
        $this->assertSame(250, MediaResource::query()->where('source_type', 'website_media')->count());
        $this->assertSame(250, (int) $run->fresh()->total_synced);
    }

    public function test_ceying_media_one_sync_resumes_running_run_from_recorded_page(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$superAdmin] = $this->createAdminWithSite('media_resume_root_admin', 'super_admin');
        config(['media_distribution.page_size' => 100]);

        $requestedZiMediaPages = [];

        Http::fake([
            '*/api/media/media_list' => function (): void {
                $this->fail('Completed website media pages should not be requested again when resuming a running sync.');
            },
            '*/api/zi_media_api/media_list' => function ($request) use (&$requestedZiMediaPages) {
                $payload = $this->httpRequestPayload($request);
                $page = (int) ($payload['p'] ?? 1);
                $requestedZiMediaPages[] = $page;

                $this->assertSame(122, $page);

                return Http::response([
                    'code' => 1,
                    'msg' => 'success',
                    'data' => [[
                        'resource_id' => 990122,
                        'title' => 'Resumed Zi Media',
                        'status' => 1,
                        'price' => '16.00',
                    ]],
                ]);
            },
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.media-distribution.settings.update'), [
                'api_base_url' => 'http://8.138.187.158:8082',
                'api_key' => 'test-api-key',
                'status' => 'active',
            ])
            ->assertRedirect(route('admin.media-distribution.settings.index'));

        $run = MediaResourceSyncRun::query()->create([
            'status' => 'running',
            'current_source_type' => MediaResource::SOURCE_ZI_MEDIA,
            'current_page' => 121,
            'website_synced' => 100,
            'zi_media_synced' => 12100,
            'total_synced' => 12200,
            'started_by_admin_id' => (int) $superAdmin->id,
            'started_at' => now()->subMinute(),
        ]);

        (new ProcessMediaResourceSyncJob((int) $run->id))
            ->handle(app(MediaResourceSyncService::class));

        $this->assertSame([122], $requestedZiMediaPages);
        $this->assertDatabaseHas('media_resources', [
            'source_type' => MediaResource::SOURCE_ZI_MEDIA,
            'external_resource_id' => '990122',
            'title' => 'Resumed Zi Media',
        ]);

        $run->refresh();
        $this->assertSame('completed', (string) $run->status);
        $this->assertSame(100, (int) $run->website_synced);
        $this->assertSame(12101, (int) $run->zi_media_synced);
        $this->assertSame(12201, (int) $run->total_synced);
    }

    public function test_ceying_media_one_sync_waits_between_full_pages_when_configured(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$superAdmin] = $this->createAdminWithSite('media_page_delay_root_admin', 'super_admin');
        config([
            'media_distribution.page_size' => 2,
            'media_distribution.page_delay_ms' => 800,
        ]);

        Http::fake([
            '*/api/media/media_list' => function ($request) {
                $payload = $this->httpRequestPayload($request);
                $page = (int) ($payload['p'] ?? 1);

                return Http::response([
                    'code' => 1,
                    'msg' => 'success',
                    'data' => $page === 1 ? [
                        [
                            'resource_id' => 88001,
                            'title' => 'Delayed Media 1',
                            'status' => 1,
                            'price' => '10.00',
                        ],
                        [
                            'resource_id' => 88002,
                            'title' => 'Delayed Media 2',
                            'status' => 1,
                            'price' => '10.00',
                        ],
                    ] : [],
                ]);
            },
            '*/api/zi_media_api/media_list' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => [],
            ]),
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.media-distribution.settings.update'), [
                'api_base_url' => 'http://8.138.187.158:8082',
                'api_key' => 'test-api-key',
                'status' => 'active',
            ])
            ->assertRedirect(route('admin.media-distribution.settings.index'));

        $run = MediaResourceSyncRun::query()->create([
            'status' => 'pending',
            'started_by_admin_id' => (int) $superAdmin->id,
        ]);

        $startedAt = hrtime(true);

        (new ProcessMediaResourceSyncJob((int) $run->id))
            ->handle(app(MediaResourceSyncService::class));

        $elapsedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

        $this->assertGreaterThanOrEqual(700, $elapsedMilliseconds);
        $this->assertSame('completed', (string) $run->fresh()->status);
    }

    public function test_super_admin_can_recharge_site_credits_and_update_media_sale_price(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$superAdmin] = $this->createAdminWithSite('media_credit_root', 'super_admin');
        [, $site] = $this->createAdminWithSite('media_credit_owner', 'admin');
        $resource = MediaResource::query()->create([
            'source_type' => 'website_media',
            'external_resource_id' => '73880',
            'title' => '中华网生活',
            'status' => 'active',
            'cost_price' => '27.00',
            'sale_price' => '27.00',
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.media-distribution.credits.recharge', ['site' => $site->id]), [
                'amount' => '200',
                'remark' => '首次充值',
            ])
            ->assertRedirect(route('admin.media-distribution.credits.index'));

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.media-distribution.credits.adjust', ['site' => $site->id]), [
                'amount' => '-25',
                'remark' => 'manual debit',
            ])
            ->assertRedirect(route('admin.media-distribution.credits.index'));

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.media-distribution.resources.price', ['resource' => $resource->id]), [
                'sale_price' => '88',
            ])
            ->assertRedirect(route('admin.media-distribution.resources.index'));

        $this->assertDatabaseHas('site_credit_accounts', [
            'site_id' => $site->id,
            'balance' => '175.00',
        ]);
        $this->assertDatabaseHas('site_credit_ledger', [
            'site_id' => $site->id,
            'type' => 'adjust',
            'amount' => '-25.00',
            'balance_after' => '175.00',
        ]);
        $this->assertSame('88.00', $resource->fresh()->sale_price);
    }

    public function test_super_admin_can_apply_media_price_multiplier_to_all_resources(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$superAdmin] = $this->createAdminWithSite('media_multiplier_root', 'super_admin');
        $first = MediaResource::query()->create([
            'source_type' => 'website_media',
            'external_resource_id' => 'multiplier-1',
            'title' => 'Multiplier One',
            'status' => 'active',
            'cost_price' => '10.00',
            'sale_price' => '10.00',
        ]);
        $second = MediaResource::query()->create([
            'source_type' => 'zi_media',
            'external_resource_id' => 'multiplier-2',
            'title' => 'Multiplier Two',
            'status' => 'active',
            'cost_price' => '20.00',
            'sale_price' => '20.00',
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.media-distribution.resources.price-multiplier'), [
                'price_multiplier' => '1.5',
            ])
            ->assertRedirect(route('admin.media-distribution.resources.index'));

        $this->assertSame('15.00', $first->fresh()->sale_price);
        $this->assertSame('30.00', $second->fresh()->sale_price);
        $this->assertSame('1.50', MediaApiSetting::query()->firstOrFail()->price_multiplier);
    }

    public function test_standard_admin_can_submit_article_and_sync_order_status_with_site_credits(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$admin, $site] = $this->createAdminWithSite('media_submit_admin', 'admin');
        $category = Category::query()->create([
            'site_id' => $site->id,
            'name' => '默认分类',
            'slug' => 'default',
            'status' => 'active',
        ]);
        $author = Author::query()->create([
            'site_id' => $site->id,
            'name' => '默认作者',
            'slug' => 'default-author',
            'status' => 'active',
        ]);
        $article = Article::query()->create([
            'site_id' => $site->id,
            'title' => '品牌出海内容',
            'slug' => 'brand-global-content',
            'content' => '<p>这是一篇可投稿文章。</p>',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
        ]);
        $resource = MediaResource::query()->create([
            'source_type' => 'website_media',
            'external_resource_id' => '73880',
            'title' => '中华网生活',
            'status' => 'active',
            'cost_price' => '27.00',
            'sale_price' => '88.00',
        ]);
        $this->grantAdminCredits($admin, $site, '100.00');

        Http::fake([
            '*/api/media/send' => Http::response([
                'code' => 1,
                'msg' => '投稿成功',
                'data' => ['order_nid' => 123456],
            ]),
            '*/api/media/order_info' => function ($request) {
                $contentType = (string) ($request->header('Content-Type')[0] ?? '');
                $payload = $this->httpRequestPayload($request);

                $this->assertStringContainsString('multipart/form-data', $contentType);
                $this->assertSame('123456', (string) ($payload['order_nids[]'] ?? ''));

                return Http::response([
                    'code' => 1,
                    'msg' => 'success',
                    'data' => [[
                        'order_nid' => '123456',
                        'status' => 'published',
                        'url' => 'https://example.com/published.html',
                    ]],
                ]);
            },
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.store'), [
                'article_id' => $article->id,
                'media_resource_id' => $resource->id,
                'remark' => '请尽快发布',
            ])
            ->assertRedirect(route('admin.media-distribution.submissions.index'));

        $submission = MediaSubmission::query()->where('article_id', $article->id)->firstOrFail();

        $this->assertSame('submitted', $submission->status);
        $this->assertSame('123456', $submission->external_order_nid);
        $this->assertSame('12.00', AdminCreditAccount::query()
            ->where('admin_id', (int) $admin->id)
            ->where('site_id', (int) $site->id)
            ->value('balance'));

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.sync', ['submission' => $submission->id]))
            ->assertRedirect(route('admin.media-distribution.submissions.show', ['submission' => $submission->id]));

        $submission->refresh();
        $this->assertSame('published', $submission->status);
        $this->assertSame('https://example.com/published.html', $submission->published_url);
    }

    public function test_media_submission_deducts_account_credits_independently_per_site_user(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $agent = Admin::query()->create([
            'username' => 'media_credit_agent',
            'password' => 'secret-123',
            'email' => 'media-credit-agent@example.com',
            'display_name' => 'Media Credit Agent',
            'role' => 'agent_admin',
            'status' => 'active',
        ]);
        $userOne = Admin::query()->create([
            'username' => 'media_credit_user_one',
            'password' => 'secret-123',
            'email' => 'media-credit-user-one@example.com',
            'display_name' => 'Media Credit User One',
            'role' => 'site_user',
            'status' => 'active',
            'created_by' => (int) $agent->id,
        ]);
        $userTwo = Admin::query()->create([
            'username' => 'media_credit_user_two',
            'password' => 'secret-123',
            'email' => 'media-credit-user-two@example.com',
            'display_name' => 'Media Credit User Two',
            'role' => 'site_user',
            'status' => 'active',
            'created_by' => (int) $agent->id,
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => (int) $agent->id,
            'name' => 'Media Credit Agent Site',
            'status' => 'active',
            'customer_mode' => 'agent',
            'agent_admin_id' => (int) $agent->id,
        ]);
        $site->members()->attach((int) $agent->id, ['role' => 'owner']);
        $site->members()->attach((int) $userOne->id, ['role' => 'member']);
        $site->members()->attach((int) $userTwo->id, ['role' => 'member']);
        $this->openTestingPlanForSite($site, $agent, [], 'agent');
        app(\App\Services\Billing\AdminPlanSubscriptionService::class)->inheritForAgentUser($agent, $userOne, $site, $agent);
        app(\App\Services\Billing\AdminPlanSubscriptionService::class)->inheritForAgentUser($agent, $userTwo, $site, $agent);
        AdminCreditAccount::query()->updateOrCreate([
            'admin_id' => (int) $userOne->id,
            'site_id' => (int) $site->id,
        ], [
            'balance' => '100.00',
            'frozen_balance' => '0.00',
            'total_granted' => '100.00',
            'total_consumed' => '0.00',
        ]);
        AdminCreditAccount::query()->updateOrCreate([
            'admin_id' => (int) $userTwo->id,
            'site_id' => (int) $site->id,
        ], [
            'balance' => '100.00',
            'frozen_balance' => '0.00',
            'total_granted' => '100.00',
            'total_consumed' => '0.00',
        ]);
        SiteCreditAccount::query()->create([
            'site_id' => (int) $site->id,
            'balance' => '999.00',
            'frozen_balance' => '0.00',
            'total_recharged' => '999.00',
            'total_consumed' => '0.00',
        ]);

        [$articleOne, $resource] = $this->createArticleAndResource($site, 'account-credit-one');
        [$articleTwo] = $this->createArticleAndResource($site, 'account-credit-two');
        $articleOne->forceFill(['owner_admin_id' => (int) $userOne->id])->save();
        $articleTwo->forceFill(['owner_admin_id' => (int) $userTwo->id])->save();

        Http::fake([
            '*/api/media/send' => Http::sequence()
                ->push(['code' => 1, 'msg' => 'success', 'data' => ['order_nid' => 'account-credit-order-one']], 200)
                ->push(['code' => 1, 'msg' => 'success', 'data' => ['order_nid' => 'account-credit-order-two']], 200),
        ]);

        $this->actingAs($userOne, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.media-distribution.submissions.store'), [
                'article_id' => (int) $articleOne->id,
                'media_resource_id' => (int) $resource->id,
            ])
            ->assertRedirect(route('admin.media-distribution.submissions.index'));

        $this->actingAs($userTwo, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.media-distribution.submissions.store'), [
                'article_id' => (int) $articleTwo->id,
                'media_resource_id' => (int) $resource->id,
            ])
            ->assertRedirect(route('admin.media-distribution.submissions.index'));

        $this->assertSame('12.00', AdminCreditAccount::query()
            ->where('admin_id', (int) $userOne->id)
            ->where('site_id', (int) $site->id)
            ->value('balance'));
        $this->assertSame('12.00', AdminCreditAccount::query()
            ->where('admin_id', (int) $userTwo->id)
            ->where('site_id', (int) $site->id)
            ->value('balance'));
        $this->assertSame('999.00', SiteCreditAccount::query()->where('site_id', (int) $site->id)->value('balance'));
        $this->assertSame(2, AdminCreditLedger::query()->where('site_id', (int) $site->id)->where('type', 'deduct')->count());
        $this->assertSame(0, SiteCreditLedger::query()->where('site_id', (int) $site->id)->where('type', 'deduct')->count());
    }

    public function test_media_submission_uses_form_urlencoded_payload_for_remote_send(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$admin, $site] = $this->createAdminWithSite('media_form_submit_admin', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'form-encoded-submit');
        $this->grantAdminCredits($admin, $site, '100.00');

        Http::fake([
            '*/api/media/send' => function ($request) use ($resource) {
                $contentType = (string) ($request->header('Content-Type')[0] ?? '');
                $payload = $this->httpRequestPayload($request);

                $this->assertStringContainsString('application/x-www-form-urlencoded', $contentType);
                $this->assertStringNotContainsString('multipart/form-data', $contentType);
                $this->assertSame((string) $resource->external_resource_id, (string) ($payload['resource_id'] ?? ''));
                $this->assertSame('form-submit-note', (string) ($payload['remark'] ?? ''));

                return Http::response([
                    'code' => 1,
                    'msg' => 'success',
                    'data' => ['order_nid' => 'form-order'],
                ]);
            },
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.store'), [
                'article_id' => $article->id,
                'media_resource_id' => $resource->id,
                'remark' => 'form-submit-note',
            ])
            ->assertRedirect(route('admin.media-distribution.submissions.index'));

        $submission = MediaSubmission::query()->where('article_id', $article->id)->firstOrFail();

        $this->assertSame('submitted', $submission->status);
        $this->assertSame('form-order', $submission->external_order_nid);
    }

    public function test_chaojimeijie_we_media_submission_uses_preview_url_and_fixed_article_params(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$admin, $site] = $this->createAdminWithSite('media_cjmj_submit_admin', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'cjmj-submit');
        $resource->forceFill([
            'platform_id' => MediaPlatform::CEYING_MEDIA_2,
            'source_type' => MediaResource::SOURCE_ZI_MEDIA,
            'external_resource_id' => '60001',
        ])->save();
        MediaApiSetting::query()->create([
            'platform_id' => MediaPlatform::CEYING_MEDIA_2,
            'api_base_url' => 'https://vip.chaojimeijie.com/api',
            'app_id' => 'app-id',
            'api_secret_ciphertext' => app(\App\Support\GeoFlow\ApiKeyCrypto::class)->encrypt('secret'),
            'status' => 'active',
            'price_multiplier' => '1.00',
        ]);
        $this->grantAdminCredits($admin, $site, '100.00');

        Http::fake([
            '*/we-media/order' => function ($request) use ($resource) {
                $payload = $this->httpRequestPayload($request);

                $this->assertSame((string) $resource->external_resource_id, (string) ($payload['resource_id'] ?? ''));
                $this->assertSame('1', (string) ($payload['publish_form'] ?? ''));
                $this->assertSame('1', (string) ($payload['publish_type'] ?? ''));
                $this->assertSame('3', (string) ($payload['account_rule'] ?? ''));
                $this->assertStringContainsString('/media-submission-preview/', (string) ($payload['content'] ?? ''));
                $this->assertNotEmpty($payload['signature'] ?? '');

                return Http::response([
                    'code' => 200,
                    'message' => 'success',
                    'data' => ['partner_sn' => 'CJMJ202606040000000001'],
                ]);
            },
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.store'), [
                'article_id' => $article->id,
                'media_resource_id' => $resource->id,
                'remark' => 'cjmj submit',
            ])
            ->assertRedirect(route('admin.media-distribution.submissions.index'));

        $submission = MediaSubmission::query()->where('article_id', $article->id)->firstOrFail();

        $this->assertSame(MediaPlatform::CEYING_MEDIA_2, (int) $submission->platform_id);
        $this->assertSame('CJMJ202606040000000001', $submission->external_order_nid);
        $this->assertNotSame('', (string) $submission->agent_order_sn);
        $this->assertNotSame('', (string) $submission->preview_token);
    }

    public function test_media_submission_preview_renders_markdown_snapshot_as_safe_html(): void
    {
        [$submission] = $this->createPreviewSubmissionWithSnapshot(
            "## Section Heading\n\nThis is **bold content**.\n\n- First item\n- Second item\n\n<script>alert('xss')</script>",
            'markdown-preview-token'
        );

        $response = $this->get(route('media-submission-preview.show', [
            'submission' => (int) $submission->id,
            'token' => 'markdown-preview-token',
        ]));

        $response
            ->assertOk()
            ->assertSee('<h2>Section Heading</h2>', false)
            ->assertSee('<strong>bold content</strong>', false)
            ->assertSee('<li>First item</li>', false)
            ->assertDontSee('<script>', false);
    }

    public function test_media_submission_preview_renders_escaped_markdown_snapshot_with_br_tags(): void
    {
        [$submission] = $this->createPreviewSubmissionWithSnapshot(
            "## Section Heading<br />\n<br />\nThis is **bold content**.<br />\n<br />\n- First item<br />\n- Second item",
            'escaped-markdown-preview-token'
        );

        $response = $this->get(route('media-submission-preview.show', [
            'submission' => (int) $submission->id,
            'token' => 'escaped-markdown-preview-token',
        ]));

        $response
            ->assertOk()
            ->assertSee('<h2>Section Heading</h2>', false)
            ->assertSee('<strong>bold content</strong>', false)
            ->assertSee('<li>First item</li>', false)
            ->assertDontSee('## Section Heading');
    }

    public function test_media_submission_preview_keeps_existing_html_snapshot(): void
    {
        [$submission] = $this->createPreviewSubmissionWithSnapshot(
            '<h2>Existing HTML</h2><p>Already rendered.</p>',
            'html-preview-token'
        );

        $response = $this->get(route('media-submission-preview.show', [
            'submission' => (int) $submission->id,
            'token' => 'html-preview-token',
        ]));

        $response
            ->assertOk()
            ->assertSee('<h2>Existing HTML</h2>', false)
            ->assertSee('<p>Already rendered.</p>', false);
    }

    public function test_media_submission_preview_decodes_escaped_html_snapshot(): void
    {
        [$submission] = $this->createPreviewSubmissionWithSnapshot(
            htmlspecialchars('<h2>Escaped HTML</h2><p>Rendered content should remain visible.</p>', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'escaped-html-preview-token'
        );

        $response = $this->get(route('media-submission-preview.show', [
            'submission' => (int) $submission->id,
            'token' => 'escaped-html-preview-token',
        ]));

        $response
            ->assertOk()
            ->assertSee('<h2>Escaped HTML</h2>', false)
            ->assertSee('<p>Rendered content should remain visible.</p>', false)
            ->assertDontSee('&lt;h2&gt;', false);
    }

    private function createPreviewSubmissionWithSnapshot(string $contentSnapshot, string $token): array
    {
        $site = Site::query()->create(['name' => 'Preview Site '.Str::random(6), 'domain' => Str::random(8).'.test', 'status' => 'active']);
        $category = Category::query()->create([
            'site_id' => $site->id,
            'name' => 'Preview',
            'slug' => 'preview-'.Str::random(6),
        ]);
        $author = Author::query()->create([
            'site_id' => $site->id,
            'name' => 'Preview Author',
            'slug' => 'preview-author-'.Str::random(6),
        ]);
        $article = Article::query()->create([
            'site_id' => $site->id,
            'category_id' => $category->id,
            'author_id' => $author->id,
            'title' => 'Markdown Preview Article',
            'slug' => 'markdown-preview-article-'.Str::random(6),
            'content' => '# Markdown Preview Article',
            'status' => 'published',
            'published_at' => now(),
        ]);
        $resource = MediaResource::query()->create([
            'platform_id' => MediaPlatform::CEYING_MEDIA_2,
            'source_type' => MediaResource::SOURCE_ZI_MEDIA,
            'external_resource_id' => 'preview-resource-'.Str::random(8),
            'title' => 'Preview Resource',
            'status' => 'active',
            'cost_price' => '0.00',
            'sale_price' => '0.00',
        ]);
        $submission = MediaSubmission::query()->create([
            'site_id' => $site->id,
            'article_id' => $article->id,
            'media_resource_id' => $resource->id,
            'platform_id' => MediaPlatform::CEYING_MEDIA_2,
            'source_type' => 'we_media',
            'external_order_nid' => 'preview-order',
            'agent_order_sn' => 'preview-agent-sn',
            'preview_token' => $token,
            'title_snapshot' => 'Markdown Preview Article',
            'content_snapshot' => $contentSnapshot,
            'cost_price_snapshot' => '0.00',
            'sale_price_snapshot' => '0.00',
            'points_amount' => '0.00',
            'status' => 'submitted',
        ]);

        return [$submission, $site, $article, $resource];
    }

    public function test_submission_requires_enough_account_credits(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$admin, $site] = $this->createAdminWithSite('media_low_credit_admin', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'low-credit-article');
        $this->grantAdminCredits($admin, $site, '10.00');
        Http::fake();

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.store'), [
                'article_id' => $article->id,
                'media_resource_id' => $resource->id,
            ])
            ->assertSessionHasErrors();

        $this->assertDatabaseMissing('media_submissions', [
            'article_id' => $article->id,
        ]);
        $this->assertSame('10.00', AdminCreditAccount::query()
            ->where('admin_id', (int) $admin->id)
            ->where('site_id', (int) $site->id)
            ->value('balance'));
        Http::assertNothingSent();
    }

    public function test_media_submission_allows_zero_credit_plan_as_unlimited_without_deducting_balance(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$admin, $site] = $this->createAdminWithSite('media_unlimited_credit_admin', 'admin');
        $subscription = \App\Models\AdminPlanSubscription::query()
            ->where('admin_id', (int) $admin->id)
            ->where('site_id', (int) $site->id)
            ->firstOrFail();
        $snapshot = (array) $subscription->entitlements_snapshot;
        $snapshot[\App\Models\PlatformPlan::RESOURCE_CREDITS] = [
            'enabled' => true,
            'quota_value' => 0,
            'quota_period' => 'cycle',
            'unit' => 'points',
            'meta' => [],
        ];
        $subscription->forceFill(['entitlements_snapshot' => $snapshot])->save();
        [$article, $resource] = $this->createArticleAndResource($site, 'unlimited-credit-article');
        Http::fake([
            '*/api/media/send' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => ['order_nid' => 'unlimited-credit-order'],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.store'), [
                'article_id' => $article->id,
                'media_resource_id' => $resource->id,
            ])
            ->assertRedirect(route('admin.media-distribution.submissions.index'))
            ->assertSessionHasNoErrors();

        $submission = MediaSubmission::query()->where('article_id', $article->id)->firstOrFail();

        $this->assertSame('submitted', (string) $submission->status);
        $this->assertSame('unlimited-credit-order', (string) $submission->external_order_nid);
        $this->assertNull(AdminCreditAccount::query()
            ->where('admin_id', (int) $admin->id)
            ->where('site_id', (int) $site->id)
            ->value('balance'));
        $this->assertSame(0, AdminCreditLedger::query()
            ->where('admin_id', (int) $admin->id)
            ->where('site_id', (int) $site->id)
            ->where('type', 'deduct')
            ->count());
    }

    public function test_submit_failure_records_failed_order_and_refunds_credits(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$admin, $site] = $this->createAdminWithSite('media_failed_submit_admin', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'failed-submit-article');
        $this->grantAdminCredits($admin, $site, '100.00');
        Http::fake([
            '*/api/media/send' => Http::response([
                'code' => 0,
                'msg' => 'remote rejected',
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.store'), [
                'article_id' => $article->id,
                'media_resource_id' => $resource->id,
            ])
            ->assertSessionHasErrors();

        $submission = MediaSubmission::query()->where('article_id', $article->id)->firstOrFail();
        $this->assertSame('failed', $submission->status);
        $this->assertSame('100.00', AdminCreditAccount::query()
            ->where('admin_id', (int) $admin->id)
            ->where('site_id', (int) $site->id)
            ->value('balance'));
        $this->assertDatabaseHas('admin_credit_ledger', [
            'admin_id' => (int) $admin->id,
            'site_id' => $site->id,
            'submission_id' => $submission->id,
            'type' => 'refund',
            'amount' => '88.00',
            'balance_after' => '100.00',
        ]);
    }

    public function test_media_submissions_are_isolated_by_current_site_for_standard_admins(): void
    {
        [$adminA, $siteA] = $this->createAdminWithSite('media_site_a_admin', 'admin');
        [, $siteB] = $this->createAdminWithSite('media_site_b_admin', 'admin');
        [$article, $resource] = $this->createArticleAndResource($siteB, 'site-b-article');
        $submission = MediaSubmission::query()->create([
            'site_id' => $siteB->id,
            'article_id' => $article->id,
            'media_resource_id' => $resource->id,
            'source_type' => $resource->source_type,
            'title_snapshot' => $article->title,
            'content_snapshot' => $article->content,
            'cost_price_snapshot' => '27.00',
            'sale_price_snapshot' => '88.00',
            'points_amount' => '88.00',
            'status' => 'submitted',
        ]);

        $this->actingAs($adminA, 'admin')
            ->withSession(['current_site_id' => $siteA->id])
            ->get(route('admin.media-distribution.submissions.show', ['submission' => $submission->id]))
            ->assertNotFound();
    }

    public function test_command_auto_syncs_unfinished_media_submission_statuses(): void
    {
        [, $site] = $this->createAdminWithSite('media_sync_command_admin', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'sync-command-article');
        $submission = MediaSubmission::query()->create([
            'site_id' => $site->id,
            'article_id' => $article->id,
            'media_resource_id' => $resource->id,
            'source_type' => $resource->source_type,
            'external_order_nid' => 'order-1001',
            'title_snapshot' => $article->title,
            'content_snapshot' => $article->content,
            'cost_price_snapshot' => '27.00',
            'sale_price_snapshot' => '88.00',
            'points_amount' => '88.00',
            'status' => 'submitted',
        ]);
        MediaSubmission::query()->create([
            'site_id' => $site->id,
            'article_id' => $article->id,
            'media_resource_id' => $resource->id,
            'source_type' => $resource->source_type,
            'external_order_nid' => 'order-done',
            'title_snapshot' => $article->title,
            'content_snapshot' => $article->content,
            'cost_price_snapshot' => '27.00',
            'sale_price_snapshot' => '88.00',
            'points_amount' => '88.00',
            'status' => 'published',
        ]);
        Http::fake([
            '*/api/media/order_info' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => [
                    'status' => 'published',
                    'url' => 'https://example.com/auto-synced.html',
                ],
            ]),
        ]);

        $this->artisan('media-distribution:sync-submissions', ['--limit' => 10])
            ->assertExitCode(0);

        $submission->refresh();
        $this->assertSame('published', $submission->status);
        $this->assertSame('https://example.com/auto-synced.html', $submission->published_url);
        Http::assertSentCount(1);
    }

    public function test_admin_can_cancel_and_appeal_media_submission(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$admin, $site] = $this->createAdminWithSite('media_cancel_admin', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'cancel-appeal-article');
        $submission = MediaSubmission::query()->create([
            'site_id' => $site->id,
            'article_id' => $article->id,
            'media_resource_id' => $resource->id,
            'source_type' => $resource->source_type,
            'external_order_nid' => 'order-cancel',
            'title_snapshot' => $article->title,
            'content_snapshot' => $article->content,
            'cost_price_snapshot' => '27.00',
            'sale_price_snapshot' => '88.00',
            'points_amount' => '88.00',
            'status' => 'submitted',
        ]);
        Http::fake([
            '*/api/media/cancel_order' => Http::response(['code' => 1, 'msg' => 'cancelled', 'data' => []]),
            '*/api/media/rejection' => Http::response(['code' => 1, 'msg' => 'appeal accepted', 'data' => []]),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.cancel', ['submission' => $submission->id]), [
                'reason' => 'wrong article',
            ])
            ->assertRedirect(route('admin.media-distribution.submissions.show', ['submission' => $submission->id]));

        $submission->refresh();
        $this->assertSame('cancelled', $submission->status);
        $this->assertSame('wrong article', $submission->cancel_reason);

        $submission->forceFill(['status' => 'rejected'])->save();
        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.appeal', ['submission' => $submission->id]), [
                'content' => 'please recheck',
            ])
            ->assertRedirect(route('admin.media-distribution.submissions.show', ['submission' => $submission->id]));

        $submission->refresh();
        $this->assertSame('appealing', $submission->status);
        $this->assertSame('please recheck', $submission->appeal_content);
    }

    public function test_failed_manual_sync_persists_error_on_submission_detail(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$admin, $site] = $this->createAdminWithSite('media_sync_error_admin', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'sync-error-article');
        $submission = MediaSubmission::query()->create([
            'site_id' => $site->id,
            'article_id' => $article->id,
            'media_resource_id' => $resource->id,
            'source_type' => $resource->source_type,
            'external_order_nid' => 'sync-error-order',
            'title_snapshot' => $article->title,
            'content_snapshot' => $article->content,
            'cost_price_snapshot' => '27.00',
            'sale_price_snapshot' => '88.00',
            'points_amount' => '88.00',
            'status' => 'submitted',
        ]);
        Http::fake([
            '*/api/media/order_info' => Http::response(['code' => 0, 'msg' => '参数异常', 'data' => []]),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.sync', ['submission' => $submission->id]))
            ->assertSessionHasErrors();

        $submission->refresh();
        $this->assertSame('参数异常', $submission->last_error_message);
        $this->assertNotNull($submission->last_synced_at);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->get(route('admin.media-distribution.submissions.show', ['submission' => $submission->id]))
            ->assertOk()
            ->assertSee('参数异常');
    }

    public function test_media_submission_detail_displays_chinese_status_label(): void
    {
        [$admin, $site] = $this->createAdminWithSite('media_status_label_admin', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'status-label-article');
        $submission = MediaSubmission::query()->create([
            'site_id' => $site->id,
            'article_id' => $article->id,
            'media_resource_id' => $resource->id,
            'source_type' => $resource->source_type,
            'external_order_nid' => 'status-label-order',
            'title_snapshot' => $article->title,
            'content_snapshot' => $article->content,
            'cost_price_snapshot' => '27.00',
            'sale_price_snapshot' => '88.00',
            'points_amount' => '88.00',
            'status' => 'submitted',
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->get(route('admin.media-distribution.submissions.show', ['submission' => $submission->id]))
            ->assertOk()
            ->assertSee('待安排')
            ->assertDontSee('submitted');
    }

    public function test_successful_manual_sync_clears_previous_error_message(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$admin, $site] = $this->createAdminWithSite('media_sync_clear_error_admin', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'sync-clear-error-article');
        $submission = MediaSubmission::query()->create([
            'site_id' => $site->id,
            'article_id' => $article->id,
            'media_resource_id' => $resource->id,
            'source_type' => $resource->source_type,
            'external_order_nid' => 'sync-clear-error-order',
            'title_snapshot' => $article->title,
            'content_snapshot' => $article->content,
            'cost_price_snapshot' => '27.00',
            'sale_price_snapshot' => '88.00',
            'points_amount' => '88.00',
            'status' => 'submitted',
            'last_error_message' => '参数异常',
        ]);
        Http::fake([
            '*/api/media/order_info' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => [[
                    'order_nid' => 'sync-clear-error-order',
                    'status' => 'submitted',
                ]],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.sync', ['submission' => $submission->id]))
            ->assertRedirect(route('admin.media-distribution.submissions.show', ['submission' => $submission->id]));

        $submission->refresh();
        $this->assertSame('', (string) $submission->last_error_message);
    }

    public function test_submission_list_auto_syncs_visible_unfinished_orders(): void
    {
        [$admin, $site] = $this->createAdminWithSite('media_list_auto_sync_admin', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'list-auto-sync-article');
        $submission = MediaSubmission::query()->create([
            'site_id' => $site->id,
            'article_id' => $article->id,
            'media_resource_id' => $resource->id,
            'source_type' => $resource->source_type,
            'external_order_nid' => 'list-auto-sync-order',
            'title_snapshot' => $article->title,
            'content_snapshot' => $article->content,
            'cost_price_snapshot' => '27.00',
            'sale_price_snapshot' => '88.00',
            'points_amount' => '88.00',
            'status' => 'submitted',
        ]);
        Http::fake([
            '*/api/media/order_info' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => [[
                    'order_nid' => 'list-auto-sync-order',
                    'status' => 'published',
                    'url' => 'https://example.com/list-auto-sync.html',
                ]],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->get(route('admin.media-distribution.submissions.index'))
            ->assertOk()
            ->assertSee('已发布')
            ->assertDontSee('待安排');

        $submission->refresh();
        $this->assertSame('published', $submission->status);
        $this->assertSame('https://example.com/list-auto-sync.html', $submission->published_url);
    }

    public function test_submission_detail_auto_syncs_order_status(): void
    {
        [$admin, $site] = $this->createAdminWithSite('media_detail_auto_sync_admin', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'detail-auto-sync-article');
        $submission = MediaSubmission::query()->create([
            'site_id' => $site->id,
            'article_id' => $article->id,
            'media_resource_id' => $resource->id,
            'source_type' => $resource->source_type,
            'external_order_nid' => 'detail-auto-sync-order',
            'title_snapshot' => $article->title,
            'content_snapshot' => $article->content,
            'cost_price_snapshot' => '27.00',
            'sale_price_snapshot' => '88.00',
            'points_amount' => '88.00',
            'status' => 'submitted',
        ]);
        Http::fake([
            '*/api/media/order_info' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => [[
                    'order_nid' => 'detail-auto-sync-order',
                    'status' => 'published',
                    'url' => 'https://example.com/detail-auto-sync.html',
                ]],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->get(route('admin.media-distribution.submissions.show', ['submission' => $submission->id]))
            ->assertOk()
            ->assertSee('已发布')
            ->assertSee('打开链接')
            ->assertSee('文章链接')
            ->assertSee('打开文章')
            ->assertDontSee('待安排');

        $submission->refresh();
        $this->assertSame('published', $submission->status);
        $this->assertSame('https://example.com/detail-auto-sync.html', $submission->published_url);
    }

    public function test_submission_sync_accepts_numeric_published_status_and_publish_url(): void
    {
        [$admin, $site] = $this->createAdminWithSite('media_numeric_status_admin', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'numeric-status-article');
        $submission = MediaSubmission::query()->create([
            'site_id' => $site->id,
            'article_id' => $article->id,
            'media_resource_id' => $resource->id,
            'source_type' => $resource->source_type,
            'external_order_nid' => 'numeric-status-order',
            'title_snapshot' => $article->title,
            'content_snapshot' => $article->content,
            'cost_price_snapshot' => '27.00',
            'sale_price_snapshot' => '88.00',
            'points_amount' => '88.00',
            'status' => 'submitted',
        ]);
        Http::fake([
            '*/api/media/order_info' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => [[
                    'order_nid' => 'numeric-status-order',
                    'status' => 2,
                    'publish_url' => 'https://example.com/numeric-status.html',
                ]],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->get(route('admin.media-distribution.submissions.show', ['submission' => $submission->id]))
            ->assertOk()
            ->assertSee('已发布')
            ->assertSee('打开链接')
            ->assertDontSee('待安排');

        $submission->refresh();
        $this->assertSame('published', $submission->status);
        $this->assertSame('https://example.com/numeric-status.html', $submission->published_url);
    }

    public function test_submission_sync_falls_back_to_nested_published_url(): void
    {
        [$admin, $site] = $this->createAdminWithSite('media_nested_url_admin', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'nested-url-article');
        $submission = MediaSubmission::query()->create([
            'site_id' => $site->id,
            'article_id' => $article->id,
            'media_resource_id' => $resource->id,
            'source_type' => $resource->source_type,
            'external_order_nid' => 'nested-url-order',
            'title_snapshot' => $article->title,
            'content_snapshot' => $article->content,
            'cost_price_snapshot' => '27.00',
            'sale_price_snapshot' => '88.00',
            'points_amount' => '88.00',
            'status' => 'submitted',
        ]);
        Http::fake([
            '*/api/media/order_info' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => [[
                    'order_nid' => 'nested-url-order',
                    'status' => 2,
                    'extra' => [
                        'article_url' => 'https://example.com/nested-url.html',
                    ],
                ]],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.sync', ['submission' => $submission->id]))
            ->assertRedirect(route('admin.media-distribution.submissions.show', ['submission' => $submission->id]));

        $submission->refresh();
        $this->assertSame('published', $submission->status);
        $this->assertSame('https://example.com/nested-url.html', $submission->published_url);
    }

    public function test_submission_sync_uses_external_numeric_status_labels(): void
    {
        [$admin, $site] = $this->createAdminWithSite('media_numeric_labels_admin', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'numeric-labels-article');
        $cases = [
            0 => ['submitted', '待安排'],
            1 => ['publishing', '已安排'],
            2 => ['published', '已发布'],
            4 => ['rejected', '已退稿'],
            9 => ['appealing', '售后中'],
        ];
        Http::fake([
            '*/api/media/order_info' => function ($request) {
                $orderNid = (string) ($this->httpRequestPayload($request)['order_nids[]'] ?? '');
                $externalStatus = (int) str_replace('numeric-label-', '', $orderNid);

                return Http::response([
                    'code' => 1,
                    'msg' => 'success',
                    'data' => [[
                        'order_nid' => $orderNid,
                        'status' => $externalStatus,
                        'publish_url' => $externalStatus === 2 ? 'https://example.com/numeric-label-'.$externalStatus.'.html' : '',
                    ]],
                ]);
            },
        ]);

        foreach ($cases as $externalStatus => [$internalStatus, $label]) {
            $submission = MediaSubmission::query()->create([
                'site_id' => $site->id,
                'article_id' => $article->id,
                'media_resource_id' => $resource->id,
                'source_type' => $resource->source_type,
                'external_order_nid' => 'numeric-label-'.$externalStatus,
                'title_snapshot' => $article->title,
                'content_snapshot' => $article->content,
                'cost_price_snapshot' => '27.00',
                'sale_price_snapshot' => '88.00',
                'points_amount' => '88.00',
                'status' => 'submitted',
            ]);
            $this->actingAs($admin, 'admin')
                ->withSession(['current_site_id' => $site->id])
                ->post(route('admin.media-distribution.submissions.sync', ['submission' => $submission->id]))
                ->assertRedirect(route('admin.media-distribution.submissions.show', ['submission' => $submission->id]));

            $submission->refresh();
            $this->assertSame($internalStatus, $submission->status);
            $this->assertSame($label, $submission->statusLabel());
        }
    }

    public function test_admin_can_bulk_submit_articles_to_media(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$admin, $site] = $this->createAdminWithSite('media_bulk_submit_admin', 'admin');
        [$articleA, $resource] = $this->createArticleAndResource($site, 'bulk-submit-a');
        [$articleB] = $this->createArticleAndResource($site, 'bulk-submit-b');
        $this->grantAdminCredits($admin, $site, '200.00');
        Http::fake([
            '*/api/media/send' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => ['order_nid' => 'bulk-order'],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.bulk-store'), [
                'article_ids' => [$articleA->id, $articleB->id],
                'media_resource_id' => $resource->id,
                'remark' => 'bulk',
            ])
            ->assertRedirect(route('admin.media-distribution.submissions.index'));

        $this->assertSame(2, MediaSubmission::query()->where('media_resource_id', $resource->id)->count());
        $this->assertSame('24.00', AdminCreditAccount::query()
            ->where('admin_id', (int) $admin->id)
            ->where('site_id', (int) $site->id)
            ->value('balance'));
        $this->assertSame(2, Http::recorded(fn ($request): bool => str_contains($request->url(), '/api/media/send'))->count());
    }

    public function test_admin_can_select_multiple_media_resources_then_bulk_submit_multiple_articles(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$admin, $site] = $this->createAdminWithSite('media_bulk_matrix_admin', 'admin');
        [$articleA, $resourceA] = $this->createArticleAndResource($site, 'bulk-matrix-a');
        [$articleB, $resourceB] = $this->createArticleAndResource($site, 'bulk-matrix-b');
        $this->grantAdminCredits($admin, $site, '500.00');
        Http::fake([
            '*/api/media/send' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => ['order_nid' => 'bulk-matrix-order'],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->get(route('admin.media-distribution.resources.index'))
            ->assertOk()
            ->assertSee('bulk-media-submit-form', false)
            ->assertSee('name="media_resource_ids[]"', false)
            ->assertSee('批量投稿');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->get(route('admin.media-distribution.submissions.index', [
                'media_resource_ids' => [$resourceA->id, $resourceB->id],
            ]))
            ->assertOk()
            ->assertSee('name="media_resource_ids[]"', false)
            ->assertSee('value="'.(int) $resourceA->id.'" selected', false)
            ->assertSee('value="'.(int) $resourceB->id.'" selected', false);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.bulk-store'), [
                'article_ids' => [$articleA->id, $articleB->id],
                'media_resource_ids' => [$resourceA->id, $resourceB->id],
                'remark' => 'bulk matrix',
            ])
            ->assertRedirect(route('admin.media-distribution.submissions.index'));

        $this->assertSame(4, MediaSubmission::query()->count());
        $this->assertSame(2, MediaSubmission::query()->where('media_resource_id', $resourceA->id)->count());
        $this->assertSame(2, MediaSubmission::query()->where('media_resource_id', $resourceB->id)->count());
        $this->assertSame('148.00', AdminCreditAccount::query()
            ->where('admin_id', (int) $admin->id)
            ->where('site_id', (int) $site->id)
            ->value('balance'));
        $this->assertSame(4, Http::recorded(fn ($request): bool => str_contains($request->url(), '/api/media/send'))->count());
    }

    public function test_media_resource_submit_link_prefills_bulk_submission_article_picker(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$admin, $site] = $this->createAdminWithSite('media_prefill_submit_admin', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'prefill-submit');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->get(route('admin.media-distribution.submissions.index', [
                'media_resource_id' => (int) $resource->id,
            ]))
            ->assertOk()
            ->assertSee('name="article_ids[]"', false)
            ->assertSee('name="media_resource_ids[]"', false)
            ->assertSee('value="'.(int) $resource->id.'" selected', false)
            ->assertSee('value="'.(int) $article->id.'"', false)
            ->assertDontSee('name="article_id"', false)
            ->assertDontSee('name="media_resource_id"', false);
    }

    public function test_media_submission_page_uses_single_bulk_submission_form(): void
    {
        [$admin, $site] = $this->createAdminWithSite('media_single_submit_form_admin', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'single-submit-form');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->get(route('admin.media-distribution.submissions.index'))
            ->assertOk()
            ->assertSee('name="article_ids[]"', false)
            ->assertSee('name="media_resource_ids[]"', false)
            ->assertSee('value="'.(int) $article->id.'"', false)
            ->assertSee('value="'.(int) $resource->id.'"', false)
            ->assertDontSee('name="article_id"', false)
            ->assertDontSee('name="media_resource_id"', false);
    }

    public function test_media_resources_page_highlights_media_package_resource(): void
    {
        [$admin] = $this->createAdminWithSite('media_package_admin', 'admin');

        $package = MediaResource::query()->create([
            'platform_id' => MediaPlatform::CEYING_MEDIA_2,
            'source_type' => MediaResource::SOURCE_WEBSITE,
            'external_resource_id' => '100-package',
            'title' => '100家特价媒体套餐',
            'remarks' => '一次投稿覆盖100家媒体，发布链接为 docs 文档链接。',
            'status' => 'active',
            'cost_price' => '100.00',
            'sale_price' => '150.00',
            'raw_payload' => [
                'package_size' => 100,
                'publish_url_type' => 'docs',
            ],
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.media-distribution.resources.index'))
            ->assertOk()
            ->assertSee('媒体套餐发布')
            ->assertSee('100家特价媒体套餐')
            ->assertSee('100家媒体')
            ->assertSee('docs 文档链接')
            ->assertSee(route('admin.media-distribution.submissions.index', ['media_resource_id' => (int) $package->id]), false)
            ->assertSee('name="media_resource_ids[]"', false)
            ->assertSee('value="'.(int) $package->id.'"', false);
    }

    public function test_media_package_submission_uses_media_two_flow_and_stores_docs_url(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$admin, $site] = $this->createAdminWithSite('media_package_submit_admin', 'admin');
        [$article] = $this->createArticleAndResource($site, 'package-submit');
        $this->grantAdminCredits($admin, $site, '300.00');
        MediaApiSetting::query()->create([
            'platform_id' => MediaPlatform::CEYING_MEDIA_2,
            'api_base_url' => 'https://vip.chaojimeijie.com/api',
            'app_id' => 'app-id',
            'api_secret_ciphertext' => app(\App\Support\GeoFlow\ApiKeyCrypto::class)->encrypt('secret'),
            'status' => 'active',
            'price_multiplier' => '1.00',
        ]);
        $package = MediaResource::query()->create([
            'platform_id' => MediaPlatform::CEYING_MEDIA_2,
            'source_type' => MediaResource::SOURCE_WEBSITE,
            'external_resource_id' => '100888',
            'title' => '100家特价媒体套餐',
            'remarks' => '套餐发布结果返回 docs 文档链接。',
            'status' => 'active',
            'cost_price' => '100.00',
            'sale_price' => '150.00',
            'raw_payload' => [
                'package_size' => 100,
                'publish_url_type' => 'docs',
            ],
        ]);

        Http::fake([
            '*/media/order' => function ($request) use ($package) {
                $payload = $this->httpRequestPayload($request);
                $this->assertSame((int) $package->external_resource_id, (int) ($payload['resource_id'] ?? 0));
                $this->assertSame('Media Submit package-submit', (string) ($payload['title'] ?? ''));
                $this->assertStringContainsString('/media-submission-preview/', (string) ($payload['content'] ?? ''));

                return Http::response([
                    'code' => 200,
                    'message' => 'success',
                    'data' => ['partner_sn' => (string) ($payload['sn'] ?? 'package-sn')],
                ]);
            },
            '*/media/order/query*' => Http::response([
                'code' => 200,
                'message' => 'success',
                'data' => [[
                    'sn' => 'any-sn',
                    'status' => 4,
                    'url' => 'https://docs.qq.com/doc/package-result',
                ]],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.bulk-store'), [
                'article_ids' => [$article->id],
                'media_resource_ids' => [$package->id],
            ])
            ->assertRedirect(route('admin.media-distribution.submissions.index'));

        $submission = MediaSubmission::query()->where('media_resource_id', $package->id)->firstOrFail();
        $this->assertSame(MediaPlatform::CEYING_MEDIA_2, (int) $submission->platform_id);
        $this->assertSame('150.00', $submission->points_amount);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.sync', ['submission' => $submission->id]))
            ->assertRedirect(route('admin.media-distribution.submissions.show', ['submission' => $submission->id]));

        $submission->refresh();
        $this->assertSame('published', (string) $submission->status);
        $this->assertSame('https://docs.qq.com/doc/package-result', (string) $submission->published_url);
    }

    public function test_media_resources_support_status_and_price_filters_without_category_options(): void
    {
        [$admin] = $this->createAdminWithSite('media_filter_admin', 'admin');
        MediaResource::query()->create([
            'source_type' => 'website_media',
            'external_resource_id' => 'filter-1',
            'title' => 'Finance Media',
            'category' => 'finance',
            'status' => 'active',
            'cost_price' => '20.00',
            'sale_price' => '80.00',
        ]);
        MediaResource::query()->create([
            'source_type' => 'zi_media',
            'external_resource_id' => 'filter-2',
            'title' => 'Travel Media',
            'category' => 'travel',
            'status' => 'inactive',
            'cost_price' => '10.00',
            'sale_price' => '30.00',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.media-distribution.resources.index'))
            ->assertOk()
            ->assertSee('Finance Media')
            ->assertDontSee('Travel Media')
            ->assertSee('<option value="active" selected>可投稿</option>', false)
            ->assertDontSee('<option value="finance"', false)
            ->assertDontSee('<option value="travel"', false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.media-distribution.resources.index', [
                'status' => 'all',
            ]))
            ->assertOk()
            ->assertSee('Finance Media')
            ->assertSee('Travel Media')
            ->assertSee('媒体总数')
            ->assertSee('2 条')
            ->assertSee('<option value="all" selected', false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.media-distribution.resources.index', [
                'status' => 'active',
                'min_price' => '60',
                'max_price' => '100',
            ]))
            ->assertOk()
            ->assertSee('Finance Media')
            ->assertDontSee('Travel Media');
    }

    public function test_media_resource_search_matches_synced_media_name_aliases(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$superAdmin] = $this->createAdminWithSite('media_search_root', 'super_admin');

        Http::fake([
            '*/api/media/media_list' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => [
                    [
                        'resource_id' => 81001,
                        'media_name' => 'Hong An Network',
                        'remarks' => 'synced without title key',
                        'status' => 1,
                        'price' => '18.00',
                    ],
                ],
            ]),
            '*/api/zi_media_api/media_list' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => [],
            ]),
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.media-distribution.settings.update'), [
                'api_base_url' => 'http://8.138.187.158:8082',
                'api_key' => 'test-api-key',
                'status' => 'active',
            ])
            ->assertRedirect(route('admin.media-distribution.settings.index'));

        $run = MediaResourceSyncRun::query()->create([
            'status' => 'pending',
            'started_by_admin_id' => (int) $superAdmin->id,
        ]);

        (new ProcessMediaResourceSyncJob((int) $run->id))
            ->handle(app(MediaResourceSyncService::class));

        $this->assertDatabaseHas('media_resources', [
            'external_resource_id' => '81001',
            'title' => 'Hong An Network',
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.media-distribution.resources.index', [
                'search' => 'Hong An',
            ]))
            ->assertOk()
            ->assertSee('Hong An Network');
    }

    public function test_media_resource_search_matches_legacy_raw_payload_media_name(): void
    {
        [$admin] = $this->createAdminWithSite('media_raw_search_admin', 'admin');

        MediaResource::query()->create([
            'source_type' => 'website_media',
            'external_resource_id' => 'legacy-81001',
            'title' => 'Legacy Payload Resource',
            'status' => 'active',
            'cost_price' => '18.00',
            'sale_price' => '27.00',
            'raw_payload' => [
                'media_name' => 'Legacy Hong An Network',
            ],
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.media-distribution.resources.index', [
                'search' => 'Legacy Hong',
            ]))
            ->assertOk()
            ->assertSee('Legacy Payload Resource');
    }

    public function test_media_resource_search_matches_chinese_media_name_in_raw_payload(): void
    {
        [$admin] = $this->createAdminWithSite('media_chinese_search_admin', 'admin');

        MediaResource::query()->create([
            'source_type' => 'website_media',
            'external_resource_id' => 'hong-an',
            'title' => '未映射标题',
            'status' => 'active',
            'cost_price' => '19.00',
            'sale_price' => '28.50',
            'raw_payload' => [
                'media_name' => '红安网',
                'field' => '新闻资讯',
            ],
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.media-distribution.resources.index', [
                'search' => '红安',
            ]))
            ->assertOk()
            ->assertSee('未映射标题');
    }

    public function test_media_resource_list_shows_external_api_fields(): void
    {
        [$superAdmin] = $this->createAdminWithSite('media_field_root', 'super_admin');
        MediaResource::query()->create([
            'source_type' => 'website_media',
            'external_resource_id' => '73880',
            'title' => '中华网生活',
            'remarks' => '图片版权默认删',
            'case_link' => 'https://example.com/case.html',
            'category' => '综合',
            'status' => 'active',
            'cost_price' => '27.00',
            'sale_price' => '88.00',
            'raw_payload' => [
                'resource_id' => 73880,
                'title' => '中华网生活',
                'remarks' => '图片版权默认删',
                'case_link' => 'https://example.com/case.html',
                'field_1' => '新闻源',
                'field_2' => '可带联系方式',
                'field_3' => '综合门户',
                'field_4' => '收录稳定',
                'field_5' => '可发品牌稿',
                'field_6' => '不可改稿',
                'field_7' => '周末可发',
                'field_8' => '不包新闻源',
                'field_9' => '限正规稿件',
                'pc_weigh' => '7',
                'wap_weigh' => '6',
                'publish_rate' => '95%',
                'publish_time' => 3600,
                'status' => 1,
                'price' => '27.00',
            ],
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.media-distribution.resources.index'))
            ->assertOk()
            ->assertSee('class="w-56 max-w-56 px-5 py-4 align-top text-sm text-gray-600"', false)
            ->assertSee('class="truncate"', false)
            ->assertSee('whitespace-nowrap', false)
            ->assertSeeInOrder(['PC权重', '出稿率', '移动权重', '接口状态'])
            ->assertSee('PC权重')
            ->assertSee('7')
            ->assertSee('移动权重')
            ->assertSee('6')
            ->assertSee('出稿率')
            ->assertSee('95%')
            ->assertSee('可接单')
            ->assertSee('27.00')
            ->assertSee('专属价设置')
            ->assertDontSee('资源ID')
            ->assertDontSee('筛选1')
            ->assertDontSee('新闻源')
            ->assertDontSee('平均发布时间')
            ->assertDontSee('3600');
    }

    public function test_super_admin_can_export_media_submissions_and_credit_ledger_csv(): void
    {
        [$superAdmin] = $this->createAdminWithSite('media_export_root', 'super_admin');
        [$admin, $site] = $this->createAdminWithSite('media_export_site', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'export-article');
        $submission = MediaSubmission::query()->create([
            'site_id' => $site->id,
            'article_id' => $article->id,
            'media_resource_id' => $resource->id,
            'source_type' => $resource->source_type,
            'external_order_nid' => 'export-order',
            'title_snapshot' => $article->title,
            'content_snapshot' => $article->content,
            'cost_price_snapshot' => '27.00',
            'sale_price_snapshot' => '88.00',
            'points_amount' => '88.00',
            'status' => 'submitted',
        ]);
        $this->grantAdminCredits($admin, $site, '100.00');

        $submissionsCsv = $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.media-distribution.submissions.export'));
        $submissionsCsv->assertOk();
        $submissionsCsv->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('export-order', $submissionsCsv->streamedContent());
        $this->assertStringContainsString((string) $submission->id, $submissionsCsv->streamedContent());

        $creditsCsv = $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.media-distribution.credits.adjust', ['site' => $site->id]), [
                'amount' => '10',
                'remark' => 'export ledger',
            ])
            ->assertRedirect(route('admin.media-distribution.credits.index'));

        $creditsCsv = $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.media-distribution.credits.export'));
        $creditsCsv->assertOk();
        $creditsCsv->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('export ledger', $creditsCsv->streamedContent());
    }

    public function test_site_specific_media_price_is_used_for_submission_and_hidden_from_other_sites(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$superAdmin] = $this->createAdminWithSite('media_site_price_root', 'super_admin');
        [$admin, $site] = $this->createAdminWithSite('media_site_price_admin', 'admin');
        [, $otherSite] = $this->createAdminWithSite('media_site_price_other', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'site-price-article');
        $this->grantAdminCredits($admin, $site, '100.00');
        Http::fake([
            '*/api/media/send' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => ['order_nid' => 'site-price-order'],
            ]),
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.media-distribution.resources.site-price', ['resource' => $resource->id]), [
                'site_id' => $site->id,
                'sale_price' => '55',
            ])
            ->assertRedirect(route('admin.media-distribution.resources.index'));

        $this->assertDatabaseHas('media_resource_site_prices', [
            'site_id' => $site->id,
            'media_resource_id' => $resource->id,
            'sale_price' => '55.00',
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.store'), [
                'article_id' => $article->id,
                'media_resource_id' => $resource->id,
            ])
            ->assertRedirect(route('admin.media-distribution.submissions.index'));

        $submission = MediaSubmission::query()->where('article_id', $article->id)->firstOrFail();
        $this->assertSame('55.00', $submission->sale_price_snapshot);
        $this->assertSame('55.00', $submission->points_amount);
        $this->assertSame('45.00', AdminCreditAccount::query()
            ->where('admin_id', (int) $admin->id)
            ->where('site_id', (int) $site->id)
            ->value('balance'));
        $this->assertDatabaseMissing('media_resource_site_prices', [
            'site_id' => $otherSite->id,
            'media_resource_id' => $resource->id,
        ]);
    }

    public function test_cancelled_and_rejected_orders_refund_consumed_credits_once(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$admin, $site] = $this->createAdminWithSite('media_refund_admin', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'refund-article');
        AdminCreditAccount::query()->create([
            'admin_id' => (int) $admin->id,
            'site_id' => (int) $site->id,
            'balance' => '12.00',
            'frozen_balance' => '0.00',
            'total_granted' => '100.00',
            'total_consumed' => '88.00',
        ]);
        $submission = MediaSubmission::query()->create([
            'site_id' => $site->id,
            'owner_admin_id' => (int) $admin->id,
            'article_id' => $article->id,
            'media_resource_id' => $resource->id,
            'source_type' => $resource->source_type,
            'external_order_nid' => 'refund-order',
            'title_snapshot' => $article->title,
            'content_snapshot' => $article->content,
            'cost_price_snapshot' => '27.00',
            'sale_price_snapshot' => '88.00',
            'points_amount' => '88.00',
            'status' => 'submitted',
            'submitted_by_admin_id' => (int) $admin->id,
        ]);
        Http::fake([
            '*/api/media/cancel_order' => Http::response(['code' => 1, 'msg' => 'cancelled', 'data' => []]),
            '*/api/media/order_info' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => ['status' => 'rejected', 'reason' => 'not suitable'],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.cancel', ['submission' => $submission->id]), [
                'reason' => 'cancel request',
            ])
            ->assertRedirect(route('admin.media-distribution.submissions.show', ['submission' => $submission->id]));

        $this->assertSame('100.00', AdminCreditAccount::query()
            ->where('admin_id', (int) $admin->id)
            ->where('site_id', (int) $site->id)
            ->value('balance'));
        $this->assertSame(1, AdminCreditLedger::query()->where('submission_id', $submission->id)->where('type', 'refund')->count());

        $submission->forceFill(['status' => 'submitted'])->save();
        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.sync', ['submission' => $submission->id]))
            ->assertRedirect(route('admin.media-distribution.submissions.show', ['submission' => $submission->id]));

        $this->assertSame('100.00', AdminCreditAccount::query()
            ->where('admin_id', (int) $admin->id)
            ->where('site_id', (int) $site->id)
            ->value('balance'));
        $this->assertSame(1, AdminCreditLedger::query()->where('submission_id', $submission->id)->where('type', 'refund')->count());
    }

    public function test_chaojimeijie_sync_status_and_appeal_use_agent_order_sn(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$admin, $site] = $this->createAdminWithSite('media_cjmj_status_admin', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'cjmj-status');
        $resource->forceFill([
            'platform_id' => MediaPlatform::CEYING_MEDIA_2,
            'source_type' => MediaResource::SOURCE_WEBSITE,
            'external_resource_id' => '50001',
        ])->save();
        MediaApiSetting::query()->create([
            'platform_id' => MediaPlatform::CEYING_MEDIA_2,
            'api_base_url' => 'https://vip.chaojimeijie.com/api',
            'app_id' => 'app-id',
            'api_secret_ciphertext' => app(\App\Support\GeoFlow\ApiKeyCrypto::class)->encrypt('secret'),
            'status' => 'active',
            'price_multiplier' => '1.00',
        ]);
        AdminCreditAccount::query()->create([
            'admin_id' => (int) $admin->id,
            'site_id' => (int) $site->id,
            'balance' => '12.00',
            'frozen_balance' => '0.00',
            'total_granted' => '100.00',
            'total_consumed' => '88.00',
        ]);
        $submission = MediaSubmission::query()->create([
            'site_id' => $site->id,
            'owner_admin_id' => (int) $admin->id,
            'article_id' => $article->id,
            'media_resource_id' => $resource->id,
            'platform_id' => MediaPlatform::CEYING_MEDIA_2,
            'source_type' => $resource->source_type,
            'external_order_nid' => 'CJMJ202606040000000002',
            'agent_order_sn' => 'geoflow-2-1-agent-sn',
            'preview_token' => 'preview-token',
            'title_snapshot' => $article->title,
            'content_snapshot' => $article->content,
            'cost_price_snapshot' => '27.00',
            'sale_price_snapshot' => '88.00',
            'points_amount' => '88.00',
            'status' => 'submitted',
            'submitted_by_admin_id' => (int) $admin->id,
        ]);

        Http::fake([
            '*/media/order/query*' => function ($request) {
                $query = [];
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $this->assertSame('geoflow-2-1-agent-sn', (string) ($query['sn'][0] ?? ''));

                return Http::response([
                    'code' => 200,
                    'message' => 'success',
                    'data' => [[
                        'sn' => 'geoflow-2-1-agent-sn',
                        'status' => 7,
                        'url' => null,
                        'feedback' => ['reason' => '退款成功'],
                    ]],
                ]);
            },
            '*/media/order/apply-refund' => function ($request) {
                $payload = $this->httpRequestPayload($request);
                $this->assertSame('geoflow-2-1-agent-sn', (string) ($payload['sn'] ?? ''));
                $this->assertSame('please refund', (string) ($payload['reason'] ?? ''));

                return Http::response(['code' => 200, 'message' => 'success', 'data' => []]);
            },
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.sync', ['submission' => $submission->id]))
            ->assertRedirect(route('admin.media-distribution.submissions.show', ['submission' => $submission->id]));

        $submission->refresh();
        $this->assertSame('rejected', $submission->status);
        $this->assertSame('100.00', AdminCreditAccount::query()
            ->where('admin_id', (int) $admin->id)
            ->where('site_id', (int) $site->id)
            ->value('balance'));
        $this->assertSame(1, AdminCreditLedger::query()->where('submission_id', $submission->id)->where('type', 'refund')->count());

        $submission->forceFill(['status' => 'rejected'])->save();
        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.appeal', ['submission' => $submission->id]), [
                'content' => 'please refund',
            ])
            ->assertRedirect(route('admin.media-distribution.submissions.show', ['submission' => $submission->id]));

        $this->assertSame('appealing', $submission->fresh()->status);
    }

    public function test_super_admin_can_view_and_export_all_site_consumption_records(): void
    {
        [$superAdmin] = $this->createAdminWithSite('media_consumption_root', 'super_admin');
        [, $siteA] = $this->createAdminWithSite('media_consumption_a', 'admin');
        [, $siteB] = $this->createAdminWithSite('media_consumption_b', 'admin');
        [$articleA, $resourceA] = $this->createArticleAndResource($siteA, 'consumption-a');
        [$articleB, $resourceB] = $this->createArticleAndResource($siteB, 'consumption-b');
        foreach ([[$siteA, $articleA, $resourceA, 'order-a'], [$siteB, $articleB, $resourceB, 'order-b']] as [$site, $article, $resource, $order]) {
            $submission = MediaSubmission::query()->create([
                'site_id' => $site->id,
                'article_id' => $article->id,
                'media_resource_id' => $resource->id,
                'source_type' => $resource->source_type,
                'external_order_nid' => $order,
                'title_snapshot' => $article->title,
                'content_snapshot' => $article->content,
                'cost_price_snapshot' => '27.00',
                'sale_price_snapshot' => '88.00',
                'points_amount' => '88.00',
                'status' => 'submitted',
            ]);
            SiteCreditLedger::query()->create([
                'site_id' => $site->id,
                'submission_id' => $submission->id,
                'type' => 'deduct',
                'amount' => '-88.00',
                'balance_after' => '12.00',
                'frozen_after' => '0.00',
                'remark' => '媒体投稿扣除',
                'created_at' => now(),
            ]);
        }

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.media-distribution.credits.index'))
            ->assertOk()
            ->assertSee($siteA->name)
            ->assertSee($siteB->name);

        $csv = $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.media-distribution.credits.consumption-export'));

        $csv->assertOk();
        $csv->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $csv->streamedContent();
        $this->assertStringContainsString($siteA->name, $content);
        $this->assertStringContainsString($siteB->name, $content);
        $this->assertStringContainsString('order-a', $content);
        $this->assertStringContainsString('order-b', $content);
    }

    public function test_super_admin_can_view_profit_report(): void
    {
        [$superAdmin] = $this->createAdminWithSite('media_profit_root', 'super_admin');
        [, $site] = $this->createAdminWithSite('media_profit_site', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'profit-article');
        MediaSubmission::query()->create([
            'site_id' => $site->id,
            'article_id' => $article->id,
            'media_resource_id' => $resource->id,
            'source_type' => $resource->source_type,
            'external_order_nid' => 'profit-order',
            'title_snapshot' => $article->title,
            'content_snapshot' => $article->content,
            'cost_price_snapshot' => '27.00',
            'sale_price_snapshot' => '88.00',
            'points_amount' => '88.00',
            'status' => 'published',
            'submitted_at' => now(),
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.media-distribution.reports.profit'))
            ->assertOk()
            ->assertSee($site->name)
            ->assertSee('88.00')
            ->assertSee('27.00')
            ->assertSee('61.00');

        $csv = $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.media-distribution.reports.profit-export'));
        $csv->assertOk();
        $this->assertStringContainsString('61.00', $csv->streamedContent());
    }

    private function createAdminWithSite(string $username, string $role): array
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => str_replace('_', ' ', $username),
            'role' => $role,
            'status' => 'active',
        ]);

        $site = Site::query()->create([
            'owner_admin_id' => $admin->id,
            'name' => $admin->name.' 的默认站点',
            'status' => 'active',
        ]);
        $site->members()->attach($admin->id, ['role' => 'owner']);
        $this->openTestingPlanForSite($site, $admin);

        return [$admin, $site];
    }

    private function createArticleAndResource(Site $site, string $slug): array
    {
        $category = Category::query()->create([
            'site_id' => $site->id,
            'name' => 'Default',
            'slug' => $slug.'-category',
            'status' => 'active',
        ]);
        $author = Author::query()->create([
            'site_id' => $site->id,
            'name' => 'Default Author',
            'slug' => $slug.'-author',
            'status' => 'active',
        ]);
        $article = Article::query()->create([
            'site_id' => $site->id,
            'title' => 'Media Submit '.$slug,
            'slug' => $slug,
            'content' => '<p>Ready to publish.</p>',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
        ]);
        $resource = MediaResource::query()->create([
            'source_type' => 'website_media',
            'external_resource_id' => 'resource-'.$slug,
            'title' => 'Website Media '.$slug,
            'status' => 'active',
            'cost_price' => '27.00',
            'sale_price' => '88.00',
        ]);

        return [$article, $resource];
    }

    private function grantAdminCredits(Admin $admin, Site $site, string $amount): AdminCreditAccount
    {
        return AdminCreditAccount::query()->create([
            'admin_id' => (int) $admin->id,
            'site_id' => (int) $site->id,
            'balance' => $amount,
            'frozen_balance' => '0.00',
            'total_granted' => $amount,
            'total_consumed' => '0.00',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function httpRequestPayload($request): array
    {
        $data = $request->data();
        $payload = [];

        foreach ($data as $key => $value) {
            if (is_array($value) && array_key_exists('name', $value)) {
                $payload[(string) $value['name']] = $value['contents'] ?? '';

                continue;
            }

            $payload[(string) $key] = $value;
        }

        return $payload;
    }
}
