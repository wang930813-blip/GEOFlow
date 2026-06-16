<?php

namespace App\Models;

use App\Support\MediaDistribution\MediaPlatform;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MediaResource extends Model
{
    public const SOURCE_WEBSITE = 'website_media';
    public const SOURCE_ZI_MEDIA = 'zi_media';

    protected $fillable = [
        'platform_id',
        'source_type',
        'external_resource_id',
        'title',
        'category',
        'remarks',
        'case_link',
        'status',
        'cost_price',
        'sale_price',
        'raw_payload',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'platform_id' => 'integer',
            'raw_payload' => 'array',
            'last_synced_at' => 'datetime',
            'cost_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
        ];
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(MediaSubmission::class);
    }

    public function sitePrices(): HasMany
    {
        return $this->hasMany(MediaResourceSitePrice::class);
    }

    public function currentSitePrice(): HasOne
    {
        return $this->hasOne(MediaResourceSitePrice::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function sourceLabel(): string
    {
        return match ($this->source_type) {
            self::SOURCE_ZI_MEDIA => '第三方自媒体',
            default => '网站媒体',
        };
    }

    public function platformLabel(): string
    {
        return MediaPlatform::label((int) ($this->platform_id ?: MediaPlatform::CEYING_MEDIA_1));
    }

    /**
     * @param  list<string>|string  $keys
     */
    public function apiField(array|string $keys, string $default = '-'): string
    {
        $payload = is_array($this->raw_payload) ? $this->raw_payload : [];
        foreach ((array) $keys as $key) {
            $value = data_get($payload, $key);
            if ($value !== null && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return $default;
    }

    public function apiStatusLabel(): string
    {
        $status = $this->apiField('status', $this->status === 'active' ? '1' : '0');

        return in_array((string) $status, ['1', '2'], true) ? '可接单' : '不接单';
    }

    public function isConfiguredPackage(): bool
    {
        $packageTitle = trim((string) config('media_distribution.package.title', '100家特价媒体套餐'));

        return (int) $this->platform_id === (int) config('media_distribution.package.platform_id', MediaPlatform::CEYING_MEDIA_2)
            && $packageTitle !== ''
            && trim((string) $this->title) === $packageTitle;
    }

    public function packageSize(): int
    {
        $payloadSize = (int) $this->apiField(['package_size', 'media_count', 'resource_count'], '0');

        return $payloadSize > 0 ? $payloadSize : (int) config('media_distribution.package.size', 100);
    }

    public function packagePublishedUrlType(): string
    {
        $payloadType = trim($this->apiField(['publish_url_type', 'published_url_type', 'result_url_type'], ''));

        return $payloadType !== '' ? $payloadType : (string) config('media_distribution.package.published_url_type', 'docs 文档链接');
    }
}
