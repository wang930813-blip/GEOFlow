<?php

namespace Tests\Feature;

use App\Models\BrandDiagnosisRun;
use App\Services\BrandDiagnosis\BrandProfileNotFoundException;
use App\Services\BrandDiagnosis\BrandProfileResolver;
use App\Services\BrandDiagnosis\DoubaoBrandDiagnosisClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BrandDiagnosisLookupApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('brand_diagnosis.lookup_api.enabled', true);
        Config::set('brand_diagnosis.lookup_api.api_key', 'test-lookup-key');
        Config::set('brand_diagnosis.lookup_api.cache_ttl', 0);
    }

    public function test_lookup_api_uses_an_independent_api_key(): void
    {
        $this->getJson('/api/v1/brand-diagnoses/search?brand_word=策影GEO')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_api_key');

        Config::set('brand_diagnosis.open_api.enabled', true);
        Config::set('brand_diagnosis.open_api.api_key', 'test-open-key');

        $this->withHeader('X-Api-Key', 'test-open-key')
            ->getJson('/api/v1/brand-diagnoses/search?brand_word=策影GEO')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_api_key');
    }

    public function test_lookup_api_rejects_unknown_include_before_querying(): void
    {
        $this->withHeader('X-Api-Key', 'test-lookup-key')
            ->getJson('/api/v1/brand-diagnoses/search?brand_word=策影GEO&include=questions,unknown')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_lookup_api_returns_newest_cross_site_canonical_match(): void
    {
        $older = BrandDiagnosisRun::query()->create([
            'site_id' => null,
            'brand_name' => '推来客网络科技有限公司',
            'platforms' => ['doubao'],
            'status' => 'completed',
            'brand_profile' => '推来客提供网络营销和 GEO 服务。',
            'brand_profile_source' => 'web_search',
            'brand_profile_model' => '豆包',
            'brand_profile_status' => 'success',
            'total_questions' => 1,
            'completed_questions' => 1,
            'brand_score' => 70,
        ]);
        $older->forceFill(['created_at' => now()->subDay()])->save();

        $newer = BrandDiagnosisRun::query()->create([
            'site_id' => null,
            'brand_name' => '推来客',
            'platforms' => ['doubao'],
            'status' => 'completed',
            'brand_profile' => '推来客是 GEO 品牌诊断服务。',
            'brand_profile_source' => 'web_search',
            'brand_profile_model' => '豆包',
            'brand_profile_status' => 'success',
            'total_questions' => 1,
            'completed_questions' => 1,
            'brand_score' => 90,
        ]);
        $newer->forceFill(['created_at' => now()])->save();

        $this->withHeader('X-Api-Key', 'test-lookup-key')
            ->getJson('/api/v1/brand-diagnoses/search?brand_word=四川推来客网络科技有限公司&include=profile,performance')
            ->assertOk()
            ->assertJsonPath('data.data_source', 'stored')
            ->assertJsonPath('data.match_type', 'canonical')
            ->assertJsonPath('data.diagnosis.brand_name', '推来客')
            ->assertJsonPath('data.brand_profile.text', '推来客是 GEO 品牌诊断服务。')
            ->assertJsonPath('data.brand_performance.score', 90)
            ->assertJsonPath('meta.included.0', 'profile')
            ->assertJsonPath('meta.included.1', 'performance');
    }

    public function test_lookup_api_can_return_only_requested_modules(): void
    {
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => null,
            'brand_name' => '策影GEO',
            'platforms' => ['doubao'],
            'status' => 'completed',
            'brand_profile' => '策影 GEO 提供品牌增长服务。',
            'brand_profile_status' => 'success',
            'total_questions' => 1,
            'completed_questions' => 1,
            'brand_score' => 88,
        ]);
        $run->questions()->create([
            'site_id' => null,
            'question' => '策影GEO 是什么？',
            'question_type' => '品牌认知',
            'core_term' => '策影GEO',
            'sort_order' => 1,
            'status' => 'pending',
        ]);

        $this->withHeader('X-Api-Key', 'test-lookup-key')
            ->getJson('/api/v1/brand-diagnoses/search?brand_word=策影GEO&include=questions')
            ->assertOk()
            ->assertJsonPath('data.questions.0.question', '策影GEO 是什么？')
            ->assertJsonPath('data.brand_performance', null)
            ->assertJsonPath('data.module_status.questions', 'included')
            ->assertJsonPath('data.module_status.performance', 'omitted')
            ->assertJsonPath('meta.included.0', 'questions')
            ->assertJsonPath('meta.omitted.0', 'profile');
    }

    public function test_lookup_api_can_return_sources_without_loading_other_modules(): void
    {
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => null,
            'brand_name' => '信源测试品牌',
            'platforms' => ['doubao'],
            'status' => 'completed',
            'brand_profile' => '信源测试品牌是一家企业服务品牌。',
            'brand_profile_status' => 'success',
        ]);
        $question = $run->questions()->create([
            'site_id' => null,
            'question' => '信源测试品牌怎么样？',
            'question_type' => '品牌认知',
            'sort_order' => 1,
            'status' => 'completed',
        ]);
        $result = $question->results()->create([
            'site_id' => null,
            'run_id' => $run->id,
            'platform' => 'doubao',
            'status' => 'success',
            'answer' => '信源测试品牌值得了解。',
            'checked_at' => now(),
        ]);
        $run->sources()->create([
            'site_id' => null,
            'question_id' => $question->id,
            'result_id' => $result->id,
            'platform' => 'doubao',
            'title' => '官网',
            'url' => 'https://example.com/brand',
            'domain' => 'example.com',
            'source_type' => 'web_search_result',
        ]);

        $this->withHeader('X-Api-Key', 'test-lookup-key')
            ->getJson('/api/v1/brand-diagnoses/search?brand_word=信源测试品牌&include=sources')
            ->assertOk()
            ->assertJsonPath('data.ai_sources.0.url', 'https://example.com/brand')
            ->assertJsonPath('data.module_status.sources', 'included')
            ->assertJsonPath('data.module_status.model_results', 'omitted');
    }

    public function test_lookup_api_returns_aggregated_competitors_without_target_brand(): void
    {
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => null,
            'brand_name' => '竞品聚合测试品牌',
            'platforms' => ['doubao'],
            'status' => 'completed',
            'brand_profile' => '竞品聚合测试品牌是一家企业服务品牌。',
            'brand_profile_status' => 'success',
            'total_questions' => 2,
            'completed_questions' => 2,
        ]);
        $questionOne = $run->questions()->create([
            'site_id' => null,
            'question' => '竞品聚合测试品牌有哪些竞品？',
            'question_type' => '竞品分析',
            'sort_order' => 1,
            'status' => 'completed',
        ]);
        $questionTwo = $run->questions()->create([
            'site_id' => null,
            'question' => '竞品聚合测试品牌和竞品甲哪个好？',
            'question_type' => '竞品分析',
            'sort_order' => 2,
            'status' => 'completed',
        ]);
        $resultOne = $questionOne->results()->create([
            'site_id' => null,
            'run_id' => $run->id,
            'platform' => 'doubao',
            'status' => 'success',
            'answer' => '竞品回答一',
        ]);
        $resultTwo = $questionTwo->results()->create([
            'site_id' => null,
            'run_id' => $run->id,
            'platform' => 'doubao',
            'status' => 'success',
            'answer' => '竞品回答二',
        ]);

        $resultOne->brandMentions()->createMany([
            [
                'site_id' => null,
                'run_id' => $run->id,
                'question_id' => $questionOne->id,
                'platform' => 'doubao',
                'brand_name' => '竞品甲',
                'mention_count' => 2,
                'mention_rank' => 3,
                'sentiment' => 'positive',
                'source_count' => 1,
                'is_target_brand' => false,
            ],
            [
                'site_id' => null,
                'run_id' => $run->id,
                'question_id' => $questionOne->id,
                'platform' => 'doubao',
                'brand_name' => '竞品聚合测试品牌',
                'mention_count' => 2,
                'mention_rank' => 1,
                'sentiment' => 'positive',
                'source_count' => 1,
                'is_target_brand' => true,
            ],
        ]);
        $resultTwo->brandMentions()->create([
            'site_id' => null,
            'run_id' => $run->id,
            'question_id' => $questionTwo->id,
            'platform' => 'doubao',
            'brand_name' => '竞品甲',
            'mention_count' => 1,
            'mention_rank' => 2,
            'sentiment' => 'neutral',
            'source_count' => 2,
            'is_target_brand' => false,
        ]);
        $resultTwo->brandMentions()->create([
            'site_id' => null,
            'run_id' => $run->id,
            'question_id' => $questionTwo->id,
            'platform' => 'doubao',
            'brand_name' => '竞品乙',
            'mention_count' => 4,
            'mention_rank' => 5,
            'sentiment' => 'negative',
            'source_count' => 1,
            'is_target_brand' => false,
        ]);

        $this->withHeader('X-Api-Key', 'test-lookup-key')
            ->getJson('/api/v1/brand-diagnoses/search?brand_word=竞品聚合测试品牌&include=competitors')
            ->assertOk()
            ->assertJsonPath('data.competitors.0.brand_name', '竞品乙')
            ->assertJsonPath('data.competitors.0.mention_count', 4)
            ->assertJsonPath('data.competitors.0.best_rank', 5)
            ->assertJsonPath('data.competitors.0.source_count', 1)
            ->assertJsonPath('data.competitors.0.sentiment', 'negative')
            ->assertJsonPath('data.competitors.1.brand_name', '竞品甲')
            ->assertJsonPath('data.competitors.1.mention_count', 3)
            ->assertJsonPath('data.competitors.1.best_rank', 2)
            ->assertJsonPath('data.competitors.1.source_count', 3)
            ->assertJsonPath('data.competitors.1.sentiment', 'positive')
            ->assertJsonPath('data.brand_profile', null)
            ->assertJsonPath('data.module_status.competitors', 'included')
            ->assertJsonPath('data.module_status.questions', 'omitted');
    }

    public function test_lookup_api_generates_non_stock_profile_and_questions_without_persisting(): void
    {
        Queue::fake();
        $this->mock(BrandProfileResolver::class, function ($mock): void {
            $mock->shouldReceive('resolveStrict')->once()->andReturn([
                'profile' => '不存在存量诊断的测试品牌是一家提供企业软件的品牌。',
                'source' => 'web_search',
                'model' => '豆包',
                'status' => 'success',
                'meta' => ['sources' => [['title' => '官网', 'url' => 'https://example.com']]],
            ]);
        });
        $this->mock(DoubaoBrandDiagnosisClient::class, function ($mock): void {
            $mock->shouldReceive('generateQuestionPool')->once()->andReturn([
                ['question' => '不存在存量诊断的测试品牌适合哪些企业？', 'type' => '品牌认知', 'core_term' => '测试品牌'],
            ]);
        });

        $this->withHeader('X-Api-Key', 'test-lookup-key')
            ->getJson('/api/v1/brand-diagnoses/search?brand_word=不存在存量诊断的测试品牌&include=profile,questions,competitors')
            ->assertOk()
            ->assertJsonPath('data.data_source', 'generated_not_stock')
            ->assertJsonPath('data.match_type', 'none')
            ->assertJsonPath('data.diagnosis.status', 'not_run')
            ->assertJsonPath('data.brand_profile.status', 'generated')
            ->assertJsonPath('data.questions.0.status', 'generated')
            ->assertJsonPath('data.brand_performance', null)
            ->assertJsonPath('data.competitors', [])
            ->assertJsonPath('data.module_status.competitors', 'not_run');

        $this->assertDatabaseCount('brand_diagnosis_runs', 0);
        $this->assertDatabaseCount('brand_diagnosis_questions', 0);
        Queue::assertNothingPushed();
    }

    public function test_lookup_api_returns_brand_profile_not_found_error_for_unverified_brand(): void
    {
        $this->mock(BrandProfileResolver::class, function ($mock): void {
            $mock->shouldReceive('resolveStrict')->once()->andThrow(new BrandProfileNotFoundException('not found'));
        });

        $this->withHeader('X-Api-Key', 'test-lookup-key')
            ->getJson('/api/v1/brand-diagnoses/search?brand_word=无法核实的测试品牌')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'brand_profile_not_found');
    }
}
