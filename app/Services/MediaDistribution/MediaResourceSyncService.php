<?php

namespace App\Services\MediaDistribution;

use App\Models\MediaApiSetting;
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
        $multiplier = (float) (MediaApiSetting::query()->orderByDesc('id')->value('price_multiplier') ?? 1);

        foreach ([MediaResource::SOURCE_WEBSITE, MediaResource::SOURCE_ZI_MEDIA] as $sourceType) {
            foreach ($this->client->listResources($sourceType) as $row) {
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
            }
        }

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
