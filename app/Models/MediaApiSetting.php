<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaApiSetting extends Model
{
    protected $fillable = [
        'platform_id',
        'api_base_url',
        'api_key_ciphertext',
        'app_id',
        'api_secret_ciphertext',
        'status',
        'price_multiplier',
        'last_checked_at',
        'last_error_message',
    ];

    protected $hidden = [
        'api_key_ciphertext',
        'api_secret_ciphertext',
    ];

    protected function casts(): array
    {
        return [
            'platform_id' => 'integer',
            'price_multiplier' => 'decimal:2',
            'last_checked_at' => 'datetime',
        ];
    }
}
