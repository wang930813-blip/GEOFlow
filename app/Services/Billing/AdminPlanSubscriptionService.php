<?php

namespace App\Services\Billing;

use App\Models\Admin;
use App\Models\AdminPlanSubscription;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Models\SitePlanSubscription;
use App\Services\MediaDistribution\AdminCreditService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AdminPlanSubscriptionService
{
    public function __construct(
        private readonly PlanSubscriptionService $siteSubscriptionService,
        private readonly AdminCreditService $creditService
    ) {}

    public function openOwner(
        Admin $admin,
        Site $site,
        PlatformPlan $plan,
        string $mode,
        ?Admin $operator,
        ?CarbonInterface $startsAt = null,
        ?CarbonInterface $endsAt = null,
        bool $grantCredits = true,
        string $remark = '',
        ?int $sourceSubscriptionId = null
    ): AdminPlanSubscription {
        $mode = $this->normalizeMode($mode);
        $startsAt ??= now();
        $endsAt ??= $startsAt->copy()->addDays(max(1, (int) $plan->duration_days));
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw new RuntimeException('到期时间必须晚于开始时间');
        }

        return DB::transaction(function () use ($admin, $site, $plan, $mode, $operator, $startsAt, $endsAt, $grantCredits, $remark, $sourceSubscriptionId): AdminPlanSubscription {
            AdminPlanSubscription::query()
                ->where('admin_id', (int) $admin->id)
                ->where('site_id', (int) $site->id)
                ->where('status', 'active')
                ->update(['status' => 'cancelled']);

            $snapshotMode = str_starts_with($mode, 'direct') ? 'direct' : 'agent';
            $snapshot = $this->siteSubscriptionService->entitlementSnapshot($plan, $snapshotMode);

            $subscription = AdminPlanSubscription::query()->create([
                'admin_id' => (int) $admin->id,
                'site_id' => (int) $site->id,
                'plan_id' => (int) $plan->id,
                'source_subscription_id' => $sourceSubscriptionId,
                'inherited_from_admin_id' => null,
                'mode' => $mode,
                'status' => 'active',
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'entitlements_snapshot' => $snapshot,
                'remark' => $remark,
            ]);

            if ($grantCredits) {
                $this->grantCreditsFromSnapshot($admin, $site, $snapshot, $operator, '规格开通赠送：'.$plan->name);
            }

            if ($mode === 'agent_owner') {
                $this->refreshInheritedAgentUserSubscriptions($admin, $subscription, $operator, $grantCredits);
            }

            return $subscription;
        });
    }

    public function openAgentOwner(
        Admin $agent,
        PlatformPlan $plan,
        ?Admin $operator,
        ?CarbonInterface $startsAt = null,
        ?CarbonInterface $endsAt = null,
        string $remark = '',
        bool $grantCredits = false
    ): AdminPlanSubscription {
        $startsAt ??= now();
        $endsAt ??= $startsAt->copy()->addDays(max(1, (int) $plan->duration_days));
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw new RuntimeException('到期时间必须晚于开始时间');
        }

        return DB::transaction(function () use ($agent, $plan, $operator, $startsAt, $endsAt, $remark, $grantCredits): AdminPlanSubscription {
            AdminPlanSubscription::query()
                ->where('admin_id', (int) $agent->id)
                ->where('mode', 'agent_owner')
                ->where('status', 'active')
                ->update(['status' => 'cancelled']);

            $subscription = AdminPlanSubscription::query()->create([
                'admin_id' => (int) $agent->id,
                'site_id' => null,
                'plan_id' => (int) $plan->id,
                'source_subscription_id' => null,
                'inherited_from_admin_id' => null,
                'mode' => 'agent_owner',
                'status' => 'active',
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'entitlements_snapshot' => $this->siteSubscriptionService->entitlementSnapshot($plan, 'agent'),
                'remark' => $remark,
            ]);

            $this->refreshInheritedAgentUserSubscriptions($agent, $subscription, $operator, $grantCredits);

            return $subscription;
        });
    }

    public function inheritForAgentUser(
        Admin $agent,
        Admin $user,
        Site $site,
        ?Admin $operator,
        string $remark = '代理创建用户继承规格'
    ): AdminPlanSubscription {
        $source = $this->activeAgentOwnerSubscription($agent) ?? $this->activeOrBackfilledSubscriptionForAdmin($agent, $site);

        return $this->createInheritedSubscription($agent, $user, $site, $operator, $remark, $source);
    }

    public function inheritForAgentUserSite(
        Admin $agent,
        Admin $user,
        Site $sourceSite,
        Site $userSite,
        ?Admin $operator,
        string $remark = '代理创建用户继承规格'
    ): AdminPlanSubscription {
        $source = $this->activeAgentOwnerSubscription($agent) ?? $this->activeOrBackfilledSubscriptionForAdmin($agent, $sourceSite);

        return $this->createInheritedSubscription($agent, $user, $userSite, $operator, $remark, $source);
    }

    public function inheritForAgentUserFromAccount(
        Admin $agent,
        Admin $user,
        Site $userSite,
        ?Admin $operator,
        string $remark = '代理创建用户继承规格'
    ): AdminPlanSubscription {
        $source = $this->activeAgentOwnerSubscription($agent);

        if (! $source instanceof AdminPlanSubscription) {
            throw new RuntimeException('当前代理账号规格已到期，请联系平台续费');
        }

        return $this->createInheritedSubscription($agent, $user, $userSite, $operator, $remark, $source);
    }

    private function createInheritedSubscription(
        Admin $agent,
        Admin $user,
        Site $site,
        ?Admin $operator,
        string $remark,
        AdminPlanSubscription $source,
        bool $grantCredits = true
    ): AdminPlanSubscription {
        return DB::transaction(function () use ($agent, $user, $site, $operator, $remark, $source, $grantCredits): AdminPlanSubscription {
            AdminPlanSubscription::query()
                ->where('admin_id', (int) $user->id)
                ->where('site_id', (int) $site->id)
                ->where('status', 'active')
                ->update(['status' => 'cancelled']);

            $snapshot = (array) $source->entitlements_snapshot;
            $subscription = AdminPlanSubscription::query()->create([
                'admin_id' => (int) $user->id,
                'site_id' => (int) $site->id,
                'plan_id' => $source->plan_id,
                'source_subscription_id' => $source->source_subscription_id,
                'inherited_from_admin_id' => (int) $agent->id,
                'mode' => 'agent_user',
                'status' => 'active',
                'starts_at' => $source->starts_at,
                'ends_at' => $source->ends_at,
                'entitlements_snapshot' => $snapshot,
                'remark' => $remark,
            ]);

            if ($grantCredits) {
                $this->grantCreditsFromSnapshot($user, $site, $snapshot, $operator, '代理用户继承规格赠送');
            }

            return $subscription;
        });
    }

    private function refreshInheritedAgentUserSubscriptions(Admin $agent, AdminPlanSubscription $source, ?Admin $operator, bool $grantCredits): int
    {
        $targets = [];

        AdminPlanSubscription::query()
            ->with(['admin', 'site'])
            ->where('inherited_from_admin_id', (int) $agent->id)
            ->where('mode', 'agent_user')
            ->whereHas('admin', fn (Builder $query) => $query->where('role', 'site_user'))
            ->whereHas('site')
            ->get()
            ->each(function (AdminPlanSubscription $subscription) use (&$targets): void {
                if (! $subscription->admin instanceof Admin || ! $subscription->site instanceof Site) {
                    return;
                }

                $targets[(int) $subscription->admin_id.':'.(int) $subscription->site_id] = [
                    'admin' => $subscription->admin,
                    'site' => $subscription->site,
                ];
            });

        Site::query()
            ->with('owner')
            ->where('customer_mode', 'agent')
            ->where('agent_admin_id', (int) $agent->id)
            ->whereHas('owner', fn (Builder $query) => $query
                ->where('role', 'site_user')
                ->where('created_by', (int) $agent->id))
            ->get()
            ->each(function (Site $site) use (&$targets): void {
                if (! $site->owner instanceof Admin) {
                    return;
                }

                $targets[(int) $site->owner_admin_id.':'.(int) $site->id] = [
                    'admin' => $site->owner,
                    'site' => $site,
                ];
            });

        $refreshed = 0;

        foreach ($targets as $target) {
            if ($this->targetAlreadyUsesSource($target['admin'], $target['site'], $agent, $source)) {
                continue;
            }

            $this->createInheritedSubscription(
                agent: $agent,
                user: $target['admin'],
                site: $target['site'],
                operator: $operator,
                remark: '代理续费同步继承规格',
                source: $source,
                grantCredits: $grantCredits
            );

            $refreshed++;
        }

        return $refreshed;
    }

    private function targetAlreadyUsesSource(Admin $user, Site $site, Admin $agent, AdminPlanSubscription $source): bool
    {
        $subscription = AdminPlanSubscription::query()
            ->where('admin_id', (int) $user->id)
            ->where('site_id', (int) $site->id)
            ->where('inherited_from_admin_id', (int) $agent->id)
            ->where('mode', 'agent_user')
            ->activeNow()
            ->orderByDesc('ends_at')
            ->orderByDesc('id')
            ->first();

        if (! $subscription instanceof AdminPlanSubscription) {
            return false;
        }

        return (int) $subscription->plan_id === (int) $source->plan_id
            && (int) ($subscription->source_subscription_id ?? 0) === (int) ($source->source_subscription_id ?? 0)
            && $this->datesEqual($subscription->starts_at, $source->starts_at)
            && $this->datesEqual($subscription->ends_at, $source->ends_at);
    }

    private function datesEqual(?CarbonInterface $first, ?CarbonInterface $second): bool
    {
        if ($first === null || $second === null) {
            return $first === null && $second === null;
        }

        return $first->equalTo($second);
    }

    public function activeSubscriptionForAdmin(int $adminId, int $siteId): AdminPlanSubscription
    {
        $subscription = AdminPlanSubscription::query()
            ->where('admin_id', $adminId)
            ->where('site_id', $siteId)
            ->activeNow()
            ->orderByDesc('ends_at')
            ->orderByDesc('id')
            ->first();

        if (! $subscription instanceof AdminPlanSubscription) {
            $admin = Admin::query()->find($adminId);
            $site = Site::query()->find($siteId);
            if ($admin instanceof Admin && $site instanceof Site && ! $admin->isSiteUser()) {
                $siteSubscription = $this->activeSiteSubscriptionForAdmin($admin, $site);
                if ($siteSubscription instanceof SitePlanSubscription) {
                    return $this->backfillFromSiteSubscription($admin, $site, $siteSubscription);
                }
            }

            throw new RuntimeException('当前账号规格已到期，请联系平台续费');
        }

        return $subscription;
    }

    public function activeAgentOwnerSubscription(Admin $agent): ?AdminPlanSubscription
    {
        return AdminPlanSubscription::query()
            ->where('admin_id', (int) $agent->id)
            ->where('mode', 'agent_owner')
            ->activeNow()
            ->orderByRaw('CASE WHEN site_id IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('ends_at')
            ->orderByDesc('id')
            ->first();
    }

    public function refreshInheritedAgentUsersFromCurrentPlan(Admin $agent, ?Admin $operator = null, bool $grantCredits = false): int
    {
        $source = $this->activeAgentOwnerSubscription($agent);
        if (! $source instanceof AdminPlanSubscription) {
            return 0;
        }

        return $this->refreshInheritedAgentUserSubscriptions($agent, $source, $operator, $grantCredits);
    }

    public function activeOrBackfilledSubscriptionForAdmin(Admin $admin, Site $site): AdminPlanSubscription
    {
        try {
            return $this->activeSubscriptionForAdmin((int) $admin->id, (int) $site->id);
        } catch (RuntimeException) {
            $siteSubscription = $this->activeSiteSubscriptionForAdmin($admin, $site);

            if (! $siteSubscription instanceof SitePlanSubscription) {
                throw new RuntimeException('当前账号规格已到期，请联系平台续费');
            }

            return $this->backfillFromSiteSubscription($admin, $site, $siteSubscription);
        }
    }

    public function backfillFromSiteSubscription(Admin $admin, Site $site, SitePlanSubscription $siteSubscription): AdminPlanSubscription
    {
        return AdminPlanSubscription::query()->firstOrCreate(
            [
                'admin_id' => (int) $admin->id,
                'site_id' => (int) $site->id,
                'source_subscription_id' => (int) $siteSubscription->id,
            ],
            [
                'plan_id' => $siteSubscription->plan_id,
                'inherited_from_admin_id' => null,
                'mode' => match ((string) $siteSubscription->mode) {
                    'agent' => 'agent_owner',
                    'direct' => 'direct_owner',
                    default => 'internal',
                },
                'status' => $siteSubscription->status,
                'starts_at' => $siteSubscription->starts_at,
                'ends_at' => $siteSubscription->ends_at,
                'entitlements_snapshot' => (array) $siteSubscription->entitlements_snapshot,
                'remark' => '由站点规格兼容迁移生成',
            ]
        );
    }

    private function activeSiteSubscriptionForAdmin(Admin $admin, Site $site): ?SitePlanSubscription
    {
        return SitePlanSubscription::query()
            ->where('site_id', (int) $site->id)
            ->where('status', 'active')
            ->where(function ($query) use ($admin): void {
                $query->where('owner_admin_id', (int) $admin->id)
                    ->orWhere('agent_admin_id', (int) $admin->id);
            })
            ->where(function ($query): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->orderByDesc('ends_at')
            ->orderByDesc('id')
            ->first();
    }

    private function grantCreditsFromSnapshot(Admin $admin, Site $site, array $snapshot, ?Admin $operator, string $remark): void
    {
        $credits = (int) data_get($snapshot, PlatformPlan::RESOURCE_CREDITS.'.quota_value', 0);
        if ($credits <= 0) {
            return;
        }

        $this->creditService->grant(
            adminId: (int) $admin->id,
            siteId: (int) $site->id,
            amount: number_format($credits, 2, '.', ''),
            operatorAdminId: $operator?->id,
            remark: $remark
        );
    }

    private function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if (! in_array($mode, ['agent_owner', 'agent_user', 'direct_owner', 'internal'], true)) {
            throw new RuntimeException('账号规格模式不正确');
        }

        return $mode;
    }
}
