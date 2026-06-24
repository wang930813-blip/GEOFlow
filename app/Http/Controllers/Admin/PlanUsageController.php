<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminCreditAccount;
use App\Models\AdminPlanSubscription;
use App\Models\AdminResourceUsage;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Support\AdminWeb;
use App\Support\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class PlanUsageController extends Controller
{
    public function index(Request $request): View
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        $resourceCatalog = PlatformPlan::visibleResourceCatalog();
        $subscriptions = $this->visibleSubscriptions($admin, $request)
            ->with(['admin:id,username,display_name,role,created_by', 'site:id,name,customer_mode,agent_admin_id', 'plan:id,name,code'])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $usageRows = $this->buildUsageRows($subscriptions->getCollection(), $resourceCatalog);
        $subscriptions->setCollection($usageRows);

        return view('admin.plan-usages.index', [
            'pageTitle' => '规格使用情况',
            'activeMenu' => 'plan_usages',
            'adminSiteName' => AdminWeb::siteName(),
            'subscriptions' => $subscriptions,
            'resourceCatalog' => $resourceCatalog,
            'plans' => PlatformPlan::query()->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'code']),
            'sites' => $this->filterableSites($admin),
            'isSuperAdmin' => $admin->isSuperAdmin(),
        ]);
    }

    private function visibleSubscriptions(Admin $admin, Request $request): Builder
    {
        $query = AdminPlanSubscription::query()
            ->whereHas('admin')
            ->where(function (Builder $query): void {
                $query->whereNull('site_id')->orWhereHas('site');
            });

        if ($admin->isSuperAdmin()) {
            $siteId = (int) $request->query('site_id', 0);
            $planId = (int) $request->query('plan_id', 0);
            $adminId = (int) $request->query('admin_id', 0);
            $keyword = trim((string) $request->query('keyword', ''));

            return $query
                ->when($siteId > 0, fn (Builder $query) => $query->where('site_id', $siteId))
                ->when($planId > 0, fn (Builder $query) => $query->where('plan_id', $planId))
                ->when($adminId > 0, fn (Builder $query) => $query->where('admin_id', $adminId))
                ->when($keyword !== '', function (Builder $query) use ($keyword): void {
                    $query->whereHas('admin', function (Builder $adminQuery) use ($keyword): void {
                        $adminQuery->where('username', 'like', '%'.$keyword.'%')
                            ->orWhere('display_name', 'like', '%'.$keyword.'%');
                    });
                });
        }

        if ($admin->isAgentAdmin()) {
            $adminId = (int) $request->query('admin_id', 0);

            return $query
                ->where('admin_id', '!=', (int) $admin->id)
                ->whereHas('site')
                ->where(function (Builder $query) use ($admin): void {
                    $query->where('inherited_from_admin_id', (int) $admin->id)
                        ->orWhereHas('admin', fn (Builder $adminQuery) => $adminQuery
                            ->where('created_by', (int) $admin->id)
                            ->where('role', 'site_user'));
                })
                ->when($adminId > 0, fn (Builder $query) => $query->where('admin_id', $adminId));
        }

        $site = app(CurrentSite::class)->get();
        abort_unless($site instanceof Site, 403);

        return $query
            ->where('site_id', (int) $site->id)
            ->where('admin_id', (int) $admin->id);
    }

    /**
     * @param  Collection<int,AdminPlanSubscription>  $subscriptions
     * @param  array<string,array{label:string,unit:string}>  $resourceCatalog
     * @return Collection<int,array<string,mixed>>
     */
    private function buildUsageRows(Collection $subscriptions, array $resourceCatalog): Collection
    {
        $subscriptionIds = $subscriptions->pluck('id')->map(fn ($id) => (int) $id)->all();
        $usageBySubscription = AdminResourceUsage::query()
            ->whereIn('subscription_id', $subscriptionIds)
            ->get()
            ->groupBy('subscription_id');

        $creditAccounts = AdminCreditAccount::query()
            ->whereIn('admin_id', $subscriptions->pluck('admin_id')->map(fn ($id) => (int) $id)->all())
            ->whereIn('site_id', $subscriptions->pluck('site_id')->map(fn ($id) => (int) $id)->all())
            ->get()
            ->keyBy(fn (AdminCreditAccount $account): string => (int) $account->admin_id.':'.(int) $account->site_id);

        return $subscriptions->map(function (AdminPlanSubscription $subscription) use ($resourceCatalog, $usageBySubscription, $creditAccounts): array {
            $usages = $usageBySubscription->get((int) $subscription->id, collect())->keyBy('resource_key');
            $creditAccount = $creditAccounts->get((int) $subscription->admin_id.':'.(int) $subscription->site_id);
            $resources = collect((array) $subscription->entitlements_snapshot)
                ->filter(fn ($entitlement, string $key): bool => is_array($entitlement)
                    && (bool) ($entitlement['enabled'] ?? false)
                    && isset($resourceCatalog[$key])
                    && ! $this->shouldHideResourceForSubscription($subscription, $key))
                ->map(function (array $entitlement, string $key) use ($resourceCatalog, $usages, $creditAccount): array {
                    $quota = (int) ($entitlement['quota_value'] ?? 0);
                    $period = (string) ($entitlement['quota_period'] ?? 'cycle');
                    $isUnlimited = $period === 'unlimited' || $quota <= 0;

                    if ($key === PlatformPlan::RESOURCE_CREDITS && $creditAccount instanceof AdminCreditAccount) {
                        $used = (float) $creditAccount->total_consumed;
                        $remaining = (float) $creditAccount->balance;

                        return [
                            'key' => $key,
                            'label' => $resourceCatalog[$key]['label'],
                            'quota' => $isUnlimited ? null : $quota,
                            'used' => number_format($used, 2, '.', ''),
                            'remaining' => $isUnlimited ? null : number_format($remaining, 2, '.', ''),
                            'period' => $isUnlimited ? 'unlimited' : $period,
                            'unit' => (string) ($entitlement['unit'] ?? $resourceCatalog[$key]['unit']),
                            'percent' => $isUnlimited ? 100 : min(100, (int) round($used / max(1, $quota) * 100)),
                            'is_unlimited' => $isUnlimited,
                        ];
                    }

                    $used = (int) ($usages->get($key)?->used_amount ?? 0);

                    return [
                        'key' => $key,
                        'label' => $resourceCatalog[$key]['label'],
                        'quota' => $isUnlimited ? null : $quota,
                        'used' => $used,
                        'remaining' => $isUnlimited ? null : max(0, $quota - $used),
                        'period' => $isUnlimited ? 'unlimited' : $period,
                        'unit' => (string) ($entitlement['unit'] ?? $resourceCatalog[$key]['unit']),
                        'percent' => $isUnlimited ? 100 : min(100, (int) round($used / max(1, $quota) * 100)),
                        'is_unlimited' => $isUnlimited,
                    ];
                })
                ->values();

            $creditEntitlement = (array) data_get((array) $subscription->entitlements_snapshot, PlatformPlan::RESOURCE_CREDITS, []);
            $hasUnlimitedCredits = (bool) ($creditEntitlement['enabled'] ?? false)
                && (int) ($creditEntitlement['quota_value'] ?? 0) <= 0;

            return [
                'subscription' => $subscription,
                'resources' => $resources,
                'creditAccount' => $creditAccount,
                'hasUnlimitedCredits' => $hasUnlimitedCredits,
            ];
        });
    }

    private function shouldHideResourceForSubscription(AdminPlanSubscription $subscription, string $resourceKey): bool
    {
        if ($resourceKey !== PlatformPlan::RESOURCE_TEAM_MEMBERS) {
            return false;
        }

        $admin = $subscription->admin;
        if ($admin instanceof Admin && ($admin->isDirectAdmin() || $admin->isSiteUser())) {
            return true;
        }

        return in_array((string) $subscription->mode, ['direct_owner', 'agent_user'], true);
    }

    /**
     * @return Collection<int,Site>
     */
    private function filterableSites(Admin $admin): Collection
    {
        if (! $admin->isSuperAdmin()) {
            return collect();
        }

        return Site::query()->orderBy('id')->get(['id', 'name']);
    }
}
