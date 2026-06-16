<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminResourceUsage extends Model
{
    protected $fillable = [
        'admin_id',
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
            'admin_id' => 'integer',
            'site_id' => 'integer',
            'subscription_id' => 'integer',
            'used_amount' => 'integer',
            'reserved_amount' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(AdminPlanSubscription::class, 'subscription_id');
    }
}
