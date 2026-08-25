<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\CrebeeAccount;
use App\Models\CrebeeAgent;
use App\Models\CrebeePublishJob;
use App\Models\CrebeePublishJobItem;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminCrebeePublishRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_own_self_media_publish_records(): void
    {
        $owner = $this->admin('crebee_publish_owner', 'direct_admin');
        $otherOwner = $this->admin('crebee_publish_other', 'direct_admin');
        $site = $this->site('Self Media Publish Site', $owner, 'direct');
        $agent = $this->agent();
        $account = $this->account($agent, $site, $owner, 'bilibili', 'bilibili-account-001', '小伍大大', '/storage/crebee-avatars/bilibili-account-001.jpg');
        $otherAccount = $this->account($agent, $site, $otherOwner, 'bilibili', 'bilibili-account-002', '其他账号', '');

        $this->publishJob($agent, $site, $owner, $account, '测试文章', 'success', 'https://www.bilibili.com/read/cv123');
        $this->publishJob($agent, $site, $otherOwner, $otherAccount, '其他文章', 'success', 'https://www.bilibili.com/read/cv999');

        $this->actingAs($owner, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.crebee-publish-records.index'))
            ->assertOk()
            ->assertSee('自媒体发布记录')
            ->assertSee('测试文章')
            ->assertSee('小伍大大')
            ->assertSee('B站')
            ->assertSee('src="'.asset('assets/self-media-platforms/05.png').'"', false)
            ->assertSee('src="/storage/crebee-avatars/bilibili-account-001.jpg"', false)
            ->assertSee('https://www.bilibili.com/read/cv123', false)
            ->assertDontSee('其他文章');
    }

    public function test_agent_admin_can_view_current_site_members_self_media_publish_records(): void
    {
        $agentAdmin = $this->admin('crebee_publish_agent', 'agent_admin');
        $siteUser = $this->admin('crebee_publish_member', 'site_user', $agentAdmin);
        $site = $this->site('Agent Publish Site', $agentAdmin, 'agent');
        $site->members()->attach((int) $siteUser->id, ['role' => 'member']);
        $agent = $this->agent();
        $account = $this->account($agent, $site, $siteUser, 'weibo', 'weibo-account-001', '微博账号', '/storage/crebee-avatars/weibo-account-001.jpg');

        $this->publishJob($agent, $site, $siteUser, $account, '成员发布文章', 'submitted', '');

        $this->actingAs($agentAdmin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.crebee-publish-records.index'))
            ->assertOk()
            ->assertSee('成员发布文章')
            ->assertSee('crebee_publish_member')
            ->assertSee('微博')
            ->assertSee('待结果');
    }

    public function test_super_admin_can_view_all_self_media_publish_records_without_site_scope(): void
    {
        $superAdmin = $this->admin('crebee_publish_super', 'super_admin');
        $firstOwner = $this->admin('crebee_publish_first_owner', 'direct_admin');
        $secondOwner = $this->admin('crebee_publish_second_owner', 'direct_admin');
        $firstSite = $this->site('First Publish Site', $firstOwner, 'direct');
        $secondSite = $this->site('Second Publish Site', $secondOwner, 'direct');
        $agent = $this->agent();
        $firstAccount = $this->account($agent, $firstSite, $firstOwner, 'douyin', 'douyin-account-001', 'First Account', '');
        $secondAccount = $this->account($agent, $secondSite, $secondOwner, 'bilibili', 'bilibili-account-001', 'Second Account', '');

        $this->publishJob($agent, $firstSite, $firstOwner, $firstAccount, 'First Site Published Article', 'success', 'https://example.test/first');
        $this->publishJob($agent, $secondSite, $secondOwner, $secondAccount, 'Second Site Published Article', 'success', 'https://example.test/second');

        $this->actingAs($superAdmin, 'admin')
            ->withSession(['current_site_id' => (int) $firstSite->id])
            ->get(route('admin.crebee-publish-records.index'))
            ->assertOk()
            ->assertSee('First Site Published Article')
            ->assertSee('Second Site Published Article')
            ->assertSee('全部站点');
    }

    public function test_self_media_publish_records_use_admin_pagination_size(): void
    {
        config(['geoflow.admin_items_per_page' => 2]);

        $superAdmin = $this->admin('crebee_publish_page_super', 'super_admin');
        $owner = $this->admin('crebee_publish_page_owner', 'direct_admin');
        $site = $this->site('Paginated Publish Site', $owner, 'direct');
        $agent = $this->agent();
        $account = $this->account($agent, $site, $owner, 'douyin', 'douyin-page-account-001', 'Page Account', '');

        $this->publishJob($agent, $site, $owner, $account, 'Oldest Published Article', 'success', 'https://example.test/oldest');
        $this->publishJob($agent, $site, $owner, $account, 'Middle Published Article', 'success', 'https://example.test/middle');
        $this->publishJob($agent, $site, $owner, $account, 'Newest Published Article', 'success', 'https://example.test/newest');

        $this->actingAs($superAdmin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.crebee-publish-records.index'))
            ->assertOk()
            ->assertSee('Newest Published Article')
            ->assertSee('Middle Published Article')
            ->assertDontSee('Oldest Published Article')
            ->assertSee('page=2', false);

        $this->actingAs($superAdmin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.crebee-publish-records.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('Oldest Published Article')
            ->assertDontSee('Newest Published Article');
    }

    private function publishJob(
        CrebeeAgent $agent,
        Site $site,
        Admin $owner,
        CrebeeAccount $account,
        string $title,
        string $itemStatus,
        string $publishedUrl
    ): void {
        $job = CrebeePublishJob::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'agent_id' => (int) $agent->id,
            'content_type' => 'article',
            'title' => $title,
            'content_source_type' => 'article',
            'status' => $itemStatus === 'success' ? 'success' : 'submitted',
            'submitted_at' => now(),
            'finished_at' => $itemStatus === 'success' ? now() : null,
            'payload' => ['source' => ['type' => 'article']],
            'raw_response' => [],
        ]);

        CrebeePublishJobItem::query()->create([
            'job_id' => (int) $job->id,
            'crebee_account_id' => (int) $account->id,
            'platform' => (string) $account->platform,
            'crebee_task_id' => (string) $account->platform.'-task-001',
            'status' => $itemStatus,
            'progress' => $itemStatus === 'success' ? 100 : 0,
            'published_url' => $publishedUrl,
            'published_at' => $itemStatus === 'success' ? now() : null,
            'payload' => [],
            'raw_response' => [],
        ]);
    }

    private function admin(string $username, string $role, ?Admin $creator = null): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => $username,
            'role' => $role,
            'status' => 'active',
            'created_by' => $creator?->id,
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
        string $name,
        string $avatar
    ): CrebeeAccount {
        return CrebeeAccount::query()->create([
            'agent_id' => (int) $agent->id,
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'platform' => $platform,
            'crebee_account_id' => $crebeeAccountId,
            'account_name' => $name,
            'avatar' => $avatar,
            'status' => 'bound',
            'bound_at' => now(),
            'last_synced_at' => now(),
            'raw_account' => [],
        ]);
    }
}
