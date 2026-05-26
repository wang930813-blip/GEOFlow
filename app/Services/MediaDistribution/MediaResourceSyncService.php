<?php

namespace App\Services\MediaDistribution;

use App\Models\MediaResource;

class MediaResourceSyncService
{
    public function __construct(private readonly MediaDistributionClient $client) {}

    /**
     * @return array{synced:int}
     */
    public function syncAll(): array
    {
        $count = 0;

        foreach ([MediaResource::SOURCE_WEBSITE, MediaResource::SOURCE_ZI_MEDIA] as $sourceType) {
            foreach ($this->client->listResources($sourceType) as $row) {
                $resource = MediaResource::query()->firstOrNew([
                    'source_type' => $sourceType,
                    'external_resource_id' => (string) ($row['resource_id'] ?? ''),
                ]);
                if ((string) $resource->external_resource_id === '') {
                    continue;
                }

                $costPrice = $this->normalizeMoney($row['price'] ?? 0);
                $resource->fill([
                    'title' => trim((string) ($row['title'] ?? '')),
                    'category' => trim((string) ($row['category'] ?? $row['field'] ?? '')),
                    'remarks' => trim((string) ($row['remarks'] ?? '')),
                    'case_link' => trim((string) ($row['case_link'] ?? '')),
                    'status' => ((string) ($row['status'] ?? '1')) === '1' ? 'active' : 'inactive',
                    'cost_price' => $costPrice,
                    'raw_payload' => $row,
                    'last_synced_at' => now(),
                ]);
                if (! $resource->exists || (float) $resource->sale_price <= 0) {
                    $resource->sale_price = $costPrice;
                }
                $resource->save();
                $count++;
            }
        }

        return ['synced' => $count];
    }

    private function normalizeMoney(mixed $value): string
    {
        return number_format(max(0, (float) $value), 2, '.', '');
    }
}
