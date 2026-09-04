<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminCreditAccount;
use App\Models\AdminPlanSubscription;
use App\Models\AdminResourceUsage;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Services\MediaDistribution\MediaPackageDeliveryUsageService;
use App\Support\AdminWeb;
use App\Support\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function index(): View
    {
        $admin = auth('admin')->user();
        abort_unless($admin instanceof Admin, 403);

        $resourceCatalog = PlatformPlan::usageResourceCatalog();

        return view('admin.profile.index', [
            'pageTitle' => '个人中心',
            'activeMenu' => 'profile',
            'adminSiteName' => AdminWeb::siteName(),
            'admin' => $admin,
            'roleLabel' => $this->roleBadge($admin)['label'],
            'roleTone' => $this->roleBadge($admin)['tone'],
            'summaryCards' => $this->summaryCards($admin),
            'agentUserRows' => $admin->isSuperAdmin() ? $this->agentUserRows() : collect(),
            'planActivationRows' => $admin->isSuperAdmin() ? $this->planActivationRows() : collect(),
            'subscriptionRows' => $this->subscriptionRows($admin, $resourceCatalog, app(MediaPackageDeliveryUsageService::class)),
            'ownerLabel' => $this->ownerLabel($admin),
            'resourceCatalog' => $resourceCatalog,
            'creditDescription' => (string) ($resourceCatalog[PlatformPlan::RESOURCE_CREDITS]['description'] ?? ''),
        ]);
    }

    /**
     * @return array{label:string,tone:string}
     */
    private function roleBadge(Admin $admin): array
    {
        if ($admin->isSuperAdmin()) {
            return ['label' => '管理', 'tone' => 'indigo'];
        }

        if ($admin->isAgentAdmin()) {
            return ['label' => '代理', 'tone' => 'amber'];
        }

        return ['label' => '会员', 'tone' => 'emerald'];
    }

    /**
     * @return list<array{label:string,value:int|string,desc:string}>
     */
    private function summaryCards(Admin $admin): array
    {
        if ($admin->isSuperAdmin()) {
            return [
                ['label' => '代理数量', 'value' => $this->countAdminsByRole('agent_admin'), 'desc' => '平台代理账号'],
                ['label' => '直客数量', 'value' => $this->countAdminsByRole('direct_admin'), 'desc' => '平台直客账号'],
                ['label' => '普通用户', 'value' => $this->countAdminsByRole('site_user'), 'desc' => '代理下会员账号'],
                ['label' => '有效套餐', 'value' => AdminPlanSubscription::query()->activeNow()->count(), 'desc' => '当前有效账号规格'],
            ];
        }

        if ($admin->isAgentAdmin()) {
            $ownSubscription = $this->agentOwnSubscription($admin);

            return [
                ['label' => '当前规格', 'value' => $ownSubscription?->plan?->name ?? '未开通', 'desc' => '代理账号当前版本'],
                ['label' => '下级用户', 'value' => Admin::query()->where('created_by', (int) $admin->id)->where('role', 'site_user')->count(), 'desc' => '当前代理名下会员'],
                ['label' => '有效套餐', 'value' => $this->agentSubscriptionQuery($admin)->activeNow()->count(), 'desc' => '下级用户有效规格'],
                ['label' => '开通站点', 'value' => Site::query()->where('agent_admin_id', (int) $admin->id)->count(), 'desc' => '已关联代理的客户站点'],
            ];
        }

        $site = app(CurrentSite::class)->get();
        $subscription = $this->ownSubscriptionQuery($admin)->activeNow()->latest()->first();

        return [
            ['label' => '当前规格', 'value' => $subscription?->plan?->name ?? '未开通', 'desc' => '账号当前权益'],
            ['label' => '到期时间', 'value' => $subscription?->ends_at?->format('Y-m-d') ?? '-', 'desc' => '有效期截止日期'],
            ['label' => '站点', 'value' => $site?->name ?? '-', 'desc' => '当前账号站点'],
            ['label' => '归属', 'value' => $this->ownerLabel($admin), 'desc' => '账号来源'],
        ];
    }

    private function countAdminsByRole(string $role): int
    {
        return Admin::query()->where('role', $role)->count();
    }

    /**
     * @return Collection<int,array{agent:Admin,user_count:int}>
     */
    private function agentUserRows(): Collection
    {
        return Admin::query()
            ->where('role', 'agent_admin')
            ->orderBy('id')
            ->get(['id', 'username', 'display_name', 'role'])
            ->map(fn (Admin $agent): array => [
                'agent' => $agent,
                'user_count' => Admin::query()
                    ->where('created_by', (int) $agent->id)
                    ->where('role', 'site_user')
                    ->count(),
            ]);
    }

    /**
     * @return Collection<int,array{plan_name:string,active_count:int,total_count:int}>
     */
    private function planActivationRows(): Collection
    {
        return PlatformPlan::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (PlatformPlan $plan): array => [
                'plan_name' => (string) $plan->name,
                'active_count' => AdminPlanSubscription::query()
                    ->where('plan_id', (int) $plan->id)
                    ->activeNow()
                    ->count(),
                'total_count' => AdminPlanSubscription::query()
                    ->where('plan_id', (int) $plan->id)
                    ->count(),
            ]);
    }

    /**
     * @param  array<string,array{label:string,unit:string,description?:string}>  $resourceCatalog
     * @return Collection<int,array<string,mixed>>
     */
    private function subscriptionRows(Admin $admin, array $resourceCatalog, MediaPackageDeliveryUsageService $mediaDeliveryUsage): Collection
    {
        $subscriptions = $this->visibleSubscriptionQuery($admin)
            ->whereHas('admin')
            ->where(function (Builder $query): void {
                $query->whereNull('site_id')->orWhereHas('site');
            })
            ->with(['admin:id,username,display_name,role,created_by', 'plan:id,name,code', 'site:id,name'])
            ->orderByDesc('created_at')
            ->limit($admin->isSuperAdmin() ? 12 : 20)
            ->get();

        if ($subscriptions->isEmpty()) {
            return collect();
        }

        $usages = AdminResourceUsage::query()
            ->whereIn('subscription_id', $subscriptions->pluck('id')->map(fn ($id) => (int) $id))
            ->get()
            ->groupBy('subscription_id');

        $creditAccounts = AdminCreditAccount::query()
            ->whereIn('admin_id', $subscriptions->pluck('admin_id')->map(fn ($id) => (int) $id))
            ->whereIn('site_id', $subscriptions->pluck('site_id')->map(fn ($id) => (int) $id))
            ->get()
            ->keyBy(fn (AdminCreditAccount $account): string => (int) $account->admin_id.':'.(int) $account->site_id);

        $deliveryStats = $mediaDeliveryUsage->deliveryStatsForSubscriptions($subscriptions);

        return $subscriptions->map(function (AdminPlanSubscription $subscription) use ($resourceCatalog, $usages, $creditAccounts, $mediaDeliveryUsage, $deliveryStats): array {
            $usageByKey = $usages->get((int) $subscription->id, collect())->keyBy('resource_key');
            $creditAccount = $creditAccounts->get((int) $subscription->admin_id.':'.(int) $subscription->site_id);
            $resources = collect((array) $subscription->entitlements_snapshot)
                ->filter(fn ($entitlement, string $key): bool => is_array($entitlement)
                    && (bool) ($entitlement['enabled'] ?? false)
                    && isset($resourceCatalog[$key])
                    && ! $this->shouldHideResourceForSubscription($subscription, $key))
                ->map(function (array $entitlement, string $key) use ($resourceCatalog, $usageByKey, $creditAccount): array {
                    $quota = (int) ($entitlement['quota_value'] ?? 0);
                    $period = (string) ($entitlement['quota_period'] ?? 'cycle');
                    $isUnlimited = $period === 'unlimited' || $quota <= 0;

                    if ($key === PlatformPlan::RESOURCE_CREDITS && $creditAccount instanceof AdminCreditAccount) {
                        $used = (float) $creditAccount->total_consumed;
                        $remaining = (float) $creditAccount->balance;

                        return [
                            'key' => $key,
                            'label' => $resourceCatalog[$key]['label'],
                            'description' => (string) ($resourceCatalog[$key]['description'] ?? ''),
                            'quota' => $isUnlimited ? null : $quota,
                            'used' => number_format($used, 2, '.', ''),
                            'remaining' => $isUnlimited ? null : number_format($remaining, 2, '.', ''),
                            'period' => $isUnlimited ? 'unlimited' : $period,
                            'unit' => (string) ($entitlement['unit'] ?? $resourceCatalog[$key]['unit']),
                            'percent' => $isUnlimited ? 100 : min(100, (int) round($used / max(1, $quota) * 100)),
                            'is_unlimited' => $isUnlimited,
                        ];
                    }

                    $used = (int) ($usageByKey->get($key)?->used_amount ?? 0);

                    return [
                        'key' => $key,
                        'label' => $resourceCatalog[$key]['label'],
                        'description' => (string) ($resourceCatalog[$key]['description'] ?? ''),
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
            $statCounts = (array) $deliveryStats->get($mediaDeliveryUsage->key((int) $subscription->admin_id, (int) $subscription->site_id), ['official' => 0, 'b2b' => 0]);
            $officialDeliveryCount = (int) ($statCounts['official'] ?? 0);
            $b2bDeliveryCount = (int) ($statCounts['b2b'] ?? 0);
            if ((bool) ($creditEntitlement['enabled'] ?? false) || $officialDeliveryCount > 0 || $b2bDeliveryCount > 0) {
                $resources = collect([
                    $this->mediaDeliveryStatResource(
                        MediaPackageDeliveryUsageService::OFFICIAL_RESOURCE_KEY,
                        MediaPackageDeliveryUsageService::OFFICIAL_LABEL,
                        MediaPackageDeliveryUsageService::OFFICIAL_DESCRIPTION,
                        $officialDeliveryCount,
                        'newspaper'
                    ),
                    $this->mediaDeliveryStatResource(
                        MediaPackageDeliveryUsageService::RESOURCE_KEY,
                        MediaPackageDeliveryUsageService::LABEL,
                        MediaPackageDeliveryUsageService::DESCRIPTION,
                        $b2bDeliveryCount,
                        'megaphone'
                    ),
                ])->merge($resources)->values();
            }

            return [
                'subscription' => $subscription,
                'resources' => $resources,
                'creditAccount' => $creditAccount,
            ];
        });
    }

    /**
     * @return array<string,mixed>
     */
    private function mediaDeliveryStatResource(string $key, string $label, string $description, int $deliveryCount, string $icon): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'quota' => null,
            'used' => $deliveryCount,
            'remaining' => null,
            'period' => 'stat',
            'unit' => 'items',
            'percent' => 0,
            'is_unlimited' => true,
            'is_stat_only' => true,
            'icon' => $icon,
        ];
    }

    private function visibleSubscriptionQuery(Admin $admin): Builder
    {
        if ($admin->isSuperAdmin()) {
            return AdminPlanSubscription::query();
        }

        if ($admin->isAgentAdmin()) {
            return $this->agentSubscriptionQuery($admin);
        }

        return $this->ownSubscriptionQuery($admin);
    }

    private function agentSubscriptionQuery(Admin $admin): Builder
    {
        return AdminPlanSubscription::query()
            ->where('admin_id', '!=', (int) $admin->id)
            ->whereHas('admin')
            ->whereHas('site')
            ->where(function (Builder $query) use ($admin): void {
                $query->whereHas('admin', fn (Builder $adminQuery) => $adminQuery
                    ->where('created_by', (int) $admin->id)
                    ->where('role', 'site_user'))
                    ->orWhere('inherited_from_admin_id', (int) $admin->id);
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

    private function agentOwnSubscription(Admin $admin): ?AdminPlanSubscription
    {
        return AdminPlanSubscription::query()
            ->with('plan:id,name,code')
            ->where('admin_id', (int) $admin->id)
            ->where('mode', 'agent_owner')
            ->activeNow()
            ->orderByRaw('CASE WHEN site_id IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('ends_at')
            ->orderByDesc('id')
            ->first();
    }

    private function ownSubscriptionQuery(Admin $admin): Builder
    {
        return AdminPlanSubscription::query()->where('admin_id', (int) $admin->id);
    }

    private function ownerLabel(Admin $admin): string
    {
        if ($admin->isSiteUser() && $admin->creator instanceof Admin) {
            return '归属代理：'.$admin->creator->name;
        }

        if ($admin->isDirectAdmin()) {
            return '平台直客';
        }

        if ($admin->isAgentAdmin()) {
            return '平台代理';
        }

        return '平台管理';
    }
}
