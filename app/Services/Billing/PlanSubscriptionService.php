<?php

namespace App\Services\Billing;

use App\Models\Admin;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Models\SitePlanSubscription;
use App\Models\SiteSubscriptionLog;
use App\Services\MediaDistribution\SiteCreditService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PlanSubscriptionService
{
    public function __construct(
        private readonly SiteCreditService $siteCreditService
    ) {}

    public function open(
        Site $site,
        PlatformPlan $plan,
        string $mode,
        ?Admin $ownerAdmin,
        ?Admin $operator,
        ?CarbonInterface $startsAt = null,
        ?CarbonInterface $endsAt = null,
        bool $grantCredits = true,
        string $remark = ''
    ): SitePlanSubscription {
        $mode = $this->normalizeMode($mode);
        $startsAt ??= now();
        $endsAt ??= $startsAt->copy()->addDays(max(1, (int) $plan->duration_days));
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw new RuntimeException('到期时间必须晚于开始时间');
        }

        return DB::transaction(function () use ($site, $plan, $mode, $ownerAdmin, $operator, $startsAt, $endsAt, $grantCredits, $remark): SitePlanSubscription {
            SitePlanSubscription::query()
                ->where('site_id', (int) $site->id)
                ->where('status', 'active')
                ->update(['status' => 'cancelled']);

            $snapshot = $this->entitlementSnapshot($plan, $mode);
            $subscription = SitePlanSubscription::query()->create([
                'site_id' => (int) $site->id,
                'plan_id' => (int) $plan->id,
                'mode' => $mode,
                'owner_admin_id' => $ownerAdmin?->id,
                'agent_admin_id' => $mode === 'agent' ? $ownerAdmin?->id : null,
                'assigned_by_admin_id' => $operator?->id,
                'status' => 'active',
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'entitlements_snapshot' => $snapshot,
                'remark' => $remark,
            ]);

            $site->forceFill([
                'customer_mode' => $mode,
                'agent_admin_id' => $mode === 'agent' ? $ownerAdmin?->id : null,
                'plan_status' => 'active',
            ])->save();

            if ($grantCredits) {
                $credits = (int) data_get($snapshot, PlatformPlan::RESOURCE_CREDITS.'.quota_value', 0);
                if ($credits > 0) {
                    $this->siteCreditService->recharge(
                        (int) $site->id,
                        number_format($credits, 2, '.', ''),
                        $operator?->id,
                        '规格开通赠送：'.$plan->name
                    );
                }
            }

            SiteSubscriptionLog::query()->create([
                'site_id' => (int) $site->id,
                'subscription_id' => (int) $subscription->id,
                'action' => 'open',
                'before_payload' => null,
                'after_payload' => $subscription->toArray(),
                'operator_admin_id' => $operator?->id,
                'remark' => $remark,
                'created_at' => now(),
            ]);

            return $subscription;
        });
    }

    /**
     * @return array<string,array{enabled:bool,quota_value:int,quota_period:string,unit:string,meta:array<string,mixed>}>
     */
    public function entitlementSnapshot(PlatformPlan $plan, string $mode): array
    {
        $mode = $this->normalizeMode($mode);

        return $plan->entitlements()
            ->where('enabled', true)
            ->get()
            ->filter(function ($entitlement) use ($mode): bool {
                if ($mode === 'direct' && (string) $entitlement->resource_key === PlatformPlan::RESOURCE_TEAM_MEMBERS) {
                    return false;
                }

                return array_key_exists((string) $entitlement->resource_key, PlatformPlan::resourceCatalog());
            })
            ->mapWithKeys(static fn ($entitlement): array => [
                (string) $entitlement->resource_key => [
                    'enabled' => (bool) $entitlement->enabled,
                    'quota_value' => (int) $entitlement->quota_value,
                    'quota_period' => (string) $entitlement->quota_period,
                    'unit' => (string) $entitlement->unit,
                    'meta' => (array) ($entitlement->meta ?? []),
                ],
            ])
            ->all();
    }

    private function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if (! in_array($mode, ['agent', 'direct', 'internal'], true)) {
            throw new RuntimeException('客户模式不正确');
        }

        return $mode;
    }
}
