<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ManualPublishStatEntry extends Model
{
    use SoftDeletes;

    public const TYPE_MEDIA = 'media';
    public const TYPE_SELF_MEDIA = 'self_media';
    public const TYPE_B2B = 'b2b';
    public const TYPE_OFFICIAL_SITE = 'official_site';
    public const TYPE_VIDEO = 'video';

    /**
     * @var array<string,string>
     */
    public const TYPE_LABELS = [
        self::TYPE_MEDIA => '媒体发布',
        self::TYPE_SELF_MEDIA => '自媒体发布',
        self::TYPE_B2B => 'B2B 发布',
        self::TYPE_OFFICIAL_SITE => '官网发布',
        self::TYPE_VIDEO => '视频发布',
    ];

    /**
     * @var array<string,string>
     */
    public const TYPE_COLORS = [
        self::TYPE_MEDIA => '#4f46e5',
        self::TYPE_SELF_MEDIA => '#0ea5e9',
        self::TYPE_B2B => '#10b981',
        self::TYPE_OFFICIAL_SITE => '#f59e0b',
        self::TYPE_VIDEO => '#ef4444',
    ];

    protected $fillable = [
        'site_id',
        'owner_admin_id',
        'created_by_admin_id',
        'metric_type',
        'quantity',
        'stat_date',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
            'created_by_admin_id' => 'integer',
            'quantity' => 'integer',
            'stat_date' => 'date',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public static function typeKeys(): array
    {
        return array_keys(self::TYPE_LABELS);
    }

    public static function labelFor(string $type): string
    {
        return self::TYPE_LABELS[$type] ?? $type;
    }

    public static function colorFor(string $type): string
    {
        return self::TYPE_COLORS[$type] ?? '#64748b';
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'owner_admin_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }
}
