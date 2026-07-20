<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminRegistrationRequest;
use App\Models\Admin;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Services\Billing\AdminPlanSubscriptionService;
use App\Services\Billing\PlanSubscriptionService;
use App\Support\AdminActivityLogger;
use App\Support\AdminRegistrationCaptcha;
use App\Support\AdminRegistrationSettings;
use App\Support\AdminWeb;
use App\Support\CustomerSiteDomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class AdminRegistrationController extends Controller
{
    public function __construct(
        private readonly AdminRegistrationSettings $settings,
        private readonly AdminRegistrationCaptcha $captcha,
        private readonly PlanSubscriptionService $siteSubscriptionService,
        private readonly AdminPlanSubscriptionService $adminSubscriptionService,
        private readonly CustomerSiteDomain $customerSiteDomain
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.dashboard');
        }

        if (! $this->settings->canRegister()) {
            abort(403, '注册通道已关闭');
        }

        return view('admin.auth.register', [
            'adminSiteName' => AdminWeb::siteName(),
        ]);
    }

    public function captcha(Request $request): Response
    {
        if (! $this->settings->canRegister()) {
            abort(403, '注册通道已关闭');
        }

        $code = $this->captcha->issue($request);

        return response($this->captcha->renderSvg($code), 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function store(AdminRegistrationRequest $request): RedirectResponse
    {
        $plan = $this->settings->experiencePlan();
        if (! $plan instanceof PlatformPlan) {
            throw ValidationException::withMessages([
                'registration' => '注册体验规格未配置，请联系平台管理员',
            ]);
        }

        $payload = $request->validated();

        try {
            [$admin, $site] = DB::transaction(function () use ($payload, $plan): array {
                $mobile = trim((string) $payload['mobile']);
                $admin = Admin::query()->create([
                    'username' => $mobile,
                    'display_name' => trim((string) $payload['display_name']),
                    'mobile' => $mobile,
                    'email' => '',
                    'password' => (string) $payload['password'],
                    'role' => 'direct_admin',
                    'status' => 'active',
                    'created_by' => null,
                    'last_login' => now(),
                ]);

                $siteName = trim((string) $admin->display_name);
                if ($siteName === '') {
                    $siteName = (string) $admin->username;
                }

                $site = Site::query()->create([
                    'owner_admin_id' => (int) $admin->id,
                    'name' => $siteName.' 的默认站点',
                    'domain' => $this->customerSiteDomain->uniqueRandomDomain(),
                    'status' => 'active',
                    'customer_mode' => 'direct',
                    'agent_admin_id' => null,
                ]);
                $site->members()->attach((int) $admin->id, ['role' => 'owner']);

                $this->siteSubscriptionService->open(
                    site: $site,
                    plan: $plan,
                    mode: 'direct',
                    ownerAdmin: $admin,
                    operator: $admin,
                    startsAt: now(),
                    endsAt: null,
                    grantCredits: true,
                    remark: '用户注册自动开通体验规格'
                );

                $this->adminSubscriptionService->openOwner(
                    admin: $admin,
                    site: $site,
                    plan: $plan,
                    mode: 'direct_owner',
                    operator: $admin,
                    startsAt: now(),
                    endsAt: null,
                    grantCredits: true,
                    remark: '用户注册自动开通体验规格'
                );

                return [$admin, $site];
            });
        } catch (Throwable $exception) {
            return back()
                ->withErrors(['registration' => '注册失败：'.$exception->getMessage()])
                ->withInput();
        }

        Auth::guard('admin')->login($admin, true);
        $request->session()->regenerate();
        $request->session()->put('current_site_id', (int) $site->id);

        AdminActivityLogger::logFromRequest($request, $admin, 'auth:register', [
            'username' => (string) $admin->username,
            'mobile' => (string) $admin->mobile,
            'site_id' => (int) $site->id,
        ]);

        return redirect()->route('admin.dashboard')->with('message', '注册成功，体验规格已开通');
    }
}
