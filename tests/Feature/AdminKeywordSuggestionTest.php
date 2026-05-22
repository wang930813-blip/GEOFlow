<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminKeywordSuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_bulk_keyword_store_adds_new_keywords_and_skips_duplicates(): void
    {
        $admin = $this->createAdmin();
        $library = KeywordLibrary::query()->create([
            'name' => 'GEO Keywords',
            'description' => '',
            'keyword_count' => 1,
        ]);

        Keyword::query()->create([
            'library_id' => (int) $library->id,
            'keyword' => 'GEO优化',
            'used_count' => 0,
            'usage_count' => 0,
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.keyword-libraries.keywords.bulk-store', ['libraryId' => (int) $library->id]), [
                'keywords' => ['GEO优化', 'AI搜索优化', '品牌内容被AI引用', 'AI搜索优化', '  '],
            ]);

        $response
            ->assertRedirect(route('admin.keyword-libraries.detail', ['libraryId' => (int) $library->id]))
            ->assertSessionHas('message');

        $this->assertDatabaseHas('keywords', [
            'library_id' => (int) $library->id,
            'keyword' => 'GEO优化',
        ]);
        $this->assertDatabaseHas('keywords', [
            'library_id' => (int) $library->id,
            'keyword' => 'AI搜索优化',
        ]);
        $this->assertDatabaseHas('keywords', [
            'library_id' => (int) $library->id,
            'keyword' => '品牌内容被AI引用',
        ]);
        $this->assertSame(3, Keyword::query()->where('library_id', (int) $library->id)->count());
        $this->assertSame(3, (int) $library->fresh()->keyword_count);
    }

    public function test_suggest_keywords_requires_active_chat_model(): void
    {
        $admin = $this->createAdmin('keyword_suggestion_no_model_admin');
        $library = KeywordLibrary::query()->create([
            'name' => 'GEO Keywords',
            'description' => '',
            'keyword_count' => 0,
        ]);

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.keyword-libraries.keywords.suggest', ['libraryId' => (int) $library->id]), [
                'seed_keyword' => 'GEO优化',
                'count' => 20,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['message']);
    }

    public function test_suggest_keywords_returns_ai_generated_suggestions(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode(['GEO优化是什么', 'AI搜索优化怎么做'], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ], 200),
        ]);

        $admin = $this->createAdmin('keyword_suggestion_success_admin');
        $library = KeywordLibrary::query()->create([
            'name' => 'GEO Keywords',
            'description' => '',
            'keyword_count' => 0,
        ]);
        AiModel::query()->create([
            'name' => 'Keyword Chat',
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

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.keyword-libraries.keywords.suggest', ['libraryId' => (int) $library->id]), [
                'seed_keyword' => 'GEO优化',
                'count' => 20,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('suggestions.0', 'GEO优化是什么')
            ->assertJsonPath('suggestions.1', 'AI搜索优化怎么做');

        Http::assertSentCount(1);
    }

    private function createAdmin(string $username = 'keyword_suggestion_admin'): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => 'Keyword Suggestion Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }
}
