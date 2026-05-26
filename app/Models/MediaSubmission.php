<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaSubmission extends Model
{
    use BelongsToSite;

    protected $fillable = [
        'site_id',
        'article_id',
        'media_resource_id',
        'source_type',
        'external_order_nid',
        'title_snapshot',
        'content_snapshot',
        'cost_price_snapshot',
        'sale_price_snapshot',
        'points_amount',
        'status',
        'remark',
        'published_url',
        'last_error_message',
        'cancel_reason',
        'appeal_content',
        'submitted_by_admin_id',
        'submitted_at',
        'last_synced_at',
        'cancelled_at',
        'appealed_at',
        'raw_submit_response',
        'raw_status_response',
        'raw_cancel_response',
        'raw_appeal_response',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'article_id' => 'integer',
            'media_resource_id' => 'integer',
            'submitted_by_admin_id' => 'integer',
            'submitted_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'appealed_at' => 'datetime',
            'raw_submit_response' => 'array',
            'raw_status_response' => 'array',
            'raw_cancel_response' => 'array',
            'raw_appeal_response' => 'array',
            'cost_price_snapshot' => 'decimal:2',
            'sale_price_snapshot' => 'decimal:2',
            'points_amount' => 'decimal:2',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(MediaResource::class, 'media_resource_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'submitted_by_admin_id');
    }
}
