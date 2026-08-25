<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Site extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'owner_admin_id',
        'name',
        'domain',
        'status',
        'customer_mode',
        'agent_admin_id',
        'plan_status',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'owner_admin_id' => 'integer',
            'agent_admin_id' => 'integer',
            'settings' => 'array',
            'deleted_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'owner_admin_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Admin::class, 'site_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function agentAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'agent_admin_id');
    }

    public function planSubscriptions(): HasMany
    {
        return $this->hasMany(SitePlanSubscription::class);
    }
}
