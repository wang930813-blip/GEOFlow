<?php

namespace Tests\Feature;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KeywordQuestionVariant;
use App\Models\Site;
use App\Support\GeoFlow\ApiKeyCrypto;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminGeoProjectPhaseOneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_keyword_library_persists_project_brand_metadata(): void
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.keyword-libraries.store'), [
                'name' => 'Acme GEO Project',
                'description' => 'Project notes',
                'company_name' => 'Acme Inc',
                'domain_keyword' => 'GEO automation',
                'industry' => 'SaaS',
                'brand_description' => 'Acme helps teams manage AI search visibility.',
            ]);

        $response->assertRedirect(route('admin.keyword-libraries.index'));

        $this->assertDatabaseHas('keyword_libraries', [
            'name' => 'Acme GEO Project',
            'company_name' => 'Acme Inc',
            'domain_keyword' => 'GEO automation',
            'industry' => 'SaaS',
            'brand_description' => 'Acme helps teams manage AI search visibility.',
            'status' => 'active',
        ]);
    }

    public function test_admin_can_create_keyword_question_variant(): void
    {
        $admin = $this->createAdmin('geo_question_admin');
        $site = $this->createSiteForAdmin($admin);
        $library = KeywordLibrary::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Project',
            'keyword_count' => 0,
        ]);
        $keyword = Keyword::query()->create([
            'site_id' => (int) $site->id,
            'library_id' => (int) $library->id,
            'keyword' => 'AI search visibility',
            'used_count' => 0,
            'usage_count' => 0,
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.keyword-libraries.keywords.questions.store', [
                'libraryId' => (int) $library->id,
                'keywordId' => (int) $keyword->id,
            ]), [
                'question' => 'Which tools improve AI search visibility?',
            ]);

        $response->assertRedirect(route('admin.keyword-libraries.detail', ['libraryId' => (int) $library->id]));
        $this->assertDatabaseHas('keyword_question_variants', [
            'keyword_id' => (int) $keyword->id,
            'question' => 'Which tools improve AI search visibility?',
        ]);
    }

    public function test_admin_can_generate_keyword_question_variants_with_ai(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'What is AI search visibility?',
                            'How do brands improve GEO visibility?',
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ], 200),
        ]);

        $admin = $this->createAdmin('geo_question_ai_admin');
        $site = $this->createSiteForAdmin($admin);
        $library = KeywordLibrary::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Project',
            'company_name' => 'Acme',
            'domain_keyword' => 'GEO',
            'industry' => 'SaaS',
            'brand_description' => 'AI visibility platform',
            'keyword_count' => 0,
        ]);
        $keyword = Keyword::query()->create([
            'site_id' => (int) $site->id,
            'library_id' => (int) $library->id,
            'keyword' => 'AI search visibility',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        AiModel::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Question Chat',
            'version' => '',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
            'model_id' => 'test-chat',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test/v1',
            'failover_priority' => 1,
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->postJson(route('admin.keyword-libraries.keywords.questions.generate', [
                'libraryId' => (int) $library->id,
                'keywordId' => (int) $keyword->id,
            ]), [
                'count' => 2,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('questions.0', 'What is AI search visibility?')
            ->assertJsonPath('questions.1', 'How do brands improve GEO visibility?');

        $this->assertSame(2, KeywordQuestionVariant::query()->where('keyword_id', (int) $keyword->id)->count());
    }

    public function test_ai_question_generation_prompt_requests_mixed_query_types(): void
    {
        $capturedPrompt = null;
        MarkdownContentWriterAgent::fake(function (string $prompt) use (&$capturedPrompt): string {
            $capturedPrompt = $prompt;

            return json_encode([
                '论文润色平台',
                '写作指导怎么选',
                '论文润色和写作指导服务怎么选？',
                '论文润色平台怎么判断是否专业？',
                '硕士论文需要润色和写作指导，线上服务靠谱吗？',
            ], JSON_UNESCAPED_UNICODE);
        })->preventStrayPrompts();

        $admin = $this->createAdmin('geo_question_prompt_mix_admin');
        $site = $this->createSiteForAdmin($admin);
        $library = KeywordLibrary::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Academic Project',
            'company_name' => '学术易',
            'domain_keyword' => '科研论文服务',
            'industry' => '学术服务',
            'brand_description' => '提供论文润色和写作指导服务。',
            'keyword_count' => 1,
        ]);
        $keyword = Keyword::query()->create([
            'site_id' => (int) $site->id,
            'library_id' => (int) $library->id,
            'keyword' => '论文润色和写作指导怎么选',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        AiModel::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Question Chat',
            'version' => '',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
            'model_id' => 'test-chat',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test/v1',
            'failover_priority' => 1,
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->postJson(route('admin.keyword-libraries.keywords.questions.generate', [
                'libraryId' => (int) $library->id,
                'keywordId' => (int) $keyword->id,
            ]), [
                'count' => 5,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('questions.0', '论文润色平台')
            ->assertJsonPath('questions.4', '硕士论文需要润色和写作指导，线上服务靠谱吗？');

        $this->assertIsString($capturedPrompt);
        $this->assertStringContainsString('Query mix:', $capturedPrompt);
        $this->assertStringContainsString('2 short keyword-style searches', $capturedPrompt);
        $this->assertStringContainsString('2 medium direct questions', $capturedPrompt);
        $this->assertStringContainsString('1 scenario-based decision question', $capturedPrompt);
        $this->assertStringContainsString('Keyword intent guidance:', $capturedPrompt);
        $this->assertStringContainsString('short searches do not need question marks', $capturedPrompt);
        $this->assertStringContainsString('Do not make every variant repeat the keyword verbatim', $capturedPrompt);
        $this->assertStringContainsString('For brand-name keywords, include about 2 variants with the brand name and about 2 variants without the brand name', $capturedPrompt);
        $this->assertStringContainsString('Industry or demand variants should use service, recommendation, comparison, and scenario terms', $capturedPrompt);
    }

    public function test_keyword_library_detail_shows_all_question_variants(): void
    {
        $admin = $this->createAdmin('geo_show_all_questions_admin');
        $site = $this->createSiteForAdmin($admin);
        $library = KeywordLibrary::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Project',
            'keyword_count' => 1,
        ]);
        $keyword = Keyword::query()->create([
            'site_id' => (int) $site->id,
            'library_id' => (int) $library->id,
            'keyword' => 'AI search visibility',
            'used_count' => 0,
            'usage_count' => 0,
        ]);

        foreach (range(1, 6) as $index) {
            KeywordQuestionVariant::query()->create([
                'site_id' => (int) $site->id,
                'keyword_id' => (int) $keyword->id,
                'question' => 'Question variant '.$index,
                'created_at' => CarbonImmutable::parse('2026-05-23 10:0'.$index.':00'),
                'updated_at' => CarbonImmutable::parse('2026-05-23 10:0'.$index.':00'),
            ]);
        }

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.keyword-libraries.detail', ['libraryId' => (int) $library->id]));

        $response->assertOk();
        foreach (range(1, 6) as $index) {
            $response->assertSee('Question variant '.$index);
        }
    }

    public function test_admin_can_update_keyword_and_question_variant_from_detail(): void
    {
        $admin = $this->createAdmin('geo_edit_keyword_admin');
        $site = $this->createSiteForAdmin($admin);
        $library = KeywordLibrary::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Project',
            'keyword_count' => 1,
        ]);
        $keyword = Keyword::query()->create([
            'site_id' => (int) $site->id,
            'library_id' => (int) $library->id,
            'keyword' => 'AI search visibility',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $variant = KeywordQuestionVariant::query()->create([
            'site_id' => (int) $site->id,
            'keyword_id' => (int) $keyword->id,
            'question' => 'Which tools improve AI search visibility?',
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->put(route('admin.keyword-libraries.keywords.update', [
                'libraryId' => (int) $library->id,
                'keywordId' => (int) $keyword->id,
            ]), [
                'keyword' => 'GEO answer visibility',
            ])
            ->assertRedirect(route('admin.keyword-libraries.detail', ['libraryId' => (int) $library->id]));

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->put(route('admin.keyword-libraries.keywords.questions.update', [
                'libraryId' => (int) $library->id,
                'keywordId' => (int) $keyword->id,
                'questionId' => (int) $variant->id,
            ]), [
                'question' => 'How can brands improve GEO answer visibility?',
            ])
            ->assertRedirect(route('admin.keyword-libraries.detail', ['libraryId' => (int) $library->id]));

        $this->assertDatabaseHas('keywords', [
            'id' => (int) $keyword->id,
            'keyword' => 'GEO answer visibility',
        ]);
        $this->assertDatabaseHas('keyword_question_variants', [
            'id' => (int) $variant->id,
            'question' => 'How can brands improve GEO answer visibility?',
        ]);
    }

    public function test_admin_can_delete_question_variant_from_detail(): void
    {
        $admin = $this->createAdmin('geo_delete_question_admin');
        $site = $this->createSiteForAdmin($admin);
        $library = KeywordLibrary::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Project',
            'keyword_count' => 1,
        ]);
        $keyword = Keyword::query()->create([
            'site_id' => (int) $site->id,
            'library_id' => (int) $library->id,
            'keyword' => 'AI search visibility',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $variant = KeywordQuestionVariant::query()->create([
            'site_id' => (int) $site->id,
            'keyword_id' => (int) $keyword->id,
            'question' => 'Which tools improve AI search visibility?',
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->delete(route('admin.keyword-libraries.keywords.questions.delete', [
                'libraryId' => (int) $library->id,
                'keywordId' => (int) $keyword->id,
                'questionId' => (int) $variant->id,
            ]))
            ->assertRedirect(route('admin.keyword-libraries.detail', ['libraryId' => (int) $library->id]));

        $this->assertDatabaseMissing('keyword_question_variants', [
            'id' => (int) $variant->id,
        ]);
    }

    private function createAdmin(string $username = 'geo_phase_one_admin'): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => 'GEO Phase One Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function createSiteForAdmin(Admin $admin): Site
    {
        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => 'Acme Site',
            'status' => 'active',
        ]);
        $site->members()->attach($admin->id, ['role' => 'owner']);
        $this->openTestingPlanForSite($site, $admin);

        return $site;
    }
}
