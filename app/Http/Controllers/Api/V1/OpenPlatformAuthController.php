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
 * @File： OpenPlatformAuthController.php
 *
 * @Description: 提供开放平台应用凭证换取限时 Bearer Token 的接口。
 */

namespace App\Http\Controllers\Api\V1;

use App\Services\OpenPlatform\OpenPlatformCredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OpenPlatformAuthController extends BaseApiController
{
    /**
     * 获取访问令牌
     * 使用应用 ID 和应用密钥换取有效期一小时的 Bearer Token。
     *
     * @Url [POST] /api/v1/open-platform/auth/token
     *      登录 否
     *      client_id string 必选 开放平台应用标识
     *      client_secret string 必选 仅创建或轮换时显示的应用密钥
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-19 12:11:31
     *
     * @UpdateTime: 2026-11-19 12:41:17
     *
     * @Return JsonResponse Bearer Token、令牌类型及过期时间
     *
     * @Throws ValidationException|ApiException 应用凭证无效或换取频率过高
     */
    public function token(Request $request, OpenPlatformCredentialService $credentialService): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'string', 'max:64'],
            'client_secret' => ['required', 'string', 'max:128'],
        ]);

        return $this->successNoStore($request, $credentialService->exchangeClientCredentials(
            trim($validated['client_id']),
            $validated['client_secret']
        ));
    }
}
