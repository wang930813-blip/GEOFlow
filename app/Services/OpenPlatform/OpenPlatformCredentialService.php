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
 * @File： OpenPlatformCredentialService.php
 *
 * @Description: 创建开放平台应用密钥、签发短期令牌并撤销应用令牌。
 */

namespace App\Services\OpenPlatform;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\OpenPlatformApplication;
use App\Models\Site;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class OpenPlatformCredentialService
{
    private const ACCESS_TOKEN_TTL_MINUTES = 60;

    /**
     * @Name: createClientCredentials
     *
     * @Description: 创建应用标识和一次性显示的应用密钥，数据库只保存密钥哈希。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-19 12:11:31
     *
     * @UpdateTime: 2026-11-19 12:11:31
     *
     * @Param: 无
     *
     * @Return: array{client_id:string,client_secret:string} 新应用客户端凭证
     *
     * @Throws: 无
     */
    public function createClientCredentials(): array
    {
        $clientSecret = Str::random(64);

        return [
            'client_id' => Str::random(48),
            'client_secret' => $clientSecret,
            'client_secret_hash' => Hash::make($clientSecret),
        ];
    }

    /**
     * @Name: exchangeClientCredentials
     *
     * @Description: 校验应用密钥并签发仅保存 SHA-256 摘要、有效期一小时的 Bearer Token。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-19 12:11:31
     *
     * @UpdateTime: 2026-11-19 12:38:45
     *
     * @Param: string $clientId 应用标识
     *
     * @Param: string $clientSecret 应用密钥明文
     *
     * @Return: array{access_token:string,token_type:string,expires_in:int,expires_at:string} 临时访问令牌
     *
     * @Throws: ApiException 应用不存在、已停用或密钥不匹配
     */
    public function exchangeClientCredentials(string $clientId, string $clientSecret): array
    {
        $application = OpenPlatformApplication::query()
            ->where('client_id', $clientId)
            ->where('is_active', true)
            ->first();

        if (! $application instanceof OpenPlatformApplication
            || ! Hash::check($clientSecret, (string) $application->client_secret_hash)) {
            throw new ApiException('invalid_client_credentials', '应用标识或应用密钥无效', 401);
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

        $plainTextToken = 'op_'.rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $expiresAt = now()->addMinutes(self::ACCESS_TOKEN_TTL_MINUTES);

        $application->accessTokens()->create([
            'token_hash' => hash('sha256', $plainTextToken),
            'expires_at' => $expiresAt,
        ]);

        return [
            'access_token' => $plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TOKEN_TTL_MINUTES * 60,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * @Name: rotateClientSecret
     *
     * @Description: 轮换应用密钥并立即撤销该应用的全部现有访问令牌。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-19 12:11:31
     *
     * @UpdateTime: 2026-11-19 12:11:31
     *
     * @Param: OpenPlatformApplication $application 当前账号可管理的应用
     *
     * @Return: array{client_id:string,client_secret:string} 新客户端凭证，仅本次返回明文密钥
     *
     * @Throws: 无
     */
    public function rotateClientSecret(OpenPlatformApplication $application): array
    {
        $credentials = $this->createClientCredentials();
        $application->forceFill([
            'client_secret_hash' => $credentials['client_secret_hash'],
        ])->save();
        $this->revokeAccessTokens($application);

        return [
            'client_id' => (string) $application->client_id,
            'client_secret' => $credentials['client_secret'],
        ];
    }

    /**
     * @Name: revokeAccessTokens
     *
     * @Description: 将应用全部未撤销令牌标记为撤销，后续请求即刻失效。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-19 12:11:31
     *
     * @UpdateTime: 2026-11-19 12:11:31
     *
     * @Param: OpenPlatformApplication $application 需要撤销令牌的应用
     *
     * @Return: void 更新令牌撤销时间
     *
     * @Throws: 无
     */
    public function revokeAccessTokens(OpenPlatformApplication $application): void
    {
        $application->accessTokens()
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
