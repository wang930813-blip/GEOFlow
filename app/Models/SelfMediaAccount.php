<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SelfMediaAccount extends Model
{
    protected $fillable = [
        'site_id',
        'owner_admin_id',
        'provider',
        'platform',
        'external_account_id',
        'account_name',
        'avatar',
        'status',
        'auth_status',
        'bound_at',
        'last_synced_at',
        'raw_account',
    ];

    protected $attributes = [
        'provider' => 'aitoearn',
        'account_name' => '',
        'avatar' => '',
        'status' => 'bound',
        'auth_status' => 'authorized',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
            'bound_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'raw_account' => 'array',
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

    public function publishItems(): HasMany
    {
        return $this->hasMany(SelfMediaPublishJobItem::class, 'self_media_account_id');
    }
}
