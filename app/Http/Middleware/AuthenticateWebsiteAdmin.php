<?php

/**
 * Created by Codex.
 * @Date: 2026-11-11
 * @Time: 10:09:52
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： AuthenticateWebsiteAdmin.php
 * @Description: 恢复独立网站后台会话并注入当前站点及角色权限上下文。
 */

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Models\Site;
use App\Models\WebsiteUser;
use App\Support\CurrentSite;
use App\Support\WebsiteAdminContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateWebsiteAdmin
{
    public function __construct(private readonly CurrentSite $currentSite) {}

    /**
     * @Name: handle
     * @Description: 每次请求重查账号、站点和角色状态，并限制站点内容查询到当前租户。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:09:52
     * @UpdateTime: 2026-11-11 10:09:52
     * @Param Request $request 当前网站管理请求
     * @Param Closure $next 后续中间件或控制器
     * @Return Response 后续处理的 HTTP 响应
     * @Throws HttpException 会话失效或站点权限被撤销时返回 403
     */
    public function handle(Request $request, Closure $next): Response
    {
        $siteId = (int) $request->session()->get('website_admin_site_id', 0);
        $identityType = (string) $request->session()->get('website_admin_type', '');
        $identityId = (int) $request->session()->get('website_admin_id', 0);
        $site = Site::query()->whereKey($siteId)->where('status', 'active')->first();
        $hostSite = $this->currentSite->get();
        $owner = $site instanceof Site
            ? Admin::query()->whereKey((int) $site->owner_admin_id)->where('status', 'active')->first()
            : null;

        if (! $site instanceof Site || ! $owner instanceof Admin || ! $hostSite instanceof Site || (int) $hostSite->id !== (int) $site->id) {
            $request->session()->forget(['website_admin_site_id', 'website_admin_type', 'website_admin_id']);

            return redirect()->route('website-admin.login');
        }

        $isOwner = $identityType === 'owner'
            && $identityId === (int) $owner->id
            && ($owner->isDirectAdmin() || $owner->isSiteUser());
        $user = $identityType === 'user'
            ? WebsiteUser::query()
                ->where('site_id', (int) $site->id)
                ->whereKey($identityId)
                ->where('status', 'active')
                ->first()
            : null;
        $role = $user instanceof WebsiteUser
            ? $user->role()->where('site_id', (int) $site->id)->first()
            : null;

        if (! $isOwner && (! $user instanceof WebsiteUser || ! $role instanceof \App\Models\WebsiteRole)) {
            $request->session()->forget(['website_admin_site_id', 'website_admin_type', 'website_admin_id']);

            return redirect()->route('website-admin.login')->withErrors(['login' => '网站管理会话已失效，请重新登录']);
        }

        $rolePermissions = $role?->permissions;
        $permissions = is_array($rolePermissions)
            ? array_values(array_filter($rolePermissions, 'is_string'))
            : [];
        $context = new WebsiteAdminContext(
            $site,
            $owner,
            $user,
            $isOwner,
            $permissions,
            $isOwner ? (string) $owner->name : (string) ($user?->display_name ?: $user?->username)
        );

        $previousSite = $this->currentSite->get();
        $adminGuard = Auth::guard('admin');
        $previousAdmin = $adminGuard->user();
        $this->currentSite->set($site, true);
        $adminGuard->setUser($owner);
        $request->attributes->set('website_admin', $context);

        try {
            return $next($request);
        } finally {
            $this->currentSite->set($previousSite instanceof Site ? $previousSite : null);
            if ($previousAdmin instanceof Admin) {
                $adminGuard->setUser($previousAdmin);
            } else {
                $adminGuard->forgetUser();
            }
        }
    }
}
