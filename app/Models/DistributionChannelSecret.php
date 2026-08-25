<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DistributionChannelSecret extends Model
{
    use BelongsToSite;

    protected $fillable = [
        'site_id',
        'distribution_channel_id',
        'key_id',
        'secret_ciphertext',
        'status',
        'scopes',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'distribution_channel_id' => 'integer',
            'site_id' => 'integer',
            'scopes' => 'array',
            'last_used_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(DistributionChannel::class, 'distribution_channel_id');
    }
}
