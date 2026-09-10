<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Prompt;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUsersManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_see_standard_admin_edit_and_delete_actions(): void
    {
        $superAdmin = $this->createAdmin('root_admin', 'super_admin');
        $standardAdmin = $this->createAdmin('editor_admin', 'admin');

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.admin-users.index'))
            ->assertOk()
            ->assertSee(__('admin.button.edit'))
            ->assertSee(__('admin.button.delete'))
            ->assertSee(route('admin.plan-usages.index', ['keyword' => $standardAdmin->username]), false)
            ->assertSee(route('admin.admin-users.delete', ['adminId' => $standardAdmin->id]), false);
    }

    public function test_current_super_admin_can_see_own_edit_action_but_not_delete_action(): void
    {
        $superAdmin = $this->createAdmin('root_admin', 'super_admin');

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.admin-users.index'))
            ->assertOk()
            ->assertSee(__('admin.button.edit'))
            ->assertDontSee(route('admin.admin-users.delete', ['adminId' => $superAdmin->id]), false);
    }

    public function test_current_super_admin_can_update_own_profile_and_password_without_disabling_self(): void
    {
        $superAdmin = $this->createAdmin('root_admin', 'super_admin');

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.admin-users.update', ['adminId' => $superAdmin->id]), [
                'username' => 'root_owner',
                'display_name' => 'Root Owner',
                'email' => 'root-owner@example.com',
                'status' => 'inactive',
                'password' => 'new-root-secret-123',
                'confirm_password' => 'new-root-secret-123',
            ])
            ->assertRedirect(route('admin.admin-users.index'));

        $superAdmin->refresh();

        $this->assertSame('root_owner', $superAdmin->username);
        $this->assertSame('Root Owner', $superAdmin->display_name);
        $this->assertSame('root-owner@example.com', $superAdmin->email);
        $this->assertSame('active', $superAdmin->status);
        $this->assertTrue(Hash::check('new-root-secret-123', $superAdmin->password));
    }

    public function test_super_admin_can_update_standard_admin_profile_and_password(): void
    {
        $superAdmin = $this->createAdmin('root_admin', 'super_admin');
        $standardAdmin = $this->createAdmin('editor_admin', 'admin');

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.admin-users.update', ['adminId' => $standardAdmin->id]), [
                'username' => 'editor_ops',
                'display_name' => 'Editor Ops',
                'email' => 'editor-ops@example.com',
                'status' => 'inactive',
                'password' => 'new-secret-123',
                'confirm_password' => 'new-secret-123',
            ])
            ->assertRedirect(route('admin.admin-users.index'));

        $standardAdmin->refresh();

        $this->assertSame('editor_ops', $standardAdmin->username);
        $this->assertSame('Editor Ops', $standardAdmin->display_name);
        $this->assertSame('editor-ops@example.com', $standardAdmin->email);
        $this->assertSame('inactive', $standardAdmin->status);
        $this->assertTrue(Hash::check('new-secret-123', $standardAdmin->password));
    }

    public function test_super_admin_soft_deletes_standard_admin(): void
    {
        $superAdmin = $this->createAdmin('root_admin', 'super_admin');
        $standardAdmin = $this->createAdmin('editor_admin', 'admin');

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.admin-users.delete', ['adminId' => $standardAdmin->id]))
            ->assertRedirect(route('admin.admin-users.index'));

        $this->assertSoftDeleted('admins', [
            'id' => $standardAdmin->id,
        ]);
        $this->assertNull(Admin::query()->find($standardAdmin->id));
        $this->assertNotNull(Admin::withTrashed()->find($standardAdmin->id));
    }

    public function test_super_admin_can_reuse_username_after_admin_is_soft_deleted(): void
    {
        $superAdmin = $this->createAdmin('reuse_root_admin', 'super_admin');
        $standardAdmin = $this->createAdmin('reuse_editor_admin', 'admin', $superAdmin);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.admin-users.delete', ['adminId' => $standardAdmin->id]))
            ->assertRedirect(route('admin.admin-users.index'));

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.admin-users.store'), [
                'username' => 'reuse_editor_admin',
                'display_name' => 'Reused Editor',
                'email' => 'reused-editor@example.com',
                'role' => 'admin',
                'password' => 'secret-123',
                'confirm_password' => 'secret-123',
            ])
            ->assertRedirect(route('admin.admin-users.index'))
            ->assertSessionHasNoErrors();

        $newAdmin = Admin::query()->where('username', 'reuse_editor_admin')->first();

        $this->assertNotNull($newAdmin);
        $this->assertNotSame((int) $standardAdmin->id, (int) $newAdmin->id);
        $this->assertStringStartsWith(
            'deleted_'.$standardAdmin->id.'_',
            (string) Admin::withTrashed()->findOrFail($standardAdmin->id)->username
        );
    }

    public function test_deleting_direct_user_soft_deletes_owned_sites_and_cancels_active_subscriptions(): void
    {
        $superAdmin = $this->createAdmin('delete_direct_root', 'super_admin');
        $direct = $this->createAdmin('delete_direct_owner', 'direct_admin', $superAdmin);
        $site = Site::query()->create([
            'owner_admin_id' => (int) $direct->id,
            'name' => 'Direct Owned Site',
            'status' => 'active',
            'customer_mode' => 'direct',
        ]);
        $site->members()->attach((int) $direct->id, ['role' => 'owner']);
        $this->openTestingPlanForSite($site, $direct);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.admin-users.delete', ['adminId' => $direct->id]))
            ->assertRedirect(route('admin.admin-users.index'));

        $this->assertSoftDeleted('admins', ['id' => (int) $direct->id]);
        $this->assertSoftDeleted('sites', ['id' => (int) $site->id]);
        $this->assertDatabaseMissing('site_members', [
            'site_id' => (int) $site->id,
            'admin_id' => (int) $direct->id,
        ]);
        $this->assertDatabaseHas('admin_plan_subscriptions', [
            'admin_id' => (int) $direct->id,
            'site_id' => (int) $site->id,
            'status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('site_plan_subscriptions', [
            'site_id' => (int) $site->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_super_admin_cannot_delete_agent_that_still_has_child_users(): void
    {
        $superAdmin = $this->createAdmin('delete_agent_root', 'super_admin');
        $agent = $this->createAdmin('delete_agent_owner', 'agent_admin', $superAdmin);
        $child = $this->createAdmin('delete_agent_child', 'site_user', $agent);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.admin-users.delete', ['adminId' => $agent->id]))
            ->assertSessionHasErrors();

        $this->assertNotSoftDeleted('admins', ['id' => (int) $agent->id]);
        $this->assertNotSoftDeleted('admins', ['id' => (int) $child->id]);
    }

    public function test_admin_user_list_shows_account_ownership_for_agent_direct_and_member_users(): void
    {
        $superAdmin = $this->createAdmin('owner_root_admin', 'super_admin');
        $agent = $this->createAdmin('owner_agent_admin', 'agent_admin', $superAdmin);
        $direct = $this->createAdmin('owner_direct_admin', 'direct_admin', $superAdmin);
        $member = $this->createAdmin('owner_agent_member', 'site_user', $agent);

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.admin-users.index'))
            ->assertOk()
            ->assertSee('归属')
            ->assertSee('平台代理')
            ->assertSee('平台直客')
            ->assertSee('代理：owner_agent_admin')
            ->assertSee('平台管理');

        $this->assertSame((int) $agent->id, (int) $member->created_by);
        $this->assertSame((int) $superAdmin->id, (int) $direct->created_by);
    }

    public function test_creating_agent_admin_copies_four_default_content_prompts_to_agent_scope(): void
    {
        $superAdmin = $this->createAdmin('prompt_agent_root', 'super_admin');
        Prompt::withoutEvents(fn (): Prompt => Prompt::query()->withoutGlobalScope('current_site')->create([
            'site_id' => null,
            'owner_admin_id' => null,
            'name' => '平台新增正文提示词',
            'type' => 'content',
            'content' => 'This custom platform prompt must not be copied to agents by default.',
            'variables' => '',
        ]));

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.admin-users.store'), [
                'username' => 'prompt_agent_owner',
                'display_name' => 'Prompt Agent Owner',
                'email' => 'prompt-agent-owner@example.com',
                'role' => 'agent_admin',
                'password' => 'secret-123',
                'confirm_password' => 'secret-123',
            ])
            ->assertRedirect(route('admin.admin-users.index'));

        $agent = Admin::query()->where('username', 'prompt_agent_owner')->firstOrFail();
        $promptNames = Prompt::query()
            ->withoutGlobalScope('current_site')
            ->where('owner_admin_id', (int) $agent->id)
            ->whereNull('site_id')
            ->where('type', 'content')
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $this->assertSame(
            collect($this->defaultContentPromptNames())->sort()->values()->all(),
            $promptNames
        );
    }

    public function test_admin_user_list_is_paginated_and_newest_first(): void
    {
        config(['geoflow.admin_items_per_page' => 2]);

        $superAdmin = $this->createAdmin('oldest_user_page_root', 'super_admin');
        $this->setAdminCreatedAt($superAdmin, now()->subDays(3));
        $middleAdmin = $this->createAdmin('middle_user_page_account', 'direct_admin', $superAdmin);
        $this->setAdminCreatedAt($middleAdmin, now()->subDays(2));
        $newAdmin = $this->createAdmin('newest_user_page_account', 'agent_admin', $superAdmin);
        $this->setAdminCreatedAt($newAdmin, now()->subDay());

        $response = $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.admin-users.index'));

        $admins = $response->viewData('admins');

        $response->assertOk();
        $this->assertInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class, $admins);
        $this->assertSame(
            [(int) $newAdmin->id, (int) $middleAdmin->id],
            collect($admins->items())->pluck('id')->map(fn ($id): int => (int) $id)->all()
        );
        $this->assertSame(3, $admins->total());
    }

    private function createAdmin(string $username, string $role, ?Admin $creator = null): Admin
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

    private function setAdminCreatedAt(Admin $admin, \Carbon\CarbonInterface $createdAt): void
    {
        $admin->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();
    }

    /**
     * @return list<string>
     */
    private function defaultContentPromptNames(): array
    {
        return [
            'GEO Marketing · Trust-Based Article Generation (English)',
            'GEO Ranking-Style Article Generation (English)',
            'GEO营销学·信任型正文生成',
            'GEO榜单型正文生成',
        ];
    }
}
