<?php

/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-18
 *
 * @Time: 16:35
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： ApiIdempotencyIsolationTest.php
 *
 * @Description: 验证 API 幂等键按站点、账号和 Token 隔离，并在业务执行前阻止并发重复请求。
 */

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Http\ApiAuthContext;
use App\Models\Admin;
use App\Models\ApiIdempotencyKey;
use App\Models\Site;
use App\Services\Api\IdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ApiIdempotencyIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @Name: test_completed_response_is_replayed_for_same_token_without_reexecuting
     *
     * @Description: 验证相同 Token 的相同请求在首次完成后直接返回缓存响应。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 16:35:00
     *
     * @UpdateTime: 2026-07-18 16:35:00
     *
     * @Return: void
     */
    public function test_completed_response_is_replayed_for_same_token_without_reexecuting(): void
    {
        [$admin, $site] = $this->account('idempotency_owner');
        $request = $this->request($admin, $site, 101, 'shared-operation-key', ['title' => '同一篇文章']);

        $this->assertNull(IdempotencyService::maybeReplayJson($request, 'POST /articles'));
        IdempotencyService::rememberFromResponse(
            $request,
            'POST /articles',
            response()->json(['success' => true, 'data' => ['id' => 88]], 201),
        );

        $replay = IdempotencyService::maybeReplayJson(
            $this->request($admin, $site, 101, 'shared-operation-key', ['title' => '同一篇文章']),
            'POST /articles',
        );

        $this->assertNotNull($replay);
        $this->assertSame(201, $replay->getStatusCode());
        $this->assertSame(88, $replay->getData(true)['data']['id']);
        $this->assertSame(1, ApiIdempotencyKey::withoutGlobalScopes()->count());
    }

    /**
     * @Name: test_same_client_key_is_isolated_between_accounts_and_tokens
     *
     * @Description: 验证同站点不同账号和 Token 使用相同客户端键时分别获得独立占位。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 16:35:00
     *
     * @UpdateTime: 2026-07-18 16:35:00
     *
     * @Return: void
     */
    public function test_same_client_key_is_isolated_between_accounts_and_tokens(): void
    {
        [$firstAdmin, $site] = $this->account('idempotency_first');
        $secondAdmin = $this->admin('idempotency_second');
        $site->members()->attach((int) $secondAdmin->id, ['role' => 'member']);

        $this->assertNull(IdempotencyService::maybeReplayJson(
            $this->request($firstAdmin, $site, 201, 'same-client-key', ['article_id' => 1]),
            'POST /media/submissions',
        ));
        $this->assertNull(IdempotencyService::maybeReplayJson(
            $this->request($secondAdmin, $site, 202, 'same-client-key', ['article_id' => 1]),
            'POST /media/submissions',
        ));

        $rows = ApiIdempotencyKey::withoutGlobalScopes()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertNotSame((string) $rows[0]->idempotency_key, (string) $rows[1]->idempotency_key);
        $this->assertSame((int) $site->id, (int) $rows[0]->site_id);
        $this->assertSame((int) $site->id, (int) $rows[1]->site_id);
    }

    /**
     * @Name: test_processing_request_rejects_duplicate_before_business_execution
     *
     * @Description: 验证首次请求仍在处理时，第二个相同请求在进入业务逻辑前返回冲突。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 16:35:00
     *
     * @UpdateTime: 2026-07-18 16:35:00
     *
     * @Return: void
     */
    public function test_processing_request_rejects_duplicate_before_business_execution(): void
    {
        [$admin, $site] = $this->account('idempotency_processing');
        $this->assertNull(IdempotencyService::maybeReplayJson(
            $this->request($admin, $site, 301, 'processing-key', ['task_id' => 9]),
            'POST /tasks/{id}/enqueue',
        ));

        try {
            IdempotencyService::maybeReplayJson(
                $this->request($admin, $site, 301, 'processing-key', ['task_id' => 9]),
                'POST /tasks/{id}/enqueue',
            );
            $this->fail('并发重复请求必须被幂等占位拒绝');
        } catch (ApiException $exception) {
            $this->assertSame('idempotency_in_progress', $exception->getErrorCode());
            $this->assertSame(409, $exception->getHttpStatus());
        }
    }

    /**
     * @Name: account
     *
     * @Description: 创建站点用户和所属站点，提供完整幂等隔离测试上下文。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 16:35:00
     *
     * @UpdateTime: 2026-07-18 16:35:00
     *
     * @Param: string $username 管理员用户名
     *
     * @Return: array{0: Admin, 1: Site} 管理员与站点
     */
    private function account(string $username): array
    {
        $admin = $this->admin($username);
        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => $username.'站点',
            'domain' => $username.'.example.com',
            'status' => 'active',
            'customer_mode' => 'direct',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'owner']);

        return [$admin, $site];
    }

    /**
     * @Name: admin
     *
     * @Description: 创建用于幂等隔离验证的普通站点账号。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 16:35:00
     *
     * @UpdateTime: 2026-07-18 16:35:00
     *
     * @Param: string $username 管理员用户名
     *
     * @Return: Admin 新建管理员
     */
    private function admin(string $username): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => $username,
            'role' => 'site_user',
            'status' => 'active',
        ]);
    }

    /**
     * @Name: request
     *
     * @Description: 构造携带幂等键及机器认证上下文的 API 写请求。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 16:35:00
     *
     * @UpdateTime: 2026-07-18 16:35:00
     *
     * @Param: Admin $admin 当前 Token 所属账号
     *
     * @Param: Site $site 当前 Token 绑定站点
     *
     * @Param: int $tokenId Token 编号
     *
     * @Param: string $key 客户端幂等键
     *
     * @Param: array<string, mixed> $payload 请求体
     *
     * @Return: Request 构造后的 API 请求
     */
    private function request(Admin $admin, Site $site, int $tokenId, string $key, array $payload): Request
    {
        $request = Request::create('/api/v1/write', 'POST', $payload);
        $request->headers->set('X-Idempotency-Key', $key);
        $request->attributes->set('api_auth', new ApiAuthContext(
            ['id' => $tokenId, 'created_by_admin_id' => (int) $admin->id],
            (int) $admin->id,
            (int) $site->id,
        ));

        return $request;
    }
}
