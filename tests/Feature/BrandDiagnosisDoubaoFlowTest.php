<?php

namespace Tests\Feature;

use App\Jobs\ProcessBrandDiagnosisJob;
use App\Models\Admin;
use App\Models\BrandDiagnosisBrandMention;
use App\Models\BrandDiagnosisResult;
use App\Models\BrandDiagnosisRun;
use App\Models\BrandDiagnosisSource;
use App\Models\Site;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BrandDiagnosisDoubaoFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
        config()->set('brand_diagnosis.doubao.enabled', true);
        config()->set('brand_diagnosis.doubao.base_url', 'https://ark.cn-beijing.volces.com/api/v3');
        config()->set('brand_diagnosis.doubao.api_key', 'test-doubao-key');
        config()->set('brand_diagnosis.doubao.model', 'doubao-seed-2-0-lite-260428');
        config()->set('brand_diagnosis.doubao.timeout', 10);
        config()->set('brand_diagnosis.deepseek.enabled', true);
        config()->set('brand_diagnosis.deepseek.base_url', 'https://ark.cn-beijing.volces.com/api/v3');
        config()->set('brand_diagnosis.deepseek.api_key', '');
        config()->set('brand_diagnosis.deepseek.model', 'deepseek-v4-flash-260425');
        config()->set('brand_diagnosis.deepseek.timeout', 10);
    }

    public function test_admin_can_create_new_doubao_brand_diagnosis_run(): void
    {
        Queue::fake();
        [$admin, $site] = $this->createAdminWithSite('brand_diagnosis_create_admin');

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.brand-diagnosis.store'), [
                'brand_name' => '策影GEO',
                'platforms' => ['doubao'],
                'deep_thinking' => '1',
            ]);

        $response->assertRedirect(route('admin.brand-diagnosis.index'));

        $run = BrandDiagnosisRun::query()->first();
        $this->assertNotNull($run);
        $this->assertSame((int) $site->id, (int) $run->site_id);
        $this->assertSame((int) $admin->id, (int) $run->admin_id);
        $this->assertSame('策影GEO', $run->brand_name);
        $this->assertSame(['doubao'], $run->platforms);
        $this->assertSame('pending', $run->status);
        $this->assertSame('daily_free', $run->billing_mode);
        $this->assertFalse((bool) $run->limit_bypassed);
        $this->assertSame(0, (int) $run->total_questions);
        $this->assertSame(0, $run->questions()->count());

        Queue::assertPushedOn('geoflow', ProcessBrandDiagnosisJob::class, function (ProcessBrandDiagnosisJob $job) use ($run): bool {
            return $job->runId === (int) $run->id;
        });
    }

    public function test_doubao_job_generates_industry_question_variants_before_collecting_answers(): void
    {
        Http::fake([
            'ark.cn-beijing.volces.com/api/v3/responses' => Http::sequence()
                ->push([
                    'id' => 'resp-question-generation',
                    'output_text' => json_encode([
                        'analysis' => [
                            'industry' => '人工智能技术服务',
                            'type' => '企业服务',
                        ],
                        'questions' => [
                            ['question' => '成都本地AI服务公司口碑怎么样？', 'type' => '认知'],
                            ['question' => '新知地适合哪些客户？', 'type' => '服务对象'],
                            ['question' => '成都AI项目合作流程是什么？', 'type' => '流程'],
                            ['question' => '本地人工智能团队怎么比较？', 'type' => '对比'],
                            ['question' => '成都智能化方案怎么选？', 'type' => '选择'],
                        ],
                    ], JSON_UNESCAPED_UNICODE),
                    'usage' => ['input_tokens' => 30, 'output_tokens' => 80],
                ], 200)
                ->push([
                    'id' => 'resp-question-selection',
                    'output_text' => json_encode([
                        'questions' => [
                            ['question' => '成都本地AI服务公司口碑怎么样？', 'type' => '认知'],
                            ['question' => '成都AI项目合作流程是什么？', 'type' => '流程'],
                            ['question' => '本地人工智能团队怎么比较？', 'type' => '对比'],
                            ['question' => '新知地适合哪些客户？', 'type' => '服务对象'],
                            ['question' => '成都智能化方案怎么选？', 'type' => '选择'],
                        ],
                    ], JSON_UNESCAPED_UNICODE),
                    'usage' => ['input_tokens' => 30, 'output_tokens' => 80],
                ], 200)
                ->push($this->doubaoAnswerResponse('成都本地AI服务公司的公开资料中暂未检索到明确品牌推荐。'))
                ->push($this->doubaoAnswerResponse('成都AI项目合作流程的公开网页里暂未发现新知地相关内容。'))
                ->push($this->doubaoAnswerResponse('本地人工智能团队比较的公开资料中暂未看到新知地。'))
                ->push($this->doubaoAnswerResponse('新知地适合哪些客户的公开网页里没有明确答案。'))
                ->push($this->doubaoAnswerResponse('成都智能化方案怎么选的公开资料中暂未检索到新知地。')),
        ]);

        [$admin, $site] = $this->createAdminWithSite('brand_diagnosis_dynamic_questions_admin');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '策影GEO',
            'platforms' => ['doubao'],
            'status' => 'pending',
            'total_questions' => 0,
            'completed_questions' => 0,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);

        (new ProcessBrandDiagnosisJob((int) $run->id))->handle();

        $questions = $run->questions()->orderBy('sort_order')->pluck('question')->all();
        $this->assertSame([
            '成都本地AI服务公司口碑怎么样？',
            '成都AI项目合作流程是什么？',
            '本地人工智能团队怎么比较？',
            '新知地适合哪些客户？',
            '成都智能化方案怎么选？',
        ], $questions);

        $recordedRequests = Http::recorded()->map(fn (array $record) => $record[0])->values();
        $this->assertCount(7, $recordedRequests);
        $firstPrompt = (string) data_get($recordedRequests[0]->data(), 'input.0.content.0.text');
        $secondPrompt = (string) data_get($recordedRequests[1]->data(), 'input.0.content.0.text');
        $selectionPrompt = (string) data_get($recordedRequests[1]->data(), 'input.0.content.0.text');
        $this->assertStringContainsString('生成 5 个', $firstPrompt);
        $this->assertStringContainsString('智能分析', $firstPrompt);
        $this->assertStringNotContainsString('至少 1 个问题用于竞品/服务商对比', $firstPrompt);
        $this->assertStringNotContainsString('GEO优化', $firstPrompt);
        $this->assertStringContainsString('成都本地AI服务公司口碑怎么样？', $selectionPrompt);
        $this->assertStringContainsString('成都AI项目合作流程是什么？', $selectionPrompt);

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(5, (int) $run->total_questions);
        $this->assertSame(5, (int) $run->completed_questions);
        $this->assertSame(0, (int) $run->failed_questions);
    }

    public function test_doubao_generated_question_count_uses_configuration(): void
    {
        config()->set('brand_diagnosis.question_count', 3);

        Http::fake([
            'ark.cn-beijing.volces.com/api/v3/responses' => Http::sequence()
                ->push([
                    'id' => 'resp-question-generation-configured-count',
                    'output_text' => json_encode([
                        'analysis' => [
                            'industry' => '人工智能技术服务',
                            'type' => '企业服务',
                        ],
                        'questions' => [
                            ['question' => '成都本地AI服务公司口碑怎么样？', 'type' => '认知'],
                            ['question' => '新知地适合哪些客户？', 'type' => '服务对象'],
                            ['question' => '成都AI项目合作流程是什么？', 'type' => '流程'],
                            ['question' => '本地人工智能团队怎么比较？', 'type' => '对比'],
                            ['question' => '成都智能化方案怎么选？', 'type' => '选择'],
                        ],
                    ], JSON_UNESCAPED_UNICODE),
                ], 200)
                ->push([
                    'id' => 'resp-question-selection-configured-count',
                    'output_text' => json_encode([
                        'questions' => [
                            ['question' => '成都本地AI服务公司口碑怎么样？', 'type' => '认知'],
                            ['question' => '成都AI项目合作流程是什么？', 'type' => '流程'],
                            ['question' => '本地人工智能团队怎么比较？', 'type' => '对比'],
                        ],
                    ], JSON_UNESCAPED_UNICODE),
                ], 200)
                ->push($this->doubaoAnswerResponse('成都本地AI服务公司的公开网页里暂未发现明确品牌推荐。'))
                ->push($this->doubaoAnswerResponse('成都AI项目合作流程的公开网页里暂未发现明确品牌推荐。'))
                ->push($this->doubaoAnswerResponse('本地人工智能团队比较的公开网页里暂未发现明确品牌推荐。')),
        ]);

        [$admin, $site] = $this->createAdminWithSite('brand_diagnosis_configured_question_count_admin');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '策影GEO',
            'platforms' => ['doubao'],
            'status' => 'pending',
            'total_questions' => 0,
            'completed_questions' => 0,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);

        (new ProcessBrandDiagnosisJob((int) $run->id))->handle();

        $recordedRequests = Http::recorded()->map(fn (array $record) => $record[0])->values();
        $this->assertCount(5, $recordedRequests);
        $this->assertStringContainsString('生成 3 个', (string) data_get($recordedRequests[0]->data(), 'input.0.content.0.text'));

        $this->assertSame([
            '成都本地AI服务公司口碑怎么样？',
            '成都AI项目合作流程是什么？',
            '本地人工智能团队怎么比较？',
        ], $run->questions()->orderBy('sort_order')->pluck('question')->all());

        $run->refresh();
        $this->assertSame(3, (int) $run->total_questions);
        $this->assertSame(3, (int) $run->completed_questions);
    }

    public function test_question_generation_prompt_does_not_force_geo_context_for_unrelated_brand(): void
    {
        Http::fake([
            'ark.cn-beijing.volces.com/api/v3/responses' => Http::response([
                'id' => 'resp-question-generation-brand-context',
                'output_text' => json_encode([
                    'analysis' => [
                        'industry' => '人工智能技术服务',
                        'type' => '企业服务',
                    ],
                    'questions' => [
                        ['question' => '成都人工智能技术服务商哪家靠谱？', 'type' => '选择'],
                        ['question' => '企业知识库智能化改造怎么选服务商？', 'type' => '方案'],
                        ['question' => '本地AI应用开发公司能力怎么评估？', 'type' => '评估'],
                        ['question' => '人工智能项目交付服务有哪些选择？', 'type' => '对比'],
                        ['question' => '企业智能化升级服务商如何筛选？', 'type' => '选择'],
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ], 200),
        ]);

        app(\App\Services\BrandDiagnosis\DoubaoBrandDiagnosisClient::class)
            ->generateQuestions('新知地(成都)人工智能科技有限公司', 5);

        $request = Http::recorded()->first()[0];
        $prompt = (string) data_get($request->data(), 'input.0.content.0.text');

        $this->assertStringContainsString('先联网检索目标品牌', $prompt);
        $this->assertStringContainsString('行业', $prompt);
        $this->assertStringContainsString('类型', $prompt);
        $this->assertStringContainsString('不要直接出现目标品牌名称', $prompt);
        $this->assertStringNotContainsString('至少 1 个问题用于竞品/服务商对比', $prompt);
        $this->assertStringNotContainsString('至少 1 个问题用于行业服务选择', $prompt);
        $this->assertStringNotContainsString('至少 1 个问题用于系统能力或效果评价', $prompt);
        $this->assertStringNotContainsString('GEO优化', $prompt);
        $this->assertStringNotContainsString('AI搜索优化服务选哪家靠谱', $prompt);
    }

    public function test_selected_platforms_merge_candidate_question_pools_before_selecting_final_questions(): void
    {
        config()->set('brand_diagnosis.question_count', 5);

        Http::fake([
            'ark.cn-beijing.volces.com/api/v3/responses' => Http::sequence()
                ->push([
                    'id' => 'resp-doubao-candidates',
                    'output_text' => json_encode([
                        'analysis' => [
                            'industry' => '人工智能服务',
                            'type' => '本地企业服务',
                        ],
                        'questions' => [
                            ['question' => '成都本地AI服务公司口碑怎么样？', 'type' => '认知'],
                            ['question' => '新知地适合哪些客户？', 'type' => '服务对象'],
                            ['question' => '成都AI项目合作流程是什么？', 'type' => '流程'],
                            ['question' => '本地人工智能团队怎么比较？', 'type' => '对比'],
                            ['question' => '成都智能化方案怎么选？', 'type' => '选择'],
                        ],
                    ], JSON_UNESCAPED_UNICODE),
                ], 200)
                ->push([
                    'id' => 'resp-deepseek-candidates',
                    'output_text' => json_encode([
                        'analysis' => [
                            'industry' => '企业数字化服务',
                            'type' => 'AI应用咨询',
                        ],
                        'questions' => [
                            ['question' => '成都企业做AI业务先看什么？', 'type' => '选择'],
                            ['question' => '新知地的服务适合哪些企业？', 'type' => '服务对象'],
                            ['question' => '本地AI项目交付能力怎么判断？', 'type' => '评估'],
                            ['question' => '成都有哪些类似品牌？', 'type' => '对比'],
                            ['question' => '企业智能化合作怎么比价？', 'type' => '选择'],
                        ],
                    ], JSON_UNESCAPED_UNICODE),
                ], 200)
                ->push([
                    'id' => 'resp-selected-questions',
                    'output_text' => json_encode([
                        'questions' => [
                            ['question' => '成都本地AI服务公司口碑怎么样？', 'type' => '认知'],
                            ['question' => '成都企业做AI业务先看什么？', 'type' => '选择'],
                            ['question' => '新知地的服务适合哪些企业？', 'type' => '服务对象'],
                            ['question' => '本地AI项目交付能力怎么判断？', 'type' => '评估'],
                            ['question' => '成都智能化方案怎么选？', 'type' => '选择'],
                        ],
                    ], JSON_UNESCAPED_UNICODE),
                ], 200)
                ->push($this->doubaoAnswerResponse('公开资料中暂未检索到明确品牌提及。'))
                ->push($this->doubaoAnswerResponse('公开资料中暂未检索到明确品牌提及。'))
                ->push($this->doubaoAnswerResponse('公开资料中暂未检索到明确品牌提及。'))
                ->push($this->doubaoAnswerResponse('公开资料中暂未检索到明确品牌提及。'))
                ->push($this->doubaoAnswerResponse('公开资料中暂未检索到明确品牌提及。'))
                ->push($this->doubaoAnswerResponse('公开资料中暂未检索到明确品牌提及。'))
                ->push($this->doubaoAnswerResponse('公开资料中暂未检索到明确品牌提及。'))
                ->push($this->doubaoAnswerResponse('公开资料中暂未检索到明确品牌提及。'))
                ->push($this->doubaoAnswerResponse('公开资料中暂未检索到明确品牌提及。'))
                ->push($this->doubaoAnswerResponse('公开资料中暂未检索到明确品牌提及。')),
        ]);

        [$admin, $site] = $this->createAdminWithSite('brand_diagnosis_multi_model_pool_admin');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '新知地(成都)人工智能科技有限公司',
            'platforms' => ['doubao', 'deepseek'],
            'status' => 'pending',
            'total_questions' => 0,
            'completed_questions' => 0,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);

        (new ProcessBrandDiagnosisJob((int) $run->id))->handle();

        $this->assertSame([
            '成都本地AI服务公司口碑怎么样？',
            '成都企业做AI业务先看什么？',
            '新知地的服务适合哪些企业？',
            '本地AI项目交付能力怎么判断？',
            '成都智能化方案怎么选？',
        ], $run->questions()->orderBy('sort_order')->pluck('question')->all());

        $recordedRequests = Http::recorded()->map(fn (array $record) => $record[0])->values();
        $this->assertCount(13, $recordedRequests);

        $doubaoPrompt = (string) data_get($recordedRequests[0]->data(), 'input.0.content.0.text');
        $deepseekPrompt = (string) data_get($recordedRequests[1]->data(), 'input.0.content.0.text');
        $selectionPrompt = (string) data_get($recordedRequests[2]->data(), 'input.0.content.0.text');

        $this->assertStringContainsString('智能分析它可能对应的行业', $doubaoPrompt);
        $this->assertStringNotContainsString('至少 1 个问题用于竞品/服务商对比', $doubaoPrompt);
        $this->assertStringNotContainsString('GEO优化', $deepseekPrompt);
        $this->assertStringContainsString('成都本地AI服务公司口碑怎么样？', $selectionPrompt);
        $this->assertStringContainsString('成都企业做AI业务先看什么？', $selectionPrompt);
        $this->assertStringContainsString('新知地的服务适合哪些企业？', $selectionPrompt);

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(5, (int) $run->total_questions);
        $this->assertSame(5, (int) $run->completed_questions);
        $this->assertSame(0, (int) $run->mention_rate);
        $this->assertSame(0, (int) $run->mention_count);
    }

    public function test_standard_admin_can_only_create_one_free_diagnosis_per_day(): void
    {
        Queue::fake();
        [$admin, $site] = $this->createAdminWithSite('brand_diagnosis_limit_admin');

        BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '已诊断品牌',
            'platforms' => ['doubao'],
            'status' => 'completed',
            'total_questions' => 5,
            'completed_questions' => 5,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.brand-diagnosis.store'), [
                'brand_name' => '策影GEO',
                'platforms' => ['doubao'],
            ]);

        $response->assertSessionHasErrors('brand_name');
        $this->assertSame(1, BrandDiagnosisRun::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_super_admin_can_bypass_daily_free_limit(): void
    {
        Queue::fake();
        [$admin, $site] = $this->createAdminWithSite('brand_diagnosis_super_admin', 'super_admin');

        BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '上午诊断',
            'platforms' => ['doubao'],
            'status' => 'completed',
            'total_questions' => 5,
            'completed_questions' => 5,
            'failed_questions' => 0,
            'billing_mode' => 'admin_unlimited',
            'usage_date' => now()->toDateString(),
            'limit_bypassed' => true,
            'limit_bypass_reason' => 'super_admin',
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.brand-diagnosis.store'), [
                'brand_name' => '下午诊断',
                'platforms' => ['doubao'],
            ])
            ->assertRedirect(route('admin.brand-diagnosis.index'));

        $latest = BrandDiagnosisRun::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame('admin_unlimited', $latest->billing_mode);
        $this->assertTrue((bool) $latest->limit_bypassed);
        $this->assertSame('super_admin', $latest->limit_bypass_reason);
        $this->assertSame(2, BrandDiagnosisRun::query()->count());
    }

    public function test_doubao_job_persists_answers_sources_and_metrics(): void
    {
        Http::fake([
            'ark.cn-beijing.volces.com/api/v3/responses' => Http::response([
                'id' => 'resp-test',
                'output' => [
                    [
                        'type' => 'message',
                        'content' => [
                            [
                                'type' => 'output_text',
                                'text' => '策影GEO 是一个 GEO 品牌诊断和 AI 搜索优化平台，整体评价偏正面。',
                                'annotations' => [
                                    [
                                        'type' => 'url_citation',
                                        'title' => '策影GEO 官网介绍',
                                        'url' => 'https://geo.example.com/intro',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
            ], 200),
        ]);

        [$admin, $site] = $this->createAdminWithSite('brand_diagnosis_job_admin');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '策影GEO',
            'platforms' => ['doubao'],
            'status' => 'pending',
            'total_questions' => 0,
            'completed_questions' => 0,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);
        $question = $run->questions()->create([
            'site_id' => (int) $site->id,
            'question' => '策影GEO 是什么品牌？',
            'question_type' => '品牌认知',
            'sort_order' => 1,
            'status' => 'pending',
        ]);
        $run->update(['total_questions' => 1]);

        (new ProcessBrandDiagnosisJob((int) $run->id))->handle();

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://ark.cn-beijing.volces.com/api/v3/responses'
                && $request->hasHeader('Authorization', 'Bearer test-doubao-key')
                && ($payload['model'] ?? null) === 'doubao-seed-2-0-lite-260428'
                && ($payload['tools'][0]['type'] ?? null) === 'web_search';
        });

        $result = BrandDiagnosisResult::query()->where('question_id', (int) $question->id)->firstOrFail();
        $this->assertSame('doubao', $result->platform);
        $this->assertSame('success', $result->status);
        $this->assertTrue((bool) $result->brand_mentioned);
        $this->assertSame(1, (int) $result->mention_count);
        $this->assertSame('positive', $result->sentiment);
        $this->assertStringContainsString('策影GEO', (string) $result->answer);

        $source = BrandDiagnosisSource::query()->where('result_id', (int) $result->id)->firstOrFail();
        $this->assertSame('策影GEO 官网介绍', $source->title);
        $this->assertSame('https://geo.example.com/intro', $source->url);

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, (int) $run->completed_questions);
        $this->assertSame(100, (int) $run->mention_rate);
        $this->assertSame(1, (int) $run->mention_count);
        $this->assertSame(100, (int) $run->sentiment_rate);
        $this->assertGreaterThanOrEqual(80, (int) $run->brand_score);
    }

    public function test_job_persists_competing_brand_mentions_and_recalculates_target_metrics(): void
    {
        Http::fake([
            'ark.cn-beijing.volces.com/api/v3/responses' => Http::response([
                'id' => 'resp-competitor-mentions',
                'output_text' => json_encode([
                    'answer' => 'GEO 服务商对比中，泓动数据更常被优先列出，策影GEO在内容诊断场景被提及两次，蓝色光标也被列入候选。',
                    'brand_mentions' => [
                        [
                            'brand' => '泓动数据',
                            'mention_count' => 1,
                            'mention_rank' => 1,
                            'sentiment' => 'neutral',
                            'evidence' => '优先列出',
                        ],
                        [
                            'brand' => '策影GEO',
                            'mention_count' => 2,
                            'mention_rank' => 2,
                            'sentiment' => 'positive',
                            'evidence' => '内容诊断场景被提及两次',
                        ],
                        [
                            'brand' => '蓝色光标',
                            'mention_count' => 1,
                            'mention_rank' => 3,
                            'sentiment' => 'neutral',
                            'evidence' => '候选服务商',
                        ],
                    ],
                ], JSON_UNESCAPED_UNICODE),
                'usage' => ['input_tokens' => 20, 'output_tokens' => 60],
            ], 200),
        ]);

        [$admin, $site] = $this->createAdminWithSite('brand_diagnosis_competitor_admin');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '策影GEO',
            'platforms' => ['doubao'],
            'status' => 'pending',
            'total_questions' => 1,
            'completed_questions' => 0,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);
        $question = $run->questions()->create([
            'site_id' => (int) $site->id,
            'question' => '企业AI搜索优化服务选哪家靠谱？',
            'question_type' => '对比/选择',
            'sort_order' => 1,
            'status' => 'pending',
        ]);

        (new ProcessBrandDiagnosisJob((int) $run->id))->handle();

        $result = BrandDiagnosisResult::query()->where('question_id', (int) $question->id)->firstOrFail();
        $mentions = BrandDiagnosisBrandMention::query()
            ->where('result_id', (int) $result->id)
            ->orderBy('mention_rank')
            ->get();

        $this->assertCount(3, $mentions);
        $this->assertSame(['泓动数据', '策影GEO', '蓝色光标'], $mentions->pluck('brand_name')->all());
        $this->assertSame(2, (int) $mentions->firstWhere('brand_name', '策影GEO')->mention_count);
        $this->assertSame(2, (int) $mentions->firstWhere('brand_name', '策影GEO')->mention_rank);
        $this->assertTrue((bool) $mentions->firstWhere('brand_name', '策影GEO')->is_target_brand);

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(100, (int) $run->mention_rate);
        $this->assertSame(2, (int) $run->mention_count);
        $this->assertSame(2.0, (float) $run->average_rank);
        $this->assertSame(100, (int) $run->sentiment_rate);
    }

    public function test_target_brand_metrics_stay_zero_when_target_is_not_in_answer_or_source_evidence(): void
    {
        Http::fake([
            'ark.cn-beijing.volces.com/api/v3/responses' => Http::response([
                'id' => 'resp-no-target-evidence',
                'output' => [
                    [
                        'type' => 'message',
                        'content' => [
                            [
                                'type' => 'output_text',
                                'text' => json_encode([
                                    'answer' => '成都人工智能技术服务商对比中，示例智能和云启科技更常被公开资料提到。',
                                    'brand_mentions' => [
                                        [
                                            'brand' => '示例智能',
                                            'mention_count' => 1,
                                            'mention_rank' => 1,
                                            'sentiment' => 'neutral',
                                            'evidence' => '回答中被提到',
                                        ],
                                        [
                                            'brand' => '新知地(成都)人工智能科技有限公司',
                                            'mention_count' => 1,
                                            'mention_rank' => 2,
                                            'sentiment' => 'neutral',
                                            'evidence' => '目标品牌来自诊断输入，不是回答或引用命中',
                                        ],
                                    ],
                                ], JSON_UNESCAPED_UNICODE),
                                'annotations' => [
                                    [
                                        'type' => 'url_citation',
                                        'title' => '成都人工智能服务商名单',
                                        'url' => 'https://example.com/ai-service-list',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        [$admin, $site] = $this->createAdminWithSite('brand_diagnosis_no_target_evidence_admin');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '新知地(成都)人工智能科技有限公司',
            'platforms' => ['doubao'],
            'status' => 'pending',
            'total_questions' => 1,
            'completed_questions' => 0,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);
        $run->questions()->create([
            'site_id' => (int) $site->id,
            'question' => '成都人工智能技术服务商哪家靠谱？',
            'question_type' => '选择',
            'sort_order' => 1,
            'status' => 'pending',
        ]);

        (new ProcessBrandDiagnosisJob((int) $run->id))->handle();

        $result = BrandDiagnosisResult::query()->firstOrFail();
        $this->assertFalse((bool) $result->brand_mentioned);
        $this->assertSame(0, (int) $result->mention_count);
        $this->assertSame(0, (int) $result->mention_rank);

        $this->assertDatabaseHas('brand_diagnosis_brand_mentions', [
            'run_id' => (int) $run->id,
            'brand_name' => '示例智能',
            'is_target_brand' => false,
        ]);
        $this->assertDatabaseMissing('brand_diagnosis_brand_mentions', [
            'run_id' => (int) $run->id,
            'brand_name' => '新知地(成都)人工智能科技有限公司',
        ]);

        $run->refresh();
        $this->assertSame(0, (int) $run->mention_rate);
        $this->assertSame(0, (int) $run->mention_count);
        $this->assertSame(0.0, (float) $run->average_rank);
        $this->assertSame(0, (int) $run->brand_score);
    }

    public function test_target_brand_short_alias_is_counted_when_answer_evidence_contains_alias(): void
    {
        Http::fake([
            'ark.cn-beijing.volces.com/api/v3/responses' => Http::response([
                'id' => 'resp-target-alias-evidence',
                'output_text' => json_encode([
                    'answer' => '成都人工智能技术服务对比中，新知地被列为本地AI应用服务商之一。',
                    'brand_mentions' => [
                        [
                            'brand' => '新知地',
                            'mention_count' => 1,
                            'mention_rank' => 2,
                            'sentiment' => 'neutral',
                            'evidence' => '回答中出现新知地',
                        ],
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ], 200),
        ]);

        [$admin, $site] = $this->createAdminWithSite('brand_diagnosis_target_alias_admin');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '新知地(成都)人工智能科技有限公司',
            'platforms' => ['doubao'],
            'status' => 'pending',
            'total_questions' => 1,
            'completed_questions' => 0,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);
        $run->questions()->create([
            'site_id' => (int) $site->id,
            'question' => '成都人工智能技术服务商哪家靠谱？',
            'question_type' => '选择',
            'sort_order' => 1,
            'status' => 'pending',
        ]);

        (new ProcessBrandDiagnosisJob((int) $run->id))->handle();

        $mention = BrandDiagnosisBrandMention::query()->firstOrFail();
        $this->assertSame('新知地', $mention->brand_name);
        $this->assertTrue((bool) $mention->is_target_brand);

        $run->refresh();
        $this->assertSame(100, (int) $run->mention_rate);
        $this->assertSame(1, (int) $run->mention_count);
        $this->assertSame(2.0, (float) $run->average_rank);
    }

    public function test_full_company_name_fallback_mention_count_does_not_double_count_aliases(): void
    {
        Http::fake([
            'ark.cn-beijing.volces.com/api/v3/responses' => Http::response([
                'id' => 'resp-full-company-name-fallback',
                'output_text' => json_encode([
                    'answer' => '公开资料显示，新知地(成都)人工智能科技有限公司提供人工智能相关服务。',
                    'brand_mentions' => [],
                ], JSON_UNESCAPED_UNICODE),
            ], 200),
        ]);

        [$admin, $site] = $this->createAdminWithSite('brand_diagnosis_full_name_count_admin');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '新知地(成都)人工智能科技有限公司',
            'platforms' => ['doubao'],
            'status' => 'pending',
            'total_questions' => 1,
            'completed_questions' => 0,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);
        $run->questions()->create([
            'site_id' => (int) $site->id,
            'question' => '成都人工智能技术服务商哪家靠谱？',
            'question_type' => '选择',
            'sort_order' => 1,
            'status' => 'pending',
        ]);

        (new ProcessBrandDiagnosisJob((int) $run->id))->handle();

        $result = BrandDiagnosisResult::query()->firstOrFail();
        $this->assertSame(1, (int) $result->mention_count);

        $run->refresh();
        $this->assertSame(1, (int) $run->mention_count);
    }

    public function test_target_brand_is_not_counted_when_answer_only_repeats_question_without_evidence(): void
    {
        Http::fake([
            'ark.cn-beijing.volces.com/api/v3/responses' => Http::response([
                'id' => 'resp-question-repeat-only',
                'output_text' => json_encode([
                    'answer' => '关于“新知地适合哪些客户”这个问题，公开资料较少，暂无法确认其客户类型。',
                    'brand_mentions' => [],
                ], JSON_UNESCAPED_UNICODE),
            ], 200),
        ]);

        [$admin, $site] = $this->createAdminWithSite('brand_diagnosis_question_repeat_admin');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '新知地(成都)人工智能科技有限公司',
            'platforms' => ['doubao'],
            'status' => 'pending',
            'total_questions' => 1,
            'completed_questions' => 0,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);
        $run->questions()->create([
            'site_id' => (int) $site->id,
            'question' => '新知地适合哪些客户？',
            'question_type' => '服务对象',
            'sort_order' => 1,
            'status' => 'pending',
        ]);

        (new ProcessBrandDiagnosisJob((int) $run->id))->handle();

        $result = BrandDiagnosisResult::query()->firstOrFail();
        $this->assertFalse((bool) $result->brand_mentioned);
        $this->assertSame(0, (int) $result->mention_count);

        $run->refresh();
        $this->assertSame(0, (int) $run->mention_rate);
        $this->assertSame(0, (int) $run->mention_count);
    }

    public function test_target_brand_is_counted_when_only_citation_source_contains_target_alias(): void
    {
        Http::fake([
            'ark.cn-beijing.volces.com/api/v3/responses' => Http::response([
                'id' => 'resp-target-source-evidence',
                'output' => [
                    [
                        'type' => 'message',
                        'content' => [
                            [
                                'type' => 'output_text',
                                'text' => json_encode([
                                    'answer' => '成都人工智能技术服务商资料较少，需要结合公开网页进一步核验。',
                                    'brand_mentions' => [
                                        [
                                            'brand' => '新知地',
                                            'mention_count' => 1,
                                            'mention_rank' => 0,
                                            'sentiment' => 'neutral',
                                            'evidence' => '引用来源标题出现该品牌简称',
                                        ],
                                    ],
                                ], JSON_UNESCAPED_UNICODE),
                                'annotations' => [
                                    [
                                        'type' => 'url_citation',
                                        'title' => '新知地人工智能服务介绍',
                                        'url' => 'https://example.com/xinzhidi',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        [$admin, $site] = $this->createAdminWithSite('brand_diagnosis_target_source_admin');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '新知地(成都)人工智能科技有限公司',
            'platforms' => ['doubao'],
            'status' => 'pending',
            'total_questions' => 1,
            'completed_questions' => 0,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);
        $run->questions()->create([
            'site_id' => (int) $site->id,
            'question' => '成都人工智能技术服务商哪家靠谱？',
            'question_type' => '选择',
            'sort_order' => 1,
            'status' => 'pending',
        ]);

        (new ProcessBrandDiagnosisJob((int) $run->id))->handle();

        $mention = BrandDiagnosisBrandMention::query()->firstOrFail();
        $this->assertSame('新知地', $mention->brand_name);
        $this->assertTrue((bool) $mention->is_target_brand);

        $run->refresh();
        $this->assertSame(100, (int) $run->mention_rate);
        $this->assertSame(1, (int) $run->mention_count);
    }

    public function test_deepseek_uses_ark_base_url_and_doubao_api_key_with_deepseek_model(): void
    {
        Http::fake([
            'ark.cn-beijing.volces.com/api/v3/responses' => Http::response([
                'id' => 'resp-deepseek-test',
                'output_text' => json_encode([
                    'answer' => 'DeepSeek 对 GEO 服务商进行对比时提到策影GEO。',
                    'brand_mentions' => [
                        [
                            'brand' => '策影GEO',
                            'mention_count' => 1,
                            'mention_rank' => 1,
                            'sentiment' => 'neutral',
                            'evidence' => '被列入对比',
                        ],
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ], 200),
        ]);

        [$admin, $site] = $this->createAdminWithSite('brand_diagnosis_deepseek_admin');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '策影GEO',
            'platforms' => ['deepseek'],
            'status' => 'pending',
            'total_questions' => 1,
            'completed_questions' => 0,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);
        $run->questions()->create([
            'site_id' => (int) $site->id,
            'question' => 'GEO优化系统哪家更全面？',
            'question_type' => '对比/选择',
            'sort_order' => 1,
            'status' => 'pending',
        ]);

        (new ProcessBrandDiagnosisJob((int) $run->id))->handle();

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://ark.cn-beijing.volces.com/api/v3/responses'
                && $request->hasHeader('Authorization', 'Bearer test-doubao-key')
                && ($payload['model'] ?? null) === 'deepseek-v4-flash-260425';
        });

        $result = BrandDiagnosisResult::query()->firstOrFail();
        $this->assertSame('deepseek', $result->platform);
        $this->assertSame('success', $result->status);
    }

    /**
     * @return array{0:Admin,1:Site}
     */
    private function createAdminWithSite(string $username, string $role = 'admin'): array
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => 'Brand Diagnosis Admin',
            'role' => $role,
            'status' => 'active',
        ]);

        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => $username.' Site',
            'status' => 'active',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'owner']);

        return [$admin, $site];
    }

    /**
     * @return array<string,mixed>
     */
    private function doubaoAnswerResponse(string $answer): array
    {
        return [
            'id' => 'resp-answer-'.md5($answer),
            'output' => [
                [
                    'type' => 'message',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => $answer,
                            'annotations' => [
                                [
                                    'type' => 'url_citation',
                                    'title' => '测试引用来源',
                                    'url' => 'https://geo.example.com/source',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
        ];
    }
}
