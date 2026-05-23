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

    public function test_ai_model_list_only_shows_current_site_models(): void
    {
        [$admin, $site] = $this->createAdminWithSite('ai_site_admin', 'AI Site');
        [$otherAdmin, $otherSite] = $this->createAdminWithSite('other_ai_site_admin', 'Other AI Site');

        AiModel::query()->create([
            'site_id' => $site->id,
            'name' => 'Current Site Model',
            'api_key' => 'encrypted-key',
            'model_id' => 'current-model',
            'model_type' => 'chat',
            'api_url' => 'https://example.test/v1',
            'status' => 'active',
        ]);
        AiModel::query()->create([
            'site_id' => $otherSite->id,
            'name' => 'Other Site Model',
            'api_key' => 'encrypted-key',
            'model_id' => 'other-model',
            'model_type' => 'chat',
            'api_url' => 'https://example.test/v1',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->get(route('admin.ai-models.index'))
            ->assertOk()
            ->assertSee('Current Site Model')
            ->assertDontSee('Other Site Model');

        $this->actingAs($otherAdmin, 'admin')
            ->withSession(['current_site_id' => $otherSite->id])
            ->get(route('admin.ai-models.index'))
            ->assertOk()
            ->assertSee('Other Site Model')
            ->assertDontSee('Current Site Model');
    }

    private function createAdminWithSite(string $username, string $siteName): array
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => $username,
            'role' => 'admin',
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
