<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Models\SitePlanSubscription;
use App\Services\Billing\PlanSubscriptionService;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlanSubscriptionController extends Controller
{
    public function __construct(
        private readonly PlanSubscriptionService $subscriptionService
    ) {}

    public function index(): View
    {
        return view('admin.plan-subscriptions.index', [
            'pageTitle' => '客户开通',
            'activeMenu' => 'plan_subscriptions',
            'adminSiteName' => AdminWeb::siteName(),
            'subscriptions' => SitePlanSubscription::query()
                ->with(['site:id,name,customer_mode,plan_status', 'plan:id,name,code', 'ownerAdmin:id,username,display_name'])
                ->orderByDesc('created_at')
                ->limit(100)
                ->get(),
            'sites' => Site::query()->orderBy('id')->get(['id', 'name', 'customer_mode']),
            'plans' => PlatformPlan::query()->where('status', 'active')->with('entitlements')->orderBy('sort_order')->orderBy('id')->get(),
            'admins' => Admin::query()->where('status', 'active')->orderBy('username')->get(['id', 'username', 'display_name', 'role']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'site_id' => ['required', 'integer', Rule::exists('sites', 'id')],
            'plan_id' => ['required', 'integer', Rule::exists('platform_plans', 'id')],
            'mode' => ['required', Rule::in(['agent', 'direct', 'internal'])],
            'owner_admin_id' => ['nullable', 'integer', Rule::exists('admins', 'id')],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'grant_credits' => ['nullable'],
            'remark' => ['nullable', 'string', 'max:1000'],
        ], [
            'site_id.required' => '请选择站点',
            'plan_id.required' => '请选择规格',
        ]);

        $site = Site::query()->findOrFail((int) $payload['site_id']);
        $plan = PlatformPlan::query()->with('entitlements')->findOrFail((int) $payload['plan_id']);
        $owner = isset($payload['owner_admin_id']) && (int) $payload['owner_admin_id'] > 0
            ? Admin::query()->find((int) $payload['owner_admin_id'])
            : $site->owner;
        $operator = auth('admin')->user();
        abort_unless($operator instanceof Admin, 403);

        $startsAt = isset($payload['starts_at']) && (string) $payload['starts_at'] !== '' ? \Carbon\Carbon::parse((string) $payload['starts_at']) : now();
        $endsAt = isset($payload['ends_at']) && (string) $payload['ends_at'] !== '' ? \Carbon\Carbon::parse((string) $payload['ends_at']) : null;

        $this->subscriptionService->open(
            site: $site,
            plan: $plan,
            mode: (string) $payload['mode'],
            ownerAdmin: $owner,
            operator: $operator,
            startsAt: $startsAt,
            endsAt: $endsAt,
            grantCredits: (bool) ($payload['grant_credits'] ?? false),
            remark: (string) ($payload['remark'] ?? '')
        );

        return redirect()->route('admin.plan-subscriptions.index')->with('message', '客户规格已开通');
    }
}
