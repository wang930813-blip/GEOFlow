<?php

namespace App\Services\MediaDistribution;

use App\Models\MediaApiSetting;
use App\Models\MediaResource;
use App\Models\MediaResourceSyncRun;
use App\Support\MediaDistribution\MediaPlatform;
use Carbon\CarbonInterface;

class MediaResourceSyncService
{
    public function __construct(private readonly MediaPlatformClientManager $clients) {}

    /**
     * @return array{synced:int}
     */
    public function syncAll(?MediaResourceSyncRun $run = null, ?int $platformId = null): array
    {
        $platformId = $platformId ?? (int) ($run?->platform_id ?: MediaPlatform::CEYING_MEDIA_1);
        $client = $this->clients->forPlatform($platformId);
        $sourceTypes = [MediaResource::SOURCE_WEBSITE, MediaResource::SOURCE_ZI_MEDIA];
        $isResume = $run?->isRunning()
            && in_array((string) $run->current_source_type, $sourceTypes, true)
            && (int) $run->current_page > 0;
        $sourceCounts = $this->initialSourceCounts($run, $isResume);
        $count = array_sum($sourceCounts);
        $syncTimestamp = now()->startOfSecond();
        $multiplier = (float) (MediaApiSetting::query()
            ->where('platform_id', $platformId)
            ->orderByDesc('id')
            ->value('price_multiplier') ?? 1);

        $run?->update([
            'platform_id' => $platformId,
            'status' => 'running',
            'started_at' => $run->started_at ?? now(),
            'completed_at' => null,
            'last_error_message' => null,
        ]);

        foreach ($this->sourceResumePlan($sourceTypes, $run, $isResume) as $sourceType => $startPage) {
            foreach ($client->resourcePages($sourceType, $startPage) as $page => $rows) {
                foreach ($rows as $row) {
                    $resource = MediaResource::query()->firstOrNew([
                        'platform_id' => $platformId,
                        'source_type' => $sourceType,
                        'external_resource_id' => $this->firstFilled($row, ['resource_id', 'id', 'nid']),
                    ]);
                    if ((string) $resource->external_resource_id === '') {
                        continue;
                    }

                    $costPrice = $this->normalizeMoney($row['price'] ?? 0);
                    $resource->fill([
                        'platform_id' => $platformId,
                        'title' => $this->firstFilled($row, ['title', 'media_name', 'name', 'site_name', 'account_name'], (string) $resource->external_resource_id),
                        'category' => $this->firstFilled($row, ['category', 'field', 'type_name', 'class_name', 'platform']),
                        'remarks' => $this->firstFilled($row, ['remarks', 'remark', 'description', 'desc']),
                        'case_link' => $this->limitText($this->firstFilled($row, ['case_link', 'case_url', 'entrance_link', 'url', 'link']), 500),
                        'status' => $this->isActiveStatus($row['status'] ?? '1', $platformId) ? 'active' : 'inactive',
                        'cost_price' => $costPrice,
                        'raw_payload' => $this->rawPayload($row, $platformId),
                        'last_synced_at' => $syncTimestamp,
                    ]);
                    $resource->sale_price = $this->multiplyMoney($costPrice, $multiplier);
                    $resource->save();
                    $count++;
                    $sourceCounts[$sourceType]++;
                }

                $this->markRunProgress($run, $sourceType, (int) $page, $sourceCounts, $count);
            }

            $this->markMissingResourcesInactive($platformId, $sourceType, $syncTimestamp);
        }

        $run?->update([
            'status' => 'completed',
            'current_source_type' => '',
            'current_page' => 0,
            'website_synced' => $sourceCounts[MediaResource::SOURCE_WEBSITE],
            'zi_media_synced' => $sourceCounts[MediaResource::SOURCE_ZI_MEDIA],
            'total_synced' => $count,
            'completed_at' => now(),
        ]);

        return ['synced' => $count];
    }

    /**
     * @return array<string,int>
     */
    private function initialSourceCounts(?MediaResourceSyncRun $run, bool $isResume): array
    {
        if ($isResume && $run) {
            return [
                MediaResource::SOURCE_WEBSITE => (int) $run->website_synced,
                MediaResource::SOURCE_ZI_MEDIA => (int) $run->zi_media_synced,
            ];
        }

        return [
            MediaResource::SOURCE_WEBSITE => 0,
            MediaResource::SOURCE_ZI_MEDIA => 0,
        ];
    }

    /**
     * @param  list<string>  $sourceTypes
     * @return array<string,int>
     */
    private function sourceResumePlan(array $sourceTypes, ?MediaResourceSyncRun $run, bool $isResume): array
    {
        if (! $isResume || ! $run) {
            return array_fill_keys($sourceTypes, 1);
        }

        $currentSourceType = (string) $run->current_source_type;
        $plan = [];
        $resumeFound = false;

        foreach ($sourceTypes as $sourceType) {
            if (! $resumeFound && $sourceType !== $currentSourceType) {
                continue;
            }

            $resumeFound = true;
            $plan[$sourceType] = $sourceType === $currentSourceType
                ? (int) $run->current_page + 1
                : 1;
        }

        return $plan;
    }

    private function normalizeMoney(mixed $value): string
    {
        return number_format(max(0, (float) $value), 2, '.', '');
    }

    private function multiplyMoney(string $value, float $multiplier): string
    {
        return number_format(max(0, (float) $value * $multiplier), 2, '.', '');
    }

    private function isActiveStatus(mixed $status, int $platformId): bool
    {
        return (string) $status === ($platformId === MediaPlatform::CEYING_MEDIA_2 ? '2' : '1');
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function rawPayload(array $row, int $platformId): array
    {
        if ($platformId !== MediaPlatform::CEYING_MEDIA_2) {
            return $row;
        }

        return $row + [
            'publish_rate' => $row['published_rate'] ?? null,
            'pc_weigh' => $row['pc_weight'] ?? null,
            'wap_weight' => $row['mobile_weight'] ?? null,
            'status_label' => $row['status'] ?? null,
        ];
    }

    /**
     * @param  array<string,int>  $sourceCounts
     */
    private function markRunProgress(?MediaResourceSyncRun $run, string $sourceType, int $page, array $sourceCounts, int $total): void
    {
        if (! $run) {
            return;
        }

        $run->update([
            'status' => 'running',
            'current_source_type' => $sourceType,
            'current_page' => $page,
            'website_synced' => $sourceCounts[MediaResource::SOURCE_WEBSITE] ?? 0,
            'zi_media_synced' => $sourceCounts[MediaResource::SOURCE_ZI_MEDIA] ?? 0,
            'total_synced' => $total,
            'completed_at' => null,
        ]);
    }

    private function markMissingResourcesInactive(int $platformId, string $sourceType, CarbonInterface $syncTimestamp): void
    {
        MediaResource::query()
            ->where('platform_id', $platformId)
            ->where('source_type', $sourceType)
            ->where('status', 'active')
            ->where(function ($query) use ($syncTimestamp): void {
                $query->whereNull('last_synced_at')
                    ->orWhere('last_synced_at', '<>', $syncTimestamp);
            })
            ->update(['status' => 'inactive']);
    }

    private function limitText(string $value, int $limit): string
    {
        return mb_substr($value, 0, $limit);
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  list<string>  $keys
     */
    private function firstFilled(array $row, array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            if ($value !== null && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return $default;
    }
}
