<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SelfMediaPublishJob extends Model
{
    protected $fillable = [
        'site_id',
        'owner_admin_id',
        'provider',
        'content_type',
        'title',
        'content_source_type',
        'status',
        'scheduled_at',
        'submitted_at',
        'finished_at',
        'quota_ledger_id',
        'external_flow_id',
        'sync_attempts',
        'failure_reason',
        'payload',
        'raw_response',
    ];

    protected $attributes = [
        'provider' => 'aitoearn',
        'title' => '',
        'content_source_type' => 'manual',
        'status' => 'queued',
        'external_flow_id' => '',
        'sync_attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
            'scheduled_at' => 'datetime',
            'submitted_at' => 'datetime',
            'finished_at' => 'datetime',
            'quota_ledger_id' => 'integer',
            'sync_attempts' => 'integer',
            'payload' => 'array',
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

    public function quotaLedger(): BelongsTo
    {
        return $this->belongsTo(AdminResourceLedger::class, 'quota_ledger_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SelfMediaPublishJobItem::class, 'job_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SelfMediaPublishEvent::class, 'job_id');
    }
}
