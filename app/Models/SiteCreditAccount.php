<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteCreditAccount extends Model
{
    protected $fillable = [
        'site_id',
        'balance',
        'frozen_balance',
        'total_recharged',
        'total_consumed',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'balance' => 'decimal:2',
            'frozen_balance' => 'decimal:2',
            'total_recharged' => 'decimal:2',
            'total_consumed' => 'decimal:2',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
