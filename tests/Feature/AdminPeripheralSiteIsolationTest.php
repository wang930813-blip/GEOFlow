<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPeripheralSiteIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_model_list_is_scoped_by_ai_configuration_owner(): void
    {
        [$superAdmin, $site] = $this->createAdminWithSite('ai_super_admin', 'AI Platform Site', 'super_admin');
        [$agentAdmin, $agentSite] = $this->createAdminWithSite('ai_agent_admin', 'AI Agent Site', 'agent_admin');

        AiModel::withoutEvents(fn (): AiModel => AiModel::query()->withoutGlobalScope('current_site')->create([
            'site_id' => null,
            'owner_admin_id' => null,
            'name' => 'Platform Default Model',
            'api_key' => 'encrypted-key',
            'model_id' => 'platform-model',
            'model_type' => 'chat',
            'api_url' => 'https://example.test/v1',
            'status' => 'active',
        ]));
        AiModel::withoutEvents(fn (): AiModel => AiModel::query()->withoutGlobalScope('current_site')->create([
            'site_id' => null,
            'owner_admin_id' => (int) $agentAdmin->id,
            'name' => 'Agent Shared Model',
            'api_key' => 'encrypted-key',
            'model_id' => 'agent-model',
            'model_type' => 'chat',
            'api_url' => 'https://example.test/v1',
            'status' => 'active',
        ]));

        $this->actingAs($superAdmin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->get(route('admin.ai-models.index'))
            ->assertOk()
            ->assertSee('Platform Default Model')
            ->assertSee('Agent Shared Model')
            ->assertSee('所属代理')
            ->assertSee('平台模型')
            ->assertSee('ai_agent_admin');

        $this->actingAs($agentAdmin, 'admin')
            ->withSession(['current_site_id' => $agentSite->id])
            ->get(route('admin.ai-models.index'))
            ->assertOk()
            ->assertSee('Agent Shared Model')
            ->assertDontSee('Platform Default Model')
            ->assertDontSee('所属代理');
    }

    private function createAdminWithSite(string $username, string $siteName, string $role = 'direct_admin'): array
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => $username,
            'role' => $role,
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => $admin->id,
            'name' => $siteName,
            'status' => 'active',
        ]);
        $site->members()->attach($admin->id, ['role' => 'owner']);

        return [$admin, $site];
    }
}
