<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-08-05
 *
 * @Time: 18:12:22
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： McpVideoGenerationTest.php
 *
 * @Description: 验证 MCP 视频生成、套餐额度、读写权限和账号站点隔离。
 */

namespace Tests\Feature;

use App\Jobs\StartVideoGenerationJob;
use App\Models\Admin;
use App\Models\AdminResourceUsage;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Models\VideoGenerationJob;
use App\Services\Mcp\McpKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class McpVideoGenerationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @Name: test_mcp_key_can_create_and_read_video_generation_jobs_with_owner_isolation
     *
     * @Description: 验证 MCP Key 创建视频任务时按生成数量扣减视频额度、投递队列，并只能读取当前账号和站点内的视频任务。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-08-05 18:12:22
     *
     * @UpdateTime: 2026-08-05 18:12:22
     *
     * @Return: void
     *
     * @Throws: \PHPUnit\Framework\AssertionFailedError 权限、隔离、额度或队列结果不符合预期
     */
    public function test_mcp_key_can_create_and_read_video_generation_jobs_with_owner_isolation(): void
    {
        Queue::fake([StartVideoGenerationJob::class]);
        [$admin, $site] = $this->createAccount('mcp_video_owner');
        $otherAdmin = $this->createSiteMember($site, 'mcp_video_same_site_other');
        $otherVideo = $this->completedVideo($site, $otherAdmin, '其他账号视频');
        $key = $this->createMcpKey($admin, $site, ['videos:read', 'videos:write']);
        $authorization = [
            'Authorization' => 'Bearer '.$key,
            'X-Idempotency-Key' => 'mcp-video-create-123',
        ];

        $createResponse = $this->withHeaders($authorization)
            ->postJson('/api/v1/mcp/videos', [
                'subject' => '策影GEO 品牌增长短视频',
                'script' => '介绍策影GEO如何帮助企业提升 AI 搜索可见性。',
                'terms' => '品牌增长, AI搜索',
                'negative_terms' => '卡通',
                'video_aspect' => '9:16',
                'video_count' => 2,
                'cover_image' => 'https://cdn.example.test/video-cover.jpg',
            ])
            ->assertCreated()
            ->assertJsonPath('data.video.subject', '策影GEO 品牌增长短视频')
            ->assertJsonPath('data.video.status', 'queued')
            ->assertJsonPath('data.video.video_count', 2)
            ->assertJsonPath('data.billing.resource_key', PlatformPlan::RESOURCE_VIDEO_GENERATIONS)
            ->assertJsonPath('data.billing.amount', 2);
        $videoId = (int) $createResponse->json('data.video.id');

        $this->withHeaders($authorization)
            ->postJson('/api/v1/mcp/videos', [
                'subject' => '策影GEO 品牌增长短视频',
                'script' => '介绍策影GEO如何帮助企业提升 AI 搜索可见性。',
                'terms' => '品牌增长, AI搜索',
                'negative_terms' => '卡通',
                'video_aspect' => '9:16',
                'video_count' => 2,
                'cover_image' => 'https://cdn.example.test/video-cover.jpg',
            ])
            ->assertCreated()
            ->assertJsonPath('data.video.id', $videoId);

        $this->assertSame(2, VideoGenerationJob::query()->count());
        $usage = AdminResourceUsage::query()
            ->where('admin_id', (int) $admin->id)
            ->where('site_id', (int) $site->id)
            ->where('resource_key', PlatformPlan::RESOURCE_VIDEO_GENERATIONS)
            ->firstOrFail();
        $this->assertSame(2, (int) $usage->used_amount);
        Queue::assertPushed(StartVideoGenerationJob::class, 1);

        $this->withHeader('Authorization', 'Bearer '.$key)
            ->getJson('/api/v1/mcp/videos')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $videoId)
            ->assertJsonMissingPath('data.items.0.terms')
            ->assertJsonMissingPath('data.items.0.negative_terms')
            ->assertJsonMissingPath('data.items.0.api_task_id')
            ->assertJsonMissingPath('data.items.0.request_payload');
        $this->withHeader('Authorization', 'Bearer '.$key)
            ->getJson('/api/v1/mcp/videos/'.$otherVideo->id)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'video_not_found');
    }

    /**
     * @Name: test_mcp_video_read_and_write_require_separate_scopes
     *
     * @Description: 验证视频读取和视频生成分别受独立 scope 控制，避免只读 Key 或仅写入 Key 执行越权操作。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-08-05 18:12:22
     *
     * @UpdateTime: 2026-08-05 18:12:22
     *
     * @Return: void
     *
     * @Throws: \PHPUnit\Framework\AssertionFailedError 权限拦截结果不符合预期
     */
    public function test_mcp_video_read_and_write_require_separate_scopes(): void
    {
        [$admin, $site] = $this->createAccount('mcp_video_scope_owner');
        $readOnlyKey = $this->createMcpKey($admin, $site, ['videos:read']);
        $writeOnlyKey = $this->createMcpKey($admin, $site, ['videos:write']);

        $this->withHeader('Authorization', 'Bearer '.$readOnlyKey)
            ->postJson('/api/v1/mcp/videos', ['subject' => '无权生成视频'])
            ->assertForbidden()
            ->assertJsonPath('error.details.required_scope', 'videos:write');
        $this->withHeader('Authorization', 'Bearer '.$writeOnlyKey)
            ->getJson('/api/v1/mcp/videos')
            ->assertForbidden()
            ->assertJsonPath('error.details.required_scope', 'videos:read');
    }

    /**
     * @Name: createAccount
     *
     * @Description: 创建带站点成员关系、API Token 名额和视频生成额度的独立用户账号。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-08-05 18:12:22
     *
     * @UpdateTime: 2026-08-05 18:12:22
     *
     * @Param: string $username 管理员用户名
     *
     * @Return: array{0: Admin, 1: Site} 管理员和站点
     *
     * @Throws: \Throwable 账号、站点或套餐创建失败
     */
    private function createAccount(string $username): array
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => 'MCP 视频用户',
            'role' => 'site_user',
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => $username.' 站点',
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
            PlatformPlan::RESOURCE_VIDEO_GENERATIONS => [
                'quota_value' => 5,
                'quota_period' => 'cycle',
                'unit' => 'times',
            ],
        ]);

        return [$admin, $site];
    }

    /**
     * @Name: createSiteMember
     *
     * @Description: 在指定站点创建另一个普通账号，用于验证同站点视频任务隔离。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-08-05 18:12:22
     *
     * @UpdateTime: 2026-08-05 18:12:22
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
            'display_name' => '同站点其他视频用户',
            'role' => 'site_user',
            'status' => 'active',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'member']);

        return $admin;
    }

    /**
     * @Name: createMcpKey
     *
     * @Description: 为指定账号和站点创建只包含目标视频业务权限的 MCP Key。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-08-05 18:12:22
     *
     * @UpdateTime: 2026-08-05 18:12:22
     *
     * @Param: Admin $admin MCP Key 所属账号
     *
     * @Param: Site $site MCP Key 绑定站点
     *
     * @Param: array<int, string> $scopes 视频业务权限
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
            '视频 MCP Key',
            $scopes,
            now()->addDay()->format('Y-m-d H:i:s'),
        );

        return (string) $created['token'];
    }

    /**
     * @Name: completedVideo
     *
     * @Description: 创建已生成成功的视频任务，用于验证视频读取账号隔离。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-08-05 18:12:22
     *
     * @UpdateTime: 2026-08-05 18:12:22
     *
     * @Param: Site $site 视频所属站点
     *
     * @Param: Admin $owner 视频所属账号
     *
     * @Param: string $title 视频标题
     *
     * @Return: VideoGenerationJob 已完成的视频任务
     *
     * @Throws: \Throwable 视频任务创建失败
     */
    private function completedVideo(Site $site, Admin $owner, string $title): VideoGenerationJob
    {
        return VideoGenerationJob::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'created_by_admin_id' => (int) $owner->id,
            'title' => $title,
            'subject' => $title,
            'script' => '视频脚本',
            'terms' => 'AI, 品牌增长',
            'negative_terms' => '',
            'video_source' => 'pexels',
            'video_aspect' => '9:16',
            'video_count' => 1,
            'cover_image' => 'https://cdn.example.test/video-cover.jpg',
            'status' => 'success',
            'progress' => 100,
            'api_task_id' => 'video-task-003',
            'request_payload' => [],
            'result_payload' => [],
            'videos' => ['https://video.example.test/tasks/video-task-003/final-1.mp4'],
            'combined_videos' => [],
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
        ]);
    }

}
