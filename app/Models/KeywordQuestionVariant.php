<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KeywordQuestionVariant extends Model
{
    protected $table = 'keyword_question_variants';

    protected $fillable = [
        'keyword_id',
        'question',
    ];

    protected function casts(): array
    {
        return [
            'keyword_id' => 'integer',
        ];
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'keyword_id');
    }
}
