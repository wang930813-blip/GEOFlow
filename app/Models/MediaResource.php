<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MediaResource extends Model
{
    public const SOURCE_WEBSITE = 'website_media';
    public const SOURCE_ZI_MEDIA = 'zi_media';

    protected $fillable = [
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
}
