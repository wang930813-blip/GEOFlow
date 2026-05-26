<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaApiSetting extends Model
{
    protected $fillable = [
        'api_base_url',
        'api_key_ciphertext',
        'status',
        'price_multiplier',
        'last_checked_at',
        'last_error_message',
    ];

    protected $hidden = [
        'api_key_ciphertext',
    ];

    protected function casts(): array
    {
        return [
            'price_multiplier' => 'decimal:2',
            'last_checked_at' => 'datetime',
        ];
    }
}
