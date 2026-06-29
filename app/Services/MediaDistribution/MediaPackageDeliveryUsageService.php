<?php

namespace App\Services\MediaDistribution;

use App\Models\AdminPlanSubscription;
use App\Models\MediaResource;
use App\Models\MediaSubmission;
use App\Support\MediaDistribution\MediaPlatform;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MediaPackageDeliveryUsageService
{
    public const OFFICIAL_RESOURCE_KEY = 'official_media_publishes';
    public const OFFICIAL_LABEL = '官媒累计投放';
    public const OFFICIAL_DESCRIPTION = '官媒套餐按 100 条计入，单篇官媒按 1 条计入';

    public const RESOURCE_KEY = 'b2b_website_publishes';
    public const LABEL = 'B2B行业网站累计投放';
    public const DESCRIPTION = '发布 1 次 B2B 网站套餐计入 200 条行业网站投放';

    /**
     * @param  Collection<int,AdminPlanSubscription>  $subscriptions
     * @return Collection<string,int>
     */
    public function deliveryCountsForSubscriptions(Collection $subscriptions): Collection
    {
        return $this->b2bDeliveryCountsForSubscriptions($subscriptions);
    }

    /**
     * @param  Collection<int,AdminPlanSubscription>  $subscriptions
     * @return Collection<string,array{official:int,b2b:int}>
     */
    public function deliveryStatsForSubscriptions(Collection $subscriptions): Collection
    {
        $officialCounts = $this->officialDeliveryCountsForSubscriptions($subscriptions);
        $b2bCounts = $this->b2bDeliveryCountsForSubscriptions($subscriptions);

        return $subscriptions
            ->map(fn (AdminPlanSubscription $subscription): string => $this->key((int) $subscription->admin_id, (int) $subscription->site_id))
            ->unique()
            ->mapWithKeys(fn (string $key): array => [
                $key => [
                    'official' => (int) $officialCounts->get($key, 0),
                    'b2b' => (int) $b2bCounts->get($key, 0),
                ],
            ]);
    }

    /**
     * @param  Collection<int,AdminPlanSubscription>  $subscriptions
     * @return Collection<string,int>
     */
    private function b2bDeliveryCountsForSubscriptions(Collection $subscriptions): Collection
    {
        $pairs = $subscriptions
            ->map(fn (AdminPlanSubscription $subscription): array => [
                'admin_id' => (int) $subscription->admin_id,
                'site_id' => (int) $subscription->site_id,
            ])
            ->filter(fn (array $pair): bool => $pair['admin_id'] > 0 && $pair['site_id'] > 0)
            ->unique(fn (array $pair): string => $this->key($pair['admin_id'], $pair['site_id']))
            ->values();

        if ($pairs->isEmpty()) {
            return collect();
        }

        $packageTitle = trim((string) config('media_distribution.b2b_package.title', '200家B2B网站套餐'));
        if ($packageTitle === '') {
            return collect();
        }

        $submissionTable = (new MediaSubmission)->getTable();
        $resourceTable = (new MediaResource)->getTable();
        $packageSize = $this->packageSize();
        $platformId = (int) config('media_distribution.b2b_package.platform_id', MediaPlatform::CEYING_MEDIA_1);

        return MediaSubmission::withoutGlobalScopes()
            ->join($resourceTable, $resourceTable.'.id', '=', $submissionTable.'.media_resource_id')
            ->select([
                $submissionTable.'.owner_admin_id',
                $submissionTable.'.site_id',
                DB::raw('COUNT('.$submissionTable.'.id) as orders_count'),
            ])
            ->whereIn($submissionTable.'.owner_admin_id', $pairs->pluck('admin_id')->all())
            ->whereIn($submissionTable.'.site_id', $pairs->pluck('site_id')->all())
            ->whereIn($submissionTable.'.status', ['submitted', 'publishing', 'published'])
            ->where($resourceTable.'.platform_id', $platformId)
            ->where($resourceTable.'.title', $packageTitle)
            ->groupBy($submissionTable.'.owner_admin_id', $submissionTable.'.site_id')
            ->get()
            ->mapWithKeys(fn (MediaSubmission $row): array => [
                $this->key((int) $row->owner_admin_id, (int) $row->site_id) => (int) $row->orders_count * $packageSize,
            ]);
    }

    /**
     * @param  Collection<int,AdminPlanSubscription>  $subscriptions
     * @return Collection<string,int>
     */
    private function officialDeliveryCountsForSubscriptions(Collection $subscriptions): Collection
    {
        $pairs = $subscriptions
            ->map(fn (AdminPlanSubscription $subscription): array => [
                'admin_id' => (int) $subscription->admin_id,
                'site_id' => (int) $subscription->site_id,
            ])
            ->filter(fn (array $pair): bool => $pair['admin_id'] > 0 && $pair['site_id'] > 0)
            ->unique(fn (array $pair): string => $this->key($pair['admin_id'], $pair['site_id']))
            ->values();

        if ($pairs->isEmpty()) {
            return collect();
        }

        $submissionTable = (new MediaSubmission)->getTable();
        $resourceTable = (new MediaResource)->getTable();
        $packageTitle = trim((string) config('media_distribution.package.title', '100家特价媒体套餐'));
        $packagePlatformId = (int) config('media_distribution.package.platform_id', MediaPlatform::CEYING_MEDIA_2);
        $packageSize = $this->officialPackageSize();
        $b2bTitle = trim((string) config('media_distribution.b2b_package.title', '200家B2B网站套餐'));
        $b2bPlatformId = (int) config('media_distribution.b2b_package.platform_id', MediaPlatform::CEYING_MEDIA_1);

        return MediaSubmission::withoutGlobalScopes()
            ->join($resourceTable, $resourceTable.'.id', '=', $submissionTable.'.media_resource_id')
            ->select([
                $submissionTable.'.owner_admin_id',
                $submissionTable.'.site_id',
            ])
            ->selectRaw(
                'SUM(CASE WHEN '.$resourceTable.'.platform_id = ? AND '.$resourceTable.'.title = ? THEN ? ELSE 1 END) as delivery_count',
                [$packagePlatformId, $packageTitle, $packageSize]
            )
            ->whereIn($submissionTable.'.owner_admin_id', $pairs->pluck('admin_id')->all())
            ->whereIn($submissionTable.'.site_id', $pairs->pluck('site_id')->all())
            ->whereIn($submissionTable.'.status', ['submitted', 'publishing', 'published'])
            ->where($resourceTable.'.source_type', MediaResource::SOURCE_WEBSITE)
            ->when($b2bTitle !== '', function ($query) use ($resourceTable, $b2bPlatformId, $b2bTitle): void {
                $query->where(function ($query) use ($resourceTable, $b2bPlatformId, $b2bTitle): void {
                    $query->where($resourceTable.'.platform_id', '!=', $b2bPlatformId)
                        ->orWhere($resourceTable.'.title', '!=', $b2bTitle);
                });
            })
            ->groupBy($submissionTable.'.owner_admin_id', $submissionTable.'.site_id')
            ->get()
            ->mapWithKeys(fn (MediaSubmission $row): array => [
                $this->key((int) $row->owner_admin_id, (int) $row->site_id) => (int) $row->delivery_count,
            ]);
    }

    public function packageSize(): int
    {
        return max(1, (int) config('media_distribution.b2b_package.size', 200));
    }

    public function officialPackageSize(): int
    {
        return max(1, (int) config('media_distribution.package.size', 100));
    }

    public function key(int $adminId, int $siteId): string
    {
        return $adminId.':'.$siteId;
    }

}
