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
 * @File： AuthenticateApiToken.php
 *
 * @Description: 校验 API Token、账号状态及绑定站点的实时访问权限，并建立请求级认证上下文。
 */

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Http\ApiAuthContext;
use App\Models\Admin;
use App\Models\Site;
use App\Services\Api\ApiTokenService;
use App\Support\AdminDataScope;
use App\Support\CurrentSite;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    /**
     * @Name: __construct
     *
     * @Description: 注入 Token 服务和后台数据权限服务，确保鉴权使用项目统一站点授权规则。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 15:34:56
     *
     * @UpdateTime: 2026-07-18 15:34:56
     *
     * @Param: ApiTokenService $tokenService Token 查询、解析及使用时间更新服务
     *
     * @Param: AdminDataScope $adminDataScope 后台管理员统一数据权限服务
     *
     * @Return: void
     */
    public function __construct(
        private ApiTokenService $tokenService,
        private AdminDataScope $adminDataScope
    ) {}

    /**
     * @Name: handle
     *
     * @Description: 校验 Bearer Token、所属管理员状态和绑定站点实时权限，通过后写入请求认证上下文。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 15:34:56
     *
     * @UpdateTime: 2026-07-18 15:34:56
     *
     * @Param: Request $request 当前 HTTP 请求
     *
     * @Param: Closure $next 后续请求处理器
     *
     * @Return: Response 后续请求响应
     *
     * @Throws: ApiException Token、管理员或绑定站点不可用
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authorization = $request->header('Authorization');
        if (! is_string($authorization) || $authorization === '') {
            throw new ApiException('unauthorized', '缺少 Authorization 头', 401);
        }

        if (! preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            throw new ApiException('unauthorized', 'Authorization 格式无效', 401);
        }

        $tokenValue = trim($matches[1]);
        if ($tokenValue === '') {
            throw new ApiException('unauthorized', 'Token 不能为空', 401);
        }

        $token = $this->tokenService->getActiveTokenByPlaintext($tokenValue);
        if (! $token) {
            throw new ApiException('unauthorized', 'Token 无效或已过期', 401);
        }

        $tokenAdminId = (int) ($token['created_by_admin_id'] ?? 0);
        $tokenAdmin = $tokenAdminId > 0
            ? Admin::query()->whereKey($tokenAdminId)->where('status', 'active')->first()
            : null;
        if (! $tokenAdmin instanceof Admin) {
            throw new ApiException('admin_not_available', 'Token 所属账号不存在或已停用', 403);
        }

        $siteId = isset($token['site_id']) ? (int) $token['site_id'] : null;
        if ($siteId !== null && $siteId > 0) {
            $requiresSiteMembership = $tokenAdmin->isSiteUser() || $tokenAdmin->isDirectAdmin();

            // 代理管理员不依赖站点成员表，必须按统一数据范围逐次复核，保证站点转交后旧 Key 立即失效。
            $site = Site::query()
                ->whereKey($siteId)
                ->where('status', 'active')
                ->when(
                    $tokenAdmin->isAgentAdmin(),
                    fn ($query) => $this->adminDataScope->applySiteScope($query, $tokenAdmin, 'sites.id')
                )
                ->when($requiresSiteMembership, fn ($query) => $query->whereHas(
                    'members',
                    fn ($memberQuery) => $memberQuery->where('admins.id', $tokenAdminId)
                ))
                ->first();
            if (! $site instanceof Site) {
                throw new ApiException('site_not_available', 'Token 绑定的站点不可用或账号已无访问权限', 403);
            }

            app(CurrentSite::class)->set($site);
        } else {
            $siteId = null;
        }

        $this->tokenService->touchToken((int) $token['id']);
        $request->attributes->set('api_auth', new ApiAuthContext($token, $tokenAdminId, $siteId));

        $guard = Auth::guard('admin');
        $previousAdmin = $guard->user();
        $guard->setUser($tokenAdmin);

        try {
            return $next($request);
        } finally {
            if ($previousAdmin instanceof Admin) {
                $guard->setUser($previousAdmin);
            } else {
                $guard->forgetUser();
            }
        }
    }
}
