<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrebeeBindRequest extends Model
{
    protected $fillable = [
        'site_id',
        'owner_admin_id',
        'agent_id',
        'operator_admin_id',
        'platform',
        'status',
        'failure_reason',
        'meta',
        'requested_at',
        'confirmed_at',
        'expired_at',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
            'agent_id' => 'integer',
            'operator_admin_id' => 'integer',
            'meta' => 'array',
            'requested_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'owner_admin_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(CrebeeAgent::class, 'agent_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'operator_admin_id');
    }
}
