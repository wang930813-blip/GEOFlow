<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Services\Admin\AdminUpdateMetadataService;
use App\Services\Admin\AdminWelcomeModalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminWelcomeModalTest extends TestCase
{
    use RefreshDatabase;

    public function test_intro_guide_does_not_auto_open_for_new_admin(): void
    {
        config([
            'geoflow.update_check_enabled' => false,
            'geoflow.update_metadata_url' => '',
            'geoflow.welcome_intro_version' => '2.0',
        ]);

        $admin = $this->createAdmin('welcome_intro_new_admin');

        $payload = app(AdminWelcomeModalService::class)->buildModalPayload($admin);

        $this->assertSame('intro', $payload['state']['mode']);
        $this->assertFalse($payload['state']['shouldAutoOpen']);
        $this->assertNull($admin->fresh()->welcome_seen_version);
    }

    public function test_update_notice_still_auto_opens_when_unseen(): void
    {
        $admin = $this->createAdmin('welcome_update_new_admin');
        $service = new AdminWelcomeModalService(new class extends AdminUpdateMetadataService
        {
            public function fetchState(?string $currentVersion = null): array
            {
                return [
                    'current_version' => '2.0',
                    'latest_version' => '2.1',
                    'payload' => [],
                    'is_update_available' => true,
                    'is_ignored' => false,
                    'status' => 'available',
                    'source_url' => '',
                    'checked_at' => now()->toDateTimeString(),
                ];
            }
        });

        $payload = $service->buildModalPayload($admin);

        $this->assertSame('update', $payload['state']['mode']);
        $this->assertTrue($payload['state']['shouldAutoOpen']);
        $this->assertSame('update:2.1', $admin->fresh()->welcome_seen_version);
    }

    private function createAdmin(string $username): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => str_replace('_', ' ', $username),
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }
}
