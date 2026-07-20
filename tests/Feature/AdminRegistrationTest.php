<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Models\SiteSetting;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_entry_is_hidden_when_registration_is_disabled(): void
    {
        $this->get('/geo_admin/login')
            ->assertOk()
            ->assertDontSee('/geo_admin/register', false);

        $this->get('/geo_admin/register')
            ->assertForbidden();
    }

    public function test_registration_entry_is_visible_when_registration_is_enabled_with_experience_plan(): void
    {
        $plan = $this->createPlan();
        $this->enableRegistration($plan);

        $this->get('/geo_admin/login')
            ->assertOk()
            ->assertSee('/geo_admin/register', false);

        $this->get('/geo_admin/register')
            ->assertOk()
            ->assertDontSee('name="username"', false)
            ->assertSee('name="mobile"', false);
    }

    public function test_guest_can_register_as_direct_customer_and_receive_experience_plan(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $plan = $this->createPlan([
            PlatformPlan::RESOURCE_CREDITS => ['quota_value' => 1200, 'quota_period' => 'cycle', 'unit' => 'points'],
            PlatformPlan::RESOURCE_BRAND_DIAGNOSES => ['quota_value' => 3, 'quota_period' => 'cycle', 'unit' => 'times'],
        ]);
        $this->enableRegistration($plan);

        $this->withSession(['admin_registration_captcha' => 'A9K2'])
            ->post('/geo_admin/register', [
                'display_name' => 'Registered Direct User',
                'mobile' => '13800138000',
                'captcha' => 'A9K2',
                'password' => 'secret-123',
                'password_confirmation' => 'secret-123',
            ])
            ->assertRedirect(route('admin.dashboard'));

        $admin = Admin::query()->where('username', '13800138000')->firstOrFail();
        $site = Site::query()->where('owner_admin_id', (int) $admin->id)->firstOrFail();

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertSame('13800138000', (string) $admin->username);
        $this->assertSame('Registered Direct User', (string) $admin->display_name);
        $this->assertSame('13800138000', (string) $admin->mobile);
        $this->assertSame('direct_admin', (string) $admin->role);
        $this->assertSame('active', (string) $admin->status);
        $this->assertNull($admin->created_by);
        $this->assertSame('direct', (string) $site->customer_mode);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{8}\.geo\.xinzhidi\.cn$/', (string) $site->domain);
        $this->assertNull($site->agent_admin_id);

        $this->assertDatabaseHas('site_members', [
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'role' => 'owner',
        ]);
        $this->assertDatabaseHas('site_plan_subscriptions', [
            'site_id' => (int) $site->id,
            'plan_id' => (int) $plan->id,
            'mode' => 'direct',
            'owner_admin_id' => (int) $admin->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('admin_plan_subscriptions', [
            'admin_id' => (int) $admin->id,
            'site_id' => (int) $site->id,
            'plan_id' => (int) $plan->id,
            'mode' => 'direct_owner',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('site_credit_accounts', [
            'site_id' => (int) $site->id,
            'balance' => '1200.00',
        ]);
        $this->assertDatabaseHas('admin_credit_accounts', [
            'admin_id' => (int) $admin->id,
            'site_id' => (int) $site->id,
            'balance' => '1200.00',
        ]);
    }

    public function test_registration_rejects_invalid_captcha_and_duplicate_mobile(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $plan = $this->createPlan();
        $this->enableRegistration($plan);

        Admin::query()->create([
            'username' => '13800138001',
            'password' => 'secret-123',
            'email' => '',
            'display_name' => 'Existing Mobile User',
            'mobile' => '13800138001',
            'role' => 'direct_admin',
            'status' => 'active',
        ]);

        $this->withSession(['admin_registration_captcha' => 'A9K2'])
            ->from('/geo_admin/register')
            ->post('/geo_admin/register', [
                'display_name' => 'New Direct User',
                'mobile' => '13800138001',
                'captcha' => 'WRONG',
                'password' => 'secret-123',
                'password_confirmation' => 'secret-123',
            ])
            ->assertRedirect('/geo_admin/register')
            ->assertSessionHasErrors(['mobile', 'captcha']);
    }

    /**
     * @param  array<string,array{quota_value:int,quota_period:string,unit:string}>  $resources
     */
    private function createPlan(array $resources = []): PlatformPlan
    {
        $plan = PlatformPlan::query()->create([
            'name' => 'Registration Experience Plan',
            'code' => 'registration-experience-'.str()->random(8),
            'audience' => 'direct',
            'duration_days' => 30,
            'price' => null,
            'market_price' => null,
            'description' => '',
            'status' => 'active',
            'sort_order' => 0,
        ]);

        foreach ($resources as $resourceKey => $resource) {
            $plan->entitlements()->create([
                'resource_key' => $resourceKey,
                'enabled' => true,
                'quota_value' => $resource['quota_value'],
                'quota_period' => $resource['quota_period'],
                'unit' => $resource['unit'],
                'meta' => [],
            ]);
        }

        return $plan;
    }

    private function enableRegistration(PlatformPlan $plan): void
    {
        foreach ([
            'admin_registration_enabled' => '1',
            'admin_registration_experience_plan_id' => (string) $plan->id,
        ] as $key => $value) {
            SiteSetting::withoutEvents(function () use ($key, $value): void {
                SiteSetting::query()
                    ->withoutGlobalScope('current_site')
                    ->updateOrCreate(
                        ['site_id' => null, 'setting_key' => $key],
                        ['site_id' => null, 'setting_value' => $value]
                    );
            });
        }
    }
}
