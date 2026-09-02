<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BrandDiagnosisBrandMention;
use App\Models\BrandDiagnosisQuestion;
use App\Models\BrandDiagnosisResult;
use App\Models\BrandDiagnosisRun;
use App\Models\KeywordLibrary;
use App\Models\ProductCase;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCaseModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_cases_are_public_and_drafts_are_hidden(): void
    {
        $published = ProductCase::query()->create([
            'title' => 'Jufulou GEO Case',
            'slug' => 'jufulou-geo-case',
            'company_name' => 'Jufulou',
            'summary' => 'Jufulou improves brand visibility through GEO content and AI search monitoring.',
            'content' => "## Brand Intro\n\nJufulou is a local restaurant brand.",
            'status' => ProductCase::STATUS_PUBLISHED,
            'published_at' => now()->subMinute(),
        ]);

        ProductCase::query()->create([
            'title' => 'Draft Product Case',
            'slug' => 'draft-product-case',
            'company_name' => 'Draft Brand',
            'summary' => 'Drafts should not be public.',
            'content' => 'Draft content',
            'status' => ProductCase::STATUS_DRAFT,
        ]);

        ProductCase::query()->create([
            'title' => 'Hidden Product Case',
            'slug' => 'hidden-product-case',
            'company_name' => 'Hidden Brand',
            'summary' => 'Hidden cases should not be public.',
            'content' => 'Hidden content',
            'status' => ProductCase::STATUS_HIDDEN,
            'published_at' => now()->subMinute(),
        ]);

        $deleted = ProductCase::query()->create([
            'title' => 'Deleted Product Case',
            'slug' => 'deleted-product-case',
            'company_name' => 'Deleted Brand',
            'summary' => 'Deleted cases should not be public.',
            'content' => 'Deleted content',
            'status' => ProductCase::STATUS_PUBLISHED,
            'published_at' => now()->subMinute(),
        ]);
        $deleted->delete();

        $this->get(route('product-cases.index'))
            ->assertOk()
            ->assertSee($published->title)
            ->assertDontSee('Draft Product Case')
            ->assertDontSee('Hidden Product Case')
            ->assertDontSee('Deleted Product Case');

        $this->get(route('product-cases.show', ['slug' => 'jufulou-geo-case']))
            ->assertOk()
            ->assertSee('Jufulou GEO Case')
            ->assertSee('Brand Intro');

        $this->get(route('product-cases.show', ['slug' => 'draft-product-case']))
            ->assertNotFound();

        $this->get(route('product-cases.show', ['slug' => 'hidden-product-case']))
            ->assertNotFound();

        $this->get(route('product-cases.show', ['slug' => 'deleted-product-case']))
            ->assertNotFound();
    }

    public function test_public_case_list_filters_by_keyword_industry_region_and_tag(): void
    {
        ProductCase::query()->create([
            'title' => 'Restaurant Brand Case',
            'slug' => 'restaurant-case',
            'company_name' => 'Jufulou',
            'industry' => 'Restaurant',
            'region' => 'Beijing',
            'business_mode' => 'Direct',
            'module_tags' => ['Brand Diagnosis'],
            'summary' => 'Restaurant GEO case',
            'status' => ProductCase::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);

        ProductCase::query()->create([
            'title' => 'Education Brand Case',
            'slug' => 'education-case',
            'company_name' => 'Xueshuyi',
            'industry' => 'Education',
            'region' => 'Shanghai',
            'business_mode' => 'Platform',
            'module_tags' => ['AI Search Inclusion'],
            'summary' => 'Education GEO case',
            'status' => ProductCase::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);

        $this->get(route('product-cases.index', ['keyword' => 'Jufulou']))
            ->assertOk()
            ->assertSee('Restaurant Brand Case')
            ->assertDontSee('Education Brand Case');

        $this->get(route('product-cases.index', [
            'industry' => 'Education',
            'region' => 'Shanghai',
            'tag' => 'AI Search Inclusion',
        ]))
            ->assertOk()
            ->assertSee('Education Brand Case')
            ->assertDontSee('Restaurant Brand Case');
    }

    public function test_admin_prefixed_product_case_library_routes_render_public_pages(): void
    {
        ProductCase::query()->create([
            'title' => 'Admin Prefixed Product Case',
            'slug' => 'admin-prefixed-product-case',
            'company_name' => 'Prefixed Brand',
            'summary' => 'Public case reachable from the admin path.',
            'content' => "## Case Body\n\nAdmin-prefixed public content.",
            'status' => ProductCase::STATUS_PUBLISHED,
            'published_at' => now()->subMinute(),
        ]);

        $this->get(route('admin.product-case-library.index'))
            ->assertOk()
            ->assertSee('Admin Prefixed Product Case')
            ->assertSee(route('admin.product-case-library.show', ['slug' => 'admin-prefixed-product-case']), false)
            ->assertDontSee(route('product-cases.show', ['slug' => 'admin-prefixed-product-case']), false);

        $this->get(route('admin.product-case-library.show', ['slug' => 'admin-prefixed-product-case']))
            ->assertOk()
            ->assertSee('Admin Prefixed Product Case')
            ->assertSee('Case Body')
            ->assertSee(route('admin.product-case-library.index'), false);
    }

    public function test_product_case_model_relationships_and_scope(): void
    {
        [$owner, $site] = $this->createAdminWithSite('case_owner', 'direct_admin');

        $case = ProductCase::query()->create([
            'site_id' => $site->id,
            'owner_admin_id' => $owner->id,
            'title' => 'Bound Site Case',
            'slug' => 'bound-site-case',
            'company_name' => 'Bound Brand',
            'module_tags' => ['AI Search Monitoring', 'Brand Diagnosis'],
            'status' => ProductCase::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);

        $this->assertTrue($case->site->is($site));
        $this->assertTrue($case->owner->is($owner));
        $this->assertSame(['AI Search Monitoring', 'Brand Diagnosis'], $case->module_tags);
        $this->assertSame(1, ProductCase::query()->published()->count());
    }

    public function test_super_admin_can_manage_product_cases(): void
    {
        [$superAdmin] = $this->createAdminWithSite('super_case_manager', 'super_admin');
        [$owner, $site] = $this->createAdminWithSite('case_customer', 'direct_admin');

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.product-cases.index'))
            ->assertOk()
            ->assertSee('产品案例管理')
            ->assertSee(route('admin.product-cases.create'), false);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.product-cases.store'), $this->casePayload($site, $owner, [
                'title' => 'Managed Product Case',
                'slug' => '',
                'status' => ProductCase::STATUS_PUBLISHED,
            ]))
            ->assertRedirect(route('admin.product-cases.index'))
            ->assertSessionHasNoErrors();

        $case = ProductCase::query()->firstOrFail();
        $this->assertSame('managed-product-case', $case->slug);
        $this->assertSame((int) $site->id, (int) $case->site_id);
        $this->assertSame((int) $owner->id, (int) $case->owner_admin_id);
        $this->assertSame((int) $superAdmin->id, (int) $case->created_by_admin_id);

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.product-cases.edit', ['product_case' => $case->id]))
            ->assertOk()
            ->assertSee('Managed Product Case');

        $this->actingAs($superAdmin, 'admin')
            ->put(route('admin.product-cases.update', ['product_case' => $case->id]), $this->casePayload($site, $owner, [
                'title' => 'Updated Product Case',
                'slug' => 'custom-product-case',
                'status' => ProductCase::STATUS_DRAFT,
            ]))
            ->assertRedirect(route('admin.product-cases.index'))
            ->assertSessionHasNoErrors();

        $case->refresh();
        $this->assertSame('Updated Product Case', $case->title);
        $this->assertSame('custom-product-case', $case->slug);
        $this->assertSame(ProductCase::STATUS_DRAFT, $case->status);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.product-cases.toggle-status', ['product_case' => $case->id]))
            ->assertRedirect(route('admin.product-cases.index'));

        $this->assertSame(ProductCase::STATUS_PUBLISHED, $case->refresh()->status);

        $this->actingAs($superAdmin, 'admin')
            ->delete(route('admin.product-cases.destroy', ['product_case' => $case->id]))
            ->assertRedirect(route('admin.product-cases.index'));

        $this->assertSoftDeleted('product_cases', ['id' => $case->id]);
    }

    public function test_super_admin_can_create_product_case_with_optional_profile_fields_blank(): void
    {
        [$superAdmin] = $this->createAdminWithSite('super_case_optional', 'super_admin');
        [$owner, $site] = $this->createAdminWithSite('case_optional_customer', 'direct_admin');

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.product-cases.store'), $this->casePayload($site, $owner, [
                'company_name' => '',
                'logo_url' => '',
                'cover_url' => '',
                'industry' => '',
                'region' => '',
                'business_mode' => '',
                'summary' => '',
                'customer_level' => '',
                'started_at' => '',
            ]))
            ->assertRedirect(route('admin.product-cases.index'))
            ->assertSessionHasNoErrors();

        $case = ProductCase::query()->firstOrFail();

        $this->assertSame('', $case->business_mode);
        $this->assertSame('', $case->company_name);
        $this->assertSame('', $case->summary);
        $this->assertNull($case->started_at);
    }

    public function test_admin_product_case_form_uses_fixed_industry_and_region_options_and_hides_removed_inputs(): void
    {
        [$superAdmin] = $this->createAdminWithSite('super_case_form_options', 'super_admin');
        [$owner, $site] = $this->createAdminWithSite('case_form_options_customer', 'direct_admin');

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.product-cases.create'))
            ->assertOk()
            ->assertSee('name="industry"', false)
            ->assertSee('name="region"', false)
            ->assertSee('食品、饮料')
            ->assertSee('成都市')
            ->assertDontSee('name="business_mode"', false)
            ->assertDontSee('name="module_tags"', false);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.product-cases.store'), $this->casePayload($site, $owner, [
                'industry' => '食品、饮料',
                'region' => '成都市',
                'business_mode' => 'Direct',
                'module_tags' => 'Brand Diagnosis',
            ]))
            ->assertRedirect(route('admin.product-cases.index'))
            ->assertSessionHasNoErrors();

        $case = ProductCase::query()->firstOrFail();

        $this->assertSame('食品、饮料', $case->industry);
        $this->assertSame('成都市', $case->region);
        $this->assertSame('', $case->business_mode);
        $this->assertNull($case->module_tags);
    }

    public function test_non_super_admin_cannot_manage_product_cases(): void
    {
        [$admin] = $this->createAdminWithSite('normal_case_manager', 'direct_admin');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.product-cases.index'))
            ->assertForbidden();
    }

    public function test_case_detail_includes_monitoring_summary_section_for_bound_site(): void
    {
        [$owner, $site] = $this->createAdminWithSite('summary_owner', 'direct_admin');

        ProductCase::query()->create([
            'site_id' => $site->id,
            'owner_admin_id' => $owner->id,
            'title' => 'Monitoring Summary Case',
            'slug' => 'monitoring-summary-case',
            'company_name' => 'Summary Brand',
            'summary' => 'A case with monitoring report blocks.',
            'content' => 'Manual case content should stay primary.',
            'status' => ProductCase::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);

        $this->get(route('product-cases.show', ['slug' => 'monitoring-summary-case']))
            ->assertOk()
            ->assertSee('Manual case content should stay primary.')
            ->assertSee('GEO 成效总览')
            ->assertSee('AI 平台覆盖');
    }

    public function test_case_detail_hides_bound_site_name_and_renders_numeric_customer_level_as_stars(): void
    {
        [$owner, $site] = $this->createAdminWithSite('public_profile_owner', 'direct_admin');

        ProductCase::query()->create([
            'site_id' => $site->id,
            'owner_admin_id' => $owner->id,
            'title' => 'Public Profile Case',
            'slug' => 'public-profile-case',
            'company_name' => 'Public Brand',
            'summary' => 'A public product case profile.',
            'content' => 'Manual case content.',
            'customer_level' => '4',
            'started_at' => null,
            'status' => ProductCase::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);

        $this->get(route('product-cases.show', ['slug' => 'public-profile-case']))
            ->assertOk()
            ->assertSee('Public Brand')
            ->assertDontSee($site->name)
            ->assertSee('aria-label="4 of 5 stars"', false)
            ->assertSee('未设置');
    }

    public function test_case_detail_includes_industry_competition_report_blocks(): void
    {
        [$owner, $site] = $this->createAdminWithSite('industry_case_owner', 'direct_admin');

        KeywordLibrary::query()->create([
            'site_id' => $site->id,
            'owner_admin_id' => $owner->id,
            'name' => 'Industry Case Library',
            'company_name' => 'Case Brand',
            'domain_keyword' => 'AI Search Visibility',
            'industry' => 'Technology Service',
            'brand_description' => 'Case Brand helps companies improve AI search visibility.',
            'status' => 'active',
        ]);

        ProductCase::query()->create([
            'site_id' => $site->id,
            'owner_admin_id' => $owner->id,
            'title' => 'Industry Competition Case',
            'slug' => 'industry-competition-case',
            'company_name' => 'Case Brand',
            'summary' => 'A public product case with industry competition data.',
            'content' => 'Manual case content.',
            'status' => ProductCase::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);

        $run = BrandDiagnosisRun::query()->create([
            'site_id' => $site->id,
            'owner_admin_id' => $owner->id,
            'admin_id' => $owner->id,
            'brand_name' => 'Case Brand',
            'platforms' => ['doubao', 'qianwen'],
            'status' => 'completed',
            'total_questions' => 2,
            'completed_questions' => 2,
            'usage_date' => now()->toDateString(),
            'started_at' => now()->subHour(),
            'completed_at' => now()->subMinutes(30),
        ]);

        $question = BrandDiagnosisQuestion::query()->create([
            'site_id' => $site->id,
            'owner_admin_id' => $owner->id,
            'run_id' => $run->id,
            'question' => 'Which AI search service is reliable?',
            'question_type' => 'competition',
            'sort_order' => 1,
            'status' => 'completed',
        ]);

        $doubaoResult = BrandDiagnosisResult::query()->create([
            'site_id' => $site->id,
            'owner_admin_id' => $owner->id,
            'run_id' => $run->id,
            'question_id' => $question->id,
            'platform' => 'doubao',
            'answer' => 'Case Brand and Competitor Alpha are mentioned.',
            'brand_mentioned' => true,
            'mention_count' => 1,
            'mention_rank' => 1,
            'sentiment' => 'positive',
            'status' => 'success',
            'checked_at' => now()->subMinutes(20),
        ]);

        $qianwenResult = BrandDiagnosisResult::query()->create([
            'site_id' => $site->id,
            'owner_admin_id' => $owner->id,
            'run_id' => $run->id,
            'question_id' => $question->id,
            'platform' => 'qianwen',
            'answer' => 'Competitor Beta is mentioned.',
            'brand_mentioned' => false,
            'mention_count' => 0,
            'mention_rank' => 0,
            'sentiment' => 'neutral',
            'status' => 'success',
            'checked_at' => now()->subMinutes(10),
        ]);

        BrandDiagnosisBrandMention::query()->create([
            'site_id' => $site->id,
            'owner_admin_id' => $owner->id,
            'run_id' => $run->id,
            'question_id' => $question->id,
            'result_id' => $doubaoResult->id,
            'platform' => 'doubao',
            'brand_name' => 'Case Brand',
            'mention_count' => 1,
            'mention_rank' => 1,
            'sentiment' => 'positive',
            'source_count' => 1,
            'is_target_brand' => true,
        ]);

        BrandDiagnosisBrandMention::query()->create([
            'site_id' => $site->id,
            'owner_admin_id' => $owner->id,
            'run_id' => $run->id,
            'question_id' => $question->id,
            'result_id' => $doubaoResult->id,
            'platform' => 'doubao',
            'brand_name' => 'Competitor Alpha',
            'mention_count' => 3,
            'mention_rank' => 2,
            'sentiment' => 'neutral',
            'source_count' => 2,
            'is_target_brand' => false,
        ]);

        BrandDiagnosisBrandMention::query()->create([
            'site_id' => $site->id,
            'owner_admin_id' => $owner->id,
            'run_id' => $run->id,
            'question_id' => $question->id,
            'result_id' => $qianwenResult->id,
            'platform' => 'qianwen',
            'brand_name' => 'Competitor Beta',
            'mention_count' => 1,
            'mention_rank' => 1,
            'sentiment' => 'neutral',
            'source_count' => 1,
            'is_target_brand' => false,
        ]);

        $this->get(route('product-cases.show', ['slug' => 'industry-competition-case']))
            ->assertOk()
            ->assertSee('行业竞争力')
            ->assertSee('品牌画像')
            ->assertSee('竞品表现')
            ->assertSee('情感倾向')
            ->assertSee('Competitor Alpha')
            ->assertSee('Competitor Beta')
            ->assertSee('TOP5');
    }

    public function test_product_case_public_nav_is_visible_to_logged_in_users_and_management_only_to_super_admin(): void
    {
        [$superAdmin] = $this->createAdminWithSite('super_nav_case', 'super_admin');
        [$user] = $this->createAdminWithSite('normal_nav_case', 'direct_admin');

        $this->actingAs($user, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.product-case-library.index'), false)
            ->assertSee('产品案例')
            ->assertDontSee(route('admin.product-cases.index'), false)
            ->assertDontSee('产品案例管理');

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.product-case-library.index'), false)
            ->assertSee(route('admin.product-cases.index'), false)
            ->assertSee('产品案例管理');
    }

    public function test_product_case_pages_include_basic_seo_metadata(): void
    {
        ProductCase::query()->create([
            'title' => 'SEO Product Case',
            'slug' => 'seo-product-case',
            'company_name' => 'SEO Brand',
            'summary' => 'SEO description summary',
            'content' => 'SEO body',
            'status' => ProductCase::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);

        $this->get(route('product-cases.index'))
            ->assertOk()
            ->assertSee('<script type="application/ld+json">', false)
            ->assertSee('CollectionPage');

        $this->get(route('product-cases.show', ['slug' => 'seo-product-case']))
            ->assertOk()
            ->assertSee('SEO description summary')
            ->assertSee('<script type="application/ld+json">', false)
            ->assertSee('Article');
    }

    public function test_product_case_json_ld_escapes_script_breakout_text(): void
    {
        ProductCase::query()->create([
            'title' => 'Bad </script><script>alert(1)</script>',
            'slug' => 'safe-json-ld-case',
            'company_name' => 'Safe Brand',
            'summary' => 'Summary with </script><script>alert(1)</script>',
            'content' => 'Body',
            'status' => ProductCase::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);

        $response = $this->get(route('product-cases.show', ['slug' => 'safe-json-ld-case']))
            ->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('\\u003C/script\\u003E\\u003Cscript\\u003Ealert(1)\\u003C/script\\u003E', $html);
        $this->assertStringNotContainsString('Bad </script><script>alert(1)</script>', $html);
    }

    /**
     * @return array{0: Admin, 1: Site}
     */
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
            'owner_admin_id' => (int) $admin->id,
            'name' => $username.' Site',
            'domain' => $username.'.example.test',
            'status' => 'active',
            'customer_mode' => 'direct',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'owner']);

        return [$admin, $site];
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function casePayload(Site $site, Admin $owner, array $overrides = []): array
    {
        return array_merge([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'title' => 'Product Case',
            'slug' => '',
            'company_name' => 'Case Company',
            'logo_url' => 'https://example.test/logo.png',
            'cover_url' => 'https://example.test/cover.png',
            'industry' => '商务服务',
            'region' => '北京市',
            'business_mode' => 'Direct',
            'module_tags' => 'Brand Diagnosis,AI Search Monitoring',
            'summary' => 'This is a manually maintained product case summary.',
            'content' => "## Company Profile\n\nManual product case body.",
            'customer_level' => 'Standard',
            'started_at' => now()->subMonth()->toDateString(),
            'status' => ProductCase::STATUS_DRAFT,
            'sort_order' => 0,
            'published_at' => now()->subDay()->format('Y-m-d\TH:i'),
        ], $overrides);
    }
}
