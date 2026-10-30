<?php

/**
 * Created by Codex.
 * @Date: 2026-10-30
 * @Time: 01:13:11
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： Image.php
 * @Description: 图片资源模型及站点归属、路径身份维护。
 */

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use App\Models\Concerns\BelongsToAdminOwner;
use App\Services\GeoFlow\ManagedImagePathHasherV1;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class Image extends Model
{
    use BelongsToSite;
    use BelongsToAdminOwner;

    public const UPDATED_AT = null;

    protected $table = 'images';

    protected $fillable = [
        'library_id',
        'site_id',
        'owner_admin_id',
        'filename',
        'original_name',
        'file_name',
        'file_path',
        'managed_path_hash',
        'file_size',
        'mime_type',
        'width',
        'height',
        'tags',
        'used_count',
        'usage_count',
    ];

    protected function casts(): array
    {
        return [
            'library_id' => 'integer',
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
            'file_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'used_count' => 'integer',
            'usage_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(static function (self $image): void {
            $managedPathHash = trim((string) $image->managed_path_hash);
            if ($managedPathHash !== '' && (! $image->exists || ! $image->isDirty('file_path') || $image->isDirty('managed_path_hash'))) {
                return;
            }

            $path = (string) $image->file_path;
            $hasher = app(ManagedImagePathHasherV1::class);

            try {
                $image->managed_path_hash = $hasher->hashManagedPathV1($path);
            } catch (InvalidArgumentException) {
                $image->managed_path_hash = $hasher->terminalHashV1($path);
            }
        });
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(ImageLibrary::class, 'library_id');
    }

    public function articleImages(): HasMany
    {
        return $this->hasMany(ArticleImage::class, 'image_id');
    }
}
