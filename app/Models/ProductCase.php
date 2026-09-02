<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ProductCase extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_HIDDEN = 'hidden';

    protected $attributes = [
        'company_name' => '',
        'logo_url' => '',
        'cover_url' => '',
        'industry' => '',
        'region' => '',
        'business_mode' => '',
        'summary' => '',
        'content' => '',
        'customer_level' => '',
        'status' => self::STATUS_DRAFT,
        'sort_order' => 0,
        'view_count' => 0,
    ];

    protected $fillable = [
        'site_id',
        'owner_admin_id',
        'title',
        'slug',
        'company_name',
        'logo_url',
        'cover_url',
        'industry',
        'region',
        'business_mode',
        'module_tags',
        'summary',
        'content',
        'customer_level',
        'started_at',
        'status',
        'sort_order',
        'view_count',
        'published_at',
        'created_by_admin_id',
        'updated_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
            'module_tags' => 'array',
            'started_at' => 'date',
            'sort_order' => 'integer',
            'view_count' => 'integer',
            'published_at' => 'datetime',
            'created_by_admin_id' => 'integer',
            'updated_by_admin_id' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
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

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by_admin_id');
    }

    public static function uniqueSlug(string $title, ?self $ignore = null): string
    {
        $base = Str::slug($title);
        if ($base === '') {
            $base = 'case-'.Str::lower(Str::random(8));
        }

        $slug = $base;
        $suffix = 2;

        while (self::query()
            ->withTrashed()
            ->when($ignore instanceof self, fn (Builder $query): Builder => $query->whereKeyNot($ignore->id))
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    public function publicUrl(): string
    {
        return route('product-cases.show', ['slug' => $this->slug]);
    }
}
