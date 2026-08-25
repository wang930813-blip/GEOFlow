<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteResourceLedger extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'site_resource_ledger';

    protected $fillable = [
        'site_id',
        'subscription_id',
        'resource_key',
        'type',
        'amount',
        'balance_after',
        'actor_admin_id',
        'subject_type',
        'subject_id',
        'idempotency_key',
        'remark',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'subscription_id' => 'integer',
            'amount' => 'integer',
            'balance_after' => 'integer',
            'actor_admin_id' => 'integer',
            'subject_id' => 'integer',
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

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'actor_admin_id');
    }
}
