<?php

namespace Tests\Feature;

use App\Models\CrebeeAgent;
use App\Models\CrebeeAccount;
use App\Models\CrebeeBindRequest;
use App\Models\CrebeePublishJob;
use App\Models\CrebeePublishJobItem;
use App\Models\Admin;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CrebeeAgentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_crebee_agent_heartbeat_requires_agent_credentials(): void
    {
        $this->postJson('/api/v1/crebee-agent/heartbeat', [
            'version' => '0.1.0',
        ])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'crebee_agent_unauthorized');
    }

    public function test_crebee_agent_can_send_heartbeat_with_valid_credentials(): void
    {
        $agent = CrebeeAgent::query()->create([
            'name' => 'Office Bridge',
            'agent_uid' => 'agent-office-1',
            'secret_hash' => Hash::make('agent-secret'),
            'status' => 'active',
        ]);

        $this->withHeaders([
            'X-CreBee-Agent-Id' => 'agent-office-1',
            'X-CreBee-Agent-Secret' => 'agent-secret',
        ])->postJson('/api/v1/crebee-agent/heartbeat', [
            'version' => '0.1.0',
            'crebee_status' => 'online',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.agent_id', (int) $agent->id)
            ->assertJsonPath('data.status', 'ok');

        $this->assertDatabaseHas('crebee_agents', [
            'id' => (int) $agent->id,
            'status' => 'active',
            'version' => '0.1.0',
            'crebee_status' => 'online',
        ]);
        $this->assertNotNull($agent->fresh()->last_seen_at);
    }

    public function test_crebee_agent_can_sync_local_accounts(): void
    {
        $agent = $this->createAgent();

        $this->agentJson($agent)->postJson('/api/v1/crebee-agent/accounts/sync', [
            'accounts' => [
                [
                    'account_id' => 'douyin-account-001',
                    'account_platform' => 'douyin',
                    'nickname' => '测试抖音号',
                    'avatar' => 'https://example.test/avatar.png',
                    'raw' => ['account_id' => 'douyin-account-001', 'account_platform' => 'douyin'],
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.synced', 1);

        $this->assertDatabaseHas('crebee_accounts', [
            'agent_id' => (int) $agent->id,
            'platform' => 'douyin',
            'crebee_account_id' => 'douyin-account-001',
            'account_name' => '测试抖音号',
            'status' => 'available',
        ]);
    }

    public function test_crebee_agent_upgrades_http_avatar_urls_to_https(): void
    {
        Http::fake([
            'https://i0.hdslb.com/*' => Http::response('', 404),
        ]);
        $agent = $this->createAgent();

        $this->agentJson($agent)->postJson('/api/v1/crebee-agent/accounts/sync', [
            'accounts' => [
                [
                    'account_id' => 'bilibili-account-001',
                    'account_platform' => 'bilibili',
                    'nickname' => '测试B站号',
                    'avatar' => 'http://i0.hdslb.com/bfs/face/bc268311a7ca2f8694d2099f9fed2d4e1300e8ec.jpg',
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('crebee_accounts', [
            'agent_id' => (int) $agent->id,
            'platform' => 'bilibili',
            'crebee_account_id' => 'bilibili-account-001',
            'avatar' => 'https://i0.hdslb.com/bfs/face/bc268311a7ca2f8694d2099f9fed2d4e1300e8ec.jpg',
        ]);
    }

    public function test_crebee_agent_caches_remote_account_avatar_locally(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://tvax4.sinaimg.cn/*' => Http::response('avatar-bytes', 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);
        $agent = $this->createAgent();
        $avatar = 'https://tvax4.sinaimg.cn/crop.0.0.1080.1080.50/006jfjdUly8g4pdxxq5dxj30u00u0n03.jpg?KID=imgbed,tva&Expires=1781771125&ssig=NV4NUATriG';

        $this->agentJson($agent)->postJson('/api/v1/crebee-agent/accounts/sync', [
            'accounts' => [
                [
                    'account_id' => 'weibo-account-001',
                    'account_platform' => 'weibo',
                    'nickname' => '微博账号',
                    'avatar' => $avatar,
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $account = CrebeeAccount::query()->where('crebee_account_id', 'weibo-account-001')->firstOrFail();

        $this->assertStringStartsWith('/storage/crebee-avatars/', (string) $account->avatar);
        $this->assertSame($avatar, (string) data_get($account->raw_account, 'avatar_original'));
        Storage::disk('public')->assertExists(str_replace('/storage/', '', (string) $account->avatar));
    }

    public function test_crebee_agent_can_sync_empty_local_account_list(): void
    {
        $agent = $this->createAgent();

        $this->agentJson($agent)->postJson('/api/v1/crebee-agent/accounts/sync', [
            'accounts' => [],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.synced', 0);
    }

    public function test_crebee_agent_marks_unbound_accounts_missing_from_latest_sync_as_unavailable(): void
    {
        $agent = $this->createAgent();
        CrebeeAccount::query()->create([
            'agent_id' => (int) $agent->id,
            'platform' => 'weibo',
            'crebee_account_id' => 'weibo-old-account',
            'account_name' => '旧微博号',
            'status' => 'available',
            'last_synced_at' => now()->subMinutes(10),
        ]);

        $this->agentJson($agent)->postJson('/api/v1/crebee-agent/accounts/sync', [
            'accounts' => [
                [
                    'account_id' => 'douyin-current-account',
                    'account_platform' => 'douyin',
                    'nickname' => '当前抖音号',
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.synced', 1);

        $this->assertDatabaseHas('crebee_accounts', [
            'agent_id' => (int) $agent->id,
            'platform' => 'weibo',
            'crebee_account_id' => 'weibo-old-account',
            'status' => 'unavailable',
        ]);
        $this->assertDatabaseHas('crebee_accounts', [
            'agent_id' => (int) $agent->id,
            'platform' => 'douyin',
            'crebee_account_id' => 'douyin-current-account',
            'status' => 'available',
        ]);
    }

    public function test_crebee_agent_does_not_mark_bound_accounts_unavailable_when_missing_from_latest_sync(): void
    {
        $agent = $this->createAgent();
        $owner = $this->admin('crebee_bound_owner', 'direct_admin');
        $site = $this->site('CreBee Bound Site', $owner, 'direct');
        CrebeeAccount::query()->create([
            'agent_id' => (int) $agent->id,
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'platform' => 'bilibili',
            'crebee_account_id' => 'bilibili-bound-account',
            'account_name' => '已绑定B站号',
            'status' => 'bound',
            'bound_at' => now()->subMinutes(5),
            'last_synced_at' => now()->subMinutes(10),
        ]);

        $this->agentJson($agent)->postJson('/api/v1/crebee-agent/accounts/sync', [
            'accounts' => [
                [
                    'account_id' => 'douyin-current-account',
                    'account_platform' => 'douyin',
                    'nickname' => '当前抖音号',
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.synced', 1);

        $this->assertDatabaseHas('crebee_accounts', [
            'agent_id' => (int) $agent->id,
            'platform' => 'bilibili',
            'crebee_account_id' => 'bilibili-bound-account',
            'status' => 'bound',
        ]);
    }

    public function test_crebee_agent_sync_auto_binds_new_account_to_single_active_request(): void
    {
        $agent = $this->createAgent();
        $owner = $this->admin('crebee_auto_bind_owner', 'direct_admin');
        $site = $this->site('CreBee Auto Bind Site', $owner, 'direct');
        $request = CrebeeBindRequest::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'platform' => 'douyin',
            'status' => 'processing',
            'requested_at' => now(),
            'expired_at' => now()->addHours(2),
            'meta' => [],
        ]);

        $this->agentJson($agent)->postJson('/api/v1/crebee-agent/accounts/sync', [
            'accounts' => [
                [
                    'account_id' => 'douyin-new-account',
                    'account_platform' => 'douyin',
                    'nickname' => '扫码新抖音号',
                    'raw' => ['source' => 'local'],
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.synced', 1)
            ->assertJsonPath('data.auto_bound', 1);

        $this->assertDatabaseHas('crebee_accounts', [
            'agent_id' => (int) $agent->id,
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'platform' => 'douyin',
            'crebee_account_id' => 'douyin-new-account',
            'account_name' => '扫码新抖音号',
            'status' => 'bound',
        ]);
        $this->assertDatabaseHas('crebee_bind_requests', [
            'id' => (int) $request->id,
            'agent_id' => (int) $agent->id,
            'status' => 'confirmed',
        ]);
        $this->assertNotNull($request->fresh()->confirmed_at);
    }

    public function test_crebee_agent_sync_auto_binds_existing_available_account_to_single_active_request(): void
    {
        $agent = $this->createAgent();
        $owner = $this->admin('crebee_existing_bind_owner', 'direct_admin');
        $site = $this->site('CreBee Existing Bind Site', $owner, 'direct');
        $account = CrebeeAccount::query()->create([
            'agent_id' => (int) $agent->id,
            'platform' => 'bilibili',
            'crebee_account_id' => 'bilibili-existing-account',
            'account_name' => '待绑定B站号',
            'status' => 'available',
            'last_synced_at' => now()->subMinutes(10),
        ]);
        $request = CrebeeBindRequest::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'platform' => 'bilibili',
            'status' => 'pending',
            'requested_at' => now(),
            'expired_at' => now()->addHours(2),
            'meta' => [],
        ]);

        $this->agentJson($agent)->postJson('/api/v1/crebee-agent/accounts/sync', [
            'accounts' => [
                [
                    'account_id' => 'bilibili-existing-account',
                    'account_platform' => 'bilibili',
                    'nickname' => '扫码后的B站号',
                    'raw' => ['source' => 'local'],
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.synced', 1)
            ->assertJsonPath('data.auto_bound', 1);

        $this->assertDatabaseHas('crebee_accounts', [
            'id' => (int) $account->id,
            'agent_id' => (int) $agent->id,
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'platform' => 'bilibili',
            'crebee_account_id' => 'bilibili-existing-account',
            'account_name' => '扫码后的B站号',
            'status' => 'bound',
        ]);
        $this->assertDatabaseHas('crebee_bind_requests', [
            'id' => (int) $request->id,
            'agent_id' => (int) $agent->id,
            'status' => 'confirmed',
        ]);
        $this->assertNotNull($request->fresh()->confirmed_at);
    }

    public function test_crebee_agent_sync_does_not_auto_bind_when_multiple_active_requests_share_platform(): void
    {
        $agent = $this->createAgent();
        $firstOwner = $this->admin('crebee_multi_owner_one', 'direct_admin');
        $secondOwner = $this->admin('crebee_multi_owner_two', 'direct_admin');
        $firstSite = $this->site('CreBee Multi Site One', $firstOwner, 'direct');
        $secondSite = $this->site('CreBee Multi Site Two', $secondOwner, 'direct');
        CrebeeBindRequest::query()->create([
            'site_id' => (int) $firstSite->id,
            'owner_admin_id' => (int) $firstOwner->id,
            'platform' => 'xiaohongshu',
            'status' => 'pending',
            'requested_at' => now(),
            'expired_at' => now()->addHours(2),
            'meta' => [],
        ]);
        CrebeeBindRequest::query()->create([
            'site_id' => (int) $secondSite->id,
            'owner_admin_id' => (int) $secondOwner->id,
            'platform' => 'xiaohongshu',
            'status' => 'processing',
            'requested_at' => now(),
            'expired_at' => now()->addHours(2),
            'meta' => [],
        ]);

        $this->agentJson($agent)->postJson('/api/v1/crebee-agent/accounts/sync', [
            'accounts' => [
                [
                    'account_id' => 'xhs-new-account',
                    'account_platform' => 'xiaohongshu',
                    'nickname' => '冲突小红书号',
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.synced', 1)
            ->assertJsonPath('data.auto_bound', 0);

        $this->assertDatabaseHas('crebee_accounts', [
            'agent_id' => (int) $agent->id,
            'site_id' => null,
            'owner_admin_id' => null,
            'platform' => 'xiaohongshu',
            'crebee_account_id' => 'xhs-new-account',
            'status' => 'available',
        ]);
    }

    public function test_crebee_agent_can_claim_next_assigned_publish_job(): void
    {
        $agent = $this->createAgent();
        $account = CrebeeAccount::query()->create([
            'agent_id' => (int) $agent->id,
            'platform' => 'douyin',
            'crebee_account_id' => 'douyin-account-001',
            'account_name' => '测试抖音号',
            'status' => 'bound',
        ]);
        $job = CrebeePublishJob::query()->create([
            'agent_id' => (int) $agent->id,
            'content_type' => 'article',
            'title' => '测试文章',
            'status' => 'queued',
            'payload' => [
                'contentType' => 'article',
                'commonForm' => ['title' => '测试文章', 'content' => '<p>正文</p>', 'covers' => []],
            ],
        ]);
        $item = CrebeePublishJobItem::query()->create([
            'job_id' => (int) $job->id,
            'crebee_account_id' => (int) $account->id,
            'platform' => 'douyin',
            'crebee_task_id' => 'task-douyin-001',
            'status' => 'queued',
            'payload' => [
                'taskId' => 'task-douyin-001',
                'accountId' => 'douyin-account-001',
                'platform' => 'douyin',
                'contentType' => 'article',
                'params' => ['title' => '测试文章', 'content' => '<p>正文</p>', 'covers' => [], 'taskId' => 'task-douyin-001'],
            ],
        ]);

        $this->agentJson($agent)->getJson('/api/v1/crebee-agent/jobs/next')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.job.id', (int) $job->id)
            ->assertJsonPath('data.job.contentType', 'article')
            ->assertJsonPath('data.job.tasks.0.taskId', 'task-douyin-001')
            ->assertJsonPath('data.job.tasks.0.accountId', 'douyin-account-001');

        $this->assertDatabaseHas('crebee_publish_jobs', [
            'id' => (int) $job->id,
            'status' => 'dispatching',
        ]);
        $this->assertDatabaseHas('crebee_publish_job_items', [
            'id' => (int) $item->id,
            'status' => 'dispatching',
        ]);
    }

    public function test_crebee_agent_can_report_publish_events_and_finish_job(): void
    {
        $agent = $this->createAgent();
        $account = CrebeeAccount::query()->create([
            'agent_id' => (int) $agent->id,
            'platform' => 'douyin',
            'crebee_account_id' => 'douyin-account-001',
            'account_name' => '测试抖音号',
            'status' => 'bound',
        ]);
        $job = CrebeePublishJob::query()->create([
            'agent_id' => (int) $agent->id,
            'content_type' => 'article',
            'title' => '测试文章',
            'status' => 'submitted',
            'payload' => ['contentType' => 'article'],
        ]);
        $item = CrebeePublishJobItem::query()->create([
            'job_id' => (int) $job->id,
            'crebee_account_id' => (int) $account->id,
            'platform' => 'douyin',
            'crebee_task_id' => 'task-douyin-001',
            'status' => 'submitted',
        ]);

        $this->agentJson($agent)->postJson('/api/v1/crebee-agent/jobs/'.$job->id.'/events', [
            'events' => [
                [
                    'taskId' => 'task-douyin-001',
                    'type' => 'publishing',
                    'progress' => 55,
                    'message' => '正在发布',
                    'raw' => ['taskId' => 'task-douyin-001', 'type' => 'publishing'],
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.recorded', 1);

        $this->assertDatabaseHas('crebee_publish_job_items', [
            'id' => (int) $item->id,
            'status' => 'publishing',
            'progress' => 55,
            'message' => '正在发布',
        ]);
        $this->assertDatabaseHas('crebee_publish_events', [
            'crebee_task_id' => 'task-douyin-001',
            'event_type' => 'publishing',
            'progress' => 55,
        ]);

        $this->agentJson($agent)->postJson('/api/v1/crebee-agent/jobs/'.$job->id.'/finished', [
            'items' => [
                [
                    'taskId' => 'task-douyin-001',
                    'status' => 'success',
                    'published_url' => 'https://example.test/published/1',
                    'raw' => ['status' => 'success'],
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'success');

        $this->assertDatabaseHas('crebee_publish_jobs', [
            'id' => (int) $job->id,
            'status' => 'success',
        ]);
        $this->assertDatabaseHas('crebee_publish_job_items', [
            'id' => (int) $item->id,
            'status' => 'success',
            'published_url' => 'https://example.test/published/1',
        ]);
    }

    public function test_crebee_agent_failed_callback_accepts_long_error_messages(): void
    {
        $agent = $this->createAgent();
        $account = CrebeeAccount::query()->create([
            'agent_id' => (int) $agent->id,
            'platform' => 'bilibili',
            'crebee_account_id' => 'bilibili-account-001',
            'account_name' => '测试B站号',
            'status' => 'bound',
        ]);
        $job = CrebeePublishJob::query()->create([
            'agent_id' => (int) $agent->id,
            'content_type' => 'video',
            'title' => '测试视频',
            'status' => 'dispatching',
            'payload' => ['contentType' => 'video'],
        ]);
        $item = CrebeePublishJobItem::query()->create([
            'job_id' => (int) $job->id,
            'crebee_account_id' => (int) $account->id,
            'platform' => 'bilibili',
            'crebee_task_id' => 'task-bilibili-001',
            'status' => 'dispatching',
        ]);
        $message = str_repeat('发布失败信息', 300);

        $this->agentJson($agent)->postJson('/api/v1/crebee-agent/jobs/'.$job->id.'/failed', [
            'message' => $message,
            'raw' => ['message' => $message],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'failed');

        $this->assertDatabaseHas('crebee_publish_jobs', [
            'id' => (int) $job->id,
            'status' => 'failed',
        ]);
        $this->assertSame(mb_substr($message, 0, 2000), (string) $job->fresh()->failure_reason);

        $freshItem = $item->fresh();
        $this->assertSame('failed', (string) $freshItem->status);
        $this->assertSame(500, mb_strlen((string) $freshItem->message));
    }

    public function test_artisan_command_can_create_crebee_agent_credentials(): void
    {
        $this->artisan('crebee:agent-create', [
            'agent_uid' => 'agent-office-2',
            '--name' => 'Office Bridge Two',
            '--secret' => 'known-agent-secret',
        ])->assertExitCode(0);

        $agent = CrebeeAgent::query()->where('agent_uid', 'agent-office-2')->firstOrFail();

        $this->assertSame('Office Bridge Two', (string) $agent->name);
        $this->assertSame('active', (string) $agent->status);
        $this->assertTrue(Hash::check('known-agent-secret', (string) $agent->secret_hash));
    }

    private function createAgent(): CrebeeAgent
    {
        return CrebeeAgent::query()->create([
            'name' => 'Office Bridge',
            'agent_uid' => 'agent-office-1',
            'secret_hash' => Hash::make('agent-secret'),
            'status' => 'active',
        ]);
    }

    private function agentJson(CrebeeAgent $agent): self
    {
        return $this->withHeaders([
            'X-CreBee-Agent-Id' => (string) $agent->agent_uid,
            'X-CreBee-Agent-Secret' => 'agent-secret',
        ]);
    }

    private function admin(string $username, string $role): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => $username,
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function site(string $name, Admin $owner, string $mode): Site
    {
        $site = Site::query()->create([
            'owner_admin_id' => (int) $owner->id,
            'agent_admin_id' => $mode === 'agent' ? (int) $owner->id : null,
            'name' => $name,
            'status' => 'active',
            'customer_mode' => $mode,
        ]);
        $site->members()->attach((int) $owner->id, ['role' => 'owner']);

        return $site;
    }
}
