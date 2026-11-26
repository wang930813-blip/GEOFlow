<?php

/**
 * Created by Codex.
 * @Date: 2026-11-17
 * @Time: 17:48:44
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： MonitorSsoController.php
 * @Description: 为后台超级管理员提供进入所选客户监测中心的短时桥接页。
 */

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\MonitoringCenter\MonitorUserAuthService;
use App\Support\CurrentSite;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

final class MonitorSsoController extends Controller
{
    /**
     * @Name: open
     * @Description: 签发所选客户的一次性登录码并返回不缓存的监测中心认证桥接页。
     * @Author: cdkay
     * @CreateTime: 2026-11-17 17:48:44
     * @UpdateTime: 2026-11-26 20:39:04
     * @Param: MonitorUserAuthService $authService 监测中心认证服务
     * @Param: CurrentSite $currentSite 当前站点与账号上下文
     * @Return: Response 自动登录桥接页
     * @Throws: \Symfony\Component\HttpKernel\Exception\HttpException 后台管理员无权限或当前客户账号、站点不可用
     */
    public function open(
        MonitorUserAuthService $authService,
        CurrentSite $currentSite
    ): Response {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof Admin && $admin->isSuperAdmin(), 403);

        try {
            $code = $authService->issueAdminSsoCode($admin, $currentSite);
        } catch (ApiException $exception) {
            // 后台入口由网页异常处理器渲染，显式保留认证服务返回的 HTTP 状态。
            abort($exception->getHttpStatus(), $exception->getMessage());
        }

        return response()
            ->view('monitor.sso', ['code' => $code])
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
