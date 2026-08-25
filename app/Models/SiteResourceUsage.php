<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteResourceUsage extends Model
{
    protected $fillable = [
        'site_id',
        'subscription_id',
        'resource_key',
        'period_key',
        'used_amount',
        'reserved_amount',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'subscription_id' => 'integer',
            'used_amount' => 'integer',
            'reserved_amount' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(SitePlanSubscription::class, 'subscription_id');
    }
}
