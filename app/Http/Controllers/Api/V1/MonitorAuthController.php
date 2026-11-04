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
 * @File： MonitorAuthController.php
 *
 * @Description: 提供新版监测中心前端用户的登录和退出接口。
 */

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Services\MonitoringCenter\MonitorUserAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MonitorAuthController extends BaseApiController
{
    /**
     * login
     * 前端用户登录新版监测中心。
     *
     * @Url [POST] /api/v1/monitor/auth/login
     *      登录 否
     *      username string 必选 前端用户账号
     *      password string 必选 前端用户密码
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-02 17:51:16
     *
     * @UpdateTime: 2026-11-02 18:05:28
     *
     * @Return JsonResponse 监测中心凭证、账号和站点信息
     *
     * @Throws ApiException 凭证无效、账号停用、限流或站点不可用
     */
    public function login(Request $request, MonitorUserAuthService $authService): JsonResponse
    {
        $username = $request->input('username', '');
        $password = $request->input('password', '');
        if (! is_string($username) || ! is_string($password)) {
            throw new ApiException('validation_failed', '用户名和密码格式无效', 422, [
                'field_errors' => ['username' => '用户名和密码必须是文本'],
            ]);
        }

        return $this->success($request, $authService->login(
            trim($username),
            $password,
            (string) ($request->ip() ?? '')
        ));
    }

    /**
     * logout
     * 退出新版监测中心并撤销当前前端凭证。
     *
     * @Url [POST] /api/v1/monitor/auth/logout
     *      登录 是
     *      无请求体参数
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-02 17:51:16
     *
     * @UpdateTime: 2026-11-02 18:05:28
     *
     * @Return JsonResponse 退出状态
     *
     * @Throws ApiException 未认证
     */
    public function logout(Request $request, MonitorUserAuthService $authService): JsonResponse
    {
        $identity = $request->attributes->get('monitor_auth');
        if (! is_array($identity)) {
            throw new ApiException('unauthorized', '未认证', 401);
        }

        $authService->revoke($identity);

        return $this->success($request, ['logged_out' => true]);
    }
}
