<?php

/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-18
 *
 * @Time: 18:40
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： McpMediaSubmissionExperienceTest.php
 *
 * @Description: 验证 MCP 媒体投稿无需消费策略或预算参数，并保留渠道数量限制和来源 Key 记录。
 */

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminCreditAccount;
use App\Models\AdminCreditLedger;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\MediaResource;
use App\Models\MediaSubmission;
use App\Models\Site;
use App\Services\Api\ApiTokenService;
use App\Services\MediaDistribution\MediaPlatformClient;
use App\Services\MediaDistribution\MediaPlatformClientManager;
use App\Support\MediaDistribution\MediaPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class McpMediaSubmissionExperienceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @Name: test_mcp_submission_does_not_require_spending_policy_or_budget_fields
     *
     * @Description: 验证 MCP Key 仅凭媒体投稿权限即可按站点实时售价投稿，不要求 Key 消费策略或调用预算参数。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 18:40:00
     *
     * @UpdateTime: 2026-07-18 18:40:00
     *
     * @Return: void
     */
    public function test_mcp_submission_does_not_require_spending_policy_or_budget_fields(): void
    {
        [$plainToken, $token, $article, $resource] = $this->context('mcp_submission_simple');
        $client = Mockery::mock(MediaPlatformClient::class);
        $client->shouldReceive('submit')
            ->once()
            ->andReturn(['data' => ['order_nid' => 'mcp-simple-order']]);
        $clients = Mockery::mock(MediaPlatformClientManager::class);
        $clients->shouldReceive('forPlatform')
            ->once()
            ->with(MediaPlatform::CEYING_MEDIA_1)
            ->andReturn($client);
        $this->app->instance(MediaPlatformClientManager::class, $clients);

        $response = $this->withHeader('Authorization', 'Bearer '.$plainToken)
            ->postJson('/api/v1/media/submissions', [
                'article_ids' => [(int) $article->id],
                'media_resource_ids' => [(int) $resource->id],
                'remark' => '直接投稿',
            ]);

        $response
            ->assertCreated()
            ->assertJsonMissingPath('data.budget')
            ->assertJsonPath('data.submissions.0.status', 'submitted')
            ->assertJsonPath('data.submissions.0.points_amount', '3.00');
        $this->assertDatabaseHas('media_submissions', [
            'mcp_token_id' => (int) $token->id,
            'article_id' => (int) $article->id,
            'media_resource_id' => (int) $resource->id,
            'points_amount' => 3,
            'status' => 'submitted',
        ]);
        $this->assertSame('7.00', AdminCreditAccount::query()
            ->where('admin_id', (int) $article->owner_admin_id)
            ->where('site_id', (int) $article->site_id)
            ->value('balance'));
        $this->assertSame(1, AdminCreditLedger::query()
            ->where('submission_id', (int) $response->json('data.submissions.0.id'))
            ->where('type', 'deduct')
            ->count());
    }

    /**
     * @Name: test_mcp_submission_failure_refunds_deducted_credits
     *
     * @Description: 验证普通付费账号通过 MCP 投稿失败时恢复全部积分，并仅保留一条扣款和一条退款流水。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 18:15:00
     *
     * @UpdateTime: 2026-07-18 18:15:00
     *
     * @Return: void
     */
    public function test_mcp_submission_failure_refunds_deducted_credits(): void
    {
        [$plainToken, $token, $article, $resource] = $this->context('mcp_submission_refund');
        $client = Mockery::mock(MediaPlatformClient::class);
        $client->shouldReceive('submit')
            ->once()
            ->andThrow(new RuntimeException('上游投稿失败'));
        $clients = Mockery::mock(MediaPlatformClientManager::class);
        $clients->shouldReceive('forPlatform')
            ->once()
            ->with(MediaPlatform::CEYING_MEDIA_1)
            ->andReturn($client);
        $this->app->instance(MediaPlatformClientManager::class, $clients);

        $this->withHeader('Authorization', 'Bearer '.$plainToken)
            ->postJson('/api/v1/media/submissions', [
                'article_ids' => [(int) $article->id],
                'media_resource_ids' => [(int) $resource->id],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'media_submission_failed');

        $submission = MediaSubmission::query()
            ->where('mcp_token_id', (int) $token->id)
            ->firstOrFail();
        $this->assertSame('failed', (string) $submission->status);
        $this->assertSame('10.00', AdminCreditAccount::query()
            ->where('admin_id', (int) $article->owner_admin_id)
            ->where('site_id', (int) $article->site_id)
            ->value('balance'));
        $this->assertSame('0.00', AdminCreditAccount::query()
            ->where('admin_id', (int) $article->owner_admin_id)
            ->where('site_id', (int) $article->site_id)
            ->value('total_consumed'));
        $this->assertSame(1, AdminCreditLedger::query()
            ->where('submission_id', (int) $submission->id)
            ->where('type', 'deduct')
            ->count());
        $this->assertSame(1, AdminCreditLedger::query()
            ->where('submission_id', (int) $submission->id)
            ->where('type', 'refund')
            ->count());
    }

    /**
     * @Name: test_mcp_submission_still_limits_each_request_to_twenty_channels
     *
     * @Description: 验证移除消费策略后仍保留单次最多二十个媒体渠道的请求规模限制。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 18:40:00
     *
     * @UpdateTime: 2026-07-18 18:40:00
     *
     * @Return: void
     */
    public function test_mcp_submission_still_limits_each_request_to_twenty_channels(): void
    {
        [$plainToken, , $article] = $this->context('mcp_submission_limit');

        $this->withHeader('Authorization', 'Bearer '.$plainToken)
            ->postJson('/api/v1/media/submissions', [
                'article_ids' => [(int) $article->id],
                'media_resource_ids' => range(1, 21),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['media_resource_ids']);

        $this->assertDatabaseCount('media_submissions', 0);
    }

    /**
     * @Name: context
     *
     * @Description: 创建具备媒体投稿能力的站点、超级管理员、文章、媒体资源和无消费策略 MCP Key。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 18:40:00
     *
     * @UpdateTime: 2026-07-18 18:40:00
     *
     * @Param: string $prefix 测试数据唯一前缀
     *
     * @Return: array{0: string, 1: PersonalAccessToken, 2: Article, 3: MediaResource} 投稿上下文
     */
    private function context(string $prefix): array
    {
        $admin = Admin::query()->create([
            'username' => $prefix,
            'password' => 'secret-123',
            'email' => $prefix.'@example.com',
            'display_name' => $prefix,
            'role' => 'site_user',
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => $prefix.'站点',
            'domain' => $prefix.'.example.com',
            'status' => 'active',
            'customer_mode' => 'direct',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'owner']);
        $this->openTestingPlanForSite($site, $admin);
        AdminCreditAccount::query()->create([
            'admin_id' => (int) $admin->id,
            'site_id' => (int) $site->id,
            'balance' => '10.00',
            'frozen_balance' => '0.00',
            'total_granted' => '10.00',
            'total_consumed' => '0.00',
        ]);
        $author = Author::withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => $prefix.'作者',
        ]);
        $category = Category::withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => $prefix.'分类',
            'slug' => $prefix.'-category',
        ]);
        $article = Article::withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'title' => $prefix.'文章',
            'slug' => $prefix.'-article',
            'content' => '<p>用于 MCP 投稿的正文</p>',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);
        $resource = MediaResource::query()->create([
            'platform_id' => MediaPlatform::CEYING_MEDIA_1,
            'source_type' => MediaResource::SOURCE_WEBSITE,
            'external_resource_id' => $prefix.'-resource',
            'title' => $prefix.'媒体',
            'category' => 'news',
            'status' => 'active',
            'cost_price' => '1.00',
            'sale_price' => '3.00',
        ]);
        $created = app(ApiTokenService::class)->createToken(
            $prefix.' MCP Key',
            [ApiTokenService::MCP_CONNECT_SCOPE, 'media:submit'],
            (int) $admin->id,
            now()->addDay()->format('Y-m-d H:i:s'),
            (int) $site->id,
        );
        $token = PersonalAccessToken::findToken((string) $created['token']);
        $this->assertInstanceOf(PersonalAccessToken::class, $token);

        return [(string) $created['token'], $token, $article, $resource];
    }
}
