<?php

namespace App\Services\Admin;

use App\Models\ManualPublishStatEntry;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Models\SitePlanSubscription;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ManualPublishStatService
{
    /**
     * @return array{
     *     total:int,
     *     by_type: array<string, array{label:string, quantity:int}>
     * }
     */
    public function summaryForSite(Site $site): array
    {
        $rows = $this->baseQuery($site)
            ->selectRaw('metric_type, SUM(quantity) as quantity')
            ->groupBy('metric_type')
            ->get();

        $byType = [];
        foreach (ManualPublishStatEntry::typeKeys() as $type) {
            $byType[$type] = [
                'label' => ManualPublishStatEntry::labelFor($type),
                'quantity' => 0,
            ];
        }

        foreach ($rows as $row) {
            $type = (string) $row->metric_type;
            if (! isset($byType[$type])) {
                continue;
            }

            $byType[$type]['quantity'] = (int) $row->quantity;
        }

        return [
            'total' => (int) array_sum(array_map(static fn (array $item): int => (int) $item['quantity'], $byType)),
            'by_type' => $byType,
        ];
    }

    /**
     * @return Collection<int,array{date:string, values: array<string,int>}>
     */
    public function dailyChartRowsForSite(Site $site, int $days = 30): Collection
    {
        $startDate = now()->startOfDay()->subDays(max(1, $days - 1));
        $rows = $this->baseQuery($site)
            ->whereDate('stat_date', '>=', $startDate->toDateString())
            ->selectRaw('stat_date, metric_type, SUM(quantity) as quantity')
            ->groupBy('stat_date', 'metric_type')
            ->orderBy('stat_date')
            ->get();

        $calendar = [];
        for ($cursor = $startDate->copy(); $cursor->lte(now()->startOfDay()); $cursor->addDay()) {
            $calendar[$cursor->toDateString()] = [
                'date' => $cursor->toDateString(),
                'values' => array_fill_keys(ManualPublishStatEntry::typeKeys(), 0),
            ];
        }

        foreach ($rows as $row) {
            $date = $row->stat_date instanceof CarbonInterface
                ? $row->stat_date->toDateString()
                : Carbon::parse((string) $row->stat_date)->toDateString();
            $type = (string) $row->metric_type;
            if (! isset($calendar[$date], $calendar[$date]['values'][$type])) {
                continue;
            }

            $calendar[$date]['values'][$type] = (int) $row->quantity;
        }

        return collect(array_values($calendar));
    }

    /**
     * @return Collection<int,array{type:string,label:string,quantity:int,percent:float,color:string}>
     */
    public function pieSegmentsForSite(Site $site): Collection
    {
        $summary = $this->summaryForSite($site);
        $total = max(1, (int) $summary['total']);

        return collect($summary['by_type'])
            ->map(function (array $item, string $type) use ($total): array {
                $quantity = (int) $item['quantity'];

                return [
                    'type' => $type,
                    'label' => (string) $item['label'],
                    'quantity' => $quantity,
                    'percent' => round($quantity / $total * 100, 2),
                    'color' => ManualPublishStatEntry::colorFor($type),
                ];
            })
            ->values();
    }

    /**
     * @return Collection<int,array{type:string,label:string,resource_key:string|null,used:int,quota:int|null,remaining:int|null,percent:int,is_unlimited:bool,color:string}>
     */
    public function progressRowsForSite(Site $site): Collection
    {
        $summary = $this->summaryForSite($site);
        $snapshot = $this->activeEntitlementSnapshotForSite($site);

        return collect(ManualPublishStatEntry::typeKeys())
            ->map(function (string $type) use ($summary, $snapshot): array {
                $used = (int) data_get($summary, 'by_type.'.$type.'.quantity', 0);
                $resourceKey = ManualPublishStatEntry::resourceKeyFor($type);
                $entitlement = $resourceKey !== null ? (array) ($snapshot[$resourceKey] ?? []) : [];
                $enabled = (bool) ($entitlement['enabled'] ?? false);
                $quotaValue = (int) ($entitlement['quota_value'] ?? 0);
                $isUnlimited = $enabled && $quotaValue <= 0;
                $quota = $enabled && ! $isUnlimited ? $quotaValue : ($isUnlimited ? null : 0);
                $percent = $isUnlimited
                    ? 100
                    : ($quota > 0 ? min(100, (int) round($used / max(1, $quota) * 100)) : 0);

                return [
                    'type' => $type,
                    'label' => ManualPublishStatEntry::labelFor($type),
                    'resource_key' => $resourceKey,
                    'used' => $used,
                    'quota' => $quota,
                    'remaining' => $isUnlimited ? null : max(0, (int) $quota - $used),
                    'percent' => $percent,
                    'is_unlimited' => $isUnlimited,
                    'color' => ManualPublishStatEntry::colorFor($type),
                ];
            })
            ->values();
    }

    public function entriesForSite(Site $site, int $perPage = 15): LengthAwarePaginator
    {
        return $this->baseQuery($site)
            ->with(['owner:id,username,display_name', 'createdBy:id,username,display_name'])
            ->orderByDesc('stat_date')
            ->orderByDesc('id')
            ->paginate(max(1, min(100, $perPage)))
            ->withQueryString();
    }

    public function createEntry(Site $site, int $createdByAdminId, string $metricType, int $quantity, string $statDate, string $remark = ''): ManualPublishStatEntry
    {
        return ManualPublishStatEntry::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $site->owner_admin_id,
            'created_by_admin_id' => $createdByAdminId,
            'metric_type' => $metricType,
            'quantity' => $quantity,
            'stat_date' => $statDate,
            'remark' => $remark !== '' ? $remark : null,
        ]);
    }

    public function deleteEntry(ManualPublishStatEntry $entry): void
    {
        $entry->delete();
    }

    private function baseQuery(Site $site): Builder
    {
        return ManualPublishStatEntry::query()
            ->where('site_id', (int) $site->id);
    }

    /**
     * @return array<string,array{enabled:bool,quota_value:int,quota_period:string,unit:string,meta:array<string,mixed>}>
     */
    private function activeEntitlementSnapshotForSite(Site $site): array
    {
        $subscription = SitePlanSubscription::query()
            ->where('site_id', (int) $site->id)
            ->where('status', 'active')
            ->where(function (Builder $query): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function (Builder $query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->orderByDesc('ends_at')
            ->orderByDesc('id')
            ->first();

        if (! $subscription instanceof SitePlanSubscription) {
            return [];
        }

        return collect((array) $subscription->entitlements_snapshot)
            ->filter(fn ($entitlement, string $resourceKey): bool => is_array($entitlement)
                && array_key_exists($resourceKey, PlatformPlan::resourceCatalog()))
            ->map(fn (array $entitlement): array => [
                'enabled' => (bool) ($entitlement['enabled'] ?? false),
                'quota_value' => (int) ($entitlement['quota_value'] ?? 0),
                'quota_period' => (string) ($entitlement['quota_period'] ?? 'cycle'),
                'unit' => (string) ($entitlement['unit'] ?? 'items'),
                'meta' => (array) ($entitlement['meta'] ?? []),
            ])
            ->all();
    }
}
