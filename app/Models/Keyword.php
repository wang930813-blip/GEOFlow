<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use App\Models\Concerns\BelongsToAdminOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Keyword extends Model
{
    use BelongsToSite;
    use BelongsToAdminOwner;

    public const UPDATED_AT = null;

    protected $table = 'keywords';

    protected $fillable = [
        'library_id',
        'site_id',
        'owner_admin_id',
        'keyword',
        'used_count',
        'usage_count',
    ];

    protected function casts(): array
    {
        return [
            'library_id' => 'integer',
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
            'used_count' => 'integer',
            'usage_count' => 'integer',
        ];
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(KeywordLibrary::class, 'library_id');
    }

    public function questionVariants(): HasMany
    {
        return $this->hasMany(KeywordQuestionVariant::class, 'keyword_id');
    }
}
