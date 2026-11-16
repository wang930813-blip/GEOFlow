<?php

/**
 * Created by Codex.
 * @Date: 2026-11-11
 * @Time: 10:09:52
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： WebsiteAdminAuthService.php
 * @Description: 处理客户网站后台登录及监测平台的一次性站点跳转。
 */

namespace App\Services\WebsiteAdmin;

use App\Models\Admin;
use App\Models\Site;
use App\Models\WebsiteUser;
use App\Support\SiteDomain;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

final class WebsiteAdminAuthService
{
    private const SSO_TTL_SECONDS = 60;

    /**
     * @Name: login
     * @Description: 按域名解析活动站点，分别验证不可变 GEO 所有者或本站独立成员账号。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:09:52
     * @UpdateTime: 2026-11-11 10:09:52
     * @Param string $domain 客户系统域名或自定义域名
     * @Param string $username 登录账号
     * @Param string $password 登录密码
     * @Param string $ipAddress 请求来源地址
     * @Return array{site:Site,owner:Admin,type:string,id:int} 已验证的网站后台身份
     * @Throws RuntimeException 账号、密码或站点无效、停用、限流时抛出
     */
    public function login(string $domain, string $username, string $password, string $ipAddress): array
    {
        $normalizedDomain = SiteDomain::normalize($domain);
        $username = trim($username);
        if ($normalizedDomain === '' || $username === '' || $password === '') {
            throw new \RuntimeException('请填写站点域名、账号和密码');
        }

        $throttleKey = 'website_admin_login:'.sha1(Str::lower($username).'|'.$ipAddress);
        $maxAttempts = max(1, (int) config('geoflow.api_login_rate_limit_attempts', 10));
        $decaySeconds = max(1, (int) config('geoflow.api_login_rate_limit_decay_seconds', 60));
        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            throw new \RuntimeException('登录尝试过于频繁，请稍后再试');
        }
        RateLimiter::hit($throttleKey, $decaySeconds);

        $site = $this->findSiteByDomain($normalizedDomain);
        if (! $site instanceof Site) {
            throw new \RuntimeException('站点或账号信息不正确');
        }

        $admin = Admin::query()->where('username', $username)->where('status', 'active')->first();
        if ($admin instanceof Admin
            && (int) $site->owner_admin_id === (int) $admin->id
            && ($admin->isDirectAdmin() || $admin->isSiteUser())
            && Hash::check($password, (string) $admin->password)) {
            RateLimiter::clear($throttleKey);
            $admin->forceFill(['last_login' => now()])->save();

            return ['site' => $site, 'owner' => $admin, 'type' => 'owner', 'id' => (int) $admin->id];
        }

        $user = WebsiteUser::query()
            ->where('site_id', (int) $site->id)
            ->where('username', $username)
            ->where('status', 'active')
            ->first();
        if ($user instanceof WebsiteUser && $user->verifyPassword($password)) {
            $owner = Admin::query()->whereKey((int) $site->owner_admin_id)->where('status', 'active')->first();
            if ($owner instanceof Admin) {
                RateLimiter::clear($throttleKey);
                $user->forceFill(['last_login' => now()])->save();

                return ['site' => $site, 'owner' => $owner, 'type' => 'user', 'id' => (int) $user->id];
            }
        }

        throw new \RuntimeException('站点或账号信息不正确');
    }

    /**
     * @Name: issueSsoToken
     * @Description: 为已认证且正是站点所有者的监测用户创建 60 秒、一次性跳转凭证。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:09:52
     * @UpdateTime: 2026-11-11 10:09:52
     * @Param Admin $admin 监测中心已认证的 GEO 账号
     * @Param Site $site 监测中心已授权的当前站点
     * @Return string 一次性不透明跳转令牌
     * @Throws RuntimeException 当前账号不是站点所有者时抛出
     */
    public function issueSsoToken(Admin $admin, Site $site): string
    {
        if ((int) $site->owner_admin_id !== (int) $admin->id
            || $site->status !== 'active'
            || ! ($admin->isDirectAdmin() || $admin->isSiteUser())) {
            throw new \RuntimeException('当前账号没有该站点的网站管理权限');
        }

        $token = Str::random(64);
        Cache::put($this->ssoCacheKey($token), [
            'admin_id' => (int) $admin->id,
            'site_id' => (int) $site->id,
        ], now()->addSeconds(self::SSO_TTL_SECONDS));

        return $token;
    }

    /**
     * @Name: consumeSsoToken
     * @Description: 消费跳转令牌并重新核实所有者及站点状态，令牌消费后不可再次使用。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:09:52
     * @UpdateTime: 2026-11-11 10:09:52
     * @Param string $token URL 中的一次性跳转令牌
     * @Return array{site:Site,owner:Admin,type:string,id:int} 已验证的网站后台身份
     * @Throws RuntimeException 令牌无效、过期或所有者无权访问时抛出
     */
    public function consumeSsoToken(string $token): array
    {
        if (preg_match('/^[A-Za-z0-9]{64}$/', $token) !== 1) {
            throw new \RuntimeException('网站管理跳转链接已失效，请重新进入');
        }

        $identity = Cache::pull($this->ssoCacheKey($token));
        if (! is_array($identity)) {
            throw new \RuntimeException('网站管理跳转链接已失效，请重新进入');
        }

        $site = Site::query()->whereKey((int) ($identity['site_id'] ?? 0))->where('status', 'active')->first();
        $admin = Admin::query()->whereKey((int) ($identity['admin_id'] ?? 0))->where('status', 'active')->first();
        if (! $site instanceof Site || ! $admin instanceof Admin
            || (int) $site->owner_admin_id !== (int) $admin->id
            || ! ($admin->isDirectAdmin() || $admin->isSiteUser())) {
            throw new \RuntimeException('网站管理跳转链接已失效，请重新进入');
        }

        return ['site' => $site, 'owner' => $admin, 'type' => 'owner', 'id' => (int) $admin->id];
    }

    private function findSiteByDomain(string $domain): ?Site
    {
        return Site::query()
            ->where('status', 'active')
            ->where(fn ($query) => $query->where('domain', $domain)->orWhere('custom_domain', $domain))
            ->first();
    }

    private function ssoCacheKey(string $token): string
    {
        return 'website_admin_sso:'.hash('sha256', $token);
    }
}
