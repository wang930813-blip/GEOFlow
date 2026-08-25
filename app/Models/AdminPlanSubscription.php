<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdminPlanSubscription extends Model
{
    protected $fillable = [
        'admin_id',
        'site_id',
        'plan_id',
        'source_subscription_id',
        'inherited_from_admin_id',
        'mode',
        'status',
        'starts_at',
        'ends_at',
        'entitlements_snapshot',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'admin_id' => 'integer',
            'site_id' => 'integer',
            'plan_id' => 'integer',
            'source_subscription_id' => 'integer',
            'inherited_from_admin_id' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'entitlements_snapshot' => 'array',
        ];
    }

    public function scopeActiveNow(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->where(function (Builder $query): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function (Builder $query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            });
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlatformPlan::class, 'plan_id')->withTrashed();
    }

    public function sourceSubscription(): BelongsTo
    {
        return $this->belongsTo(SitePlanSubscription::class, 'source_subscription_id');
    }

    public function inheritedFromAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'inherited_from_admin_id');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(AdminResourceUsage::class, 'subscription_id');
    }
}
