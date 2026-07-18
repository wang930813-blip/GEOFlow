<?php

/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-18
 *
 * @Time: 15:34
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： AuthenticateApiTokenAgentSiteAuthorizationTest.php
 *
 * @Description: 验证代理管理员 MCP Key 会按实时站点归属重新执行授权校验。
 */

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Site;
use App\Services\Api\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticateApiTokenAgentSiteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @Name: test_agent_mcp_key_authorization_is_rechecked_after_site_transfer
     *
     * @Description: 验证代理站点转交后旧代理 MCP Key 立即被拒绝，当前代理 MCP Key 可正常建立站点上下文。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 15:34:56
     *
     * @UpdateTime: 2026-07-18 15:34:56
     *
     * @Return: void
     */
    public function test_agent_mcp_key_authorization_is_rechecked_after_site_transfer(): void
    {
        $previousAgent = $this->createAdmin('mcp_previous_agent', 'agent_admin');
        $previousOwner = $this->createAdmin('mcp_previous_owner', 'site_user', $previousAgent);
        $currentAgent = $this->createAdmin('mcp_current_agent', 'agent_admin');
        $currentOwner = $this->createAdmin('mcp_current_owner', 'site_user', $currentAgent);

        $site = Site::query()->create([
            'owner_admin_id' => (int) $previousOwner->id,
            'agent_admin_id' => (int) $previousAgent->id,
            'name' => '代理授权复核站点',
            'status' => 'active',
            'customer_mode' => 'agent',
        ]);
        $site->members()->attach((int) $previousOwner->id, ['role' => 'owner']);

        // 先确认旧代理在站点转交前符合 AdminDataScope，可使用绑定该站点的 MCP Key。
        $previousAgentKey = $this->createMcpKey($previousAgent, $site, '旧代理 MCP Key');
        $this->withToken($previousAgentKey)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.admin.id', (int) $previousAgent->id)
            ->assertJsonPath('data.site.id', (int) $site->id);

        // 完整转交代理、站点所有者和成员关系，避免旧代理通过任一现有可见性条件继续访问。
        $site->forceFill([
            'owner_admin_id' => (int) $currentOwner->id,
            'agent_admin_id' => (int) $currentAgent->id,
        ])->save();
        $site->members()->sync([
            (int) $currentOwner->id => ['role' => 'owner'],
        ]);

        // 同一旧 Key 在下一次请求中必须重新校验，并立即返回站点无权限错误。
        $this->withToken($previousAgentKey)
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'site_not_available');

        // 当前代理按转交后的实时数据范围可继续访问同一站点。
        $currentAgentKey = $this->createMcpKey($currentAgent, $site, '当前代理 MCP Key');
        $this->withToken($currentAgentKey)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.admin.id', (int) $currentAgent->id)
            ->assertJsonPath('data.site.id', (int) $site->id);
    }

    /**
     * @Name: createAdmin
     *
     * @Description: 创建指定角色的有效管理员，并按需建立代理与站点用户的创建关系。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 15:34:56
     *
     * @UpdateTime: 2026-07-18 15:34:56
     *
     * @Param: string $username 管理员用户名
     *
     * @Param: string $role 管理员角色
     *
     * @Param: Admin|null $creator 上级代理管理员
     *
     * @Return: Admin 已创建的有效管理员
     */
    private function createAdmin(string $username, string $role, ?Admin $creator = null): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => $username,
            'role' => $role,
            'status' => 'active',
            'created_by' => $creator?->id,
        ]);
    }

    /**
     * @Name: createMcpKey
     *
     * @Description: 为指定管理员创建绑定目标站点且具备机器凭证自检权限的 MCP Key。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 15:34:56
     *
     * @UpdateTime: 2026-07-18 15:34:56
     *
     * @Param: Admin $admin MCP Key 所属管理员
     *
     * @Param: Site $site MCP Key 绑定站点
     *
     * @Param: string $name MCP Key 名称
     *
     * @Return: string MCP Key 明文
     */
    private function createMcpKey(Admin $admin, Site $site, string $name): string
    {
        $created = app(ApiTokenService::class)->createToken(
            $name,
            [ApiTokenService::MCP_CONNECT_SCOPE],
            (int) $admin->id,
            now()->addDay()->format('Y-m-d H:i:s'),
            (int) $site->id,
        );

        return $created['token'];
    }
}
