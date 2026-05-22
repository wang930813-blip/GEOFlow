<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KeywordQuestionVariant;
use App\Support\GeoFlow\ApiKeyCrypto;
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
        $library = KeywordLibrary::query()->create(['name' => 'Project', 'keyword_count' => 0]);
        $keyword = Keyword::query()->create([
            'library_id' => (int) $library->id,
            'keyword' => 'AI search visibility',
            'used_count' => 0,
            'usage_count' => 0,
        ]);

        $response = $this->actingAs($admin, 'admin')
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
        $library = KeywordLibrary::query()->create([
            'name' => 'Project',
            'company_name' => 'Acme',
            'domain_keyword' => 'GEO',
            'industry' => 'SaaS',
            'brand_description' => 'AI visibility platform',
            'keyword_count' => 0,
        ]);
        $keyword = Keyword::query()->create([
            'library_id' => (int) $library->id,
            'keyword' => 'AI search visibility',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        AiModel::query()->create([
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
}
