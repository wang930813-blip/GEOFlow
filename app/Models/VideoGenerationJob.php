<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoGenerationJob extends Model
{
    protected $fillable = [
        'site_id',
        'owner_admin_id',
        'created_by_admin_id',
        'title',
        'subject',
        'script',
        'terms',
        'negative_terms',
        'video_source',
        'video_aspect',
        'video_count',
        'cover_image',
        'status',
        'progress',
        'api_task_id',
        'request_payload',
        'result_payload',
        'videos',
        'combined_videos',
        'failure_reason',
        'quota_ledger_id',
        'started_at',
        'finished_at',
    ];

    protected $attributes = [
        'title' => '',
        'terms' => '',
        'negative_terms' => '',
        'video_source' => 'pexels',
        'video_aspect' => '9:16',
        'video_count' => 1,
        'cover_image' => '',
        'status' => 'queued',
        'progress' => 0,
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
            'created_by_admin_id' => 'integer',
            'video_count' => 'integer',
            'progress' => 'integer',
            'request_payload' => 'array',
            'result_payload' => 'array',
            'videos' => 'array',
            'combined_videos' => 'array',
            'quota_ledger_id' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function quotaLedger(): BelongsTo
    {
        return $this->belongsTo(AdminResourceLedger::class, 'quota_ledger_id');
    }

    public function firstVideoUrl(): string
    {
        $videos = (array) ($this->videos ?? []);
        $combinedVideos = (array) ($this->combined_videos ?? []);
        $url = (string) ($videos[0] ?? $combinedVideos[0] ?? '');

        return trim($url);
    }
}
