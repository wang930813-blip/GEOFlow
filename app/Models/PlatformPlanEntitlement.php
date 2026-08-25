<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformPlanEntitlement extends Model
{
    protected $fillable = [
        'plan_id',
        'resource_key',
        'enabled',
        'quota_value',
        'quota_period',
        'unit',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'plan_id' => 'integer',
            'enabled' => 'boolean',
            'quota_value' => 'integer',
            'meta' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlatformPlan::class, 'plan_id');
    }
}
