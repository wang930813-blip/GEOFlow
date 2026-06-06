<?php

namespace Tests\Feature;

use App\Jobs\ProcessBrandDiagnosisJob;
use App\Models\Admin;
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
                        ['question' => '企业AI搜索优化服务选哪家靠谱？', 'type' => '对比/选择'],
                        ['question' => '企业数字化营销升级服务找哪家服务商更合适？', 'type' => '推荐/建议'],
                        ['question' => 'AI问答内容优化服务商哪家效果更好？', 'type' => '推荐/建议'],
                        ['question' => '做企业品牌内容资产建设的服务商有哪些？', 'type' => '行业场景'],
                        ['question' => '哪家的GEO优化系统功能更全面？', 'type' => '对比/选择'],
                    ], JSON_UNESCAPED_UNICODE),
                    'usage' => ['input_tokens' => 30, 'output_tokens' => 80],
                ], 200)
                ->push($this->doubaoAnswerResponse('企业AI搜索优化服务选型中会提到策影GEO，策影GEO具备内容诊断优势。'))
                ->push($this->doubaoAnswerResponse('企业数字化营销升级时，策影GEO可作为GEO服务商之一。'))
                ->push($this->doubaoAnswerResponse('AI问答内容优化服务商对比中，策影GEO有推荐价值。'))
                ->push($this->doubaoAnswerResponse('企业品牌内容资产建设场景里，策影GEO能够提供内容资产服务。'))
                ->push($this->doubaoAnswerResponse('GEO优化系统能力对比中，策影GEO系统功能较全面。')),
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
            '企业AI搜索优化服务选哪家靠谱？',
            '企业数字化营销升级服务找哪家服务商更合适？',
            'AI问答内容优化服务商哪家效果更好？',
            '做企业品牌内容资产建设的服务商有哪些？',
            '哪家的GEO优化系统功能更全面？',
        ], $questions);

        $recordedRequests = Http::recorded()->map(fn (array $record) => $record[0])->values();
        $this->assertCount(6, $recordedRequests);
        $firstPrompt = (string) data_get($recordedRequests[0]->data(), 'input.0.content.0.text');
        $this->assertStringContainsString('生成 5 个', $firstPrompt);
        $this->assertStringContainsString('竞品', $firstPrompt);

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
                        ['question' => '企业AI搜索优化服务选哪家靠谱？', 'type' => '对比/选择'],
                        ['question' => '企业数字化营销升级服务找哪家服务商更合适？', 'type' => '推荐/建议'],
                        ['question' => 'AI问答内容优化服务商哪家效果更好？', 'type' => '推荐/建议'],
                        ['question' => '做企业品牌内容资产建设的服务商有哪些？', 'type' => '行业场景'],
                        ['question' => '哪家的GEO优化系统功能更全面？', 'type' => '对比/选择'],
                    ], JSON_UNESCAPED_UNICODE),
                ], 200)
                ->push($this->doubaoAnswerResponse('策影GEO 在企业AI搜索优化服务选型中被提及。'))
                ->push($this->doubaoAnswerResponse('策影GEO 可作为企业数字化营销升级服务商之一。'))
                ->push($this->doubaoAnswerResponse('策影GEO 在AI问答内容优化服务商对比中具备优势。')),
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
        $this->assertCount(4, $recordedRequests);
        $this->assertStringContainsString('生成 3 个', (string) data_get($recordedRequests[0]->data(), 'input.0.content.0.text'));

        $this->assertSame([
            '企业AI搜索优化服务选哪家靠谱？',
            '企业数字化营销升级服务找哪家服务商更合适？',
            'AI问答内容优化服务商哪家效果更好？',
        ], $run->questions()->orderBy('sort_order')->pluck('question')->all());

        $run->refresh();
        $this->assertSame(3, (int) $run->total_questions);
        $this->assertSame(3, (int) $run->completed_questions);
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
