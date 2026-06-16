<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use App\Models\Concerns\BelongsToAdminOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KeywordQuestionVariant extends Model
{
    use BelongsToSite;
    use BelongsToAdminOwner;

    protected $table = 'keyword_question_variants';

    protected $fillable = [
        'site_id',
        'owner_admin_id',
        'keyword_id',
        'question',
    ];

    protected function casts(): array
    {
        return [
            'keyword_id' => 'integer',
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
        ];
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'keyword_id');
    }
}
