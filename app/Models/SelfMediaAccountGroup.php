<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SelfMediaAccountGroup extends Model
{
    protected $fillable = [
        'site_id',
        'owner_admin_id',
        'provider',
        'external_group_id',
        'group_name',
        'is_default',
        'last_synced_at',
        'raw_response',
    ];

    protected $attributes = [
        'provider' => 'aitoearn',
        'group_name' => '',
        'is_default' => false,
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
            'is_default' => 'boolean',
            'last_synced_at' => 'datetime',
            'raw_response' => 'array',
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
}
