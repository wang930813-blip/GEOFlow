<?php

/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-24
 *
 * @Time: 12:01
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： McpBrandDiagnosisTest.php
 *
 * @Description: 验证 MCP 品牌诊断的专用权限、账号站点隔离、幂等创建、问题确认和套餐额度结算。
 */

namespace Tests\Feature;

use App\Jobs\GenerateBrandDiagnosisQuestionsJob;
use App\Jobs\ProcessBrandDiagnosisJob;
use App\Models\Admin;
use App\Models\BrandDiagnosisRun;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Services\Api\ApiTokenService;
use App\Services\Mcp\McpKeyService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class McpBrandDiagnosisTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @Name: test_mcp_brand_diagnosis_read_is_isolated_by_token_owner_and_site
     *
     * @Description: 验证品牌诊断读取只返回 MCP Key 创建账号在绑定站点中的记录，并拒绝普通 API Token。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-24 12:01:13
     *
     * @UpdateTime: 2026-07-24 12:01:13
     *
     * @Return: void
     *
     * @Throws: \PHPUnit\Framework\AssertionFailedError 权限或隔离结果不符合预期
     */
    public function test_mcp_brand_diagnosis_read_is_isolated_by_token_owner_and_site(): void
    {
        [$admin, $site] = $this->createAccount('mcp_brand_read_owner', '品牌诊断站点');
        $otherAdmin = $this->createSiteMember($site, 'mcp_brand_same_site_other');
        [, $otherSite] = $this->createAccount('mcp_brand_other_site_owner', '其他品牌诊断站点');

        $ownRun = $this->createRun($admin, $site, '当前账号品牌');
        $sameSiteOtherRun = $this->createRun($otherAdmin, $site, '同站点其他账号品牌');
        $otherSiteRun = $this->createRun($admin, $otherSite, '其他站点品牌');
        $key = $this->createMcpKey($admin, $site, ['brand-diagnoses:read']);
        $authorization = ['Authorization' => 'Bearer '.$key];

        $this->withHeaders($authorization)
            ->getJson('/api/v1/mcp/brand-diagnoses')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.run_id', (int) $ownRun->id)
            ->assertJsonPath('data.items.0.brand_name', '当前账号品牌');

        $this->withHeaders($authorization)
            ->getJson('/api/v1/mcp/brand-diagnoses/'.$ownRun->id)
            ->assertOk()
            ->assertJsonPath('data.run_id', (int) $ownRun->id)
            ->assertJsonPath('data.can_confirm', true);
        $this->withHeaders($authorization)
            ->getJson('/api/v1/mcp/brand-diagnoses/'.$sameSiteOtherRun->id)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'brand_diagnosis_not_found');
        $this->withHeaders($authorization)
            ->getJson('/api/v1/mcp/brand-diagnoses/'.$otherSiteRun->id)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'brand_diagnosis_not_found');

        $regularToken = app(ApiTokenService::class)->createToken(
            '普通品牌诊断 Token',
            ['brand-diagnoses:read'],
            (int) $admin->id,
            now()->addDay()->format('Y-m-d H:i:s'),
            (int) $site->id,
        );
        $this->withHeader('Authorization', 'Bearer '.$regularToken['token'])
            ->getJson('/api/v1/mcp/brand-diagnoses')
            ->assertForbidden()
            ->assertJsonPath('error.details.required_scope', ApiTokenService::MCP_CONNECT_SCOPE);
    }

    /**
     * @Name: test_mcp_brand_diagnosis_read_remains_available_before_open_api_column_migration
     *
     * @Description: 验证开放接口任务列尚未迁移时，MCP 仍可读取当前账号和站点的普通品牌诊断记录。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-24 13:31:18
     *
     * @UpdateTime: 2026-07-24 13:31:18
     *
     * @Return: void
     *
     * @Throws: \PHPUnit\Framework\AssertionFailedError MCP 兼容查询结果不符合预期
     */
    public function test_mcp_brand_diagnosis_read_remains_available_before_open_api_column_migration(): void
    {
        [$admin, $site] = $this->createAccount('mcp_brand_pre_migration_owner', '迁移前品牌诊断站点');
        $run = $this->createRun($admin, $site, '迁移前品牌');
        $key = $this->createMcpKey($admin, $site, ['brand-diagnoses:read']);
        Schema::table('brand_diagnosis_runs', function (Blueprint $table): void {
            $table->dropUnique(['api_task_key']);
            $table->dropColumn('api_task_key');
        });

        $this->withHeader('Authorization', 'Bearer '.$key)
            ->getJson('/api/v1/mcp/brand-diagnoses')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.run_id', (int) $run->id)
            ->assertJsonPath('data.items.0.brand_name', '迁移前品牌');
    }

    /**
     * @Name: test_mcp_brand_diagnosis_create_and_confirm_are_idempotent_and_use_existing_quota
     *
     * @Description: 验证 MCP 创建只生成待确认任务，确认时仅扣减一次现有品牌诊断额度并安全启动队列。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-24 12:01:13
     *
     * @UpdateTime: 2026-07-24 12:01:13
     *
     * @Return: void
     *
     * @Throws: \PHPUnit\Framework\AssertionFailedError 幂等、费用或队列结果不符合预期
     */
    public function test_mcp_brand_diagnosis_create_and_confirm_are_idempotent_and_use_existing_quota(): void
    {
        Queue::fake([GenerateBrandDiagnosisQuestionsJob::class, ProcessBrandDiagnosisJob::class]);
        [$admin, $site] = $this->createAccount('mcp_brand_write_owner', '品牌诊断执行站点');
        $key = $this->createMcpKey($admin, $site, ['brand-diagnoses:read', 'brand-diagnoses:write']);
        $authorization = [
            'Authorization' => 'Bearer '.$key,
            'X-Idempotency-Key' => 'mcp-brand-create-123',
        ];
        $createPayload = [
            'brand_name' => '策影GEO',
            'models' => ['doubao', 'deepseek'],
            'reuse_questions' => false,
        ];

        $createResponse = $this->withHeaders($authorization)
            ->postJson('/api/v1/mcp/brand-diagnoses', $createPayload)
            ->assertCreated()
            ->assertJsonPath('data.brand_name', '策影GEO')
            ->assertJsonPath('data.raw_status', 'questions_generating')
            ->assertJsonPath('data.billing.quota_consumed', false);
        $runId = (int) $createResponse->json('data.run_id');

        $this->withHeaders($authorization)
            ->postJson('/api/v1/mcp/brand-diagnoses', $createPayload)
            ->assertCreated()
            ->assertJsonPath('data.run_id', $runId);
        $this->assertDatabaseCount('brand_diagnosis_runs', 1);
        Queue::assertPushed(GenerateBrandDiagnosisQuestionsJob::class, 1);

        $run = BrandDiagnosisRun::query()->withoutGlobalScopes()->findOrFail($runId);
        $run->update([
            'status' => 'questions_ready',
            'total_questions' => 2,
        ]);
        $firstQuestion = $run->questions()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'question' => 'GEO 服务怎么选',
            'question_type' => '推荐选择',
            'sort_order' => 1,
            'status' => 'pending',
        ]);
        $secondQuestion = $run->questions()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'question' => 'GEO 服务有哪些优势',
            'question_type' => '知识解释',
            'sort_order' => 2,
            'status' => 'pending',
        ]);
        $confirmHeaders = [
            'Authorization' => 'Bearer '.$key,
            'X-Idempotency-Key' => 'mcp-brand-confirm-123',
        ];
        $confirmPayload = [
            'questions' => [
                ['id' => (int) $firstQuestion->id, 'question' => '企业做 GEO 服务应该怎么选'],
                ['id' => (int) $secondQuestion->id, 'question' => ''],
            ],
        ];

        $this->withHeaders([
            'Authorization' => 'Bearer '.$key,
            'X-Idempotency-Key' => 'mcp-brand-invalid-question-123',
        ])->postJson('/api/v1/mcp/brand-diagnoses/'.$runId.'/confirm', [
            'questions' => [
                ['id' => 999999, 'question' => '不属于当前任务的问题'],
            ],
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');
        $this->assertDatabaseMissing('admin_resource_usages', [
            'admin_id' => (int) $admin->id,
            'site_id' => (int) $site->id,
            'resource_key' => PlatformPlan::RESOURCE_BRAND_DIAGNOSES,
        ]);

        $this->withHeaders($confirmHeaders)
            ->postJson('/api/v1/mcp/brand-diagnoses/'.$runId.'/confirm', $confirmPayload)
            ->assertOk()
            ->assertJsonPath('data.raw_status', 'running')
            ->assertJsonPath('data.billing.mode', 'plan_quota')
            ->assertJsonPath('data.billing.confirmed', true)
            ->assertJsonPath('data.billing.quota_consumed', true)
            ->assertJsonPath('data.progress.total_questions', 1);
        $this->withHeaders($confirmHeaders)
            ->postJson('/api/v1/mcp/brand-diagnoses/'.$runId.'/confirm', $confirmPayload)
            ->assertOk()
            ->assertJsonPath('data.raw_status', 'running');

        $this->assertDatabaseHas('brand_diagnosis_runs', [
            'id' => $runId,
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'status' => 'running',
            'billing_mode' => 'plan_quota',
            'total_questions' => 1,
        ]);
        $this->assertDatabaseHas('brand_diagnosis_questions', [
            'id' => (int) $firstQuestion->id,
            'question' => '企业做 GEO 服务应该怎么选',
        ]);
        $this->assertDatabaseMissing('brand_diagnosis_questions', ['id' => (int) $secondQuestion->id]);
        $this->assertDatabaseHas('admin_resource_usages', [
            'admin_id' => (int) $admin->id,
            'site_id' => (int) $site->id,
            'resource_key' => PlatformPlan::RESOURCE_BRAND_DIAGNOSES,
            'used_amount' => 1,
        ]);
        Queue::assertPushed(ProcessBrandDiagnosisJob::class, 1);
    }

    /**
     * @Name: createAccount
     *
     * @Description: 创建带站点成员关系、API Token 名额和品牌诊断额度的独立用户账号。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-24 12:01:13
     *
     * @UpdateTime: 2026-07-24 12:01:13
     *
     * @Param: string $username 管理员用户名
     *
     * @Param: string $siteName 站点名称
     *
     * @Return: array{0: Admin, 1: Site} 管理员和站点
     *
     * @Throws: \Throwable 账号、站点或套餐创建失败
     */
    private function createAccount(string $username, string $siteName): array
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => 'MCP 品牌诊断用户',
            'role' => 'site_user',
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => $siteName,
            'status' => 'active',
            'customer_mode' => 'direct',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'owner']);
        $this->openTestingPlanForSite($site, $admin, [
            PlatformPlan::RESOURCE_API_TOKENS => [
                'quota_value' => 5,
                'quota_period' => 'cycle',
                'unit' => 'tokens',
            ],
            PlatformPlan::RESOURCE_BRAND_DIAGNOSES => [
                'quota_value' => 2,
                'quota_period' => 'cycle',
                'unit' => 'times',
            ],
        ]);

        return [$admin, $site];
    }

    /**
     * @Name: createSiteMember
     *
     * @Description: 在指定站点创建另一个普通账号，用于验证同站点账号隔离。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-24 12:01:13
     *
     * @UpdateTime: 2026-07-24 12:01:13
     *
     * @Param: Site $site 目标站点
     *
     * @Param: string $username 管理员用户名
     *
     * @Return: Admin 新建站点成员
     *
     * @Throws: \Throwable 账号或成员关系创建失败
     */
    private function createSiteMember(Site $site, string $username): Admin
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => '同站点其他用户',
            'role' => 'site_user',
            'status' => 'active',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'member']);

        return $admin;
    }

    /**
     * @Name: createRun
     *
     * @Description: 创建指定账号和站点的待确认品牌诊断记录，用于验证读取隔离。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-24 12:01:13
     *
     * @UpdateTime: 2026-07-24 12:01:13
     *
     * @Param: Admin $admin 诊断记录所属账号
     *
     * @Param: Site $site 诊断记录所属站点
     *
     * @Param: string $brandName 品牌词
     *
     * @Return: BrandDiagnosisRun 新建品牌诊断记录
     *
     * @Throws: \Throwable 品牌诊断记录创建失败
     */
    private function createRun(Admin $admin, Site $site, string $brandName): BrandDiagnosisRun
    {
        return BrandDiagnosisRun::query()->withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => $brandName,
            'platforms' => ['doubao'],
            'status' => 'questions_ready',
            'total_questions' => 0,
            'completed_questions' => 0,
            'failed_questions' => 0,
            'billing_mode' => 'pending_confirmation',
            'points_cost' => 0,
            'limit_bypassed' => false,
        ]);
    }

    /**
     * @Name: createMcpKey
     *
     * @Description: 为指定账号和站点创建只包含目标业务权限的 MCP Key。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-24 12:01:13
     *
     * @UpdateTime: 2026-07-24 12:01:13
     *
     * @Param: Admin $admin MCP Key 所属账号
     *
     * @Param: Site $site MCP Key 绑定站点
     *
     * @Param: array<int, string> $scopes 品牌诊断业务权限
     *
     * @Return: string MCP Key 明文
     *
     * @Throws: \Throwable MCP Key 创建失败
     */
    private function createMcpKey(Admin $admin, Site $site, array $scopes): string
    {
        $created = app(McpKeyService::class)->createKey(
            $admin,
            $site,
            '品牌诊断 MCP Key',
            $scopes,
            now()->addDay()->format('Y-m-d H:i:s'),
        );

        return (string) $created['token'];
    }
}
