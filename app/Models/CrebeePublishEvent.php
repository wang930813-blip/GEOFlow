<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrebeePublishEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'job_id',
        'job_item_id',
        'crebee_task_id',
        'event_type',
        'progress',
        'message',
        'raw_event',
        'created_at',
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
        return $this->belongsTo(CrebeePublishJob::class, 'job_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(CrebeePublishJobItem::class, 'job_item_id');
    }
}
