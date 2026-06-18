<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrebeePublishJob extends Model
{
    protected $fillable = [
        'site_id',
        'owner_admin_id',
        'agent_id',
        'content_type',
        'title',
        'content_source_type',
        'status',
        'scheduled_at',
        'dispatch_started_at',
        'submitted_at',
        'finished_at',
        'quota_ledger_id',
        'failure_reason',
        'payload',
        'raw_response',
    ];

    protected $attributes = [
        'title' => '',
        'content_source_type' => 'manual',
        'status' => 'queued',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
            'agent_id' => 'integer',
            'scheduled_at' => 'datetime',
            'dispatch_started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'finished_at' => 'datetime',
            'quota_ledger_id' => 'integer',
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

    public function agent(): BelongsTo
    {
        return $this->belongsTo(CrebeeAgent::class, 'agent_id');
    }

    public function quotaLedger(): BelongsTo
    {
        return $this->belongsTo(AdminResourceLedger::class, 'quota_ledger_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CrebeePublishJobItem::class, 'job_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CrebeePublishEvent::class, 'job_id');
    }
}
