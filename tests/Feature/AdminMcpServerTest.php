<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-07-29
 *
 * @Time: 15:41:12
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： AdminMcpServerTest.php
 *
 * @Description: 验证用户侧 ceying-geo MCP Key、Skill 下载、站点隔离、套餐额度和机器凭证自检契约。
 */

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\KeywordLibrary;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Models\Task;
use App\Models\TaskRun;
use App\Services\Api\ApiTokenService;
use App\Services\Mcp\McpKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;
use ZipArchive;

class AdminMcpServerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @Name: test_user_can_open_mcp_server_page_for_current_site
     *
     * @Description: 验证用户可从当前站点打开 MCP Server 页面并看到连接、Skill、工具和费用说明。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 16:38:47
     *
     * @UpdateTime: 2026-08-05 16:27:09
     *
     * @Return: void
     */
    public function test_user_can_open_mcp_server_page_for_current_site(): void
    {
        [$admin, $site] = $this->createAccount('mcp_page_user');

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.mcp-server.index'));

        $response
            ->assertOk()
            ->assertSee('<h1 class="text-2xl font-bold text-gray-900">MCP Server</h1>', false)
            ->assertSee($site->name)
            ->assertSee('Streamable HTTP')
            ->assertSee('&quot;ceying-geo&quot;:', false)
            ->assertSee('<h2 id="skill-heading" class="text-xl font-semibold text-gray-900">GEO Skills</h2>', false)
            ->assertSee('策影GEO品牌增长智能体')
            ->assertSee('自然触发场景')
            ->assertSee('推广曝光增长方案')
            ->assertSee('行业推广方案')
            ->assertSee('品牌或产品推广')
            ->assertSee('视频生成与文章站内发布')
            ->assertSee('ceying-geo-content-operations')
            ->assertSee('GEOFlow')
            ->assertDontSee('qw_mcp_list')
            ->assertDontSee('潜客挖掘')
            ->assertSee('<h2 id="materials-heading" class="text-xl font-semibold text-gray-900">GEO 素材管理</h2>', false)
            ->assertSee(route('admin.mcp-server.skills.download'), false)
            ->assertSee('geo_run_task')
            ->assertSee('geo_get_material_summary')
            ->assertSee('geo_delete_material_items')
            ->assertSee('geo_publish_article_to_site')
            ->assertSee('geo_publish_article_to_media')
            ->assertSee('geo_create_video')
            ->assertDontSee('geo_publish_video_to_self_media')
            ->assertSee('geo_create_brand_diagnosis')
            ->assertSee('geo_confirm_brand_diagnosis')
            ->assertSee('视频生成')
            ->assertSee('文章站内发布不扣费')
            ->assertSee('品牌诊断执行')
            ->assertSee('扣减一次品牌诊断额度')
            ->assertSee('按渠道实际售价扣费，失败退款')
            ->assertSee('MCP 请求本身不单独计费')
            ->assertSee('data-mcp-never-expires', false)
            ->assertSee('data-mcp-scope-grid class="mt-2 grid grid-cols-1 gap-2 md:grid-cols-2"', false)
            ->assertDontSee('媒体投稿消费策略');

        $this->assertSame(
            count(McpKeyService::BUSINESS_SCOPES),
            substr_count((string) $response->getContent(), 'data-mcp-scope-option')
        );
    }

    /**
     * @Name: test_user_can_download_versioned_ceying_geo_skill_package
     *
     * @Description: 验证登录用户可下载带 MCP 能力门禁的品牌增长 Skill ZIP，包内包含安装引导和十五类业务参考文件且不携带连接凭证。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-29 15:41:12
     *
     * @UpdateTime: 2026-08-05 16:26:48
     *
     * @Return: void
     */
    public function test_user_can_download_versioned_ceying_geo_skill_package(): void
    {
        [$admin, $site] = $this->createAccount('mcp_skill_download_user');

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.mcp-server.skills.download'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/zip');
        $this->assertStringContainsString(
            'ceying-geo-content-operations-2.4.1.zip',
            (string) $response->headers->get('content-disposition')
        );

        $zipPath = tempnam(sys_get_temp_dir(), 'ceying-geo-skill-');
        $this->assertIsString($zipPath);
        file_put_contents($zipPath, $response->streamedContent());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath));

        $root = 'ceying-geo-content-operations/';
        $skill = (string) $zip->getFromName($root.'SKILL.md');
        $this->assertStringContainsString('name: ceying-geo-content-operations', $skill);
        $this->assertStringContainsString('策影GEO品牌增长智能体', $skill);
        $this->assertStringContainsString('即使没有提及 GEO', $skill);
        $this->assertStringContainsString('如何推广品牌或产品', $skill);
        $this->assertStringContainsString('如何增加曝光和咨询', $skill);
        $this->assertStringContainsString('如何做短视频推广', $skill);
        $this->assertStringContainsString('广告软文', $skill);
        $this->assertStringContainsString('推广成都旅游', $skill);
        $this->assertStringContainsString('品牌增长', $skill);
        $this->assertStringContainsString('品牌建设和推广执行', $skill);
        $this->assertStringContainsString('品牌诊断只是品牌增长中的一项检测能力', $skill);
        $this->assertStringContainsString('发布到官网', $skill);
        $this->assertStringContainsString('视频生成', $skill);
        $this->assertStringContainsString('文章站内发布', $skill);
        $this->assertStringContainsString('推广曝光增长方案', $skill);
        $this->assertStringContainsString('行业推广方案', $skill);
        $this->assertStringContainsString('## 强制能力门禁', $skill);
        $this->assertStringContainsString('缺少必需 ceying-geo MCP 时的首轮响应不得止于', $skill);
        $this->assertStringContainsString('“策影 GEO”“GEO”“geo”“GEOFlow”“geoflow”', $skill);
        $this->assertStringContainsString('GEOFlow', $skill);
        $this->assertStringContainsString('geo_*', $skill);
        $this->assertStringNotContainsString('潜客挖掘', $skill);
        $this->assertStringNotContainsString('qw_mcp_list', $skill);
        $this->assertStringNotContainsString('qian-ke-wa-jue', $skill);
        $this->assertStringNotContainsString('Authorization: Bearer', $skill);
        $openAiMetadata = (string) $zip->getFromName($root.'agents/openai.yaml');
        $this->assertStringContainsString('display_name: "策影GEO品牌增长智能体"', $openAiMetadata);
        $this->assertStringContainsString('$ceying-geo-content-operations', $openAiMetadata);
        $this->assertStringContainsString('type: "mcp"', $openAiMetadata);
        $this->assertStringContainsString('value: "ceying-geo"', $openAiMetadata);
        $this->assertStringNotContainsString('value: "qian-ke-wa-jue"', $openAiMetadata);
        $this->assertStringContainsString('transport: "streamable_http"', $openAiMetadata);
        $this->assertStringContainsString('allow_implicit_invocation: true', $openAiMetadata);
        $this->assertNotFalse($zip->getFromName($root.'references/article-publishing.md'));
        $this->assertNotFalse($zip->getFromName($root.'references/video-generation-and-publishing.md'));
        $this->assertNotFalse($zip->getFromName($root.'references/task-execution.md'));
        $this->assertNotFalse($zip->getFromName($root.'references/material-management.md'));
        $this->assertNotFalse($zip->getFromName($root.'references/brand-diagnosis.md'));
        $this->assertNotFalse($zip->getFromName($root.'references/error-recovery.md'));
        $this->assertNotFalse($zip->getFromName($root.'references/marketing-intent-routing.md'));
        $this->assertNotFalse($zip->getFromName($root.'references/brand-positioning.md'));
        $this->assertNotFalse($zip->getFromName($root.'references/competitor-analysis.md'));
        $this->assertNotFalse($zip->getFromName($root.'references/product-promotion.md'));
        $this->assertNotFalse($zip->getFromName($root.'references/brand-promotion.md'));
        $this->assertNotFalse($zip->getFromName($root.'references/growth-roadmap.md'));
        $this->assertNotFalse($zip->getFromName($root.'references/measurement-and-monitoring.md'));
        $this->assertNotFalse($zip->getFromName($root.'references/mcp-server-setup.md'));
        $this->assertNotFalse($zip->getFromName($root.'references/customer-acquisition-growth.md'));
        $this->assertFalse($zip->getFromName($root.'references/prospect-mining.md'));
        $customerAcquisition = (string) $zip->getFromName($root.'references/customer-acquisition-growth.md');
        $this->assertStringContainsString('## 行业属性推广回答结构', $customerAcquisition);
        $this->assertStringContainsString('品牌曝光方案', $customerAcquisition);
        $this->assertStringContainsString('转化承接方案', $customerAcquisition);
        $this->assertStringNotContainsString('潜客挖掘', $customerAcquisition);
        $this->assertStringNotContainsString('qw_mcp_list', $customerAcquisition);

        $zip->close();
        @unlink($zipPath);
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
     * @UpdateTime: 2026-08-05 21:05:15
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
                    'materials:read',
                    'materials:write',
                    'articles:read',
                    'articles:write',
                    'articles:site-publish',
                    'media:read',
                    'media:submit',
                    'videos:read',
                    'videos:write',
                    'brand-diagnoses:read',
                    'brand-diagnoses:write',
                ],
            ]);

        $response
            ->assertRedirect(route('admin.mcp-server.index'))
            ->assertSessionHas('new_mcp_key');

        $token = PersonalAccessToken::query()->where('name', '内容运营助手')->firstOrFail();
        $this->assertSame((int) $admin->id, (int) $token->tokenable_id);
        $this->assertSame((int) $site->id, (int) $token->site_id);
        $this->assertContains(ApiTokenService::MCP_CONNECT_SCOPE, (array) $token->abilities);
        $this->assertContains('tasks:read', (array) $token->abilities);
        $this->assertContains('materials:read', (array) $token->abilities);
        $this->assertContains('materials:write', (array) $token->abilities);
        $this->assertContains('articles:write', (array) $token->abilities);
        $this->assertContains('articles:site-publish', (array) $token->abilities);
        $this->assertNotContains('articles:publish', (array) $token->abilities);
        $this->assertContains('media:read', (array) $token->abilities);
        $this->assertContains('media:submit', (array) $token->abilities);
        $this->assertContains('videos:read', (array) $token->abilities);
        $this->assertContains('videos:write', (array) $token->abilities);
        $this->assertNotContains('videos:publish', (array) $token->abilities);
        $this->assertContains('brand-diagnoses:read', (array) $token->abilities);
        $this->assertContains('brand-diagnoses:write', (array) $token->abilities);

        $plainToken = (string) $response->getSession()->get('new_mcp_key');
        $authResponse = $this->withHeader('Authorization', 'Bearer '.$plainToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonMissingPath('data.token.spending_policy');
        $this->assertContains('materials:read', $authResponse->json('data.token.scopes'));
        $this->assertContains('materials:write', $authResponse->json('data.token.scopes'));
    }

    /**
     * @Name: test_mcp_created_article_is_approved_without_manual_review
     *
     * @Description: 验证 MCP 上传文章时自动保存为已审核草稿，取消人工审核环节，同时不通过文章写入权限直接公开发布。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-08-06 00:04:31
     *
     * @UpdateTime: 2026-08-06 00:04:31
     *
     * @Return: void
     *
     * @Throws \PHPUnit\Framework\AssertionFailedError MCP 文章创建审核状态或发布边界不符合预期
     */
    public function test_mcp_created_article_is_approved_without_manual_review(): void
    {
        [$admin, $site] = $this->createAccount('mcp_article_auto_review_user');
        $author = Author::withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => 'MCP 上传作者',
        ]);
        $category = Category::withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => 'MCP 上传分类',
            'slug' => 'mcp-upload-category',
        ]);
        $created = app(McpKeyService::class)->createKey(
            $admin,
            $site,
            '文章上传 MCP Key',
            ['articles:read', 'articles:write'],
            now()->addDay()->format('Y-m-d H:i:s'),
        );

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$created['token'],
            'X-Idempotency-Key' => 'mcp-create-approved-article-123',
        ])->postJson('/api/v1/articles', [
            'title' => 'MCP 上传免审核文章',
            'content' => '这是一篇通过 MCP 上传的文章正文。',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'published',
            'review_status' => 'pending',
            'keywords' => ['MCP', '自动审核'],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.title', 'MCP 上传免审核文章')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.review_status', 'approved')
            ->assertJsonPath('data.published_at', null);

        $this->assertDatabaseHas('articles', [
            'id' => (int) $response->json('data.id'),
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'status' => 'draft',
            'review_status' => 'approved',
            'is_ai_generated' => 1,
        ]);
    }

    /**
     * @Name: test_mcp_site_publish_scope_auto_approves_without_review_api
     *
     * @Description: 验证 MCP 站内发布权限不会授予审核接口权限，但可自动通过并发布未被拒绝的文章。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-08-05 21:05:15
     *
     * @UpdateTime: 2026-08-05 21:12:19
     *
     * @Return: void
     *
     * @Throws \PHPUnit\Framework\AssertionFailedError 权限边界或发布结果不符合预期
     */
    public function test_mcp_site_publish_scope_auto_approves_without_review_api(): void
    {
        [$admin, $site] = $this->createAccount('mcp_site_publish_user');
        $author = Author::withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => '站内发布作者',
        ]);
        $category = Category::withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => '站内发布分类',
            'slug' => 'mcp-site-publish-category',
        ]);
        $approvedArticle = Article::withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'title' => '已审核站内发布文章',
            'slug' => 'mcp-site-publish-approved',
            'content' => '已审核文章正文',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'draft',
            'review_status' => 'approved',
        ]);
        $pendingArticle = Article::withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'title' => '待审核站内发布文章',
            'slug' => 'mcp-site-publish-pending',
            'content' => '待审核文章正文',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);
        $rejectedArticle = Article::withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'title' => '已拒绝站内发布文章',
            'slug' => 'mcp-site-publish-rejected',
            'content' => '已拒绝文章正文',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'draft',
            'review_status' => 'rejected',
        ]);
        $created = app(McpKeyService::class)->createKey(
            $admin,
            $site,
            '站内发布 MCP Key',
            ['articles:read', 'articles:site-publish'],
            now()->addDay()->format('Y-m-d H:i:s'),
        );
        $authorization = ['Authorization' => 'Bearer '.$created['token']];

        $this->withHeaders($authorization)
            ->postJson('/api/v1/articles/'.$pendingArticle->id.'/review', [
                'review_status' => 'approved',
                'review_note' => 'MCP 尝试审核',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.details.required_scope', 'articles:publish');
        $this->assertSame(
            'pending',
            (string) Article::withoutGlobalScopes()->whereKey((int) $pendingArticle->id)->value('review_status')
        );

        $this->withHeaders([...$authorization, 'X-Idempotency-Key' => 'mcp-site-publish-123'])
            ->postJson('/api/v1/mcp/articles/'.$approvedArticle->id.'/publish')
            ->assertOk()
            ->assertJsonPath('data.id', (int) $approvedArticle->id)
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.review_status', 'approved');

        $this->withHeaders([...$authorization, 'X-Idempotency-Key' => 'mcp-site-publish-pending-123'])
            ->postJson('/api/v1/mcp/articles/'.$pendingArticle->id.'/publish')
            ->assertOk()
            ->assertJsonPath('data.id', (int) $pendingArticle->id)
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.review_status', 'auto_approved');

        $this->withHeaders([...$authorization, 'X-Idempotency-Key' => 'mcp-site-publish-rejected-123'])
            ->postJson('/api/v1/mcp/articles/'.$rejectedArticle->id.'/publish')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'article_rejected');
    }

    /**
     * @Name: test_user_can_create_never_expiring_mcp_key_with_chinese_scope_labels
     *
     * @Description: 验证永不过期开关会忽略过期时间，并在 Key 列表中以中文风险标签展示已授权业务权限。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-19 01:17:52
     *
     * @UpdateTime: 2026-07-19 01:17:52
     *
     * @Return: void
     */
    public function test_user_can_create_never_expiring_mcp_key_with_chinese_scope_labels(): void
    {
        [$admin, $site] = $this->createAccount('mcp_never_expires_user');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.mcp-server.keys.store'), [
                'name' => '长期内容助手',
                'never_expires' => '1',
                'expires_at' => now()->subDay()->format('Y-m-d\TH:i'),
                'scopes' => ['tasks:read', 'materials:write', 'media:submit'],
            ])
            ->assertRedirect(route('admin.mcp-server.index'))
            ->assertSessionHas('new_mcp_key');

        $token = PersonalAccessToken::query()->where('name', '长期内容助手')->firstOrFail();
        $this->assertNull($token->expires_at);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.mcp-server.index'))
            ->assertOk()
            ->assertSee('永不过期')
            ->assertSee('data-mcp-key-scope-label="tasks:read">任务读取</span>', false)
            ->assertSee('data-mcp-key-scope-label="materials:write">素材管理</span>', false)
            ->assertSee('data-mcp-key-scope-label="media:submit">媒体投稿</span>', false);
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
     * @Name: test_user_can_update_existing_mcp_key_scopes_without_rotating_token
     *
     * @Description: 验证已生成 MCP Key 可修改业务权限，且不改变 Token 哈希、有效期和既有客户端认证能力。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-08-05 23:05:06
     *
     * @UpdateTime: 2026-08-05 23:05:06
     *
     * @Return: void
     */
    public function test_user_can_update_existing_mcp_key_scopes_without_rotating_token(): void
    {
        [$admin, $site] = $this->createAccount('mcp_update_scope_user');
        $expiresAt = now()->addDay()->format('Y-m-d H:i:s');
        $created = app(McpKeyService::class)->createKey(
            $admin,
            $site,
            '可改权限 MCP Key',
            ['tasks:read'],
            $expiresAt,
        );
        $keyId = (int) $created['record']['id'];
        $plainToken = (string) $created['token'];
        $before = PersonalAccessToken::query()->whereKey($keyId)->firstOrFail();
        $tokenHash = (string) $before->token;
        $tokenExpiresAt = $before->expires_at?->format('Y-m-d H:i:s');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.mcp-server.keys.scopes', ['keyId' => $keyId]), [
                'scopes' => ['materials:read', 'articles:site-publish', 'videos:write'],
            ])
            ->assertRedirect(route('admin.mcp-server.index'))
            ->assertSessionHas('message', 'MCP Key 权限已更新，客户端重新加载后生效。');

        $updated = PersonalAccessToken::query()->whereKey($keyId)->firstOrFail();
        $this->assertSame($tokenHash, (string) $updated->token);
        $this->assertSame($tokenExpiresAt, $updated->expires_at?->format('Y-m-d H:i:s'));
        $this->assertContains(ApiTokenService::MCP_CONNECT_SCOPE, (array) $updated->abilities);
        $this->assertContains('materials:read', (array) $updated->abilities);
        $this->assertContains('articles:site-publish', (array) $updated->abilities);
        $this->assertContains('videos:write', (array) $updated->abilities);
        $this->assertNotContains('tasks:read', (array) $updated->abilities);

        $authResponse = $this->withHeader('Authorization', 'Bearer '.$plainToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
        $this->assertContains('materials:read', $authResponse->json('data.token.scopes'));
        $this->assertContains('articles:site-publish', $authResponse->json('data.token.scopes'));
        $this->assertContains('videos:write', $authResponse->json('data.token.scopes'));
        $this->assertNotContains('tasks:read', $authResponse->json('data.token.scopes'));

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.mcp-server.index'))
            ->assertOk()
            ->assertSee(route('admin.mcp-server.keys.scopes', ['keyId' => $keyId]), false)
            ->assertSee('修改权限')
            ->assertSee('保存后不会重新显示 Key 明文');
    }

    /**
     * @Name: test_user_cannot_update_other_accounts_mcp_key_or_regular_api_token_scopes
     *
     * @Description: 验证 MCP Key 权限修改接口保持账号、站点和 MCP 专用 Token 隔离，禁止越权修改或误改普通 API Token。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-08-05 23:05:06
     *
     * @UpdateTime: 2026-08-05 23:05:06
     *
     * @Return: void
     */
    public function test_user_cannot_update_other_accounts_mcp_key_or_regular_api_token_scopes(): void
    {
        [$admin, $site] = $this->createAccount('mcp_update_scope_guard_user');
        $other = $this->createSiteMember($site, 'mcp_update_scope_guard_other');
        $this->openTestingPlanForSite($site, $other);
        $otherMcpKey = $other->createToken(
            '其他账号可改 MCP Key',
            [ApiTokenService::MCP_CONNECT_SCOPE, 'tasks:read']
        )->accessToken;
        $otherMcpKey->forceFill(['site_id' => (int) $site->id])->save();
        $regularToken = $admin->createToken('当前账号普通 API Token', ['tasks:read'])->accessToken;
        $regularToken->forceFill(['site_id' => (int) $site->id])->save();

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.mcp-server.keys.scopes', ['keyId' => (int) $otherMcpKey->id]), [
                'scopes' => ['materials:write'],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors();
        $this->assertSame(
            [ApiTokenService::MCP_CONNECT_SCOPE, 'tasks:read'],
            (array) $otherMcpKey->refresh()->abilities
        );

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.mcp-server.keys.scopes', ['keyId' => (int) $regularToken->id]), [
                'scopes' => ['materials:write'],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors();
        $this->assertSame(['tasks:read'], (array) $regularToken->refresh()->abilities);
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
     * @Description: 验证同站点不同账号的任务、文章、素材、执行记录和写操作均按 Token 真实所有者隔离。
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
        $ownKeywordLibrary = KeywordLibrary::withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => '当前账号关键词库',
            'description' => '当前账号素材',
            'keyword_count' => 0,
        ]);
        $otherKeywordLibrary = KeywordLibrary::withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $otherAdmin->id,
            'name' => '其他账号关键词库',
            'description' => '其他账号素材',
            'keyword_count' => 0,
        ]);
        $created = app(ApiTokenService::class)->createToken(
            'GEO 业务隔离 MCP Key',
            [
                ApiTokenService::MCP_CONNECT_SCOPE,
                'tasks:read',
                'tasks:write',
                'jobs:read',
                'materials:read',
                'materials:write',
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
            ->getJson('/api/v1/materials/keyword-libraries')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', (int) $ownKeywordLibrary->id);
        $this->withHeaders($authorization)
            ->getJson('/api/v1/materials/keyword-libraries/'.$otherKeywordLibrary->id)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'material_not_found');
        $this->withHeaders($authorization)
            ->patchJson('/api/v1/materials/keyword-libraries/'.$otherKeywordLibrary->id, [
                'description' => '越权更新',
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'material_not_found');
        $this->withHeaders($authorization)
            ->deleteJson('/api/v1/materials/keyword-libraries/'.$otherKeywordLibrary->id)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'material_not_found');
        $materialResponse = $this->withHeaders($authorization)
            ->postJson('/api/v1/materials/keyword-libraries', [
                'name' => 'MCP 新增关键词库',
                'description' => '由 MCP 创建',
            ])
            ->assertCreated();
        $createdMaterialId = (int) $materialResponse->json('data.item.id');
        $createdMaterial = KeywordLibrary::withoutGlobalScopes()->findOrFail($createdMaterialId);
        $this->assertSame((int) $admin->id, (int) $createdMaterial->owner_admin_id);
        $this->assertSame((int) $site->id, (int) $createdMaterial->site_id);

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
     * @Name: test_mcp_material_scopes_cover_all_machine_api_capabilities
     *
     * @Description: 验证 MCP Key 可通过现有机器 API 完成六类素材的摘要、列表、详情、创建、更新、删除，并管理三类可写条目及读取知识库切块。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 18:15:00
     *
     * @UpdateTime: 2026-07-18 18:15:00
     *
     * @Return: void
     */
    public function test_mcp_material_scopes_cover_all_machine_api_capabilities(): void
    {
        [$admin, $site] = $this->createAccount('mcp_material_capabilities');
        $created = app(ApiTokenService::class)->createToken(
            '素材完整能力 MCP Key',
            [ApiTokenService::MCP_CONNECT_SCOPE, 'materials:read', 'materials:write'],
            (int) $admin->id,
            now()->addDay()->format('Y-m-d H:i:s'),
            (int) $site->id,
        );
        $authorization = ['Authorization' => 'Bearer '.$created['token']];
        $definitions = [
            'categories' => [
                'create' => ['name' => 'MCP 分类', 'description' => '分类说明'],
                'update' => ['description' => '更新分类说明'],
            ],
            'authors' => [
                'create' => ['name' => 'MCP 作者', 'bio' => '作者介绍'],
                'update' => ['bio' => '更新作者介绍'],
            ],
            'keyword-libraries' => [
                'create' => ['name' => 'MCP 关键词库', 'description' => '关键词说明'],
                'update' => ['description' => '更新关键词说明'],
            ],
            'title-libraries' => [
                'create' => ['name' => 'MCP 标题库', 'description' => '标题说明'],
                'update' => ['description' => '更新标题说明'],
            ],
            'image-libraries' => [
                'create' => ['name' => 'MCP 图片库', 'description' => '图片说明'],
                'update' => ['description' => '更新图片说明'],
            ],
            'knowledge-bases' => [
                'create' => ['name' => 'MCP 知识库', 'description' => '知识说明', 'content' => 'GEO 素材知识库正文。'],
                'update' => ['description' => '更新知识说明'],
            ],
        ];

        $this->withHeaders($authorization)
            ->getJson('/api/v1/materials')
            ->assertOk()
            ->assertJsonCount(6, 'data.types');

        $materialIds = [];
        foreach ($definitions as $type => $definition) {
            $materialId = (int) $this->withHeaders($authorization)
                ->postJson('/api/v1/materials/'.$type, $definition['create'])
                ->assertCreated()
                ->assertJsonPath('data.type', $type)
                ->json('data.item.id');
            $materialIds[$type] = $materialId;

            $this->withHeaders($authorization)
                ->getJson('/api/v1/materials/'.$type)
                ->assertOk()
                ->assertJsonPath('data.type', $type);
            $this->withHeaders($authorization)
                ->getJson('/api/v1/materials/'.$type.'/'.$materialId)
                ->assertOk()
                ->assertJsonPath('data.item.id', $materialId);
            $this->withHeaders($authorization)
                ->patchJson('/api/v1/materials/'.$type.'/'.$materialId, $definition['update'])
                ->assertOk()
                ->assertJsonPath('data.item.id', $materialId);
        }

        $itemDefinitions = [
            'keyword-libraries' => ['keyword' => 'GEO 关键词'],
            'title-libraries' => ['title' => 'GEO 标题', 'keyword' => 'GEO'],
            'image-libraries' => [
                'file_path' => 'https://cdn.example.com/material-image.png',
                'filename' => 'material-image.png',
            ],
        ];
        foreach ($itemDefinitions as $type => $payload) {
            $materialId = $materialIds[$type];
            $itemId = (int) $this->withHeaders($authorization)
                ->postJson('/api/v1/materials/'.$type.'/'.$materialId.'/items', $payload)
                ->assertCreated()
                ->json('data.item.id');
            $this->withHeaders($authorization)
                ->getJson('/api/v1/materials/'.$type.'/'.$materialId.'/items')
                ->assertOk()
                ->assertJsonPath('data.pagination.total', 1);
            $this->withHeaders($authorization)
                ->deleteJson('/api/v1/materials/'.$type.'/'.$materialId.'/items', ['ids' => [$itemId]])
                ->assertOk()
                ->assertJsonPath('data.deleted_count', 1);
        }

        $this->withHeaders($authorization)
            ->getJson('/api/v1/materials/knowledge-bases/'.$materialIds['knowledge-bases'].'/items')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);

        foreach (array_reverse($materialIds, true) as $type => $materialId) {
            $this->withHeaders($authorization)
                ->deleteJson('/api/v1/materials/'.$type.'/'.$materialId)
                ->assertOk()
                ->assertJsonPath('data.deleted', true);
        }
    }

    /**
     * @Name: test_mcp_material_delete_preserves_files_referenced_by_another_account
     *
     * @Description: 验证素材写权限不能通过伪造相同 file_path 删除同站点其他账号仍在使用的受控存储文件。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 18:15:00
     *
     * @UpdateTime: 2026-07-18 18:15:00
     *
     * @Return: void
     */
    public function test_mcp_material_delete_preserves_files_referenced_by_another_account(): void
    {
        Storage::fake('public');
        [$admin, $site] = $this->createAccount('mcp_material_file_owner');
        $otherAdmin = $this->createSiteMember($site, 'mcp_material_file_other');
        $sharedPath = 'storage/uploads/images/shared-account-image.png';
        Storage::disk('public')->put('uploads/images/shared-account-image.png', 'shared image');

        $otherLibrary = ImageLibrary::withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $otherAdmin->id,
            'name' => '其他账号图片库',
            'description' => '',
            'image_count' => 1,
            'used_task_count' => 0,
        ]);
        $otherImage = Image::withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $otherAdmin->id,
            'library_id' => (int) $otherLibrary->id,
            'filename' => 'shared-account-image.png',
            'original_name' => 'shared-account-image.png',
            'file_name' => 'shared-account-image.png',
            'file_path' => $sharedPath,
            'file_size' => 12,
            'mime_type' => 'image/png',
            'width' => 1,
            'height' => 1,
            'tags' => '',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $ownLibrary = ImageLibrary::withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => '当前账号图片库',
            'description' => '',
            'image_count' => 0,
            'used_task_count' => 0,
        ]);
        $created = app(ApiTokenService::class)->createToken(
            '素材文件隔离 MCP Key',
            [ApiTokenService::MCP_CONNECT_SCOPE, 'materials:write'],
            (int) $admin->id,
            now()->addDay()->format('Y-m-d H:i:s'),
            (int) $site->id,
        );
        $authorization = ['Authorization' => 'Bearer '.$created['token']];

        $ownImageId = (int) $this->withHeaders($authorization)
            ->postJson('/api/v1/materials/image-libraries/'.$ownLibrary->id.'/items', [
                'file_path' => $sharedPath,
                'filename' => 'shared-account-image.png',
            ])
            ->assertCreated()
            ->json('data.item.id');
        $this->withHeaders($authorization)
            ->deleteJson('/api/v1/materials/image-libraries/'.$ownLibrary->id.'/items', [
                'ids' => [$ownImageId],
            ])
            ->assertOk()
            ->assertJsonPath('data.deleted_count', 1);

        Storage::disk('public')->assertExists('uploads/images/shared-account-image.png');
        $this->assertDatabaseHas('images', [
            'id' => (int) $otherImage->id,
            'owner_admin_id' => (int) $otherAdmin->id,
            'file_path' => $sharedPath,
        ]);

        $ownOnlyPath = 'storage/uploads/images/own-account-image.png';
        Storage::disk('public')->put('uploads/images/own-account-image.png', 'own image');
        $ownOnlyImageId = (int) $this->withHeaders($authorization)
            ->postJson('/api/v1/materials/image-libraries/'.$ownLibrary->id.'/items', [
                'file_path' => $ownOnlyPath,
                'filename' => 'own-account-image.png',
            ])
            ->assertCreated()
            ->json('data.item.id');
        $this->withHeaders($authorization)
            ->deleteJson('/api/v1/materials/image-libraries/'.$ownLibrary->id.'/items', [
                'ids' => [$ownOnlyImageId],
            ])
            ->assertOk()
            ->assertJsonPath('data.deleted_count', 1);
        Storage::disk('public')->assertMissing('uploads/images/own-account-image.png');
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
