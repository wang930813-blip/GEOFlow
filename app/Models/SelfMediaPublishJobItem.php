<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SelfMediaPublishJobItem extends Model
{
    protected $fillable = [
        'job_id',
        'self_media_account_id',
        'provider',
        'platform',
        'external_account_id',
        'external_task_id',
        'status',
        'progress',
        'message',
        'published_url',
        'published_at',
        'payload',
        'raw_response',
        'last_event_at',
    ];

    protected $attributes = [
        'provider' => 'aitoearn',
        'external_account_id' => '',
        'external_task_id' => '',
        'status' => 'queued',
        'progress' => 0,
        'message' => '',
        'published_url' => '',
    ];

    protected function casts(): array
    {
        return [
            'job_id' => 'integer',
            'self_media_account_id' => 'integer',
            'progress' => 'integer',
            'published_at' => 'datetime',
            'payload' => 'array',
            'raw_response' => 'array',
            'last_event_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(SelfMediaPublishJob::class, 'job_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SelfMediaAccount::class, 'self_media_account_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SelfMediaPublishEvent::class, 'job_item_id');
    }
}
