<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteCreditLedger extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'site_credit_ledger';

    protected $fillable = [
        'site_id',
        'submission_id',
        'type',
        'amount',
        'balance_after',
        'frozen_after',
        'remark',
        'operator_admin_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'submission_id' => 'integer',
            'operator_admin_id' => 'integer',
            'created_at' => 'datetime',
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'frozen_after' => 'decimal:2',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(MediaSubmission::class, 'submission_id');
    }
}
