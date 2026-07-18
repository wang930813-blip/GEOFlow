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
 * @File： AuthController.php
 *
 * @Description: 提供 GEOFlow API 登录和机器凭证自检接口。
 */

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\Site;
use App\Services\Api\ApiAdminAuthService;
use App\Support\CurrentSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * API v1 认证：管理员账号登录并签发 API Token。
 *
 * 无需 Bearer；成功返回 token、过期时间与 admin 摘要，与遗留 ApiAdminAuthService 行为对齐。
 */
class AuthController extends BaseApiController
{
    /**
     * 使用用户名密码登录，创建带全量 scope 的 API Token 并更新管理员 last_login。
     *
     * 请求体：username、password（JSON）。错误时抛出/映射为 401 或 422。
     */
    public function login(Request $request, ApiAdminAuthService $adminAuth): JsonResponse
    {
        $body = $request->all();

        return $this->success($request, $adminAuth->login(
            trim((string) ($body['username'] ?? '')),
            (string) ($body['password'] ?? ''),
            (string) ($request->ip() ?? ''),
            trim((string) ($request->userAgent() ?? ''))
        ));
    }

    /**
     * 当前机器凭证信息
     * 校验 Bearer Token、管理员状态和绑定站点，并返回 MCP 服务建立 GEO 业务上下文所需的最小信息。
     *
     * @Url [GET] /api/v1/auth/me
     *      登录 是
     *
     *      分页参数：
     *      无
     *
     *      筛选参数：
     *      无
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 16:38:47
     *
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Return JsonResponse 当前凭证、管理员、站点和权限信息
     *
     * @Throws ApiException 管理员或站点不可用
     */
    public function me(Request $request, CurrentSite $currentSite): JsonResponse
    {
        $context = $this->auth($request);
        $tokenAdminId = (int) ($context->token['created_by_admin_id'] ?? 0);
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin
            || $tokenAdminId <= 0
            || (int) $admin->id !== $tokenAdminId
            || (string) $admin->status !== 'active') {
            throw new ApiException('admin_not_available', 'Token 所属账号不存在或已停用', 403);
        }

        $site = $currentSite->get();
        if ($context->siteId !== null && ! $site instanceof Site) {
            throw new ApiException('site_not_available', 'Token 绑定的站点不存在或已停用', 403);
        }

        return $this->success($request, [
            'token' => [
                'id' => (int) ($context->token['id'] ?? 0),
                'name' => (string) ($context->token['name'] ?? ''),
                'scopes' => array_values((array) ($context->token['scopes'] ?? [])),
                'expires_at' => $context->token['expires_at'] ?? null,
                'spending_policy' => isset(
                    $context->token['mcp_max_unit_price'],
                    $context->token['mcp_max_total_price'],
                    $context->token['mcp_daily_spend_limit'],
                ) ? [
                    'max_unit_price' => number_format((float) $context->token['mcp_max_unit_price'], 2, '.', ''),
                    'max_total_price' => number_format((float) $context->token['mcp_max_total_price'], 2, '.', ''),
                    'daily_spend_limit' => number_format((float) $context->token['mcp_daily_spend_limit'], 2, '.', ''),
                ] : null,
            ],
            'admin' => [
                'id' => (int) $admin->id,
                'name' => (string) $admin->name,
                'role' => (string) $admin->role,
            ],
            'site' => $site instanceof Site ? [
                'id' => (int) $site->id,
                'name' => (string) $site->name,
            ] : null,
        ]);
    }
}
