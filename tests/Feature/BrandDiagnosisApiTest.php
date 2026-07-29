<?php

namespace Tests\Feature;

use App\Jobs\AutoConfirmBrandDiagnosisRunJob;
use App\Jobs\GenerateBrandDiagnosisQuestionsJob;
use App\Jobs\ProcessBrandDiagnosisJob;
use App\Models\Admin;
use App\Models\BrandDiagnosisRun;
use App\Services\BrandDiagnosis\BrandDiagnosisRunService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BrandDiagnosisApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('brand_diagnosis.open_api.enabled', true);
        Config::set('brand_diagnosis.open_api.api_key', 'test-open-api-key');
        Config::set('brand_diagnosis.open_api.admin_id', null);
    }

    public function test_brand_diagnosis_api_requires_configured_key(): void
    {
        $this->postJson('/api/v1/brand-diagnoses', [
            'brand_name' => '武城煊饼',
            'models' => ['doubao'],
        ])->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_api_key');
    }

    /**
     * @Name: test_brand_diagnosis_api_reports_unavailable_before_task_key_migration
     *
     * @Description: 验证开放接口任务列尚未迁移时返回明确服务不可用错误，不泄露底层数据库异常。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-24 13:31:18
     *
     * @UpdateTime: 2026-07-24 13:31:18
     *
     * @Return: void
     *
     * @Throws: \PHPUnit\Framework\AssertionFailedError 接口兼容错误契约不符合预期
     */
    public function test_brand_diagnosis_api_reports_unavailable_before_task_key_migration(): void
    {
        Schema::table('brand_diagnosis_runs', function (Blueprint $table): void {
            $table->dropUnique(['api_task_key']);
            $table->dropColumn('api_task_key');
        });

        $this->withHeader('X-Api-Key', 'test-open-api-key')
            ->postJson('/api/v1/brand-diagnoses', [
                'brand_name' => '武城煊饼',
                'models' => ['doubao'],
            ])
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'brand_diagnosis_api_not_ready');
    }

    public function test_brand_diagnosis_api_creates_opaque_system_level_task(): void
    {
        Queue::fake([GenerateBrandDiagnosisQuestionsJob::class]);

        $superAdmin = $this->createSuperAdmin('brand_diagnosis_open_api_super_admin');
        Config::set('brand_diagnosis.open_api.admin_id', (int) $superAdmin->id);

        $response = $this->withHeader('X-Api-Key', 'test-open-api-key')
            ->postJson('/api/v1/brand-diagnoses', [
                'brand_name' => '武城煊饼',
                'models' => ['doubao', 'qianwen'],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.brand_name', '武城煊饼')
            ->assertJsonPath('data.models.0', 'doubao')
            ->assertJsonPath('data.models.1', 'qianwen')
            ->assertJsonPath('data.status', 'diagnosing')
            ->assertJsonStructure(['data' => ['task_id']]);

        $taskId = (string) $response->json('data.task_id');
        $this->assertMatchesRegularExpression('/^bdg_[a-f0-9]{32}$/', $taskId);
        $this->assertDatabaseHas('brand_diagnosis_runs', [
            'site_id' => null,
            'owner_admin_id' => (int) $superAdmin->id,
            'admin_id' => (int) $superAdmin->id,
            'brand_name' => '武城煊饼',
            'api_task_key' => $taskId,
            'status' => 'questions_generating',
        ]);
        Queue::assertPushed(GenerateBrandDiagnosisQuestionsJob::class);
    }

    public function test_brand_diagnosis_query_returns_running_state(): void
    {
        $superAdmin = $this->createSuperAdmin('brand_diagnosis_query_super_admin');
        $taskKey = 'bdg_'.str_repeat('4', 32);
        BrandDiagnosisRun::query()->create([
            'site_id' => null,
            'owner_admin_id' => (int) $superAdmin->id,
            'admin_id' => (int) $superAdmin->id,
            'brand_name' => '武城煊饼',
            'platforms' => ['doubao'],
            'api_task_key' => $taskKey,
            'status' => 'running',
            'total_questions' => 6,
            'completed_questions' => 2,
            'failed_questions' => 0,
        ]);

        $this->withHeader('X-Api-Key', 'test-open-api-key')
            ->getJson('/api/v1/brand-diagnoses/'.$taskKey)
            ->assertOk()
            ->assertJsonPath('data.status', 'diagnosing')
            ->assertJsonPath('data.raw_status', 'running')
            ->assertJsonPath('data.progress.total_questions', 6)
            ->assertJsonPath('data.progress.completed_questions', 2);
    }

    public function test_brand_diagnosis_query_returns_not_found_for_invalid_task_key(): void
    {
        $this->withHeader('X-Api-Key', 'test-open-api-key')
            ->getJson('/api/v1/brand-diagnoses/bdg_fac2fff342d8d02cff2949336589e41')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'diagnosis_not_found')
            ->assertJsonPath('error.message', '诊断任务不存在或任务 ID 格式不正确');
    }

    public function test_auto_confirm_turns_api_questions_ready_run_into_running(): void
    {
        Queue::fake([ProcessBrandDiagnosisJob::class]);

        $superAdmin = $this->createSuperAdmin('brand_diagnosis_auto_confirm_super_admin');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => null,
            'owner_admin_id' => (int) $superAdmin->id,
            'admin_id' => (int) $superAdmin->id,
            'brand_name' => '武城煊饼',
            'platforms' => ['doubao'],
            'api_task_key' => 'bdg_'.str_repeat('2', 32),
            'status' => 'questions_ready',
            'total_questions' => 1,
            'billing_mode' => 'pending_confirmation',
        ]);
        $question = $run->questions()->create([
            'site_id' => null,
            'owner_admin_id' => (int) $superAdmin->id,
            'question' => '武城哪家煊饼店最好吃',
            'question_type' => '推荐选择',
            'core_term' => '武城煊饼',
            'sort_order' => 1,
            'status' => 'pending',
        ]);

        (new AutoConfirmBrandDiagnosisRunJob((int) $run->id))->handle(app(BrandDiagnosisRunService::class));

        $this->assertDatabaseHas('brand_diagnosis_runs', [
            'id' => (int) $run->id,
            'site_id' => null,
            'status' => 'running',
            'billing_mode' => 'open_api',
            'points_cost' => 0,
            'limit_bypassed' => true,
            'limit_bypass_reason' => 'open_api',
        ]);
        $this->assertDatabaseHas('brand_diagnosis_questions', [
            'id' => (int) $question->id,
            'site_id' => null,
            'status' => 'pending',
        ]);
        Queue::assertPushed(ProcessBrandDiagnosisJob::class);
    }

    private function createSuperAdmin(string $username): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => '品牌诊断开放 API 超管',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }
}
