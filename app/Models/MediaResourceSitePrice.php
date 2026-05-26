<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaResourceSitePrice extends Model
{
    protected $fillable = [
        'site_id',
        'media_resource_id',
        'sale_price',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'media_resource_id' => 'integer',
            'sale_price' => 'decimal:2',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(MediaResource::class, 'media_resource_id');
    }
}
