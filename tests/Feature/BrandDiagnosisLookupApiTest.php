<?php

namespace Tests\Feature;

use App\Jobs\GenerateBrandDiagnosisLookupJob;
use App\Models\BrandDiagnosisLookupJob;
use App\Models\BrandDiagnosisRun;
use App\Services\BrandDiagnosis\BrandDiagnosisLookupService;
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

    public function test_lookup_api_rejects_unsupported_model_filter(): void
    {
        $this->withHeader('X-Api-Key', 'test-lookup-key')
            ->getJson('/api/v1/brand-diagnoses/search?brand_word=策影GEO&include=performance&model=yuanbao')
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

    public function test_lookup_api_sanitizes_legacy_snapshot_answers(): void
    {
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => null,
            'brand_name' => '历史快照品牌',
            'platforms' => ['doubao'],
            'status' => 'completed',
            'brand_profile' => '历史快照品牌是一家企业服务品牌。',
            'brand_profile_status' => 'success',
        ]);
        $question = $run->questions()->create([
            'site_id' => null,
            'question' => '历史快照品牌怎么样？',
            'question_type' => '品牌认知',
            'sort_order' => 1,
            'status' => 'completed',
        ]);
        $result = $question->results()->create([
            'site_id' => null,
            'run_id' => $run->id,
            'platform' => 'doubao',
            'status' => 'success',
            'answer' => '备用回答',
            'checked_at' => now(),
        ]);
        $result->forceFill([
            'snapshot_payload' => [
                'answer' => '{"answer":"可展示回答","meta":{"internal":"secret"}}',
                'meta' => ['internal' => 'secret'],
            ],
        ])->save();

        $this->withHeader('X-Api-Key', 'test-lookup-key')
            ->getJson('/api/v1/brand-diagnoses/search?brand_word=历史快照品牌&include=snapshots')
            ->assertOk()
            ->assertJsonPath('data.conversation_snapshots.0.answer', '可展示回答')
            ->assertJsonMissing(['internal' => 'secret']);
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
            ->assertJsonPath('data.competitors.model', 'all')
            ->assertJsonPath('data.competitors.rows.0.brand_name', '竞品乙')
            ->assertJsonPath('data.competitors.rows.0.mention_count', 4)
            ->assertJsonPath('data.competitors.rows.0.best_rank', 5)
            ->assertJsonPath('data.competitors.rows.0.source_count', 1)
            ->assertJsonPath('data.competitors.rows.0.sentiment', 'negative')
            ->assertJsonPath('data.competitors.rows.1.brand_name', '竞品甲')
            ->assertJsonPath('data.competitors.rows.1.mention_count', 3)
            ->assertJsonPath('data.competitors.rows.1.best_rank', 2)
            ->assertJsonPath('data.competitors.rows.1.source_count', 3)
            ->assertJsonPath('data.competitors.rows.1.sentiment', 'positive')
            ->assertJsonPath('data.competitors.by_model.0.model', 'doubao')
            ->assertJsonPath('data.brand_profile', null)
            ->assertJsonPath('data.module_status.competitors', 'included')
            ->assertJsonPath('data.module_status.questions', 'omitted');
    }

    public function test_lookup_api_filters_brand_performance_by_model(): void
    {
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => null,
            'brand_name' => '分模型表现品牌',
            'platforms' => ['doubao', 'deepseek'],
            'status' => 'completed',
            'brand_profile' => '分模型表现品牌是一家企业服务品牌。',
            'brand_profile_status' => 'success',
            'total_questions' => 2,
            'completed_questions' => 2,
            'brand_score' => 80,
            'mention_rate' => 75,
            'average_rank' => 1.5,
            'mention_count' => 4,
            'sentiment_rate' => 100,
        ]);
        $questionOne = $run->questions()->create([
            'site_id' => null,
            'question' => '分模型表现品牌怎么样？',
            'question_type' => '品牌认知',
            'sort_order' => 1,
            'status' => 'completed',
        ]);
        $questionTwo = $run->questions()->create([
            'site_id' => null,
            'question' => '分模型表现品牌适合哪些场景？',
            'question_type' => '品牌认知',
            'sort_order' => 2,
            'status' => 'completed',
        ]);

        $doubaoMentioned = $questionOne->results()->create([
            'site_id' => null,
            'run_id' => $run->id,
            'platform' => 'doubao',
            'status' => 'success',
            'answer' => '分模型表现品牌在豆包中被推荐。',
            'brand_mentioned' => true,
            'mention_count' => 1,
            'mention_rank' => 1,
            'sentiment' => 'positive',
        ]);
        $questionTwo->results()->create([
            'site_id' => null,
            'run_id' => $run->id,
            'platform' => 'doubao',
            'status' => 'success',
            'answer' => '豆包第二条回答没有提及目标品牌。',
            'brand_mentioned' => false,
            'mention_count' => 0,
            'mention_rank' => 0,
            'sentiment' => 'neutral',
        ]);
        $deepseekOne = $questionOne->results()->create([
            'site_id' => null,
            'run_id' => $run->id,
            'platform' => 'deepseek',
            'status' => 'success',
            'answer' => 'DeepSeek 第一条推荐分模型表现品牌。',
            'brand_mentioned' => true,
            'mention_count' => 1,
            'mention_rank' => 2,
            'sentiment' => 'positive',
        ]);
        $deepseekTwo = $questionTwo->results()->create([
            'site_id' => null,
            'run_id' => $run->id,
            'platform' => 'deepseek',
            'status' => 'success',
            'answer' => 'DeepSeek 第二条也提及分模型表现品牌。',
            'brand_mentioned' => true,
            'mention_count' => 1,
            'mention_rank' => 2,
            'sentiment' => 'negative',
        ]);

        $doubaoMentioned->brandMentions()->create([
            'site_id' => null,
            'run_id' => $run->id,
            'question_id' => $questionOne->id,
            'platform' => 'doubao',
            'brand_name' => '分模型表现品牌',
            'mention_count' => 2,
            'mention_rank' => 1,
            'sentiment' => 'positive',
            'source_count' => 1,
            'is_target_brand' => true,
        ]);
        foreach ([$deepseekOne, $deepseekTwo] as $result) {
            $result->brandMentions()->create([
                'site_id' => null,
                'run_id' => $run->id,
                'question_id' => $result->question_id,
                'platform' => 'deepseek',
                'brand_name' => '分模型表现品牌',
                'mention_count' => 1,
                'mention_rank' => 2,
                'sentiment' => $result->id === $deepseekOne->id ? 'positive' : 'negative',
                'source_count' => 1,
                'is_target_brand' => true,
            ]);
        }

        $response = $this->withHeader('X-Api-Key', 'test-lookup-key')
            ->getJson('/api/v1/brand-diagnoses/search?brand_word=分模型表现品牌&include=performance&model=doubao')
            ->assertOk()
            ->assertJsonPath('data.brand_performance.model', 'doubao')
            ->assertJsonPath('data.brand_performance.model_label', '豆包')
            ->assertJsonPath('data.brand_performance.mention_rate', 50)
            ->assertJsonPath('data.brand_performance.average_rank', '1')
            ->assertJsonPath('data.brand_performance.mention_count', 2)
            ->assertJsonPath('data.brand_performance.sentiment_rate', 100)
            ->assertJsonPath('data.brand_performance.by_model.0.model', 'doubao')
            ->assertJsonPath('data.module_status.performance', 'included');

        $this->assertCount(1, $response->json('data.brand_performance.by_model'));

        $this->withHeader('X-Api-Key', 'test-lookup-key')
            ->getJson('/api/v1/brand-diagnoses/search?brand_word=分模型表现品牌&include=performance')
            ->assertOk()
            ->assertJsonPath('data.brand_performance.model', 'all')
            ->assertJsonPath('data.brand_performance.model_label', '全部平台')
            ->assertJsonPath('data.brand_performance.score', 80)
            ->assertJsonPath('data.brand_performance.by_model.0.model', 'doubao')
            ->assertJsonPath('data.brand_performance.by_model.1.model', 'deepseek')
            ->assertJsonPath('data.brand_performance.by_model.2.model', 'qianwen')
            ->assertJsonPath('data.brand_performance.by_model.3.model', 'wenxin');
    }

    public function test_lookup_api_returns_platform_analysis_and_competitor_visibility_modules(): void
    {
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => null,
            'brand_name' => '行业分析测试品牌',
            'platforms' => ['doubao', 'deepseek'],
            'status' => 'completed',
            'brand_profile' => '行业分析测试品牌是一家企业服务品牌。',
            'brand_profile_status' => 'success',
            'total_questions' => 2,
            'completed_questions' => 2,
        ]);
        $questionOne = $run->questions()->create([
            'site_id' => null,
            'question' => '行业分析测试品牌怎么样？',
            'question_type' => '品牌认知',
            'sort_order' => 1,
            'status' => 'completed',
        ]);
        $questionTwo = $run->questions()->create([
            'site_id' => null,
            'question' => '行业分析测试品牌有哪些竞品？',
            'question_type' => '竞品分析',
            'sort_order' => 2,
            'status' => 'completed',
        ]);

        $doubaoOne = $questionOne->results()->create([
            'site_id' => null,
            'run_id' => $run->id,
            'platform' => 'doubao',
            'status' => 'success',
            'answer' => '豆包推荐行业分析测试品牌和竞品甲。',
            'sentiment' => 'positive',
        ]);
        $doubaoTwo = $questionTwo->results()->create([
            'site_id' => null,
            'run_id' => $run->id,
            'platform' => 'doubao',
            'status' => 'success',
            'answer' => '豆包再次提及行业分析测试品牌、竞品甲和竞品乙。',
            'sentiment' => 'neutral',
        ]);
        $deepseekOne = $questionOne->results()->create([
            'site_id' => null,
            'run_id' => $run->id,
            'platform' => 'deepseek',
            'status' => 'success',
            'answer' => 'DeepSeek 推荐行业分析测试品牌和竞品甲。',
            'sentiment' => 'positive',
        ]);

        $doubaoOne->brandMentions()->createMany([
            [
                'site_id' => null,
                'run_id' => $run->id,
                'question_id' => $questionOne->id,
                'platform' => 'doubao',
                'brand_name' => '行业分析测试品牌',
                'mention_count' => 1,
                'mention_rank' => 1,
                'sentiment' => 'positive',
                'source_count' => 1,
                'is_target_brand' => true,
            ],
            [
                'site_id' => null,
                'run_id' => $run->id,
                'question_id' => $questionOne->id,
                'platform' => 'doubao',
                'brand_name' => '竞品甲',
                'mention_count' => 2,
                'mention_rank' => 2,
                'sentiment' => 'positive',
                'source_count' => 1,
                'is_target_brand' => false,
            ],
        ]);
        $doubaoTwo->brandMentions()->createMany([
            [
                'site_id' => null,
                'run_id' => $run->id,
                'question_id' => $questionTwo->id,
                'platform' => 'doubao',
                'brand_name' => '行业分析测试品牌',
                'mention_count' => 1,
                'mention_rank' => 2,
                'sentiment' => 'neutral',
                'source_count' => 1,
                'is_target_brand' => true,
            ],
            [
                'site_id' => null,
                'run_id' => $run->id,
                'question_id' => $questionTwo->id,
                'platform' => 'doubao',
                'brand_name' => '竞品甲',
                'mention_count' => 1,
                'mention_rank' => 4,
                'sentiment' => 'neutral',
                'source_count' => 1,
                'is_target_brand' => false,
            ],
            [
                'site_id' => null,
                'run_id' => $run->id,
                'question_id' => $questionTwo->id,
                'platform' => 'doubao',
                'brand_name' => '竞品乙',
                'mention_count' => 1,
                'mention_rank' => 3,
                'sentiment' => 'neutral',
                'source_count' => 1,
                'is_target_brand' => false,
            ],
        ]);
        $deepseekOne->brandMentions()->createMany([
            [
                'site_id' => null,
                'run_id' => $run->id,
                'question_id' => $questionOne->id,
                'platform' => 'deepseek',
                'brand_name' => '行业分析测试品牌',
                'mention_count' => 1,
                'mention_rank' => 3,
                'sentiment' => 'positive',
                'source_count' => 1,
                'is_target_brand' => true,
            ],
            [
                'site_id' => null,
                'run_id' => $run->id,
                'question_id' => $questionOne->id,
                'platform' => 'deepseek',
                'brand_name' => '竞品甲',
                'mention_count' => 1,
                'mention_rank' => 1,
                'sentiment' => 'positive',
                'source_count' => 1,
                'is_target_brand' => false,
            ],
        ]);

        $run->sources()->createMany([
            [
                'site_id' => null,
                'question_id' => $questionOne->id,
                'result_id' => $doubaoOne->id,
                'platform' => 'doubao',
                'title' => '豆包信源一',
                'url' => 'https://doubao-source.test/a',
                'domain' => 'doubao-source.test',
                'source_type' => 'web_search_result',
            ],
            [
                'site_id' => null,
                'question_id' => $questionTwo->id,
                'result_id' => $doubaoTwo->id,
                'platform' => 'doubao',
                'title' => '豆包重复域名',
                'url' => 'https://doubao-source.test/b',
                'domain' => 'doubao-source.test',
                'source_type' => 'web_search_result',
            ],
            [
                'site_id' => null,
                'question_id' => $questionOne->id,
                'result_id' => $deepseekOne->id,
                'platform' => 'deepseek',
                'title' => 'DeepSeek 信源',
                'url' => 'https://deepseek-source.test/a',
                'domain' => 'deepseek-source.test',
                'source_type' => 'web_search_result',
            ],
        ]);

        $this->withHeader('X-Api-Key', 'test-lookup-key')
            ->getJson('/api/v1/brand-diagnoses/search?brand_word=行业分析测试品牌&include=platform_analysis,competitor_visibility')
            ->assertOk()
            ->assertJsonPath('data.ai_search_platform_analysis.0.platform_key', 'doubao')
            ->assertJsonPath('data.ai_search_platform_analysis.0.platform', '豆包')
            ->assertJsonPath('data.ai_search_platform_analysis.0.analysis_count', 2)
            ->assertJsonPath('data.ai_search_platform_analysis.0.top_rank_rates.top1', 50)
            ->assertJsonPath('data.ai_search_platform_analysis.0.top_rank_rates.top2', 50)
            ->assertJsonPath('data.ai_search_platform_analysis.0.positive_sentiment_rate', 50)
            ->assertJsonPath('data.ai_search_platform_analysis.0.source_count', 1)
            ->assertJsonPath('data.ai_search_platform_analysis.1.platform_key', 'deepseek')
            ->assertJsonPath('data.ai_search_platform_analysis.1.top_rank_rates.top3', 100)
            ->assertJsonPath('data.ai_search_platform_analysis.2.platform_key', 'qianwen')
            ->assertJsonPath('data.ai_search_platform_analysis.2.analysis_count', 0)
            ->assertJsonPath('data.ai_search_platform_analysis.3.platform_key', 'wenxin')
            ->assertJsonPath('data.ai_search_platform_analysis.3.analysis_count', 0)
            ->assertJsonPath('data.competitor_visibility.platforms.0.platform_key', 'doubao')
            ->assertJsonPath('data.competitor_visibility.platforms.1.platform_key', 'deepseek')
            ->assertJsonPath('data.competitor_visibility.platforms.2.platform_key', 'qianwen')
            ->assertJsonPath('data.competitor_visibility.platforms.3.platform_key', 'wenxin')
            ->assertJsonPath('data.competitor_visibility.rows.0.type', 'recommended_competitor')
            ->assertJsonPath('data.competitor_visibility.rows.0.type_label', '推荐竞品')
            ->assertJsonPath('data.competitor_visibility.rows.0.brand_name', '竞品甲')
            ->assertJsonPath('data.competitor_visibility.rows.0.mention_count', 4)
            ->assertJsonPath('data.competitor_visibility.rows.0.best_rank', 1)
            ->assertJsonPath('data.competitor_visibility.rows.0.platform_rates.doubao', 100)
            ->assertJsonPath('data.competitor_visibility.rows.0.platform_rates.deepseek', 100)
            ->assertJsonPath('data.competitor_visibility.rows.1.brand_name', '竞品乙')
            ->assertJsonPath('data.competitor_visibility.rows.1.platform_rates.doubao', 50)
            ->assertJsonPath('data.module_status.platform_analysis', 'included')
            ->assertJsonPath('data.module_status.competitor_visibility', 'included')
            ->assertJsonPath('data.competitors', null);
    }

    public function test_lookup_api_queues_non_stock_without_running_model(): void
    {
        Queue::fake();
        $response = $this->withHeader('X-Api-Key', 'test-lookup-key')
            ->getJson('/api/v1/brand-diagnoses/search?brand_word=不存在存量诊断的测试品牌&include=profile,questions,competitors')
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.data_source', 'generated_not_stock');

        $this->assertDatabaseCount('brand_diagnosis_runs', 0);
        $this->assertDatabaseCount('brand_diagnosis_questions', 0);
        $lookup = BrandDiagnosisLookupJob::query()->firstOrFail();
        $this->assertSame($lookup->lookup_id, $response->json('data.lookup_id'));
        Queue::assertPushedOn('geoflow', GenerateBrandDiagnosisLookupJob::class, function (GenerateBrandDiagnosisLookupJob $job) use ($lookup): bool {
            return $job->lookupJobId === (int) $lookup->id;
        });
    }

    public function test_lookup_api_returns_brand_profile_not_found_error_for_unverified_brand(): void
    {
        Queue::fake();
        $this->mock(BrandProfileResolver::class, function ($mock): void {
            $mock->shouldReceive('resolveStrict')->once()->andThrow(new BrandProfileNotFoundException('not found'));
        });

        $response = $this->withHeader('X-Api-Key', 'test-lookup-key')
            ->getJson('/api/v1/brand-diagnoses/search?brand_word=无法核实的测试品牌')
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending');

        $lookup = BrandDiagnosisLookupJob::query()->where('lookup_id', $response->json('data.lookup_id'))->firstOrFail();
        (new GenerateBrandDiagnosisLookupJob((int) $lookup->id))->handle(app(BrandDiagnosisLookupService::class));

        $this->withHeader('X-Api-Key', 'test-lookup-key')
            ->getJson('/api/v1/brand-diagnoses/search/status/'.$lookup->lookup_id)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'brand_profile_not_found');
    }

    public function test_lookup_api_auto_queues_non_stock_without_mode(): void
    {
        Queue::fake();
        $response = $this->withHeader('X-Api-Key', 'test-lookup-key')
            ->getJson('/api/v1/brand-diagnoses/search?brand_word=异步测试品牌&include=profile,questions')
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.data_source', 'generated_not_stock')
            ->assertJsonPath('data.brand_word', '异步测试品牌')
            ->assertJsonPath('data.retry_after', 3);

        $lookup = BrandDiagnosisLookupJob::query()->firstOrFail();
        $this->assertSame($lookup->lookup_id, $response->json('data.lookup_id'));
        Queue::assertPushedOn('geoflow', GenerateBrandDiagnosisLookupJob::class, function (GenerateBrandDiagnosisLookupJob $job) use ($lookup): bool {
            return $job->lookupJobId === (int) $lookup->id;
        });
    }

    public function test_lookup_api_returns_stored_data_without_queueing_when_stock_exists(): void
    {
        Queue::fake();
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => null,
            'brand_name' => '异步存量品牌',
            'platforms' => ['doubao'],
            'status' => 'completed',
            'brand_profile' => '异步存量品牌是一家企业服务品牌。',
            'brand_profile_status' => 'success',
        ]);

        $this->withHeader('X-Api-Key', 'test-lookup-key')
            ->getJson('/api/v1/brand-diagnoses/search?brand_word=异步存量品牌&include=profile')
            ->assertOk()
            ->assertJsonPath('data.data_source', 'stored')
            ->assertJsonPath('data.diagnosis.brand_name', $run->brand_name)
            ->assertJsonPath('data.brand_profile.text', '异步存量品牌是一家企业服务品牌。');

        Queue::assertNothingPushed();
    }

    public function test_lookup_status_returns_accepted_while_job_is_processing(): void
    {
        $lookup = BrandDiagnosisLookupJob::query()->create([
            'lookup_id' => 'bdl_pending_test',
            'brand_word' => '轮询中的品牌',
            'canonical_key' => '轮询中的品牌',
            'includes' => ['profile', 'questions'],
            'status' => 'processing',
            'started_at' => now(),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->withHeader('X-Api-Key', 'test-lookup-key')
            ->getJson('/api/v1/brand-diagnoses/search/status/'.$lookup->lookup_id)
            ->assertStatus(202)
            ->assertJsonPath('data.lookup_id', $lookup->lookup_id)
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.data_source', 'generated_not_stock');
    }

    public function test_lookup_job_generates_preview_and_status_returns_completed_result(): void
    {
        $lookup = BrandDiagnosisLookupJob::query()->create([
            'lookup_id' => 'bdl_completed_test',
            'brand_word' => '异步完成品牌',
            'canonical_key' => '异步完成品牌',
            'includes' => ['profile', 'questions'],
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10),
        ]);
        $this->mock(BrandProfileResolver::class, function ($mock): void {
            $mock->shouldReceive('resolveStrict')->once()->andReturn([
                'profile' => '异步完成品牌是一家提供企业软件的品牌。',
                'source' => 'web_search',
                'model' => '豆包',
                'status' => 'success',
                'meta' => [],
            ]);
        });
        $this->mock(DoubaoBrandDiagnosisClient::class, function ($mock): void {
            $mock->shouldReceive('generateQuestionPool')->once()->andReturn([
                ['question' => '异步完成品牌适合哪些企业？', 'type' => '品牌认知', 'core_term' => '异步完成品牌'],
            ]);
        });

        (new GenerateBrandDiagnosisLookupJob((int) $lookup->id))->handle(app(BrandDiagnosisLookupService::class));

        $lookup->refresh();
        $this->assertSame('completed', $lookup->status);
        $this->withHeader('X-Api-Key', 'test-lookup-key')
            ->getJson('/api/v1/brand-diagnoses/search/status/'.$lookup->lookup_id)
            ->assertOk()
            ->assertJsonPath('data.data_source', 'generated_not_stock')
            ->assertJsonPath('data.diagnosis.status', 'not_run')
            ->assertJsonPath('data.brand_profile.status', 'generated')
            ->assertJsonPath('data.brand_profile.text', '异步完成品牌是一家提供企业软件的品牌。')
            ->assertJsonPath('data.questions.0.status', 'generated');
    }
}
