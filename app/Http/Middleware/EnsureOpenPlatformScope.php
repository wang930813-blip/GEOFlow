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
 * @File： EnsureOpenPlatformScope.php
 *
 * @Description: 校验开放平台应用是否具备当前只读接口所需权限。
 */

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\OpenPlatformAccessToken;
use App\Models\OpenPlatformApplication;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureOpenPlatformScope
{
    /**
     * @Name: handle
     *
     * @Description: 检查已认证应用是否授予指定接口权限，未授权时返回禁止访问。
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
     * @Param: string $scope 接口要求的权限名称
     *
     * @Return: Response 开放 API 响应
     *
     * @Throws: ApiException 应用未认证或未获得所需权限
     */
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $application = $request->attributes->get('open_platform_application');
        if (! $application instanceof OpenPlatformApplication) {
            throw new ApiException('unauthorized', '开放平台应用未认证', 401);
        }

        if (! in_array($scope, (array) $application->scopes, true)) {
            throw new ApiException('insufficient_scope', '应用未获得该接口的访问权限', 403, [
                'required_scope' => $scope,
            ]);
        }

        $lastUsedAt = now();
        $accessToken = $request->attributes->get('open_platform_access_token');
        if ($accessToken instanceof OpenPlatformAccessToken) {
            $accessToken->forceFill(['last_used_at' => $lastUsedAt])->save();
        }
        $application->forceFill(['last_used_at' => $lastUsedAt])->save();

        return $next($request);
    }
}
