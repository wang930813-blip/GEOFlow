<?php

namespace Tests\Feature;

use App\Jobs\PollVideoGenerationJob;
use App\Jobs\StartVideoGenerationJob;
use App\Models\Admin;
use App\Models\AdminResourceUsage;
use App\Models\CrebeeAccount;
use App\Models\CrebeeAgent;
use App\Models\CrebeePublishJob;
use App\Models\CrebeePublishJobItem;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Models\VideoGenerationJob;
use App\Services\Billing\AdminPlanSubscriptionService;
use App\Services\VideoGeneration\VideoGenerationClient;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class AdminVideoGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
        config([
            'video-generation.base_url' => 'https://video.example.test',
            'video-generation.api_key' => '',
            'video-generation.poll_interval' => 10,
            'video-generation.max_poll_minutes' => 60,
        ]);
    }

    public function test_user_can_create_video_generation_job_asynchronously_and_consume_video_quota(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        [$admin, $site] = $this->provisionSubscribedAdmin('video_generation_owner', videoQuota: 2, crebeeQuota: 5);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.video-generations.store'), [
                'subject' => '成都人工智能企业宣传片',
                'script' => '展示企业研发、服务和客户案例。',
                'terms' => '人工智能, 科技办公室',
                'negative_terms' => '卡通, 动画',
                'video_aspect' => '9:16',
                'video_count' => 2,
                'cover_image' => 'https://cdn.example.test/covers/video-cover.jpg',
            ])
            ->assertRedirect();

        $job = VideoGenerationJob::query()->firstOrFail();
        $this->assertSame((int) $site->id, (int) $job->site_id);
        $this->assertSame((int) $admin->id, (int) $job->owner_admin_id);
        $this->assertSame('成都人工智能企业宣传片', (string) $job->subject);
        $this->assertSame('queued', (string) $job->status);
        $this->assertSame('', (string) $job->api_task_id);
        $this->assertSame(2, (int) $job->video_count);
        $this->assertSame('https://cdn.example.test/covers/video-cover.jpg', (string) $job->cover_image);
        $this->assertNotNull($job->quota_ledger_id);
        $this->assertSame('pexels', (string) data_get($job->request_payload, 'video_source'));
        $this->assertSame('9:16', (string) data_get($job->request_payload, 'video_aspect'));
        $this->assertSame(2, (int) data_get($job->request_payload, 'video_count'));
        $this->assertSame('卡通, 动画', (string) data_get($job->request_payload, 'video_negative_terms'));

        $usage = AdminResourceUsage::query()
            ->where('admin_id', (int) $admin->id)
            ->where('site_id', (int) $site->id)
            ->where('resource_key', PlatformPlan::RESOURCE_VIDEO_GENERATIONS)
            ->firstOrFail();
        $this->assertSame(2, (int) $usage->used_amount);

        Queue::assertPushed(StartVideoGenerationJob::class, fn (StartVideoGenerationJob $queued): bool => $queued->videoGenerationJobId === (int) $job->id);
        Queue::assertNotPushed(PollVideoGenerationJob::class);
    }

    public function test_user_can_create_video_generation_job_with_default_generation_options(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        [$admin, $site] = $this->provisionSubscribedAdmin('video_generation_default_owner', videoQuota: 1, crebeeQuota: 1);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.video-generations.store'), [
                'subject' => 'Default option video',
            ])
            ->assertRedirect();

        $job = VideoGenerationJob::query()->firstOrFail();
        $this->assertSame('Default option video', (string) $job->subject);
        $this->assertSame('', (string) $job->terms);
        $this->assertSame('', (string) $job->negative_terms);
        $this->assertSame('pexels', (string) $job->video_source);
        $this->assertSame('9:16', (string) $job->video_aspect);
        $this->assertSame(1, (int) $job->video_count);
        $this->assertSame([], data_get($job->request_payload, 'video_terms'));
        $this->assertSame('', (string) data_get($job->request_payload, 'video_negative_terms'));
        $this->assertSame('pexels', (string) data_get($job->request_payload, 'video_source'));
        $this->assertSame('9:16', (string) data_get($job->request_payload, 'video_aspect'));
        $this->assertSame(1, (int) data_get($job->request_payload, 'video_count'));

        $usage = AdminResourceUsage::query()
            ->where('admin_id', (int) $admin->id)
            ->where('site_id', (int) $site->id)
            ->where('resource_key', PlatformPlan::RESOURCE_VIDEO_GENERATIONS)
            ->firstOrFail();
        $this->assertSame(1, (int) $usage->used_amount);

        Queue::assertPushed(StartVideoGenerationJob::class, fn (StartVideoGenerationJob $queued): bool => $queued->videoGenerationJobId === (int) $job->id);
    }

    public function test_create_video_generation_form_hides_generation_option_fields(): void
    {
        [$admin, $site] = $this->provisionSubscribedAdmin('video_generation_form_owner', videoQuota: 1, crebeeQuota: 1);

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.video-generations.create'));

        $response->assertOk();
        $response->assertSee('name="subject"', false);
        $response->assertSee('name="script"', false);
        $response->assertSee('name="cover_image"', false);
        $response->assertDontSee('name="terms"', false);
        $response->assertDontSee('name="negative_terms"', false);
        $response->assertDontSee('name="video_aspect"', false);
        $response->assertDontSee('name="video_count"', false);
        $response->assertDontSee('name="video_source"', false);
    }

    public function test_start_video_generation_job_calls_api_and_dispatches_polling(): void
    {
        Queue::fake();
        Http::fake([
            'https://video.example.test/api/v1/videos' => Http::response([
                'status' => 200,
                'message' => 'success',
                'data' => ['task_id' => 'video-task-001'],
            ]),
        ]);
        [$admin, $site] = $this->provisionSubscribedAdmin('video_generation_start_owner', videoQuota: 1, crebeeQuota: 5);
        $job = VideoGenerationJob::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'created_by_admin_id' => (int) $admin->id,
            'title' => 'Async video',
            'subject' => 'Async video',
            'status' => 'queued',
            'progress' => 0,
            'video_count' => 1,
            'request_payload' => [
                'video_subject' => 'Async video',
                'video_source' => 'pexels',
                'video_count' => 1,
            ],
        ]);

        (new StartVideoGenerationJob((int) $job->id))->handle(app(VideoGenerationClient::class));

        $job->refresh();
        $this->assertSame('processing', (string) $job->status);
        $this->assertSame('video-task-001', (string) $job->api_task_id);
        $this->assertSame([
            'status' => 200,
            'message' => 'success',
            'data' => ['task_id' => 'video-task-001'],
        ], $job->result_payload);

        Http::assertSent(function (HttpRequest $request): bool {
            return $request->url() === 'https://video.example.test/api/v1/videos'
                && $request->hasHeader('x-task-id', 'video-generation:'.$request->data()['video_count'].':1');
        });
        Queue::assertPushed(PollVideoGenerationJob::class, fn (PollVideoGenerationJob $queued): bool => $queued->videoGenerationJobId === (int) $job->id);
    }

    public function test_start_video_generation_job_marks_failed_when_api_fails(): void
    {
        Http::fake([
            'https://video.example.test/api/v1/videos' => Http::response(['status' => 500, 'message' => 'upstream failed'], 500),
        ]);
        [$admin, $site] = $this->provisionSubscribedAdmin('video_generation_api_fail_owner', videoQuota: 1, crebeeQuota: 5);
        $job = VideoGenerationJob::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'created_by_admin_id' => (int) $admin->id,
            'title' => 'Failed video',
            'subject' => 'Failed video',
            'status' => 'processing',
            'progress' => 0,
            'video_count' => 1,
            'request_payload' => [
                'video_subject' => 'Failed video',
                'video_source' => 'pexels',
                'video_count' => 1,
            ],
        ]);
        $startJob = new StartVideoGenerationJob((int) $job->id);

        try {
            $startJob->handle(app(VideoGenerationClient::class));
            $this->fail('Expected video generation API failure.');
        } catch (RuntimeException $exception) {
            $startJob->failed($exception);
        }

        $job->refresh();
        $this->assertSame('failed', (string) $job->status);
        $this->assertSame('upstream failed', (string) $job->failure_reason);
    }

    public function test_polling_job_marks_video_generation_success(): void
    {
        Http::fake([
            'https://video.example.test/api/v1/tasks/video-task-002' => Http::response([
                'status' => 200,
                'message' => 'success',
                'data' => [
                    'task_id' => 'video-task-002',
                    'state' => 1,
                    'progress' => 100,
                    'videos' => ['/tasks/video-task-002/final-1.mp4'],
                    'combined_videos' => ['/tasks/video-task-002/combined-1.mp4'],
                    'script' => '生成后的视频脚本',
                    'terms' => ['office', 'ai'],
                ],
            ]),
        ]);
        [$admin, $site] = $this->provisionSubscribedAdmin('video_generation_poll_owner', videoQuota: 1, crebeeQuota: 5);
        $job = VideoGenerationJob::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'created_by_admin_id' => (int) $admin->id,
            'title' => '轮询成功视频',
            'subject' => '轮询成功视频',
            'status' => 'processing',
            'progress' => 40,
            'api_task_id' => 'video-task-002',
            'video_count' => 1,
            'request_payload' => [],
            'started_at' => now(),
        ]);

        (new PollVideoGenerationJob((int) $job->id))->handle(app(VideoGenerationClient::class));

        $job->refresh();
        $this->assertSame('success', (string) $job->status);
        $this->assertSame(100, (int) $job->progress);
        $this->assertSame(['https://video.example.test/tasks/video-task-002/final-1.mp4'], $job->videos);
        $this->assertSame(['https://video.example.test/tasks/video-task-002/combined-1.mp4'], $job->combined_videos);
        $this->assertSame('生成后的视频脚本', (string) data_get($job->result_payload, 'script'));
    }

    public function test_user_can_publish_generated_video_to_self_media_and_consume_by_platform_count(): void
    {
        [$admin, $site] = $this->provisionSubscribedAdmin('video_publish_owner', videoQuota: 3, crebeeQuota: 3);
        $video = $this->completedVideo($site, $admin, '自媒体发布视频');
        $agent = $this->agent();
        $douyin = $this->account($agent, $site, $admin, 'douyin', 'douyin-video-account', '抖音视频号');
        $bilibili = $this->account($agent, $site, $admin, 'bilibili', 'bilibili-video-account', 'B站视频号');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->from(route('admin.video-generations.show', ['videoGeneration' => (int) $video->id]))
            ->post(route('admin.video-generations.self-media.publish', ['videoGeneration' => (int) $video->id]), [
                'crebee_account_ids' => [(int) $douyin->id, (int) $bilibili->id],
            ])
            ->assertRedirect(route('admin.video-generations.index'));

        $job = CrebeePublishJob::query()->firstOrFail();
        $this->assertSame('video', (string) $job->content_type);
        $this->assertSame('video_generation', (string) $job->content_source_type);
        $this->assertSame('自媒体发布视频', (string) $job->title);
        $this->assertSame('video', (string) data_get($job->payload, 'contentType'));
        $this->assertSame('https://video.example.test/tasks/video-task-003/final-1.mp4', (string) data_get($job->payload, 'assets.0.url'));
        $this->assertSame('https://cdn.example.test/video-cover.jpg', (string) data_get($job->payload, 'assets.1.url'));
        $this->assertSame('video_generation', (string) data_get($job->payload, 'source.type'));
        $this->assertSame((int) $video->id, (int) data_get($job->payload, 'source.video_generation_job_id'));

        $this->assertSame(2, CrebeePublishJobItem::query()->count());
        $douyinItem = CrebeePublishJobItem::query()->where('platform', 'douyin')->firstOrFail();
        $this->assertArrayNotHasKey('music', (array) data_get($douyinItem->payload, 'params'));
        $this->assertArrayNotHasKey('collection', (array) data_get($douyinItem->payload, 'params'));
        $this->assertArrayNotHasKey('position', (array) data_get($douyinItem->payload, 'params'));
        $this->assertArrayNotHasKey('hotEvents', (array) data_get($douyinItem->payload, 'params'));
        $this->assertArrayNotHasKey('declare', (array) data_get($douyinItem->payload, 'params'));

        $bilibiliItem = CrebeePublishJobItem::query()->where('platform', 'bilibili')->firstOrFail();
        $this->assertSame('video', (string) data_get($bilibiliItem->payload, 'contentType'));
        $this->assertSame('', (string) data_get($bilibiliItem->payload, 'params.videoPath'));
        $this->assertSame('', (string) data_get($bilibiliItem->payload, 'params.coverPath'));
        $this->assertSame($video->firstVideoUrl(), (string) data_get($bilibiliItem->payload, 'params.source'));
        $this->assertSame('', (string) data_get($bilibiliItem->payload, 'params.verticalCoverPath', ''));

        $usage = AdminResourceUsage::query()
            ->where('admin_id', (int) $admin->id)
            ->where('site_id', (int) $site->id)
            ->where('resource_key', PlatformPlan::RESOURCE_CREBEE_PUBLISHES)
            ->firstOrFail();
        $this->assertSame(2, (int) $usage->used_amount);

        $this->withHeaders([
            'X-CreBee-Agent-Id' => (string) $agent->agent_uid,
            'X-CreBee-Agent-Secret' => 'agent-secret',
        ])->getJson('/api/v1/crebee-agent/jobs/next')
            ->assertOk()
            ->assertJsonPath('data.job.contentType', 'video')
            ->assertJsonPath('data.job.assets.0.key', 'video')
            ->assertJsonPath('data.job.tasks.0.contentType', 'video');
    }

    public function test_video_publish_requires_cover_image(): void
    {
        [$admin, $site] = $this->provisionSubscribedAdmin('video_publish_no_cover_owner', videoQuota: 3, crebeeQuota: 3);
        $video = $this->completedVideo($site, $admin, '无封面视频', coverImage: '');
        $agent = $this->agent();
        $douyin = $this->account($agent, $site, $admin, 'douyin', 'douyin-no-cover-account', '抖音视频号');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->from(route('admin.video-generations.show', ['videoGeneration' => (int) $video->id]))
            ->post(route('admin.video-generations.self-media.publish', ['videoGeneration' => (int) $video->id]), [
                'crebee_account_ids' => [(int) $douyin->id],
            ])
            ->assertRedirect(route('admin.video-generations.show', ['videoGeneration' => (int) $video->id]))
            ->assertSessionHasErrors('crebee_account_ids');

        $this->assertSame(0, CrebeePublishJob::query()->count());
        $this->assertSame(0, CrebeePublishJobItem::query()->count());
    }

    public function test_video_generation_detail_hides_keywords(): void
    {
        [$admin, $site] = $this->provisionSubscribedAdmin('video_detail_owner', videoQuota: 1, crebeeQuota: 1);
        $video = $this->completedVideo($site, $admin, 'Video with hidden keywords');
        $video->forceFill([
            'script' => 'Visible video script',
            'terms' => 'Hidden keyword term',
            'negative_terms' => 'Hidden negative keyword',
        ])->save();

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.video-generations.show', ['videoGeneration' => (int) $video->id]));

        $response->assertOk();
        $response->assertSee('Visible video script');
        $response->assertDontSee('Hidden keyword term');
        $response->assertDontSee('Hidden negative keyword');
        $response->assertDontSee('关键词');
        $response->assertDontSee('排除关键词');
    }

    public function test_user_downloads_generated_video_through_local_attachment_response(): void
    {
        Http::fake([
            'https://video.example.test/tasks/video-task-003/final-1.mp4' => Http::response('fake-video-bytes', 200, [
                'Content-Type' => 'video/mp4',
                'Content-Length' => '16',
            ]),
        ]);
        [$admin, $site] = $this->provisionSubscribedAdmin('video_download_owner', videoQuota: 1, crebeeQuota: 1);
        $video = $this->completedVideo($site, $admin, 'Downloadable video');

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.video-generations.download', ['videoGeneration' => (int) $video->id]));

        $response->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('.mp4', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('video/mp4', (string) $response->headers->get('Content-Type'));
        $this->assertSame('fake-video-bytes', $response->streamedContent());

        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://video.example.test/tasks/video-task-003/final-1.mp4');
    }

    /**
     * @return array{0: Admin, 1: Site}
     */
    private function provisionSubscribedAdmin(string $username, int $videoQuota, int $crebeeQuota): array
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
        foreach ([
            PlatformPlan::RESOURCE_VIDEO_GENERATIONS => $videoQuota,
            PlatformPlan::RESOURCE_CREBEE_PUBLISHES => $crebeeQuota,
        ] as $resourceKey => $quota) {
            $plan->entitlements()->create([
                'resource_key' => $resourceKey,
                'enabled' => true,
                'quota_value' => $quota,
                'quota_period' => 'cycle',
                'unit' => 'times',
                'meta' => [],
            ]);
        }

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

    private function completedVideo(Site $site, Admin $owner, string $title, string $coverImage = 'https://cdn.example.test/video-cover.jpg'): VideoGenerationJob
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
            'cover_image' => $coverImage,
            'status' => 'success',
            'progress' => 100,
            'api_task_id' => 'video-task-003',
            'request_payload' => [],
            'result_payload' => [],
            'videos' => ['https://video.example.test/tasks/video-task-003/final-1.mp4'],
            'combined_videos' => [],
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
        ]);
    }

    private function agent(): CrebeeAgent
    {
        return CrebeeAgent::query()->create([
            'name' => 'Local Bridge',
            'agent_uid' => 'agent-'.str()->random(8),
            'secret_hash' => Hash::make('agent-secret'),
            'status' => 'active',
            'last_seen_at' => now(),
            'crebee_status' => 'online',
            'version' => '0.1.0',
        ]);
    }

    private function account(
        CrebeeAgent $agent,
        Site $site,
        Admin $owner,
        string $platform,
        string $crebeeAccountId,
        string $name
    ): CrebeeAccount {
        return CrebeeAccount::query()->create([
            'agent_id' => (int) $agent->id,
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'platform' => $platform,
            'crebee_account_id' => $crebeeAccountId,
            'account_name' => $name,
            'avatar' => '',
            'status' => 'bound',
            'bound_at' => now(),
            'last_synced_at' => now(),
            'raw_account' => [],
        ]);
    }
}
