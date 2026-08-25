<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaResourceSyncRun extends Model
{
    protected $fillable = [
        'platform_id',
        'status',
        'current_source_type',
        'current_page',
        'website_synced',
        'zi_media_synced',
        'total_synced',
        'last_error_message',
        'started_by_admin_id',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'platform_id' => 'integer',
            'current_page' => 'integer',
            'website_synced' => 'integer',
            'zi_media_synced' => 'integer',
            'total_synced' => 'integer',
            'started_by_admin_id' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'started_by_admin_id');
    }

    public function isRunning(): bool
    {
        return in_array((string) $this->status, ['pending', 'running'], true);
    }

    public function displayLastErrorMessage(): string
    {
        if (trim((string) $this->last_error_message) === '') {
            return '';
        }

        return (string) $this->status === 'failed'
            ? '同步失败，请检查接口配置或网络连通性。'
            : '同步异常，请稍后重试或查看系统日志。';
    }
}
