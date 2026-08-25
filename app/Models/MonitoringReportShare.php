<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoringReportShare extends Model
{
    protected $fillable = [
        'token_hash',
        'report_type',
        'site_id',
        'owner_admin_id',
        'created_by_admin_id',
        'title',
        'payload',
        'use_virtual_search_report_data',
        'expires_at',
        'last_viewed_at',
        'view_count',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'use_virtual_search_report_data' => 'boolean',
            'expires_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'view_count' => 'integer',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function ownerAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'owner_admin_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }
}
