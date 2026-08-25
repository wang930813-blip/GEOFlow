<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteSubscriptionLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'site_id',
        'subscription_id',
        'action',
        'before_payload',
        'after_payload',
        'operator_admin_id',
        'remark',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'subscription_id' => 'integer',
            'operator_admin_id' => 'integer',
            'before_payload' => 'array',
            'after_payload' => 'array',
            'created_at' => 'datetime',
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
