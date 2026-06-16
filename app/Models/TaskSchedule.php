<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use App\Models\Concerns\BelongsToAdminOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskSchedule extends Model
{
    use BelongsToSite;
    use BelongsToAdminOwner;

    protected $table = 'task_schedules';

    protected $fillable = [
        'site_id',
        'owner_admin_id',
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
            'owner_admin_id' => 'integer',
            'next_run_time' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }
}
