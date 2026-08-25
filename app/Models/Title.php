<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use App\Models\Concerns\BelongsToAdminOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Title extends Model
{
    use BelongsToSite;
    use BelongsToAdminOwner;

    public const UPDATED_AT = null;

    protected $table = 'titles';

    protected $fillable = [
        'library_id',
        'site_id',
        'owner_admin_id',
        'title',
        'keyword',
        'is_ai_generated',
        'used_count',
        'usage_count',
    ];

    protected function casts(): array
    {
        return [
            'library_id' => 'integer',
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
            'is_ai_generated' => 'boolean',
            'used_count' => 'integer',
            'usage_count' => 'integer',
        ];
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(TitleLibrary::class, 'library_id');
    }
}
