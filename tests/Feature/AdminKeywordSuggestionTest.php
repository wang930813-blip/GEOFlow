<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\Site;
use App\Services\GeoFlow\GeoQuestionVariantService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $site = $this->createSiteForAdmin($admin);
        $library = KeywordLibrary::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'GEO Keywords',
            'description' => '',
            'keyword_count' => 1,
        ]);

        Keyword::query()->create([
            'site_id' => (int) $site->id,
            'library_id' => (int) $library->id,
            'keyword' => 'GEO优化',
            'used_count' => 0,
            'usage_count' => 0,
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
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
        $site = $this->createSiteForAdmin($admin);
        $library = KeywordLibrary::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'GEO Keywords',
            'description' => '',
            'keyword_count' => 0,
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
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
        $site = $this->createSiteForAdmin($admin);
        $library = KeywordLibrary::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'GEO Keywords',
            'description' => '',
            'keyword_count' => 0,
        ]);
        AiModel::query()->create([
            'site_id' => (int) $site->id,
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
            ->withSession(['current_site_id' => (int) $site->id])
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

    public function test_question_variant_generation_uses_brand_profile_terms_when_backfilling(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ], 200),
        ]);

        $admin = $this->createAdmin('keyword_question_profile_admin');
        $site = $this->createSiteForAdmin($admin);
        $library = KeywordLibrary::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'AI经营关键词',
            'description' => '',
            'company_name' => '智营云',
            'domain_keyword' => 'AI经营增长',
            'industry' => '本地生活服务',
            'brand_description' => '智营云面向本地餐饮和零售门店，提供AI获客、客户管理、私域运营、数字人视频营销和经营分析，帮助门店老板解决客户少、复购低、内容生产慢的问题。',
            'keyword_count' => 1,
        ]);
        $keyword = Keyword::query()->create([
            'site_id' => (int) $site->id,
            'library_id' => (int) $library->id,
            'keyword' => 'AI获客工具',
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

        $this->actingAs($admin, 'admin')->withSession(['current_site_id' => (int) $site->id]);

        $questions = app(GeoQuestionVariantService::class)->generate($keyword, $library, 5);

        $this->assertCount(5, $questions);
        $joined = implode(' ', $questions);
        $this->assertStringContainsString('本地餐饮', $joined);
        $this->assertStringContainsString('门店老板', $joined);
        $this->assertStringContainsString('客户少', $joined);
        $this->assertStringContainsString('复购低', $joined);
        $this->assertStringNotContainsString('AI获客工具推荐 AI获客工具怎么选？ AI获客工具哪家靠谱？', $joined);

        $requestBody = json_encode(Http::recorded()->first()[0]->data(), JSON_UNESCAPED_UNICODE) ?: '';
        $this->assertStringContainsString('Core term package extracted from brand description', $requestBody);
        $this->assertStringContainsString('Dimension rotation', $requestBody);
        $this->assertStringContainsString('本地餐饮、零售门店', $requestBody);
        $this->assertStringContainsString('门店老板', $requestBody);
    }

    public function test_question_variant_generation_rejects_bare_keyword_generic_questions(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'AI获客工具哪家效果好？',
                            'AI获客工具哪个好？',
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ], 200),
        ]);

        $admin = $this->createAdmin('keyword_question_generic_admin');
        $site = $this->createSiteForAdmin($admin);
        $library = KeywordLibrary::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'AI经营关键词',
            'description' => '',
            'company_name' => '智营云',
            'domain_keyword' => 'AI经营增长',
            'industry' => '本地生活服务',
            'brand_description' => '智营云面向本地餐饮和零售门店，提供AI获客、客户管理、私域运营、数字人视频营销和经营分析，帮助门店老板解决客户少、复购低、内容生产慢的问题。',
            'keyword_count' => 1,
        ]);
        $keyword = Keyword::query()->create([
            'site_id' => (int) $site->id,
            'library_id' => (int) $library->id,
            'keyword' => 'AI获客工具',
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

        $this->actingAs($admin, 'admin')->withSession(['current_site_id' => (int) $site->id]);

        $questions = app(GeoQuestionVariantService::class)->generate($keyword, $library, 1);

        $this->assertCount(1, $questions);
        $this->assertNotContains($questions[0], ['AI获客工具哪家效果好？', 'AI获客工具哪个好？']);
        $this->assertMatchesRegularExpression('/本地餐饮|零售门店|门店老板|客户少|复购低|获客/u', $questions[0]);
    }

    public function test_question_variant_generation_extracts_terms_from_any_brand_profile_when_backfilling(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ], 200),
        ]);

        $admin = $this->createAdmin('keyword_question_generic_profile_admin');
        $site = $this->createSiteForAdmin($admin);
        $library = KeywordLibrary::query()->create([
            'site_id' => (int) $site->id,
            'name' => '婚礼影像关键词',
            'description' => '',
            'company_name' => '墨禾影像',
            'domain_keyword' => '婚礼影像服务',
            'industry' => '摄影服务',
            'brand_description' => '墨禾影像面向新人和婚礼策划团队，提供婚礼跟拍、纪实摄影、视频剪辑和旅拍服务，主打户外婚礼、目的地婚礼和亲友聚会场景，帮助用户解决拍摄风格不统一、成片交付慢的问题。',
            'keyword_count' => 1,
        ]);
        $keyword = Keyword::query()->create([
            'site_id' => (int) $site->id,
            'library_id' => (int) $library->id,
            'keyword' => '婚礼影像服务',
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

        $this->actingAs($admin, 'admin')->withSession(['current_site_id' => (int) $site->id]);

        $questions = app(GeoQuestionVariantService::class)->generate($keyword, $library, 5);

        $this->assertCount(5, $questions);
        $joined = implode(' ', $questions);
        $this->assertMatchesRegularExpression('/新人|婚礼策划团队|婚礼跟拍|纪实摄影|户外婚礼|成片交付慢/u', $joined);
        $this->assertStringNotContainsString('中小企业', $joined);
        $this->assertStringNotContainsString('本地商家', $joined);
        $this->assertStringNotContainsString('业务增长', $joined);
        $this->assertStringNotContainsString('客户转化', $joined);
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

    private function createSiteForAdmin(Admin $admin): Site
    {
        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => 'Keyword Suggestion Test Site',
            'status' => 'active',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'owner']);

        return $site;
    }
}
