<?php

/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-13
 *
 * @Time: 16:38
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： AdminMcpServerTest.php
 *
 * @Description: 验证用户侧 GEO MCP Key 管理、站点隔离、套餐额度和机器凭证自检契约。
 */

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Models\Task;
use App\Models\TaskRun;
use App\Services\Api\ApiTokenService;
use App\Services\Mcp\McpKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AdminMcpServerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @Name: test_user_can_open_mcp_server_page_for_current_site
     *
     * @Description: 验证用户可从当前站点打开 MCP Server 页面并看到连接、工具和费用说明。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 16:38:47
     *
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Return: void
     */
    public function test_user_can_open_mcp_server_page_for_current_site(): void
    {
        [$admin, $site] = $this->createAccount('mcp_page_user');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.mcp-server.index'))
            ->assertOk()
            ->assertSee('MCP Server')
            ->assertSee($site->name)
            ->assertSee('Streamable HTTP')
            ->assertSee('geo_run_task')
            ->assertSee('geo_publish_article_to_media')
            ->assertSee('按渠道实际售价扣费，失败退款')
            ->assertSee('MCP 请求本身不单独计费');
    }

    /**
     * @Name: test_user_can_create_site_bound_mcp_key
     *
     * @Description: 验证创建的 MCP Key 强制绑定当前账号和站点，并自动附加专用连接权限。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 16:38:47
     *
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Return: void
     */
    public function test_user_can_create_site_bound_mcp_key(): void
    {
        [$admin, $site] = $this->createAccount('mcp_create_user');

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.mcp-server.keys.store'), [
                'name' => '内容运营助手',
                'expires_at' => now()->addDay()->format('Y-m-d\TH:i'),
                'scopes' => [
                    'catalog:read',
                    'tasks:read',
                    'articles:read',
                    'articles:write',
                    'media:read',
                    'media:submit',
                ],
                'mcp_max_unit_price' => '100.00',
                'mcp_max_total_price' => '300.00',
                'mcp_daily_spend_limit' => '1000.00',
            ]);

        $response
            ->assertRedirect(route('admin.mcp-server.index'))
            ->assertSessionHas('new_mcp_key');

        $token = PersonalAccessToken::query()->where('name', '内容运营助手')->firstOrFail();
        $this->assertSame((int) $admin->id, (int) $token->tokenable_id);
        $this->assertSame((int) $site->id, (int) $token->site_id);
        $this->assertContains(ApiTokenService::MCP_CONNECT_SCOPE, (array) $token->abilities);
        $this->assertContains('tasks:read', (array) $token->abilities);
        $this->assertContains('articles:write', (array) $token->abilities);
        $this->assertContains('media:read', (array) $token->abilities);
        $this->assertContains('media:submit', (array) $token->abilities);
        $this->assertSame(100.0, (float) $token->mcp_max_unit_price);
        $this->assertSame(300.0, (float) $token->mcp_max_total_price);
        $this->assertSame(1000.0, (float) $token->mcp_daily_spend_limit);

        $plainToken = (string) $response->getSession()->get('new_mcp_key');
        $this->withHeader('Authorization', 'Bearer '.$plainToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.token.spending_policy.max_unit_price', '100.00')
            ->assertJsonPath('data.token.spending_policy.max_total_price', '300.00')
            ->assertJsonPath('data.token.spending_policy.daily_spend_limit', '1000.00');
    }

    /**
     * @Name: test_mcp_page_only_lists_current_accounts_mcp_keys
     *
     * @Description: 验证用户侧页面不会展示同站点其他账号的 Key，也不会把普通 API Token 识别为 MCP Key。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 16:38:47
     *
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Return: void
     */
    public function test_mcp_page_only_lists_current_accounts_mcp_keys(): void
    {
        [$admin, $site] = $this->createAccount('mcp_isolation_user');
        $other = Admin::query()->create([
            'username' => 'mcp_isolation_other',
            'password' => 'secret-123',
            'email' => 'mcp-isolation-other@example.com',
            'display_name' => '其他账号',
            'role' => 'site_user',
            'status' => 'active',
        ]);
        $site->members()->attach((int) $other->id, ['role' => 'member']);
        $this->openTestingPlanForSite($site, $other);

        $ownMcpKey = $admin->createToken('当前账号 MCP Key', [ApiTokenService::MCP_CONNECT_SCOPE, 'tasks:read'])->accessToken;
        $ownMcpKey->forceFill(['site_id' => (int) $site->id])->save();
        $regularToken = $admin->createToken('普通 API Token', ['tasks:read'])->accessToken;
        $regularToken->forceFill(['site_id' => (int) $site->id])->save();
        $otherMcpKey = $other->createToken('其他账号 MCP Key', [ApiTokenService::MCP_CONNECT_SCOPE, 'tasks:read'])->accessToken;
        $otherMcpKey->forceFill(['site_id' => (int) $site->id])->save();

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.mcp-server.index'))
            ->assertOk()
            ->assertSee('当前账号 MCP Key')
            ->assertDontSee('普通 API Token')
            ->assertDontSee('其他账号 MCP Key');
    }

    /**
     * @Name: test_mcp_key_uses_existing_api_token_quota
     *
     * @Description: 验证 MCP Key 与现有 API Token 共用账号级数量上限，避免新增一套重复计费口径。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 16:38:47
     *
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Return: void
     */
    public function test_mcp_key_uses_existing_api_token_quota(): void
    {
        [$admin, $site] = $this->createAccount('mcp_quota_user', 1);
        $token = $admin->createToken('已有 API Token', ['catalog:read'])->accessToken;
        $token->forceFill(['site_id' => (int) $site->id])->save();

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.mcp-server.keys.store'), [
                'name' => '超额 MCP Key',
                'expires_at' => now()->addDay()->format('Y-m-d\TH:i'),
                'scopes' => ['tasks:read'],
            ]);

        $response->assertSessionHasErrors();
        $this->assertSame(
            ['当前规格 API Token 数量不足，请撤销闲置凭证或升级规格'],
            $response->getSession()->get('errors')->all()
        );

        $this->assertDatabaseMissing('personal_access_tokens', ['name' => '超额 MCP Key']);
    }

    /**
     * @Name: test_mcp_key_can_read_machine_auth_context
     *
     * @Description: 验证独立 MCP 服务可通过 auth/me 获取 Key、管理员和绑定站点上下文。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 16:38:47
     *
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Return: void
     */
    public function test_mcp_key_can_read_machine_auth_context(): void
    {
        [$admin, $site] = $this->createAccount('mcp_auth_user');
        $created = app(ApiTokenService::class)->createToken(
            '自检 MCP Key',
            [ApiTokenService::MCP_CONNECT_SCOPE, 'tasks:read'],
            (int) $admin->id,
            now()->addDay()->format('Y-m-d H:i:s'),
            (int) $site->id,
        );

        $this->withHeader('Authorization', 'Bearer '.$created['token'])
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.token.name', '自检 MCP Key')
            ->assertJsonPath('data.admin.id', (int) $admin->id)
            ->assertJsonPath('data.site.id', (int) $site->id)
            ->assertJsonPath('data.site.name', $site->name)
            ->assertJsonPath('data.token.scopes.0', ApiTokenService::MCP_CONNECT_SCOPE);
    }

    /**
     * @Name: test_mcp_key_only_accesses_its_owners_geo_business_data
     *
     * @Description: 验证同站点不同账号的任务、文章、执行记录和任务投递均按 Token 真实所有者隔离。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 17:39:51
     *
     * @UpdateTime: 2026-07-13 17:39:51
     *
     * @Return: void
     */
    public function test_mcp_key_only_accesses_its_owners_geo_business_data(): void
    {
        Queue::fake();

        [$admin, $site] = $this->createAccount('mcp_geo_owner');
        $otherAdmin = $this->createSiteMember($site, 'mcp_geo_other');

        $ownTask = Task::withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => '当前账号 GEO 任务',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $otherTask = Task::withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $otherAdmin->id,
            'name' => '其他账号 GEO 任务',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $ownRun = TaskRun::withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'task_id' => (int) $ownTask->id,
            'status' => 'completed',
            'meta' => [],
        ]);
        $otherRun = TaskRun::withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $otherAdmin->id,
            'task_id' => (int) $otherTask->id,
            'status' => 'completed',
            'meta' => [],
        ]);
        $ownAuthor = Author::withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => '当前账号作者',
        ]);
        $otherAuthor = Author::withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $otherAdmin->id,
            'name' => '其他账号作者',
        ]);
        $ownCategory = Category::withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => '当前账号分类',
            'slug' => 'mcp-owner-category',
        ]);
        $otherCategory = Category::withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $otherAdmin->id,
            'name' => '其他账号分类',
            'slug' => 'mcp-other-category',
        ]);
        $ownArticle = Article::withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'title' => '当前账号 GEO 文章',
            'slug' => 'mcp-owner-article',
            'content' => '当前账号文章正文',
            'category_id' => (int) $ownCategory->id,
            'author_id' => (int) $ownAuthor->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);
        $otherArticle = Article::withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $otherAdmin->id,
            'title' => '其他账号 GEO 文章',
            'slug' => 'mcp-other-article',
            'content' => '其他账号文章正文',
            'category_id' => (int) $otherCategory->id,
            'author_id' => (int) $otherAuthor->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);
        $created = app(ApiTokenService::class)->createToken(
            'GEO 业务隔离 MCP Key',
            [
                ApiTokenService::MCP_CONNECT_SCOPE,
                'tasks:read',
                'tasks:write',
                'jobs:read',
                'articles:read',
            ],
            (int) $admin->id,
            now()->addDay()->format('Y-m-d H:i:s'),
            (int) $site->id,
        );
        $authorization = ['Authorization' => 'Bearer '.$created['token']];

        $this->withHeaders($authorization)
            ->getJson('/api/v1/tasks')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', (int) $ownTask->id);
        $this->withHeaders($authorization)
            ->getJson('/api/v1/tasks/'.$otherTask->id)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'task_not_found');
        $this->withHeaders($authorization)
            ->getJson('/api/v1/tasks/'.$ownTask->id.'/jobs')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', (int) $ownRun->id);
        $this->withHeaders($authorization)
            ->getJson('/api/v1/jobs/'.$otherRun->id)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'job_not_found');

        $this->withHeaders($authorization)
            ->getJson('/api/v1/articles')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', (int) $ownArticle->id);
        $this->withHeaders($authorization)
            ->getJson('/api/v1/articles/'.$otherArticle->id)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'article_not_found');

        $this->withHeaders($authorization)
            ->postJson('/api/v1/tasks/'.$otherTask->id.'/enqueue')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'task_not_found');
        $runResponse = $this->withHeaders($authorization)
            ->postJson('/api/v1/tasks/'.$ownTask->id.'/enqueue')
            ->assertCreated()
            ->assertJsonPath('data.task_id', (int) $ownTask->id)
            ->assertJsonPath('data.status', 'pending');

        $queuedRun = TaskRun::withoutGlobalScopes()->findOrFail((int) $runResponse->json('data.job_id'));
        $this->assertSame((int) $admin->id, (int) $queuedRun->owner_admin_id);
        $this->assertSame((int) $site->id, (int) $queuedRun->site_id);
    }

    /**
     * @Name: test_mcp_key_is_rejected_when_its_owner_is_disabled
     *
     * @Description: 验证 Token 所属账号停用后凭证立即失效，不允许回退到其他管理员身份。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 17:39:51
     *
     * @UpdateTime: 2026-07-13 17:39:51
     *
     * @Return: void
     */
    public function test_mcp_key_is_rejected_when_its_owner_is_disabled(): void
    {
        [$admin, $site] = $this->createAccount('mcp_disabled_owner');
        $created = app(ApiTokenService::class)->createToken(
            '停用账号 MCP Key',
            [ApiTokenService::MCP_CONNECT_SCOPE, 'tasks:read'],
            (int) $admin->id,
            now()->addDay()->format('Y-m-d H:i:s'),
            (int) $site->id,
        );
        $admin->forceFill(['status' => 'inactive'])->save();

        $this->withHeader('Authorization', 'Bearer '.$created['token'])
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'admin_not_available');
    }

    /**
     * @Name: test_mcp_key_is_rejected_after_owner_loses_site_membership
     *
     * @Description: 验证账号被移出绑定站点后旧 MCP Key 立即失效，不能继续访问站点 GEO 业务。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 17:39:51
     *
     * @UpdateTime: 2026-07-13 17:39:51
     *
     * @Return: void
     */
    public function test_mcp_key_is_rejected_after_owner_loses_site_membership(): void
    {
        [$admin, $site] = $this->createAccount('mcp_removed_member');
        $created = app(ApiTokenService::class)->createToken(
            '已移除成员 MCP Key',
            [ApiTokenService::MCP_CONNECT_SCOPE, 'tasks:read'],
            (int) $admin->id,
            now()->addDay()->format('Y-m-d H:i:s'),
            (int) $site->id,
        );
        $site->members()->detach((int) $admin->id);

        $this->withHeader('Authorization', 'Bearer '.$created['token'])
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'site_not_available');
    }

    /**
     * @Name: test_regular_api_token_cannot_use_mcp_auth_context
     *
     * @Description: 验证普通 API Token 缺少专用连接权限时不能调用 MCP 机器凭证自检接口。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 17:39:51
     *
     * @UpdateTime: 2026-07-13 17:39:51
     *
     * @Return: void
     */
    public function test_regular_api_token_cannot_use_mcp_auth_context(): void
    {
        [$admin, $site] = $this->createAccount('mcp_regular_token_owner');
        $created = app(ApiTokenService::class)->createToken(
            '普通 API Token',
            ['tasks:read'],
            (int) $admin->id,
            now()->addDay()->format('Y-m-d H:i:s'),
            (int) $site->id,
        );

        $this->withHeader('Authorization', 'Bearer '.$created['token'])
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden')
            ->assertJsonPath('error.details.required_scope', ApiTokenService::MCP_CONNECT_SCOPE);
    }

    /**
     * @Name: test_mcp_key_creation_hides_internal_exception_details
     *
     * @Description: 验证 MCP Key 创建发生未知异常时仅返回统一提示，不向用户暴露数据库或内部服务信息。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 17:39:51
     *
     * @UpdateTime: 2026-07-13 17:39:51
     *
     * @Return: void
     */
    public function test_mcp_key_creation_hides_internal_exception_details(): void
    {
        [$admin, $site] = $this->createAccount('mcp_internal_error_owner');
        $this->mock(McpKeyService::class)
            ->shouldReceive('createKey')
            ->once()
            ->andThrow(new \RuntimeException('数据库连接包含敏感内部信息'));

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.mcp-server.keys.store'), [
                'name' => '异常脱敏 MCP Key',
                'expires_at' => now()->addDay()->format('Y-m-d\TH:i'),
                'scopes' => ['tasks:read'],
            ]);

        $response
            ->assertRedirect()
            ->assertSessionHasErrors();
        $errors = $response->getSession()->get('errors')->all();
        $this->assertSame(['MCP Key 创建失败，请稍后重试'], $errors);
        $this->assertStringNotContainsString('数据库连接包含敏感内部信息', implode(' ', $errors));
    }

    /**
     * @Name: createAccount
     *
     * @Description: 创建具备当前站点、成员关系和有效套餐的独立账号上下文。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 16:38:47
     *
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Param: string $username 管理员用户名
     *
     * @Param: int $tokenQuota API Token 数量上限
     *
     * @Return: array{0: Admin, 1: Site} 管理员和站点
     */
    private function createAccount(string $username, int $tokenQuota = 5): array
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => 'MCP 用户',
            'role' => 'site_user',
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => 'MCP 企业站点',
            'status' => 'active',
            'customer_mode' => 'direct',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'owner']);
        $this->openTestingPlanForSite($site, $admin, [
            PlatformPlan::RESOURCE_API_TOKENS => [
                'quota_value' => $tokenQuota,
                'quota_period' => 'cycle',
                'unit' => 'tokens',
            ],
        ]);

        return [$admin, $site];
    }

    /**
     * @Name: createSiteMember
     *
     * @Description: 在既有站点中创建另一个普通用户，用于验证同站点账号级业务数据隔离。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 17:39:51
     *
     * @UpdateTime: 2026-07-13 17:39:51
     *
     * @Param: Site $site 目标站点
     *
     * @Param: string $username 管理员用户名
     *
     * @Return: Admin 新建站点用户
     */
    private function createSiteMember(Site $site, string $username): Admin
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => '其他 MCP 用户',
            'role' => 'site_user',
            'status' => 'active',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'member']);

        return $admin;
    }
}
