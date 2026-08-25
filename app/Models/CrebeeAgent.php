<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrebeeAgent extends Model
{
    protected $fillable = [
        'name',
        'agent_uid',
        'secret_hash',
        'status',
        'last_seen_at',
        'crebee_base_url',
        'crebee_status',
        'version',
        'site_scope',
        'meta',
    ];

    protected $hidden = [
        'secret_hash',
    ];

    protected $attributes = [
        'status' => 'active',
        'crebee_base_url' => 'http://127.0.0.1:3456',
        'crebee_status' => 'unknown',
        'version' => '',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'site_scope' => 'array',
            'meta' => 'array',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(CrebeeAccount::class, 'agent_id');
    }

    public function publishJobs(): HasMany
    {
        return $this->hasMany(CrebeePublishJob::class, 'agent_id');
    }
}
