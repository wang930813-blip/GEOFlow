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
 * @File： MonitorUserAuthService.php
 *
 * @Description: 为新版监测中心提供独立于管理员 API Token 的前端账号认证。
 */

namespace App\Services\MonitoringCenter;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\Site;
use App\Support\CurrentSite;
use App\Support\GeoFlow\AdminLoginLockService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use JsonException;

final class MonitorUserAuthService
{
    private const TOKEN_TTL_DAYS = 7;

    private const ADMIN_SSO_TTL_SECONDS = 90;

    public function __construct(
        private readonly AdminLoginLockService $loginLockService
    ) {}

    /**
     * @Name: login
     *
     * @Description: 验证前端用户账号并签发监测中心专用凭证，限制到账号可访问的默认站点。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-02 17:51:16
     *
     * @UpdateTime: 2026-11-02 18:05:28
     *
     * @Param: string $username 前端用户账号
     *
     * @Param: string $password 前端用户密码
     *
     * @Param: string $ipAddress 登录来源 IP 地址
     *
     * @Return: array<string,mixed> 监测中心凭证、账号和默认站点
     *
     * @Throws ApiException 凭证无效、账号停用、限流或站点不可用
     */
    public function login(string $username, string $password, string $ipAddress = ''): array
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            $fieldErrors = [];
            if ($username === '') {
                $fieldErrors['username'] = '用户名不能为空';
            }
            if ($password === '') {
                $fieldErrors['password'] = '密码不能为空';
            }

