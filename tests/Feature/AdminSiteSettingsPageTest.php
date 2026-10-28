<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PlatformPlan;
use App\Models\SensitiveWord;
use App\Models\Site;
use App\Models\SiteSetting;
use App\Support\AdminWeb;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminSiteSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_admin_can_view_admin_base_path_setting(): void
    {
        $admin = Admin::query()->create([
            'username' => 'site_settings_admin',
            'password' => 'secret-123',
            'email' => 'site-settings-admin@example.com',
            'display_name' => 'Site Settings Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.site-settings.index'))
            ->assertOk()
            ->assertSee(__('admin.site_settings.field_admin_base_path'))
            ->assertSee(__('admin.site_settings.section_home_carousel'))
            ->assertSee(__('admin.site_settings.section_products'))
            ->assertSee(__('admin.site_settings.module_sensitive_words'))
            ->assertSee('系统默认域名（不可修改）', false)
            ->assertSee('客户自定义域名（可选）', false)
            ->assertSee('启用 HTTPS 访问', false)
            ->assertSee('name="custom_https_enabled"', false)
            ->assertSee('data-custom-https-fields', false)
            ->assertSee('<details open', false)
            ->assertSee('value="'.AdminWeb::basePath().'"', false);
    }

    public function test_admin_must_bind_a_domain_and_provide_https_credentials_before_enabling_https(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        [$admin, $site] = $this->createAdminWithSite('site_https_required');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.site-settings.update'), [
                'site_name' => 'HTTPS Site',
                'custom_https_enabled' => '1',
                'site_subtitle' => '',
                'site_description' => '',
                'site_keywords' => '',
                'copyright_info' => '',
                'site_logo' => '',
                'site_favicon' => '',
                'analytics_code' => '',
                'seo_title_template' => '{title} - {site_name}',
                'seo_description_template' => '{description}',
                'featured_limit' => 6,
                'per_page' => 12,
                'admin_base_path' => AdminWeb::basePath(),
            ])
            ->assertSessionHasErrors(['custom_domain']);

        $this->assertFalse((bool) $site->fresh()->custom_https_enabled);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.site-settings.update'), [
                'site_name' => 'HTTPS Site',
                'custom_domain' => 'www.example.com',
                'custom_https_enabled' => '1',
                'site_subtitle' => '',
                'site_description' => '',
                'site_keywords' => '',
                'copyright_info' => '',
                'site_logo' => '',
                'site_favicon' => '',
                'analytics_code' => '',
                'seo_title_template' => '{title} - {site_name}',
                'seo_description_template' => '{description}',
                'featured_limit' => 6,
                'per_page' => 12,
                'admin_base_path' => AdminWeb::basePath(),
            ])
            ->assertSessionHasErrors('custom_https_certificate');

        $this->assertFalse((bool) $site->fresh()->custom_https_enabled);
    }

    public function test_site_https_private_key_is_encrypted_at_rest(): void
    {
        $site = Site::query()->create([
            'name' => 'Encrypted HTTPS Site',
            'domain' => 'encrypted.example.test',
            'status' => 'active',
        ]);
        $privateKey = '-----BEGIN PRIVATE KEY-----secret-----END PRIVATE KEY-----';

        $site->forceFill(['custom_https_private_key' => $privateKey])->save();

        $storedValue = (string) DB::table('sites')->whereKey($site->id)->value('custom_https_private_key');

        $this->assertNotSame($privateKey, $storedValue);
        $this->assertSame($privateKey, (string) $site->fresh()->custom_https_private_key);
    }

    public function test_admin_can_save_a_matching_https_certificate_and_private_key(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        if (! function_exists('openssl_pkey_new')) {
            $this->markTestSkipped('OpenSSL extension is unavailable');
        }

        [$admin, $site] = $this->createAdminWithSite('site_https_credentials');
        $privateKeyResource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        $this->assertNotFalse($privateKeyResource);

        $csr = openssl_csr_new(['commonName' => 'www.example.com'], $privateKeyResource, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($csr);
        $certificateResource = openssl_csr_sign($csr, null, $privateKeyResource, 30, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($certificateResource);
        $this->assertTrue(openssl_x509_export($certificateResource, $certificate));
        $this->assertTrue(openssl_pkey_export($privateKeyResource, $privateKey));

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.site-settings.update'), [
                'site_name' => 'HTTPS Site',
                'custom_domain' => 'www.example.com',
                'custom_https_enabled' => '1',
                'custom_https_certificate' => $certificate,
                'custom_https_private_key' => $privateKey,
                'site_subtitle' => '',
                'site_description' => '',
                'site_keywords' => '',
                'copyright_info' => '',
                'site_logo' => '',
                'site_favicon' => '',
                'analytics_code' => '',
                'seo_title_template' => '{title} - {site_name}',
                'seo_description_template' => '{description}',
                'featured_limit' => 6,
                'per_page' => 12,
                'admin_base_path' => AdminWeb::basePath(),
            ])
            ->assertRedirect(route('admin.site-settings.index'));

        $savedSite = $site->fresh();
        $this->assertTrue((bool) $savedSite->custom_https_enabled);
        $this->assertSame(trim($certificate), (string) $savedSite->custom_https_certificate);
        $this->assertSame(trim($privateKey), (string) $savedSite->custom_https_private_key);
    }

    public function test_super_admin_can_update_admin_display_settings_independently(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = Admin::query()->create([
            'username' => 'site_display_copy_root',
            'password' => 'secret-123',
            'email' => 'site-display-copy-root@example.com',
            'display_name' => 'Site Display Copy Root',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.site-settings.index'));

        $response
            ->assertOk()
            ->assertSee('data-open-admin-display-settings', false)
            ->assertSee('data-open-admin-registration-settings', false)
            ->assertSee('name="admin_quick_start_title"', false)
            ->assertSee('name="media_platform_1_label"', false);

        $this->assertSame(1, substr_count($response->getContent(), 'name="admin_quick_start_title"'));
        $this->assertSame(1, substr_count($response->getContent(), 'name="media_platform_1_label"'));
        $this->assertSame(1, substr_count($response->getContent(), 'name="admin_registration_enabled"'));
        $this->assertSame(1, substr_count($response->getContent(), 'name="admin_registration_experience_plan_id"'));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.site-settings.admin-display'), [
                'site_name' => 'Frontend Site',
                'site_subtitle' => '',
                'site_description' => '',
                'site_keywords' => '',
                'copyright_info' => '',
                'site_logo' => '',
                'site_favicon' => '',
                'analytics_code' => '',
                'seo_title_template' => '{title} - {site_name}',
                'seo_description_template' => '{description}',
                'featured_limit' => 6,
                'per_page' => 12,
                'admin_base_path' => AdminWeb::basePath(),
                'admin_quick_start_eyebrow' => 'Operator Launch',
                'admin_quick_start_title' => 'Launch custom AI production',
                'admin_quick_start_subtitle' => 'Prepare model, assets and tasks.',
                'admin_footer_brand' => 'Acme Console',
                'admin_footer_version' => '9.9.1',
                'media_platform_1_label' => 'Authority Plus',
                'media_platform_2_label' => 'Quality Plus',
            ])
            ->assertRedirect(route('admin.site-settings.index'));

        foreach ([
            'admin_quick_start_eyebrow' => 'Operator Launch',
            'admin_quick_start_title' => 'Launch custom AI production',
            'admin_quick_start_subtitle' => 'Prepare model, assets and tasks.',
            'admin_footer_brand' => 'Acme Console',
            'admin_footer_version' => '9.9.1',
            'media_platform_1_label' => 'Authority Plus',
            'media_platform_2_label' => 'Quality Plus',
        ] as $key => $value) {
            $this->assertSame(
                $value,
                (string) SiteSetting::withoutGlobalScope('current_site')
                    ->whereNull('site_id')
                    ->where('setting_key', $key)
                    ->value('setting_value')
            );
        }
    }

    public function test_non_super_admin_cannot_view_or_update_admin_display_settings(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        [$admin, $site] = $this->createAdminWithSite('site_display_copy_admin');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->get(route('admin.site-settings.index'))
            ->assertOk()
            ->assertDontSee('name="admin_quick_start_title"', false)
            ->assertDontSee('name="media_platform_1_label"', false);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.site-settings.update'), [
                'site_name' => 'Frontend Site',
                'site_subtitle' => '',
                'site_description' => '',
                'site_keywords' => '',
                'copyright_info' => '',
                'site_logo' => '',
                'site_favicon' => '',
                'analytics_code' => '',
                'seo_title_template' => '{title} - {site_name}',
                'seo_description_template' => '{description}',
                'featured_limit' => 6,
                'per_page' => 12,
                'admin_base_path' => AdminWeb::basePath(),
                'admin_quick_start_title' => 'Should Not Save',
                'media_platform_1_label' => 'Should Not Save',
            ])
            ->assertRedirect(route('admin.site-settings.index'));

        $this->assertFalse(
            SiteSetting::withoutGlobalScope('current_site')
                ->whereNull('site_id')
                ->whereIn('setting_key', ['admin_quick_start_title', 'media_platform_1_label'])
                ->exists()
        );
    }

    public function test_super_admin_can_update_registration_settings_independently(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = Admin::query()->create([
            'username' => 'site_registration_settings_root',
            'password' => 'secret-123',
            'email' => 'site-registration-settings-root@example.com',
            'display_name' => 'Site Registration Settings Root',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $plan = PlatformPlan::query()->create([
            'name' => 'Registration Trial Plan',
            'code' => 'registration-trial',
            'audience' => 'direct',
            'status' => 'active',
            'duration_days' => 30,
            'sort_order' => 10,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.site-settings.registration'), [
                'admin_registration_enabled' => '1',
                'admin_registration_experience_plan_id' => (string) $plan->id,
            ])
            ->assertRedirect(route('admin.site-settings.index'));

        $this->assertSame(
            '1',
            (string) SiteSetting::withoutGlobalScope('current_site')
                ->whereNull('site_id')
                ->where('setting_key', 'admin_registration_enabled')
                ->value('setting_value')
        );
        $this->assertSame(
            (string) $plan->id,
            (string) SiteSetting::withoutGlobalScope('current_site')
                ->whereNull('site_id')
                ->where('setting_key', 'admin_registration_experience_plan_id')
                ->value('setting_value')
        );
    }

    public function test_site_settings_main_save_does_not_update_admin_display_or_registration_settings(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = Admin::query()->create([
            'username' => 'site_settings_root_main_save',
            'password' => 'secret-123',
            'email' => 'site-settings-root-main-save@example.com',
            'display_name' => 'Site Settings Root Main Save',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.site-settings.update'), [
                'site_name' => 'Frontend Site',
                'site_subtitle' => '',
                'site_description' => '',
                'site_keywords' => '',
                'copyright_info' => '',
                'site_logo' => '',
                'site_favicon' => '',
                'analytics_code' => '',
                'seo_title_template' => '{title} - {site_name}',
                'seo_description_template' => '{description}',
                'featured_limit' => 6,
                'per_page' => 12,
                'admin_base_path' => AdminWeb::basePath(),
                'admin_quick_start_title' => 'Should Not Save From Main Form',
                'admin_registration_enabled' => '1',
            ])
            ->assertRedirect(route('admin.site-settings.index'));

        $this->assertFalse(
            SiteSetting::withoutGlobalScope('current_site')
                ->whereNull('site_id')
                ->whereIn('setting_key', ['admin_quick_start_title', 'admin_registration_enabled'])
                ->exists()
        );
    }

    public function test_admin_can_update_current_site_custom_domain_without_changing_system_domain(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = Admin::query()->create([
            'username' => 'site_domain_admin',
            'password' => 'secret-123',
            'email' => 'site-domain-admin@example.com',
            'display_name' => 'Site Domain Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => $admin->id,
            'name' => 'Domain Site',
            'domain' => 'system-domain.example.test',
            'status' => 'active',
        ]);
        $site->members()->attach($admin->id, ['role' => 'owner']);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.site-settings.update'), [
                'site_name' => 'Frontend Site',
                'custom_domain' => 'https://Client-A.Example.test/some/path',
                'site_subtitle' => '',
                'site_description' => '',
                'site_keywords' => '',
                'copyright_info' => '',
                'site_logo' => '',
                'site_favicon' => '',
                'analytics_code' => '',
                'seo_title_template' => '{title} - {site_name}',
                'seo_description_template' => '{description}',
                'featured_limit' => 6,
                'per_page' => 12,
                'admin_base_path' => AdminWeb::basePath(),
            ])
            ->assertRedirect(route('admin.site-settings.index'));

        $site->refresh();
        $this->assertSame('system-domain.example.test', (string) $site->domain);
        $this->assertSame('client-a.example.test', (string) $site->custom_domain);
    }

    public function test_direct_admin_and_site_user_can_update_their_current_site_domain_settings(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        foreach (['direct_admin', 'site_user'] as $role) {
            [$admin, $site] = $this->createAdminWithSite('site_domain_locked_'.$role, $role);
            $site->forceFill(['domain' => 'system-'.$role.'.example.test'])->save();
            $changedDomain = 'changed-'.str_replace('_', '-', $role).'.example.test';

            SiteSetting::query()->updateOrCreate(
                ['site_id' => $site->id, 'setting_key' => 'admin_base_path'],
                ['setting_value' => AdminWeb::basePath()]
            );

            $this->actingAs($admin, 'admin')
                ->withSession(['current_site_id' => $site->id])
                ->get(route('admin.ai.configurator'))
                ->assertForbidden();

            $settingsResponse = $this->actingAs($admin, 'admin')
                ->withSession(['current_site_id' => $site->id])
                ->get(route('admin.site-settings.index'));

            $settingsResponse
                ->assertOk()
                ->assertSee('name="custom_domain"', false)
                ->assertSee('system-'.$role.'.example.test')
                ->assertSee('name="admin_base_path"', false)
                ->assertDontSee('name="custom_domain" value="system-'.$role.'.example.test" readonly', false);

            $this->actingAs($admin, 'admin')
                ->withSession(['current_site_id' => $site->id])
                ->post(route('admin.site-settings.update'), [
                    'site_name' => 'Locked Site '.$role,
                    'custom_domain' => $changedDomain,
                    'site_subtitle' => '',
                    'site_description' => '',
                    'site_keywords' => '',
                    'copyright_info' => '',
                    'site_logo' => '',
                    'site_favicon' => '',
                    'analytics_code' => '',
                    'seo_title_template' => '{title} - {site_name}',
                    'seo_description_template' => '{description}',
                    'featured_limit' => 6,
                    'per_page' => 12,
                    'admin_base_path' => 'changed-admin-path',
                ])
                ->assertRedirect(route('admin.site-settings.index'));

            $site->refresh();
            $this->assertSame('system-'.$role.'.example.test', (string) $site->domain);
            $this->assertSame($changedDomain, (string) $site->custom_domain);
            $this->assertSame(
                AdminWeb::basePath(),
                (string) SiteSetting::withoutGlobalScope('current_site')
                    ->where('site_id', $site->id)
                    ->where('setting_key', 'admin_base_path')
                    ->value('setting_value')
            );

            $this->actingAs($admin, 'admin')
                ->withSession(['current_site_id' => $site->id])
                ->post(route('admin.site-settings.update'), [
                    'site_name' => 'Locked Site Without Disabled Fields '.$role,
                    'site_subtitle' => '',
                    'site_description' => '',
                    'site_keywords' => '',
                    'copyright_info' => '',
                    'site_logo' => '',
                    'site_favicon' => '',
                    'analytics_code' => '',
                    'seo_title_template' => '{title} - {site_name}',
                    'seo_description_template' => '{description}',
                    'featured_limit' => 6,
                    'per_page' => 12,
                ])
                ->assertRedirect(route('admin.site-settings.index'));

            $site->refresh();
            $this->assertSame('system-'.$role.'.example.test', (string) $site->domain);
            $this->assertSame($changedDomain, (string) $site->custom_domain);
        }
    }

    public function test_standard_admin_cannot_update_analytics_code(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$admin, $site] = $this->createAdminWithSite('site_analytics_admin');

        SiteSetting::query()->create([
            'site_id' => $site->id,
            'setting_key' => 'analytics_code',
            'setting_value' => '<script>existing()</script>',
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.site-settings.update'), [
                'site_name' => 'Frontend Site',
                'site_subtitle' => '',
                'site_description' => '',
                'site_keywords' => '',
                'copyright_info' => '',
                'site_logo' => '',
                'site_favicon' => '',
                'analytics_code' => '<script>changed()</script>',
                'seo_title_template' => '{title} - {site_name}',
                'seo_description_template' => '{description}',
                'featured_limit' => 6,
                'per_page' => 12,
                'admin_base_path' => AdminWeb::basePath(),
            ])
            ->assertRedirect(route('admin.site-settings.index'));

        $this->assertSame(
            '<script>existing()</script>',
            (string) SiteSetting::withoutGlobalScope('current_site')
                ->where('site_id', $site->id)
                ->where('setting_key', 'analytics_code')
                ->value('setting_value')
        );
    }

    public function test_sensitive_words_are_managed_under_site_settings(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        [$admin, $site] = $this->createAdminWithSite('site_sensitive_admin');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->get(route('admin.site-settings.sensitive-words'))
            ->assertOk()
            ->assertSee(__('admin.security.page_title'));

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->get(route('admin.security-settings.index'))
            ->assertRedirect(route('admin.site-settings.sensitive-words'));

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.site-settings.sensitive-words.store'), [
                'words' => "测试敏感词\n测试敏感词\n另一个敏感词",
            ])
            ->assertRedirect(route('admin.site-settings.sensitive-words'));

        $this->assertDatabaseHas('sensitive_words', ['site_id' => $site->id, 'word' => '测试敏感词']);
        $this->assertDatabaseHas('sensitive_words', ['site_id' => $site->id, 'word' => '另一个敏感词']);
        $this->assertSame(2, SensitiveWord::withoutGlobalScope('current_site')->where('site_id', $site->id)->count());

        $word = SensitiveWord::withoutGlobalScope('current_site')
            ->where('site_id', $site->id)
            ->where('word', '测试敏感词')
            ->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.site-settings.sensitive-words.delete', ['wordId' => $word->id]))
            ->assertRedirect(route('admin.site-settings.sensitive-words'));

        $this->assertDatabaseMissing('sensitive_words', ['site_id' => $site->id, 'word' => '测试敏感词']);
    }

    public function test_admin_base_path_rejects_unsafe_value(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = Admin::query()->create([
            'username' => 'site_settings_invalid_admin',
            'password' => 'secret-123',
            'email' => 'site-settings-invalid-admin@example.com',
            'display_name' => 'Site Settings Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.site-settings.update'), [
                'site_name' => 'Frontend Site',
                'site_subtitle' => '',
                'site_description' => '',
                'site_keywords' => '',
                'copyright_info' => '',
                'site_logo' => '',
                'site_favicon' => '',
                'analytics_code' => '',
                'seo_title_template' => '{title} - {site_name}',
                'seo_description_template' => '{description}',
                'featured_limit' => 6,
                'per_page' => 12,
                'admin_base_path' => '../admin',
            ])
            ->assertSessionHasErrors('admin_base_path');
    }

    public function test_site_settings_save_home_carousel_slides(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = Admin::query()->create([
            'username' => 'site_carousel_admin',
            'password' => 'secret-123',
            'email' => 'site-carousel-admin@example.com',
            'display_name' => 'Site Carousel Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.site-settings.update'), [
                'site_name' => 'Frontend Site',
                'site_subtitle' => '',
                'site_description' => '',
                'site_keywords' => '',
                'copyright_info' => '',
                'site_logo' => '',
                'site_favicon' => '',
                'analytics_code' => '',
                'seo_title_template' => '{title} - {site_name}',
                'seo_description_template' => '{description}',
                'featured_limit' => 6,
                'per_page' => 12,
                'admin_base_path' => AdminWeb::basePath(),
                'home_carousel_slides' => [
                    [
                        'image_url' => '/storage/banners/home.jpg',
                        'title' => 'Home Banner',
                        'link_url' => 'article/demo',
                        'enabled' => '1',
                    ],
                    [
                        'image_url' => 'javascript:alert(1)',
                        'title' => 'Invalid Banner',
                        'link_url' => '',
                        'enabled' => '1',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.site-settings.index'));

        $raw = (string) SiteSetting::query()
            ->where('setting_key', 'home_carousel_slides')
            ->value('setting_value');
        $slides = json_decode($raw, true);

        $this->assertIsArray($slides);
        $this->assertCount(1, $slides);
        $this->assertSame('/storage/banners/home.jpg', $slides[0]['image_url']);
        $this->assertSame('Home Banner', $slides[0]['title']);
        $this->assertSame('/article/demo', $slides[0]['link_url']);
        $this->assertTrue($slides[0]['enabled']);
    }

    public function test_site_settings_save_product_configuration_items(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = Admin::query()->create([
            'username' => 'site_products_admin',
            'password' => 'secret-123',
            'email' => 'site-products-admin@example.com',
            'display_name' => 'Site Products Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.site-settings.update'), [
                'site_name' => 'Frontend Site',
                'site_subtitle' => '',
                'site_description' => '',
                'site_keywords' => '',
                'copyright_info' => '',
                'site_logo' => '',
                'site_favicon' => '',
                'analytics_code' => '',
                'seo_title_template' => '{title} - {site_name}',
                'seo_description_template' => '{description}',
                'featured_limit' => 6,
                'per_page' => 12,
                'admin_base_path' => AdminWeb::basePath(),
                'site_products' => [
                    [
                        'name' => 'AI 搜索顾问服务',
                        'summary' => '提升品牌在 AI 搜索中的可见性。',
                        'details' => '从官网结构、内容事实和资讯更新三个方向优化。',
                        'image_url' => '/storage/products/ai-search.jpg',
                        'link_url' => 'products/ai-search',
                        'enabled' => '1',
                    ],
                    [
                        'name' => '',
                        'summary' => '名称为空的行不会保存。',
                        'details' => '',
                        'image_url' => 'javascript:alert(1)',
                        'link_url' => '',
                        'enabled' => '1',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.site-settings.index'));

        $raw = (string) SiteSetting::query()
            ->where('setting_key', 'site_products')
            ->value('setting_value');
        $products = json_decode($raw, true);

        $this->assertIsArray($products);
        $this->assertCount(1, $products);
        $this->assertSame('AI 搜索顾问服务', $products[0]['name']);
        $this->assertSame('提升品牌在 AI 搜索中的可见性。', $products[0]['summary']);
        $this->assertSame('从官网结构、内容事实和资讯更新三个方向优化。', $products[0]['details']);
        $this->assertSame('/storage/products/ai-search.jpg', $products[0]['image_url']);
        $this->assertSame('/products/ai-search', $products[0]['link_url']);
        $this->assertTrue($products[0]['enabled']);
    }

    public function test_legacy_official_website_themes_are_hidden_from_admin_selection(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = Admin::query()->create([
            'username' => 'site_theme_visibility_admin',
            'password' => 'secret-123',
            'email' => 'site-theme-visibility-admin@example.com',
            'display_name' => 'Site Theme Visibility Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.site-settings.index'));

        $response
            ->assertOk()
            ->assertDontSee('value="api-hot-recommendation-20260914"', false)
            ->assertDontSee('value="apihot-recommend-20260623"', false)
            ->assertDontSee('value="apple-support-inspired-20260914"', false)
            ->assertDontSee('value="apple_support_clone"', false)
            ->assertDontSee('value="corporate-growth-20260914"', false)
            ->assertDontSee('value="geoflow-template-01-ink-editorial"', false)
            ->assertDontSee('value="geoflow-template-20-research-journal"', false)
            ->assertDontSee('value="default"', false)
            ->assertSee('value="geoflow-template-21-enterprise-signature"', false)
            ->assertSee('value="tech-insight-20260819"', false)
            ->assertSee('value="toutiao-news-20260426"', false)
            ->assertSee(route('admin.site-settings.themes.preview', ['theme' => 'template01']))
            ->assertDontSee(route('admin.site-settings.themes.preview', ['theme' => 'tech-insight-20260819']))
            ->assertDontSee(route('admin.site-settings.themes.preview', ['theme' => 'toutiao-news-20260426']))
            ->assertDontSee(route('admin.site-settings.themes.preview', ['theme' => 'geoflow-template-21-enterprise-signature']));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.site-settings.theme'), [
                'active_theme' => 'geoflow-template-20-research-journal',
            ])
            ->assertSessionHasErrors();
    }

    private function createAdminWithSite(string $username, string $role = 'admin'): array
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
            'name' => $username.' Site',
            'status' => 'active',
        ]);
        $site->members()->attach($admin->id, ['role' => 'owner']);

        return [$admin, $site];
    }
}
