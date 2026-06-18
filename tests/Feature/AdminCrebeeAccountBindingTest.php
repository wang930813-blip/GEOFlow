<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\CrebeeAccount;
use App\Models\CrebeeAgent;
use App\Models\CrebeeBindRequest;
use App\Models\Site;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCrebeeAccountBindingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_super_admin_can_bind_synced_crebee_account_to_current_site_member(): void
    {
        $superAdmin = $this->admin('crebee_root', 'super_admin');
        $owner = $this->admin('crebee_direct_owner', 'direct_admin');
        $site = $this->site('CreBee Direct Site', $owner, 'direct');
        $agent = $this->agent();
        $account = $this->account($agent, 'weibo', 'weibo-account-001', '测试微博账号');

        $this->actingAs($superAdmin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.crebee-accounts.index'))
            ->assertOk()
            ->assertSee('自媒体账号绑定')
            ->assertSee('测试微博账号')
            ->assertSee('待绑定账号');

        $this->actingAs($superAdmin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.crebee-accounts.bind', $account), [
                'owner_admin_id' => (int) $owner->id,
            ])
            ->assertRedirect(route('admin.crebee-accounts.index'));

        $this->assertDatabaseHas('crebee_accounts', [
            'id' => (int) $account->id,
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'status' => 'bound',
        ]);

        $this->actingAs($owner, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.crebee-accounts.index'))
            ->assertOk()
            ->assertSee('测试微博账号')
            ->assertSee('已绑定')
            ->assertDontSee('待绑定账号');
    }

    public function test_site_user_only_sees_own_bound_crebee_accounts(): void
    {
        $agentAdmin = $this->admin('crebee_agent_owner', 'agent_admin');
        $firstUser = $this->admin('crebee_member_one', 'site_user', $agentAdmin);
        $secondUser = $this->admin('crebee_member_two', 'site_user', $agentAdmin);
        $site = $this->site('CreBee Agent Site', $agentAdmin, 'agent');
        $site->members()->attach((int) $firstUser->id, ['role' => 'member']);
        $site->members()->attach((int) $secondUser->id, ['role' => 'member']);
        $agent = $this->agent();

        $this->account($agent, 'douyin', 'douyin-account-one', '一号抖音', $site, $firstUser);
        $this->account($agent, 'douyin', 'douyin-account-two', '二号抖音', $site, $secondUser);

        $this->actingAs($firstUser, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.crebee-accounts.index'))
            ->assertOk()
            ->assertSee('一号抖音')
            ->assertDontSee('二号抖音')
            ->assertDontSee('待绑定账号');
    }

    public function test_platform_cards_show_logo_placeholder_and_bound_account_avatar(): void
    {
        $owner = $this->admin('crebee_card_owner', 'direct_admin');
        $site = $this->site('CreBee Card Site', $owner, 'direct');
        $agent = $this->agent();
        CrebeeAccount::query()->create([
            'agent_id' => (int) $agent->id,
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'platform' => 'douyin',
            'crebee_account_id' => 'douyin-card-account',
            'account_name' => '卡片抖音号',
            'avatar' => 'https://example.test/douyin-avatar.png',
            'status' => 'bound',
            'bound_at' => now(),
            'last_synced_at' => now(),
            'raw_account' => [],
        ]);

        $this->actingAs($owner, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.crebee-accounts.index'))
            ->assertOk()
            ->assertSee('data-platform-logo="assets/self-media-platforms/01.png"', false)
            ->assertSee('https://example.test/douyin-avatar.png', false)
            ->assertSee('卡片抖音号')
            ->assertSee('已绑定');
    }

    public function test_platform_cards_upgrade_legacy_http_avatar_urls_for_display(): void
    {
        $owner = $this->admin('crebee_http_avatar_owner', 'direct_admin');
        $site = $this->site('CreBee Http Avatar Site', $owner, 'direct');
        $agent = $this->agent();
        CrebeeAccount::query()->create([
            'agent_id' => (int) $agent->id,
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'platform' => 'bilibili',
            'crebee_account_id' => 'bilibili-http-avatar-account',
            'account_name' => '小伍大大',
            'avatar' => 'http://i0.hdslb.com/bfs/face/bc268311a7ca2f8694d2099f9fed2d4e1300e8ec.jpg',
            'status' => 'bound',
            'bound_at' => now(),
            'last_synced_at' => now(),
            'raw_account' => [],
        ]);

        $this->actingAs($owner, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.crebee-accounts.index'))
            ->assertOk()
            ->assertSee('https://i0.hdslb.com/bfs/face/bc268311a7ca2f8694d2099f9fed2d4e1300e8ec.jpg', false)
            ->assertDontSee('http://i0.hdslb.com/bfs/face/bc268311a7ca2f8694d2099f9fed2d4e1300e8ec.jpg', false);
    }

    public function test_platform_cards_render_provided_platform_logo_asset(): void
    {
        $owner = $this->admin('crebee_logo_owner', 'direct_admin');
        $site = $this->site('CreBee Logo Site', $owner, 'direct');

        $this->actingAs($owner, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.crebee-accounts.index'))
            ->assertOk()
            ->assertSee('src="'.asset('assets/self-media-platforms/01.png').'"', false)
            ->assertSee('src="'.asset('assets/self-media-platforms/05.png').'"', false)
            ->assertSee('data-platform-logo="assets/self-media-platforms/10.png"', false);
    }

    public function test_platform_cards_do_not_show_separate_official_account_platform(): void
    {
        $owner = $this->admin('crebee_no_official_owner', 'direct_admin');
        $site = $this->site('CreBee No Official Site', $owner, 'direct');

        $this->actingAs($owner, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.crebee-accounts.index'))
            ->assertOk()
            ->assertSee('公众号')
            ->assertDontSee('公众号官方');
    }

    public function test_agent_admin_can_unbind_current_site_account_but_not_other_site_account(): void
    {
        $agentAdmin = $this->admin('crebee_unbind_agent', 'agent_admin');
        $otherAgent = $this->admin('crebee_other_agent', 'agent_admin');
        $site = $this->site('CreBee Unbind Site', $agentAdmin, 'agent');
        $otherSite = $this->site('CreBee Other Site', $otherAgent, 'agent');
        $agent = $this->agent();
        $ownAccount = $this->account($agent, 'xiaohongshu', 'xhs-own', '当前站点小红书', $site, $agentAdmin);
        $otherAccount = $this->account($agent, 'xiaohongshu', 'xhs-other', '其他站点小红书', $otherSite, $otherAgent);

        $this->actingAs($agentAdmin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.crebee-accounts.unbind', $ownAccount))
            ->assertRedirect(route('admin.crebee-accounts.index'));

        $this->assertDatabaseHas('crebee_accounts', [
            'id' => (int) $ownAccount->id,
            'site_id' => null,
            'owner_admin_id' => null,
            'status' => 'available',
        ]);

        $this->actingAs($agentAdmin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.crebee-accounts.unbind', $otherAccount))
            ->assertNotFound();

        $this->assertDatabaseHas('crebee_accounts', [
            'id' => (int) $otherAccount->id,
            'site_id' => (int) $otherSite->id,
            'owner_admin_id' => (int) $otherAgent->id,
            'status' => 'bound',
        ]);
    }

    public function test_site_user_can_request_platform_binding_once(): void
    {
        $agentAdmin = $this->admin('crebee_request_agent', 'agent_admin');
        $siteUser = $this->admin('crebee_request_member', 'site_user', $agentAdmin);
        $site = $this->site('CreBee Request Site', $agentAdmin, 'agent');
        $site->members()->attach((int) $siteUser->id, ['role' => 'member']);

        $this->actingAs($siteUser, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.crebee-accounts.index'))
            ->assertOk()
            ->assertSee('选择要绑定的平台')
            ->assertSee('抖音')
            ->assertSee(route('admin.crebee-accounts.requests.store'), false);

        $this->actingAs($siteUser, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.crebee-accounts.requests.store'), [
                'platform' => 'douyin',
            ])
            ->assertRedirect(route('admin.crebee-accounts.index'));

        $this->assertDatabaseHas('crebee_bind_requests', [
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $siteUser->id,
            'platform' => 'douyin',
            'status' => 'pending',
        ]);

        $this->actingAs($siteUser, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.crebee-accounts.requests.store'), [
                'platform' => 'douyin',
            ])
            ->assertSessionHasErrors('platform');
    }

    public function test_agent_admin_can_mark_current_site_binding_request_processing(): void
    {
        $agentAdmin = $this->admin('crebee_request_operator', 'agent_admin');
        $siteUser = $this->admin('crebee_request_owner', 'site_user', $agentAdmin);
        $site = $this->site('CreBee Request Ops Site', $agentAdmin, 'agent');
        $site->members()->attach((int) $siteUser->id, ['role' => 'member']);
        $request = $this->bindRequest($site, $siteUser, 'xiaohongshu');

        $this->actingAs($agentAdmin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.crebee-accounts.index'))
            ->assertOk()
            ->assertSee('绑定申请')
            ->assertSee('小红书')
            ->assertSee('crebee_request_owner');

        $this->actingAs($agentAdmin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.crebee-accounts.requests.processing', $request))
            ->assertRedirect(route('admin.crebee-accounts.index'));

        $this->assertDatabaseHas('crebee_bind_requests', [
            'id' => (int) $request->id,
            'operator_admin_id' => (int) $agentAdmin->id,
            'status' => 'processing',
        ]);
    }

    public function test_agent_admin_cannot_operate_other_site_binding_request(): void
    {
        $agentAdmin = $this->admin('crebee_request_agent_a', 'agent_admin');
        $otherAgent = $this->admin('crebee_request_agent_b', 'agent_admin');
        $site = $this->site('CreBee Request Site A', $agentAdmin, 'agent');
        $otherSite = $this->site('CreBee Request Site B', $otherAgent, 'agent');
        $request = $this->bindRequest($otherSite, $otherAgent, 'weibo');

        $this->actingAs($agentAdmin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.crebee-accounts.requests.processing', $request))
            ->assertNotFound();

        $this->assertDatabaseHas('crebee_bind_requests', [
            'id' => (int) $request->id,
            'status' => 'pending',
            'operator_admin_id' => null,
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
            'name' => 'Local CreBee Bridge',
            'agent_uid' => 'local-agent-'.str()->random(8),
            'secret_hash' => bcrypt('secret'),
            'status' => 'active',
            'last_seen_at' => now(),
            'crebee_status' => 'online',
            'version' => '0.1.0',
        ]);
    }

    private function account(
        CrebeeAgent $agent,
        string $platform,
        string $crebeeAccountId,
        string $name,
        ?Site $site = null,
        ?Admin $owner = null
    ): CrebeeAccount {
        return CrebeeAccount::query()->create([
            'agent_id' => (int) $agent->id,
            'site_id' => $site?->id,
            'owner_admin_id' => $owner?->id,
            'platform' => $platform,
            'crebee_account_id' => $crebeeAccountId,
            'account_name' => $name,
            'avatar' => '',
            'status' => $owner instanceof Admin ? 'bound' : 'available',
            'bound_at' => $owner instanceof Admin ? now() : null,
            'last_synced_at' => now(),
            'raw_account' => [],
        ]);
    }

    private function bindRequest(Site $site, Admin $owner, string $platform): CrebeeBindRequest
    {
        return CrebeeBindRequest::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'platform' => $platform,
            'status' => 'pending',
            'requested_at' => now(),
            'expired_at' => now()->addHours(2),
            'meta' => [],
        ]);
    }
}
