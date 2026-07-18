<?php

/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-18
 *
 * @Time: 17:00
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： McpMediaSubmissionBudgetTest.php
 *
 * @Description: 验证 MCP 媒体投稿必须声明预算，并在任何订单或扣费产生前拒绝超预算请求。
 */

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\MediaResource;
use App\Models\MediaSubmission;
use App\Models\Site;
use App\Services\Api\ApiTokenService;
use App\Services\MediaDistribution\MediaPlatformClient;
use App\Services\MediaDistribution\MediaPlatformClientManager;
use App\Services\MediaDistribution\MediaSubmissionBudgetGuard;
use App\Services\MediaDistribution\MediaSubmissionService;
use App\Support\MediaDistribution\MediaPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Mockery;
use Tests\TestCase;

class McpMediaSubmissionBudgetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @Name: test_mcp_submission_requires_confirmed_unit_and_total_budget
     *
     * @Description: 验证具备投稿权限的 MCP Key 未提交预算时不能进入媒体投稿业务。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 17:00:00
     *
     * @UpdateTime: 2026-07-18 17:00:00
     *
     * @Return: void
     */
    public function test_mcp_submission_requires_confirmed_unit_and_total_budget(): void
    {
        [$token, $article, $resource] = $this->context('mcp_budget_required');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/media/submissions', [
                'article_ids' => [(int) $article->id],
                'media_resource_ids' => [(int) $resource->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['max_unit_price', 'max_total_price']);

        $this->assertDatabaseCount('media_submissions', 0);
    }

    /**
     * @Name: test_mcp_submission_rejects_actual_price_above_confirmed_budget_before_charge
     *
     * @Description: 验证渠道实时售价超过 MCP 调用确认预算时不创建投稿记录且不产生扣费流水。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 17:00:00
     *
     * @UpdateTime: 2026-07-18 17:00:00
     *
     * @Return: void
     */
    public function test_mcp_submission_rejects_actual_price_above_confirmed_budget_before_charge(): void
    {
        [$token, $article, $resource] = $this->context('mcp_budget_exceeded');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/media/submissions', [
                'article_ids' => [(int) $article->id],
                'media_resource_ids' => [(int) $resource->id],
                'max_unit_price' => '2.99',
                'max_total_price' => '2.99',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'media_unit_budget_exceeded')
            ->assertJsonPath('error.details.actual_price', '3.00');

        $this->assertDatabaseCount('media_submissions', 0);
    }

    /**
     * @Name: test_mcp_submission_rejects_budget_above_key_spending_policy
     *
     * @Description: 验证调用方不能通过提交更高预算绕过创建 Key 时保存的服务器端消费上限。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 17:40:00
     *
     * @UpdateTime: 2026-07-18 17:40:00
     *
     * @Return: void
     */
    public function test_mcp_submission_rejects_budget_above_key_spending_policy(): void
    {
        [$token, $article, $resource] = $this->context('mcp_key_budget_exceeded');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/media/submissions', [
                'article_ids' => [(int) $article->id],
                'media_resource_ids' => [(int) $resource->id],
                'max_unit_price' => '10.01',
                'max_total_price' => '20.00',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'mcp_key_budget_exceeded');

        $this->assertDatabaseCount('media_submissions', 0);
    }

    /**
     * @Name: test_legacy_mcp_key_without_spending_policy_cannot_submit_paid_order
     *
     * @Description: 验证历史 MCP Key 未配置服务器端消费策略时不能继续创建付费投稿。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 17:40:00
     *
     * @UpdateTime: 2026-07-18 17:40:00
     *
     * @Return: void
     */
    public function test_legacy_mcp_key_without_spending_policy_cannot_submit_paid_order(): void
    {
        [$token, $article, $resource] = $this->context('mcp_legacy_budget');
        $model = PersonalAccessToken::findToken($token);
        $this->assertInstanceOf(PersonalAccessToken::class, $model);
        $model->forceFill([
            'mcp_max_unit_price' => null,
            'mcp_max_total_price' => null,
            'mcp_daily_spend_limit' => null,
        ])->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/media/submissions', [
                'article_ids' => [(int) $article->id],
                'media_resource_ids' => [(int) $resource->id],
                'max_unit_price' => '3.00',
                'max_total_price' => '3.00',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'mcp_spending_policy_required');

        $this->assertDatabaseCount('media_submissions', 0);
    }

    /**
     * @Name: test_mcp_daily_limit_counts_existing_active_submissions
     *
     * @Description: 验证 Key 当日有效投稿金额达到上限后，后续投稿在创建记录前被拒绝。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 17:35:00
     *
     * @UpdateTime: 2026-07-18 17:35:00
     *
     * @Return: void
     */
    public function test_mcp_daily_limit_counts_existing_active_submissions(): void
    {
        [$plainToken, $article, $resource] = $this->context('mcp_daily_budget');
        $token = PersonalAccessToken::findToken($plainToken);
        $this->assertInstanceOf(PersonalAccessToken::class, $token);
        $token->forceFill(['mcp_daily_spend_limit' => '3.00'])->save();
        MediaSubmission::withoutGlobalScopes()->create([
            'site_id' => (int) $article->site_id,
            'owner_admin_id' => (int) $article->owner_admin_id,
            'mcp_token_id' => (int) $token->id,
            'article_id' => (int) $article->id,
            'media_resource_id' => (int) $resource->id,
            'platform_id' => 1,
            'source_type' => MediaResource::SOURCE_WEBSITE,
            'agent_order_sn' => 'mcp-daily-existing',
            'preview_token' => 'daily-preview-token',
            'title_snapshot' => (string) $article->title,
            'content_snapshot' => '<p>正文</p>',
            'cost_price_snapshot' => '1.00',
            'sale_price_snapshot' => '3.00',
            'points_amount' => '3.00',
            'status' => 'submitted',
            'submitted_by_admin_id' => (int) $article->owner_admin_id,
        ]);

        try {
            app(MediaSubmissionBudgetGuard::class)->assertDailyBudgetForSubmission(
                (int) $token->id,
                (int) $article->owner_admin_id,
                (int) $article->site_id,
                '1.00',
            );
            $this->fail('达到每日消费上限后必须拒绝后续投稿');
        } catch (ApiException $exception) {
            $this->assertSame('mcp_daily_budget_exceeded', $exception->getErrorCode());
            $this->assertSame('3.00', $exception->getDetails()['spent_today']);
        }
    }

    /**
     * @Name: test_mcp_submission_uses_budget_price_snapshot_when_channel_price_changes
     *
     * @Description: 验证预算预检后渠道售价发生变化时，投稿订单仍使用已确认价格快照，避免实际扣费越过调用预算。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 18:20:00
     *
     * @UpdateTime: 2026-07-18 18:20:00
     *
     * @Return: void
     */
    public function test_mcp_submission_uses_budget_price_snapshot_when_channel_price_changes(): void
    {
        [$plainToken, $article, $resource] = $this->context('mcp_price_snapshot');
        $token = PersonalAccessToken::findToken($plainToken);
        $this->assertInstanceOf(PersonalAccessToken::class, $token);
        $admin = Admin::query()->findOrFail((int) $article->owner_admin_id);
        $admin->forceFill(['role' => 'super_admin'])->save();

        $budget = app(MediaSubmissionBudgetGuard::class)->assertWithinBudget(
            collect([$resource]),
            (int) $article->site_id,
            1,
            '3.00',
            '3.00',
            [
                'mcp_max_unit_price' => '10.00',
                'mcp_max_total_price' => '20.00',
                'mcp_daily_spend_limit' => '100.00',
            ],
        );
        $resource->forceFill(['sale_price' => '30.00'])->save();

        $client = Mockery::mock(MediaPlatformClient::class);
        $client->shouldReceive('submit')
            ->once()
            ->andReturn(['data' => ['order_nid' => 'mcp-price-snapshot-order']]);
        $clients = Mockery::mock(MediaPlatformClientManager::class);
        $clients->shouldReceive('forPlatform')
            ->once()
            ->with(MediaPlatform::CEYING_MEDIA_1)
            ->andReturn($client);
        $this->app->instance(MediaPlatformClientManager::class, $clients);

        $submission = app(MediaSubmissionService::class)->submit(
            $article,
            $resource->fresh(),
            $admin,
            '',
            (int) $token->id,
            $budget['resource_prices'][(int) $resource->id],
        );

        $this->assertSame('3.00', (string) $submission->points_amount);
        $this->assertSame('3.00', (string) $submission->sale_price_snapshot);
        $this->assertSame('submitted', (string) $submission->status);
    }

    /**
     * @Name: context
     *
     * @Description: 创建具备 MCP 投稿权限的站点账号、文章和定价媒体资源。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 17:00:00
     *
     * @UpdateTime: 2026-07-18 17:00:00
     *
     * @Param: string $prefix 测试数据唯一前缀
     *
     * @Return: array{0: string, 1: Article, 2: MediaResource} MCP Key、文章与媒体资源
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
            'content' => '<p>用于预算校验的正文</p>',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);
        $resource = MediaResource::query()->create([
            'platform_id' => 1,
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
        PersonalAccessToken::query()
            ->whereKey((int) $created['record']['id'])
            ->update([
                'mcp_max_unit_price' => '10.00',
                'mcp_max_total_price' => '20.00',
                'mcp_daily_spend_limit' => '100.00',
            ]);

        return [(string) $created['token'], $article, $resource];
    }
}
