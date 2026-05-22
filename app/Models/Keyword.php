<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Keyword extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'keywords';

    protected $fillable = [
        'library_id',
        'keyword',
        'used_count',
        'usage_count',
    ];

    protected function casts(): array
    {
        return [
            'library_id' => 'integer',
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
