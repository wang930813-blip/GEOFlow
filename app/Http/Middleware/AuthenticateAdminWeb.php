<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 后台会话鉴权中间件：未登录时跳转 admin.login，避免默认 login 路由缺失导致 500。
 */
class AuthenticateAdminWeb
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return redirect()->route('admin.login');
        }

        if ($admin->isDirectAdmin() || $admin->isSiteUser()) {
            Auth::guard('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/monitor/login/');
        }

        return $next($request);
    }
}
