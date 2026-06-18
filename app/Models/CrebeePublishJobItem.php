<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrebeePublishJobItem extends Model
{
    protected $fillable = [
        'job_id',
        'crebee_account_id',
        'platform',
        'crebee_task_id',
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
        'status' => 'queued',
        'progress' => 0,
        'message' => '',
        'published_url' => '',
    ];

    protected function casts(): array
    {
        return [
            'job_id' => 'integer',
            'crebee_account_id' => 'integer',
            'progress' => 'integer',
            'published_at' => 'datetime',
            'payload' => 'array',
            'raw_response' => 'array',
            'last_event_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(CrebeePublishJob::class, 'job_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CrebeeAccount::class, 'crebee_account_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CrebeePublishEvent::class, 'job_item_id');
    }
}
