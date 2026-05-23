<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskSchedule extends Model
{
    use BelongsToSite;

    protected $table = 'task_schedules';

    protected $fillable = [
        'site_id',
        'task_id',
        'next_run_time',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'task_id' => 'integer',
            'site_id' => 'integer',
            'next_run_time' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }
}
