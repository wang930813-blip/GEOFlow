<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-11-02
 *
 * @Time: 17:51:16
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： AuthenticateMonitorUser.php
 *
 * @Description: 校验新版监测中心专用凭证并建立账号与站点请求上下文。
 */

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\Site;
use App\Services\MonitoringCenter\MonitorUserAuthService;
use App\Support\CurrentSite;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateMonitorUser
{
    public function __construct(
        private readonly MonitorUserAuthService $authService,
        private readonly CurrentSite $currentSite
    ) {}

    /**
     * @Name: handle
     *
     * @Description: 校验监测中心专用凭证，建立账号和站点上下文，并在请求结束时恢复上下文。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-02 17:51:16
     *
     * @UpdateTime: 2026-11-02 18:05:28
     *
     * @Param: Request $request 当前请求
     *
     * @Param: Closure $next 后续请求处理器
     *
     * @Return: Response 监测中心 API 响应
     *
     * @Throws: ApiException 缺少或无效的监测中心凭证
     */
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $authorization = (string) $request->header('Authorization', '');
        if (! preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            throw new ApiException('unauthorized', '缺少有效的监测中心登录凭证', 401);
        }

        $identity = $this->authService->authenticate(trim($matches[1]));
        $admin = $identity['admin'];
        $site = $identity['site'];
        $previousSite = $this->currentSite->get();
        $guard = Auth::guard('admin');
        $previousAdmin = $guard->user();

        $this->currentSite->set($site);
        $guard->setUser($admin);
        $request->attributes->set('monitor_auth', $identity);

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
