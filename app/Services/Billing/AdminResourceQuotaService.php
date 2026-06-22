<?php

namespace App\Services\Billing;

use App\Models\Admin;
use App\Models\AdminPlanSubscription;
use App\Models\AdminResourceLedger;
use App\Models\AdminResourceUsage;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AdminResourceQuotaService
{
    public function __construct(
        private readonly AdminPlanSubscriptionService $subscriptionService
    ) {}

    public function activeSubscriptionForAdmin(int $adminId, int $siteId): AdminPlanSubscription
    {
        return $this->subscriptionService->activeSubscriptionForAdmin($adminId, $siteId);
    }

    public function assertSubscriptionActive(int $adminId, int $siteId, ?Admin $admin = null): void
    {
        if ($admin instanceof Admin && $admin->isSuperAdmin()) {
            return;
        }

        $this->activeSubscriptionForAdmin($adminId, $siteId);
    }

    public function assertCanUse(int $adminId, int $siteId, string $resourceKey, int $amount = 1, ?Admin $admin = null): void
    {
        if ($admin instanceof Admin && $admin->isSuperAdmin()) {
            return;
        }

        $subscription = $this->activeSubscriptionForAdmin($adminId, $siteId);
        $entitlement = $this->entitlement($subscription, $resourceKey);
        if ($this->isUnlimited($entitlement)) {
            return;
        }

        $used = $this->usedAmount($subscription, $resourceKey);
        $quota = (int) $entitlement['quota_value'];
        if ($quota < $amount || $used + $amount > $quota) {
            throw new RuntimeException('当前账号规格额度不足，请联系平台升级或续费');
        }
    }

    public function consume(int $adminId, int $siteId, string $resourceKey, int $amount = 1, array $context = []): AdminResourceLedger
    {
        $amount = max(1, $amount);

        return DB::transaction(function () use ($adminId, $siteId, $resourceKey, $amount, $context): AdminResourceLedger {
            $idempotencyKey = trim((string) ($context['idempotency_key'] ?? ''));
            if ($idempotencyKey !== '') {
                $existing = AdminResourceLedger::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing instanceof AdminResourceLedger) {
                    return $existing;
                }
            }

            $subscription = $this->activeSubscriptionForAdmin($adminId, $siteId);
            $entitlement = $this->entitlement($subscription, $resourceKey);
            $periodKey = $this->periodKey($subscription, (string) $entitlement['quota_period']);
            $usage = AdminResourceUsage::query()->firstOrCreate(
                [
                    'admin_id' => $adminId,
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
            $usage = AdminResourceUsage::query()->whereKey((int) $usage->id)->lockForUpdate()->firstOrFail();
            $quota = (int) $entitlement['quota_value'];
            $used = (int) $usage->used_amount;
            $isUnlimited = $this->isUnlimited($entitlement);
            if (! $isUnlimited && $used + $amount > $quota) {
                throw new RuntimeException('当前账号规格额度不足，请联系平台升级或续费');
            }

            $usage->forceFill([
                'used_amount' => $used + $amount,
                'last_used_at' => now(),
            ])->save();

            return $this->ledger($subscription, $resourceKey, 'consume', $amount, $isUnlimited ? null : $quota - $used - $amount, $context);
        });
    }

    /**
     * @return array{quota:int|null,used:int,remaining:int|null,period:string}
     */
    public function remaining(int $adminId, int $siteId, string $resourceKey): array
    {
        $subscription = $this->activeSubscriptionForAdmin($adminId, $siteId);
        $entitlement = $this->entitlement($subscription, $resourceKey);
        $used = $this->usedAmount($subscription, $resourceKey);
        if ($this->isUnlimited($entitlement)) {
            return ['quota' => null, 'used' => $used, 'remaining' => null, 'period' => 'unlimited'];
        }

        $quota = (int) $entitlement['quota_value'];

        return [
            'quota' => $quota,
            'used' => $used,
            'remaining' => max(0, $quota - $used),
            'period' => (string) $entitlement['quota_period'],
        ];
    }

    /**
     * @return array{enabled:bool,quota_value:int,quota_period:string,unit:string,meta:array<string,mixed>}
     */
    private function entitlement(AdminPlanSubscription $subscription, string $resourceKey): array
    {
        $snapshot = (array) $subscription->entitlements_snapshot;
        $entitlement = $snapshot[$resourceKey] ?? null;
        if (! is_array($entitlement) || ! (bool) ($entitlement['enabled'] ?? false)) {
            throw new RuntimeException('当前账号规格不包含该功能');
        }

        return [
            'enabled' => true,
            'quota_value' => (int) ($entitlement['quota_value'] ?? 0),
            'quota_period' => (string) ($entitlement['quota_period'] ?? 'cycle'),
            'unit' => (string) ($entitlement['unit'] ?? 'times'),
            'meta' => (array) ($entitlement['meta'] ?? []),
        ];
    }

    private function usedAmount(AdminPlanSubscription $subscription, string $resourceKey): int
    {
        $entitlement = $this->entitlement($subscription, $resourceKey);

        return (int) AdminResourceUsage::query()
            ->where('admin_id', (int) $subscription->admin_id)
            ->where('site_id', (int) $subscription->site_id)
            ->where('subscription_id', (int) $subscription->id)
            ->where('resource_key', $resourceKey)
            ->where('period_key', $this->periodKey($subscription, (string) $entitlement['quota_period']))
            ->value('used_amount');
    }

    private function periodKey(AdminPlanSubscription $subscription, string $quotaPeriod): string
    {
        return match ($quotaPeriod) {
            'day' => now()->format('Y-m-d'),
            'month' => now()->format('Y-m'),
            'year' => now()->format('Y'),
            default => 'subscription:'.(int) $subscription->id,
        };
    }

    /**
     * @param  array{enabled:bool,quota_value:int,quota_period:string,unit:string,meta:array<string,mixed>}  $entitlement
     */
    private function isUnlimited(array $entitlement): bool
    {
        return (string) $entitlement['quota_period'] === 'unlimited'
            || (int) $entitlement['quota_value'] <= 0;
    }

    private function ledger(AdminPlanSubscription $subscription, string $resourceKey, string $type, int $amount, ?int $balanceAfter, array $context): AdminResourceLedger
    {
        return AdminResourceLedger::query()->create([
            'admin_id' => (int) $subscription->admin_id,
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
