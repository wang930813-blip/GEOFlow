<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-11-19
 *
 * @Time: 12:11:31
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： AuthenticateOpenPlatformToken.php
 *
 * @Description: 校验开放平台 Bearer Token 并建立固定账号与站点上下文。
 */

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\OpenPlatformAccessToken;
use App\Models\OpenPlatformApplication;
use App\Models\Site;
use App\Support\CurrentSite;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateOpenPlatformToken
{
    public function __construct(private readonly CurrentSite $currentSite) {}

    /**
     * @Name: handle
     *
     * @Description: 验证令牌有效期、应用状态、账号状态及绑定站点状态，并在请求结束后恢复原上下文。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-19 12:11:31
     *
     * @UpdateTime: 2026-11-19 12:38:45
     *
     * @Param: Request $request 当前开放 API 请求
     *
     * @Param: Closure $next 后续请求处理器
     *
     * @Return: Response 开放 API 响应
     *
     * @Throws: ApiException 缺少、无效、过期或已撤销令牌，或应用账号站点停用
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authorization = (string) $request->header('Authorization', '');
        if (! preg_match('/^Bearer\s+(op_[A-Za-z0-9_-]{60,80})$/i', $authorization, $matches)) {
            throw new ApiException('unauthorized', '缺少有效的开放平台访问令牌', 401);
        }

        $accessToken = OpenPlatformAccessToken::query()
            ->with('application')
            ->where('token_hash', hash('sha256', trim($matches[1])))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();
        $application = $accessToken?->application;

        if (! $accessToken instanceof OpenPlatformAccessToken
            || ! $application instanceof OpenPlatformApplication
            || ! $application->is_active) {
            throw new ApiException('invalid_access_token', '开放平台访问令牌无效或已过期', 401);
        }

        $admin = Admin::query()
            ->whereKey((int) $application->owner_admin_id)
            ->where('status', 'active')
            ->first();
        $site = Site::query()
            ->whereKey((int) $application->site_id)
            ->where('status', 'active')
            ->first();

        if (! $admin instanceof Admin
            || ! $site instanceof Site
            || ((int) $site->owner_admin_id > 0 && (int) $site->owner_admin_id !== (int) $application->owner_admin_id)) {
            throw new ApiException('open_platform_application_unavailable', '应用所属账号或站点不可用', 403);
        }

        $previousSite = $this->currentSite->get();
        $guard = Auth::guard('admin');
        $previousAdmin = $guard->user();
        $this->currentSite->set($site);
        $guard->setUser($admin);
        $request->attributes->set('open_platform_application', $application);
        $request->attributes->set('open_platform_access_token', $accessToken);

        try {
            return $next($request);
        } finally {
            $this->currentSite->set($previousSite instanceof Site ? $previousSite : null);
            if ($previousAdmin instanceof Admin) {
                $guard->setUser($previousAdmin);
            } else {
                $guard->forgetUser();
            }
        }
    }
}
