<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminCreditAccount extends Model
{
    protected $fillable = [
        'admin_id',
        'site_id',
        'balance',
        'frozen_balance',
        'total_granted',
        'total_consumed',
    ];

    protected function casts(): array
    {
        return [
            'admin_id' => 'integer',
            'site_id' => 'integer',
            'balance' => 'decimal:2',
            'frozen_balance' => 'decimal:2',
            'total_granted' => 'decimal:2',
            'total_consumed' => 'decimal:2',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
