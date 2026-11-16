<?php

/**
 * Created by Codex.
 * @Date: 2026-11-11
 * @Time: 10:46:30
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： WebsiteAdminAuthController.php
 * @Description: 提供客户官网管理系统的独立登录、一次性 SSO 和退出会话。
 */

namespace App\Http\Controllers\WebsiteAdmin;

use App\Http\Controllers\Controller;
use App\Services\WebsiteAdmin\WebsiteAdminAuthService;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class WebsiteAdminAuthController extends Controller
{
    /**
     * @Name: showLogin
     * @Description: 显示独立的官网后台登录表单，并保留用户输入的站点域名。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:46:30
     * @UpdateTime: 2026-11-11 10:46:30
     * @Param Request $request 当前登录请求
     * @Return View 官网后台登录页
     * @Throws 无
     */
    public function showLogin(Request $request, CurrentSite $currentSite): View|RedirectResponse
    {
        $site = $currentSite->get();
        abort_unless($site instanceof Site, 404);

        if ($request->session()->has('website_admin_site_id')) {
            if ((int) $request->session()->get('website_admin_site_id') === (int) $site->id) {
                return redirect()->route('website-admin.dashboard');
            }
            $request->session()->forget(['website_admin_site_id', 'website_admin_type', 'website_admin_id']);
        }

        return view('website-admin.auth.login', ['site' => $site]);
    }

    /**
     * @Name: login
     * @Description: 验证客户站点域名与账号，轮换会话 ID 后建立独立官网后台会话。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:46:30
     * @UpdateTime: 2026-11-11 10:46:30
     * @Param Request $request 包含域名、账号和密码的登录请求
     * @Param WebsiteAdminAuthService $authService 官网后台登录服务
     * @Return RedirectResponse 登录后进入站点控制台或返回错误
     * @Throws ValidationException 登录字段校验失败
     */
    public function login(Request $request, WebsiteAdminAuthService $authService, CurrentSite $currentSite): RedirectResponse
    {
        $hostSite = $currentSite->get();
        abort_unless($hostSite instanceof Site, 404);
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        try {
            $identity = $authService->login(
                (string) $request->getHost(),
                (string) $validated['username'],
                (string) $validated['password'],
                (string) ($request->ip() ?? '')
            );
            if ((int) $identity['site']->id !== (int) $hostSite->id) {
                throw new \RuntimeException('账号不属于当前官网');
            }
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['login' => $exception->getMessage()])->withInput($request->only('username'));
        }

        $request->session()->regenerate();
        $request->session()->put([
            'website_admin_site_id' => (int) $identity['site']->id,
            'website_admin_type' => (string) $identity['type'],
            'website_admin_id' => (int) $identity['id'],
        ]);

        return redirect()->route('website-admin.dashboard');
    }

    /**
     * @Name: consumeSso
     * @Description: 消费监测中心签发的一次性凭证，并建立与独立登录相同的站点后台会话。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:46:30
     * @UpdateTime: 2026-11-11 10:46:30
     * @Param Request $request 当前跳转请求
     * @Param WebsiteAdminAuthService $authService 一次性凭证校验服务
     * @Param string $token URL 中的一次性不透明令牌
     * @Return RedirectResponse 跳转到官网后台或独立登录页
     * @Throws 无，失效凭证以登录页错误提示处理
     */
    public function consumeSso(Request $request, WebsiteAdminAuthService $authService, CurrentSite $currentSite, string $token): RedirectResponse
    {
        $hostSite = $currentSite->get();
        abort_unless($hostSite instanceof Site, 404);
        try {
            $identity = $authService->consumeSsoToken($token);
            if ((int) $identity['site']->id !== (int) $hostSite->id) {
                throw new \RuntimeException('网站管理跳转链接与当前官网不匹配');
            }
        } catch (\RuntimeException $exception) {
            return redirect()->route('website-admin.login')->withErrors(['login' => $exception->getMessage()]);
        }

        $request->session()->regenerate();
        $request->session()->put([
            'website_admin_site_id' => (int) $identity['site']->id,
            'website_admin_type' => (string) $identity['type'],
            'website_admin_id' => (int) $identity['id'],
        ]);

        return redirect()->route('website-admin.dashboard');
    }

    /**
     * @Name: logout
     * @Description: 清除独立官网后台身份并轮换会话 ID，避免旧会话继续访问。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:46:30
     * @UpdateTime: 2026-11-11 10:46:30
     * @Param Request $request 当前官网后台请求
     * @Return RedirectResponse 返回独立登录页
     * @Throws 无
     */
    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget(['website_admin_site_id', 'website_admin_type', 'website_admin_id']);
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->route('website-admin.login');
    }
}
