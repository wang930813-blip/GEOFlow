<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Models\SitePlanSubscription;
use App\Services\Billing\AdminPlanSubscriptionService;
use App\Services\Billing\PlanSubscriptionService;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlanSubscriptionController extends Controller
{
    public function __construct(
        private readonly PlanSubscriptionService $subscriptionService,
        private readonly AdminPlanSubscriptionService $adminSubscriptionService
    ) {}

    public function index(): View
    {
        $perPage = max(1, min(100, (int) config('geoflow.admin_items_per_page', 20)));

        return view('admin.plan-subscriptions.index', [
            'pageTitle' => '客户开通',
            'activeMenu' => 'plan_subscriptions',
            'adminSiteName' => AdminWeb::siteName(),
            'subscriptions' => SitePlanSubscription::query()
                ->with(['site:id,name,customer_mode,plan_status', 'plan:id,name,code', 'ownerAdmin:id,username,display_name'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate($perPage)
                ->withQueryString(),
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

        if (! $owner instanceof Admin) {
            return back()->withErrors('请先为站点绑定负责人，或手动选择开通账号')->withInput();
        }

        $mode = (string) $payload['mode'];
        if ($mode === 'agent' && ! $owner->isAgentAdmin()) {
            return back()->withErrors('代理模式需要选择代理管理员账号')->withInput();
        }
        if ($mode === 'direct' && ! $owner->isDirectAdmin()) {
            return back()->withErrors('直客模式需要选择直客管理员账号')->withInput();
        }

        $startsAt = isset($payload['starts_at']) && (string) $payload['starts_at'] !== '' ? \Carbon\Carbon::parse((string) $payload['starts_at']) : now();
        $endsAt = isset($payload['ends_at']) && (string) $payload['ends_at'] !== '' ? \Carbon\Carbon::parse((string) $payload['ends_at']) : null;

        DB::transaction(function () use ($site, $plan, $mode, $owner, $operator, $startsAt, $endsAt, $payload): void {
            $subscription = $this->subscriptionService->open(
                site: $site,
                plan: $plan,
                mode: $mode,
                ownerAdmin: $owner,
                operator: $operator,
                startsAt: $startsAt,
                endsAt: $endsAt,
                grantCredits: (bool) ($payload['grant_credits'] ?? false),
                remark: (string) ($payload['remark'] ?? '')
            );

            if (in_array($mode, ['agent', 'direct'], true)) {
                $this->adminSubscriptionService->openOwner(
                    admin: $owner,
                    site: $site,
                    plan: $plan,
                    mode: $mode === 'agent' ? 'agent_owner' : 'direct_owner',
                    operator: $operator,
                    startsAt: $startsAt,
                    endsAt: $endsAt,
                    grantCredits: (bool) ($payload['grant_credits'] ?? false),
                    remark: (string) ($payload['remark'] ?? ''),
                    sourceSubscriptionId: (int) $subscription->id
                );
            }
        });

        return redirect()->route('admin.plan-subscriptions.index')->with('message', '客户规格已开通');
    }
}
