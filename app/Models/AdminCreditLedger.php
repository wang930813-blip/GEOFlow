<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminCreditLedger extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'admin_credit_ledger';

    protected $fillable = [
        'admin_id',
        'site_id',
        'submission_id',
        'type',
        'amount',
        'balance_after',
        'frozen_after',
        'operator_admin_id',
        'remark',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'admin_id' => 'integer',
            'site_id' => 'integer',
            'submission_id' => 'integer',
            'operator_admin_id' => 'integer',
            'created_at' => 'datetime',
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'frozen_after' => 'decimal:2',
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

    public function submission(): BelongsTo
    {
        return $this->belongsTo(MediaSubmission::class, 'submission_id');
    }
}
