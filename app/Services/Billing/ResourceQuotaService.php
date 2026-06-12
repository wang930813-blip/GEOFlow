<?php

namespace App\Services\Billing;

use App\Models\Admin;
use App\Models\SitePlanSubscription;
use App\Models\SiteResourceLedger;
use App\Models\SiteResourceUsage;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ResourceQuotaService
{
    public function activeSubscriptionForSite(int $siteId): SitePlanSubscription
    {
        $subscription = SitePlanSubscription::query()
            ->where('site_id', $siteId)
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->orderByDesc('ends_at')
            ->orderByDesc('id')
            ->first();

        if (! $subscription instanceof SitePlanSubscription) {
            throw new RuntimeException('当前规格已到期，请联系平台续费');
        }

        return $subscription;
    }

    public function assertSubscriptionActive(int $siteId, ?Admin $admin = null): void
    {
        if ($admin instanceof Admin && $admin->isSuperAdmin()) {
            return;
        }

        $this->activeSubscriptionForSite($siteId);
    }

    public function assertCanUse(int $siteId, string $resourceKey, int $amount = 1, ?Admin $admin = null): void
    {
        if ($admin instanceof Admin && $admin->isSuperAdmin()) {
            return;
        }

        $subscription = $this->activeSubscriptionForSite($siteId);
        $entitlement = $this->entitlement($subscription, $resourceKey);
        if ((string) $entitlement['quota_period'] === 'unlimited') {
            return;
        }

        $used = $this->usedAmount($subscription, $resourceKey);
        $quota = (int) $entitlement['quota_value'];
        if ($quota < $amount || $used + $amount > $quota) {
            throw new RuntimeException('当前规格额度不足，请联系平台升级或续费');
        }
    }

    /**
     * @param  array{actor_admin_id?:int|null,subject_type?:string|null,subject_id?:int|null,idempotency_key?:string|null,remark?:string|null}  $context
     */
    public function consume(int $siteId, string $resourceKey, int $amount = 1, array $context = []): SiteResourceLedger
    {
        $amount = max(1, $amount);

        return DB::transaction(function () use ($siteId, $resourceKey, $amount, $context): SiteResourceLedger {
            $idempotencyKey = trim((string) ($context['idempotency_key'] ?? ''));
            if ($idempotencyKey !== '') {
                $existing = SiteResourceLedger::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing instanceof SiteResourceLedger) {
                    return $existing;
                }
            }

            $subscription = $this->activeSubscriptionForSite($siteId);
            $entitlement = $this->entitlement($subscription, $resourceKey);
            if ((string) $entitlement['quota_period'] === 'unlimited') {
                return $this->ledger($subscription, $resourceKey, 'consume', $amount, null, $context);
            }

            $periodKey = $this->periodKey($subscription, (string) $entitlement['quota_period']);
            $usage = SiteResourceUsage::query()->firstOrCreate(
                [
                    'site_id' => $siteId,
                    'subscription_id' => (int) $subscription->id,
                    'resource_key' => $resourceKey,
                    'period_key' => $periodKey,
                ],
                [
                    'used_amount' => 0,
                    'reserved_amount' => 0,
                    'last_used_at' => null,
                ]
            );
            $usage = SiteResourceUsage::query()->whereKey((int) $usage->id)->lockForUpdate()->firstOrFail();
            $quota = (int) $entitlement['quota_value'];
            $used = (int) $usage->used_amount;
            if ($used + $amount > $quota) {
                throw new RuntimeException('当前规格额度不足，请联系平台升级或续费');
            }

            $usage->forceFill([
                'used_amount' => $used + $amount,
                'last_used_at' => now(),
            ])->save();

            return $this->ledger($subscription, $resourceKey, 'consume', $amount, $quota - $used - $amount, $context);
        });
    }

    /**
     * @param  array{actor_admin_id?:int|null,subject_type?:string|null,subject_id?:int|null,idempotency_key?:string|null,remark?:string|null}  $context
     */
    public function refund(int $siteId, string $resourceKey, int $amount = 1, array $context = []): SiteResourceLedger
    {
        $amount = max(1, $amount);

        return DB::transaction(function () use ($siteId, $resourceKey, $amount, $context): SiteResourceLedger {
            $subscription = $this->activeSubscriptionForSite($siteId);
            $entitlement = $this->entitlement($subscription, $resourceKey);
            $balanceAfter = null;

            if ((string) $entitlement['quota_period'] !== 'unlimited') {
                $usage = SiteResourceUsage::query()
                    ->where('site_id', $siteId)
                    ->where('subscription_id', (int) $subscription->id)
                    ->where('resource_key', $resourceKey)
                    ->where('period_key', $this->periodKey($subscription, (string) $entitlement['quota_period']))
                    ->lockForUpdate()
                    ->first();

                if ($usage instanceof SiteResourceUsage) {
                    $used = max(0, (int) $usage->used_amount - $amount);
                    $usage->forceFill(['used_amount' => $used])->save();
                    $balanceAfter = (int) $entitlement['quota_value'] - $used;
                }
            }

            return $this->ledger($subscription, $resourceKey, 'refund', $amount, $balanceAfter, $context);
        });
    }

    /**
     * @return array{quota:int|null,used:int,remaining:int|null,period:string}
     */
    public function remaining(int $siteId, string $resourceKey): array
    {
        $subscription = $this->activeSubscriptionForSite($siteId);
        $entitlement = $this->entitlement($subscription, $resourceKey);
        if ((string) $entitlement['quota_period'] === 'unlimited') {
            return ['quota' => null, 'used' => 0, 'remaining' => null, 'period' => 'unlimited'];
        }

        $used = $this->usedAmount($subscription, $resourceKey);
        $quota = (int) $entitlement['quota_value'];

        return [
            'quota' => $quota,
            'used' => $used,
            'remaining' => max(0, $quota - $used),
            'period' => (string) $entitlement['quota_period'],
        ];
    }

    /**
     * @return array<string,array{quota:int|null,used:int,remaining:int|null,period:string,label:string,unit:string}>
     */
    public function summary(int $siteId): array
    {
        $subscription = $this->activeSubscriptionForSite($siteId);
        $catalog = \App\Models\PlatformPlan::resourceCatalog();

        return collect((array) $subscription->entitlements_snapshot)
            ->mapWithKeys(function (array $entitlement, string $resourceKey) use ($siteId, $catalog): array {
                $remaining = $this->remaining($siteId, $resourceKey);

                return [
                    $resourceKey => $remaining + [
                        'label' => (string) data_get($catalog, $resourceKey.'.label', $resourceKey),
                        'unit' => (string) data_get($catalog, $resourceKey.'.unit', 'times'),
                    ],
                ];
            })
            ->all();
    }

    /**
     * @return array{enabled:bool,quota_value:int,quota_period:string,unit:string,meta:array<string,mixed>}
     */
    private function entitlement(SitePlanSubscription $subscription, string $resourceKey): array
    {
        $snapshot = (array) $subscription->entitlements_snapshot;
        $entitlement = $snapshot[$resourceKey] ?? null;
        if (! is_array($entitlement) || ! (bool) ($entitlement['enabled'] ?? false)) {
            throw new RuntimeException('当前规格不包含该功能');
        }

        return [
            'enabled' => true,
            'quota_value' => (int) ($entitlement['quota_value'] ?? 0),
            'quota_period' => (string) ($entitlement['quota_period'] ?? 'cycle'),
            'unit' => (string) ($entitlement['unit'] ?? 'times'),
            'meta' => (array) ($entitlement['meta'] ?? []),
        ];
    }

    private function usedAmount(SitePlanSubscription $subscription, string $resourceKey): int
    {
        $entitlement = $this->entitlement($subscription, $resourceKey);

        return (int) SiteResourceUsage::query()
            ->where('site_id', (int) $subscription->site_id)
            ->where('subscription_id', (int) $subscription->id)
            ->where('resource_key', $resourceKey)
            ->where('period_key', $this->periodKey($subscription, (string) $entitlement['quota_period']))
            ->value('used_amount');
    }

    private function periodKey(SitePlanSubscription $subscription, string $quotaPeriod): string
    {
        return match ($quotaPeriod) {
            'day' => now()->format('Y-m-d'),
            'month' => now()->format('Y-m'),
            'year' => now()->format('Y'),
            default => 'subscription:'.(int) $subscription->id,
        };
    }

    /**
     * @param  array{actor_admin_id?:int|null,subject_type?:string|null,subject_id?:int|null,idempotency_key?:string|null,remark?:string|null}  $context
     */
    private function ledger(SitePlanSubscription $subscription, string $resourceKey, string $type, int $amount, ?int $balanceAfter, array $context): SiteResourceLedger
    {
        return SiteResourceLedger::query()->create([
            'site_id' => (int) $subscription->site_id,
            'subscription_id' => (int) $subscription->id,
            'resource_key' => $resourceKey,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'actor_admin_id' => isset($context['actor_admin_id']) ? (int) $context['actor_admin_id'] : null,
            'subject_type' => isset($context['subject_type']) ? (string) $context['subject_type'] : null,
            'subject_id' => isset($context['subject_id']) ? (int) $context['subject_id'] : null,
            'idempotency_key' => trim((string) ($context['idempotency_key'] ?? '')) ?: null,
            'remark' => isset($context['remark']) ? (string) $context['remark'] : null,
            'created_at' => now(),
        ]);
    }
}
