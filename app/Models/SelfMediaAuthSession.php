<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SelfMediaAuthSession extends Model
{
    protected $fillable = [
        'site_id',
        'owner_admin_id',
        'provider',
        'platform',
        'session_id',
        'authorization_url',
        'status',
        'expires_at',
        'confirmed_at',
        'confirmed_account_id',
        'raw_response',
    ];

    protected $attributes = [
        'provider' => 'aitoearn',
        'authorization_url' => '',
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'confirmed_account_id' => 'integer',
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

    public function confirmedAccount(): BelongsTo
    {
        return $this->belongsTo(SelfMediaAccount::class, 'confirmed_account_id');
    }
}