            throw new ApiException('validation_failed', '用户名和密码不能为空', 422, [
                'field_errors' => $fieldErrors,
            ]);
        }
        if (mb_strlen($username) > 50 || mb_strlen($password) > 1024) {
            throw new ApiException('validation_failed', '账号或密码长度超出限制', 422);
        }

        $throttleKey = 'monitor_user_login:'.sha1(Str::lower($username).'|'.$ipAddress);
        $maxAttempts = max(1, (int) config('geoflow.api_login_rate_limit_attempts', 10));
        $decaySeconds = max(1, (int) config('geoflow.api_login_rate_limit_decay_seconds', 60));
        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            throw new ApiException('too_many_attempts', '登录尝试过于频繁，请稍后再试', 429, [
                'retry_after' => RateLimiter::availableIn($throttleKey),
            ]);
        }
        RateLimiter::hit($throttleKey, $decaySeconds);

        $admin = Admin::query()->where('username', $username)->first();
        if ($admin instanceof Admin && $this->loginLockService->isLocked($admin)) {
            throw new ApiException('account_locked', '账号已被锁定，请联系管理员处理', 423);
        }

        $passwordMatches = $admin instanceof Admin && Hash::check($password, (string) $admin->password);
        if (! $admin instanceof Admin || (string) $admin->status !== 'active' || ! $passwordMatches) {
            if ($admin instanceof Admin && (string) $admin->status === 'active' && ! $passwordMatches) {
                if ($this->loginLockService->recordFailedAttemptAndLock($admin)) {
                    throw new ApiException('account_locked', '密码错误次数过多，账号已被锁定', 423);
                }
            }

            throw new ApiException('invalid_credentials', '用户名或密码错误，或账号已被停用', 401);
        }

        if (! $admin->isDirectAdmin() && ! $admin->isSiteUser()) {
            throw new ApiException('invalid_credentials', '用户名或密码错误，或账号已被停用', 401);
        }

        $site = $this->resolveMemberSite($admin);
        if (! $site instanceof Site) {
            throw new ApiException('site_not_available', '账号没有可访问的有效站点', 403);
        }

        $expiresAt = now()->addDays(self::TOKEN_TTL_DAYS);
        $tokenId = (string) Str::uuid();
        try {
            $token = Crypt::encryptString(json_encode([
                'aud' => 'monitor',
                'sub' => (int) $admin->id,
                'site_id' => (int) $site->id,
                'jti' => $tokenId,
                'exp' => $expiresAt->timestamp,
            ], JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            throw new ApiException('token_create_failed', '监测中心凭证创建失败', 500);
        }

        $admin->forceFill(['last_login' => now()])->save();
        $this->loginLockService->clearFailedAttempts($username);
        RateLimiter::clear($throttleKey);

        return [
            'token' => $token,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'account' => [
                'id' => (int) $admin->id,
                'username' => (string) $admin->username,
                'display_name' => (string) ($admin->display_name ?: $admin->name ?: $admin->username),
                'role' => (string) $admin->role,
            ],
            'site' => [
                'id' => (int) $site->id,
                'name' => (string) $site->name,
            ],
        ];
    }

    /**
     * @Name: issueAdminSsoCode
     * @Description: 为已显式切换客户站点的超级管理员创建短时一次性监测中心登录码。
     * @Author: cdkay
     * @CreateTime: 2026-11-17 17:48:44
     * @UpdateTime: 2026-11-26 20:39:04
     * @Param: Admin $operator 当前后台管理员
     * @Param: CurrentSite $currentSite 当前站点及账号上下文
     * @Return: string 90 秒有效的一次性登录码
     * @Throws: ApiException 操作人无权限、客户账号无效或当前站点不可用
     */
    public function issueAdminSsoCode(Admin $operator, CurrentSite $currentSite): string
    {
        $selectedSite = $currentSite->get();
        $ownerAdminId = $currentSite->ownerAdminId();
        if (! $operator->isSuperAdmin()
            || (string) $operator->status !== 'active'
            || ! $currentSite->isAccountScopeEnabled()
            || ! $selectedSite instanceof Site
            || $ownerAdminId === null
            || $ownerAdminId === (int) $operator->id) {
            throw new ApiException('monitor_sso_forbidden', '请先切换到有效的客户账号', 403);
        }

        $site = Site::query()
            ->whereKey((int) $selectedSite->id)
            ->where('status', 'active')
            ->where('owner_admin_id', $ownerAdminId)
            ->whereHas('members', fn ($query) => $query->where('admins.id', $ownerAdminId))
            ->first();
        $admin = Admin::query()
            ->whereKey($ownerAdminId)
            ->where('status', 'active')
            ->first();

        if ($site instanceof Site && $admin instanceof Admin && $admin->isAgentAdmin()) {
            throw new ApiException('monitor_sso_forbidden', '代理账号无法直接进入监测中心，请切换到客户账号后再进入', 403);
        }

        if (! $site instanceof Site
            || ! $admin instanceof Admin
            || (! $admin->isDirectAdmin() && ! $admin->isSiteUser())) {
            throw new ApiException('monitor_sso_forbidden', '当前客户账号或站点不可用', 403);
        }
        if ($this->loginLockService->isLocked($admin)) {
            throw new ApiException('account_locked', '账号已被锁定，请联系管理员处理', 423);
        }

        $code = Str::random(64);
        Cache::put($this->adminSsoCodeKey($code), [
            'admin_id' => (int) $admin->id,
            'site_id' => (int) $site->id,
        ], now()->addSeconds(self::ADMIN_SSO_TTL_SECONDS));

        return $code;
    }

    /**
     * @Name: exchangeAdminSsoCode
     * @Description: 原子消费后台签发的一次性登录码，复核客户账号与站点后签发监测凭证。
     * @Author: cdkay
     * @CreateTime: 2026-11-17 17:48:44
     * @UpdateTime: 2026-11-17 17:53:00
     * @Param: string $code 后台签发的一次性登录码
     * @Return: array<string,mixed> 监测中心凭证、账号和站点信息
     * @Throws: ApiException 登录码无效、已使用、过期或账号站点权限失效
     */
    public function exchangeAdminSsoCode(string $code): array
    {
        if (preg_match('/^[A-Za-z0-9]{64}$/', $code) !== 1) {
            throw new ApiException('monitor_sso_invalid', '自动登录链接已失效，请从后台重新进入', 401);
        }

        $codeKey = $this->adminSsoCodeKey($code);
        $identity = Cache::get($codeKey);
        if (! is_array($identity)) {
            throw new ApiException('monitor_sso_invalid', '自动登录链接已失效，请从后台重新进入', 401);
        }

        $consumed = Cache::add(
            $this->adminSsoConsumedKey($code),
            true,
            now()->addSeconds(self::ADMIN_SSO_TTL_SECONDS)
        );
        if (! $consumed) {
            throw new ApiException('monitor_sso_replayed', '自动登录链接已使用，请从后台重新进入', 401);
        }
        Cache::forget($codeKey);

        $admin = Admin::query()
            ->whereKey((int) ($identity['admin_id'] ?? 0))
            ->where('status', 'active')
            ->first();
        $site = Site::query()
            ->whereKey((int) ($identity['site_id'] ?? 0))
            ->where('status', 'active')
            ->when($admin instanceof Admin, fn ($query) => $query->where('owner_admin_id', (int) $admin->id))
            ->whereHas('members', fn ($query) => $query->where('admins.id', (int) ($identity['admin_id'] ?? 0)))
            ->first();

        if (! $admin instanceof Admin
            || (! $admin->isDirectAdmin() && ! $admin->isSiteUser())
            || ! $site instanceof Site) {
            throw new ApiException('monitor_sso_forbidden', '当前客户账号或站点已不可用', 403);
        }
        if ($this->loginLockService->isLocked($admin)) {
            throw new ApiException('account_locked', '账号已被锁定，请联系管理员处理', 423);
        }

        $expiresAt = now()->addDays(self::TOKEN_TTL_DAYS);
        $tokenId = (string) Str::uuid();
        try {
            $token = Crypt::encryptString(json_encode([
                'aud' => 'monitor',
                'sub' => (int) $admin->id,
                'site_id' => (int) $site->id,
                'jti' => $tokenId,
                'exp' => $expiresAt->timestamp,
            ], JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            throw new ApiException('token_create_failed', '监测中心凭证创建失败', 500);
        }

        $admin->forceFill(['last_login' => now()])->save();
        $this->loginLockService->clearFailedAttempts((string) $admin->username);

        return [
            'token' => $token,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'account' => [
                'id' => (int) $admin->id,
                'username' => (string) $admin->username,
                'display_name' => (string) ($admin->display_name ?: $admin->name ?: $admin->username),
                'role' => (string) $admin->role,
            ],
            'site' => [
                'id' => (int) $site->id,
                'name' => (string) $site->name,
            ],
        ];
    }

    /**
     * @Name: authenticate
     *
     * @Description: 解密凭证并实时复核前端账号、站点状态和站点成员关系。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-02 17:51:16
     *
     * @UpdateTime: 2026-11-02 18:05:28
     *
     * @Param: string $token 前端监测中心凭证
     *
     * @Return array{admin:Admin,site:Site,jti:string,exp:int} 当前前端账号上下文
     *
     * @Throws: ApiException 凭证无效、过期、被撤销或站点权限已失效
     */
    public function authenticate(string $token): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new ApiException('unauthorized', '监测中心登录状态无效，请重新登录', 401);
        }

        if (! is_array($payload)
            || ($payload['aud'] ?? null) !== 'monitor'
            || ! is_numeric($payload['sub'] ?? null)
            || ! is_numeric($payload['site_id'] ?? null)
            || ! is_string($payload['jti'] ?? null)
            || ! is_numeric($payload['exp'] ?? null)) {
            throw new ApiException('unauthorized', '监测中心登录状态无效，请重新登录', 401);
        }

        $tokenId = (string) $payload['jti'];
        $expiresAt = (int) $payload['exp'];
        if ($expiresAt <= now()->timestamp || Cache::has($this->revokedTokenKey($tokenId))) {
            throw new ApiException('unauthorized', '监测中心登录状态已过期，请重新登录', 401);
        }

        $admin = Admin::query()
            ->whereKey((int) $payload['sub'])
            ->where('status', 'active')
            ->first();
        if (! $admin instanceof Admin || (! $admin->isDirectAdmin() && ! $admin->isSiteUser())) {
            throw new ApiException('admin_not_available', '前端账号不存在、已停用或无访问权限', 403);
        }

        $site = Site::query()
            ->whereKey((int) $payload['site_id'])
            ->where('status', 'active')
            ->whereHas('members', fn ($query) => $query->where('admins.id', (int) $admin->id))
            ->first();
        if (! $site instanceof Site) {
            throw new ApiException('site_not_available', '站点不可用或账号已无访问权限', 403);
        }

        return [
            'admin' => $admin,
            'site' => $site,
            'jti' => $tokenId,
            'exp' => $expiresAt,
        ];
    }

    /**
     * @Name: revoke
     *
     * @Description: 将凭证标记为已撤销，缓存时间不超过凭证剩余有效期。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-02 17:51:16
     *
     * @UpdateTime: 2026-11-02 18:05:28
     *
     * @Param: array{jti:string,exp:int} $identity 已认证凭证标识
     *
     * @Return void 凭证撤销完成
     *
     * @Throws: 无
     */
    public function revoke(array $identity): void
    {
        $expiresAt = (int) ($identity['exp'] ?? 0);
        $tokenId = trim((string) ($identity['jti'] ?? ''));
        if ($tokenId === '' || $expiresAt <= now()->timestamp) {
            return;
        }

        Cache::put($this->revokedTokenKey($tokenId), true, now()->setTimestamp($expiresAt));
    }

    /**
     * @Name: resolveMemberSite
     *
     * @Description: 选择账号可访问的第一个有效站点作为监测中心默认站点。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-02 17:51:16
     *
     * @UpdateTime: 2026-11-02 18:05:28
     *
     * @Param: Admin $admin 已认证的前端用户
     *
     * @Return: Site|null 可访问的默认站点
     *
     * @Throws: 无
     */
    private function resolveMemberSite(Admin $admin): ?Site
    {
        return Site::query()
            ->where('status', 'active')
            ->whereHas('members', fn ($query) => $query->where('admins.id', (int) $admin->id))
            ->orderBy('sites.id')
            ->first();
    }

    /**
     * @Name: revokedTokenKey
     *
     * @Description: 生成凭证撤销状态的缓存键，不直接使用外部凭证标识。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-02 17:51:16
     *
     * @UpdateTime: 2026-11-02 18:05:28
     *
     * @Param: string $tokenId 监测中心凭证 ID
     *
     * @Return: string 已撤销凭证缓存键
     *
     * @Throws: 无
     */
    private function revokedTokenKey(string $tokenId): string
    {
        return 'monitor_user_token:revoked:'.sha1($tokenId);
    }

    /**
     * @Name: adminSsoCodeKey
     * @Description: 使用 SHA-256 摘要构造一次性登录码缓存键，避免将原始凭证写入缓存键名。
     * @Author: cdkay
     * @CreateTime: 2026-11-17 17:53:00
     * @UpdateTime: 2026-11-17 17:53:00
     * @Param: string $code 一次性登录码
     * @Return: string 登录码缓存键
     * @Throws: 无
     */
    private function adminSsoCodeKey(string $code): string
    {
        return 'monitor_admin_sso:code:'.hash('sha256', $code);
    }

    /**
     * @Name: adminSsoConsumedKey
     * @Description: 使用 SHA-256 摘要构造一次性登录码的消费标记缓存键。
     * @Author: cdkay
     * @CreateTime: 2026-11-17 17:53:00
     * @UpdateTime: 2026-11-17 17:53:00
     * @Param: string $code 一次性登录码
     * @Return: string 登录码消费标记缓存键
     * @Throws: 无
     */
    private function adminSsoConsumedKey(string $code): string
    {
        return 'monitor_admin_sso:consumed:'.hash('sha256', $code);
    }
}
