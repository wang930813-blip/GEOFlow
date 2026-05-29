<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Http\ApiAuthContext;
use App\Models\Site;
use App\Services\Api\ApiTokenService;
use App\Support\CurrentSite;
use Closure;
use Illuminate\Http\Request;
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

        $this->tokenService->touchToken((int) $token['id']);
        $auditAdminId = $this->tokenService->resolveAuditAdminId(
            isset($token['created_by_admin_id']) ? (int) $token['created_by_admin_id'] : null
        );

        $siteId = isset($token['site_id']) ? (int) $token['site_id'] : null;
        if ($siteId !== null && $siteId > 0) {
            $site = Site::query()->whereKey($siteId)->where('status', 'active')->first();
            if (! $site instanceof Site) {
                throw new ApiException('site_not_available', 'Token 绑定的站点不存在或已停用', 403);
            }

            app(CurrentSite::class)->set($site);
        } else {
            $siteId = null;
        }

        $request->attributes->set('api_auth', new ApiAuthContext($token, $auditAdminId, $siteId));

        return $next($request);
    }
}
