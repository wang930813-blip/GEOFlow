<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SelfMediaPublishEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'job_id',
        'job_item_id',
        'provider',
        'external_task_id',
        'event_type',
        'progress',
        'message',
        'raw_event',
        'created_at',
    ];

    protected $attributes = [
        'provider' => 'aitoearn',
        'external_task_id' => '',
        'message' => '',
    ];

    protected function casts(): array
    {
        return [
            'job_id' => 'integer',
            'job_item_id' => 'integer',
            'progress' => 'integer',
            'raw_event' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(SelfMediaPublishJob::class, 'job_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(SelfMediaPublishJobItem::class, 'job_item_id');
    }
}
