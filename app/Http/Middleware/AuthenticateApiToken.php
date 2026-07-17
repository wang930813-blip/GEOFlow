<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Http\ApiAuthContext;
use App\Models\Admin;
use App\Models\Site;
use App\Services\Api\ApiTokenService;
use App\Support\CurrentSite;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function __construct(
        private ApiTokenService $tokenService
    ) {}

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
            $site = Site::query()
                ->whereKey($siteId)
                ->where('status', 'active')
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
