<?php

namespace App\Services\MediaDistribution;

use App\Models\MediaApiSetting;
use App\Models\MediaResource;
use App\Models\MediaResourceSyncRun;

class MediaResourceSyncService
{
    public function __construct(private readonly MediaDistributionClient $client) {}

    /**
     * @return array{synced:int}
     */
    public function syncAll(?MediaResourceSyncRun $run = null): array
    {
        $count = 0;
        $sourceCounts = [
            MediaResource::SOURCE_WEBSITE => 0,
            MediaResource::SOURCE_ZI_MEDIA => 0,
        ];
        $multiplier = (float) (MediaApiSetting::query()->orderByDesc('id')->value('price_multiplier') ?? 1);

        $run?->update([
            'status' => 'running',
            'started_at' => $run->started_at ?? now(),
            'last_error_message' => null,
        ]);

        foreach ([MediaResource::SOURCE_WEBSITE, MediaResource::SOURCE_ZI_MEDIA] as $sourceType) {
            foreach ($this->client->resourcePages($sourceType) as $page => $rows) {
                foreach ($rows as $row) {
                    $resource = MediaResource::query()->firstOrNew([
                        'source_type' => $sourceType,
                        'external_resource_id' => $this->firstFilled($row, ['resource_id', 'id', 'nid']),
                    ]);
                    if ((string) $resource->external_resource_id === '') {
                        continue;
                    }

                    $costPrice = $this->normalizeMoney($row['price'] ?? 0);
                    $resource->fill([
                        'title' => $this->firstFilled($row, ['title', 'media_name', 'name', 'site_name', 'account_name'], (string) $resource->external_resource_id),
                        'category' => $this->firstFilled($row, ['category', 'field', 'type_name', 'class_name']),
                        'remarks' => $this->firstFilled($row, ['remarks', 'remark', 'description', 'desc']),
                        'case_link' => $this->firstFilled($row, ['case_link', 'case_url', 'url', 'link']),
                        'status' => ((string) ($row['status'] ?? '1')) === '1' ? 'active' : 'inactive',
                        'cost_price' => $costPrice,
                        'raw_payload' => $row,
                        'last_synced_at' => now(),
                    ]);
                    $resource->sale_price = $this->multiplyMoney($costPrice, $multiplier);
                    $resource->save();
                    $count++;
                    $sourceCounts[$sourceType]++;
                }

                $this->markRunProgress($run, $sourceType, (int) $page, $sourceCounts, $count);
            }
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

    private function normalizeMoney(mixed $value): string
    {
        return number_format(max(0, (float) $value), 2, '.', '');
    }

    private function multiplyMoney(string $value, float $multiplier): string
    {
        return number_format(max(0, (float) $value * $multiplier), 2, '.', '');
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
        ]);
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
