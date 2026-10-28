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

    protected $hidden = [
        'custom_https_private_key',
    ];

    protected $attributes = [
        'custom_domain_deployment_status' => 'manual',
    ];

    protected $fillable = [
        'owner_admin_id',
        'name',
        'domain',
        'custom_domain',
        'custom_https_enabled',
        'custom_https_certificate',
        'custom_https_private_key',
        'custom_domain_deployment_status',
        'custom_domain_deployment_error',
        'custom_domain_deployed_at',
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
            'custom_domain' => 'string',
            'custom_https_enabled' => 'boolean',
            'custom_https_certificate' => 'encrypted',
            'custom_https_private_key' => 'encrypted',
            'custom_domain_deployed_at' => 'datetime',
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
