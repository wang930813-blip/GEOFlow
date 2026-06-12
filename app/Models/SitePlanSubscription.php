<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SitePlanSubscription extends Model
{
    protected $fillable = [
        'site_id',
        'plan_id',
        'mode',
        'owner_admin_id',
        'agent_admin_id',
        'assigned_by_admin_id',
        'status',
        'starts_at',
        'ends_at',
        'entitlements_snapshot',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'plan_id' => 'integer',
            'owner_admin_id' => 'integer',
            'agent_admin_id' => 'integer',
            'assigned_by_admin_id' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'entitlements_snapshot' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlatformPlan::class, 'plan_id');
    }

    public function ownerAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'owner_admin_id');
    }

    public function agentAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'agent_admin_id');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(SiteResourceUsage::class, 'subscription_id');
    }
}
