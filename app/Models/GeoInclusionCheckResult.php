<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use App\Models\Concerns\BelongsToAdminOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoInclusionCheckResult extends Model
{
    use BelongsToSite;
    use BelongsToAdminOwner;

    protected $table = 'geo_inclusion_check_results';

    protected $fillable = [
        'site_id',
        'owner_admin_id',
        'run_id',
        'keyword_library_id',
        'keyword_id',
        'question_variant_id',
        'platform',
        'question',
        'answer',
        'keyword_hit',
        'brand_hit',
        'status',
        'error_message',
        'meta',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'run_id' => 'integer',
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
            'keyword_library_id' => 'integer',
            'keyword_id' => 'integer',
            'question_variant_id' => 'integer',
            'keyword_hit' => 'boolean',
            'brand_hit' => 'boolean',
            'meta' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(GeoInclusionCheckRun::class, 'run_id');
    }

    public function keywordLibrary(): BelongsTo
    {
        return $this->belongsTo(KeywordLibrary::class, 'keyword_library_id');
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'keyword_id');
    }

    public function questionVariant(): BelongsTo
    {
        return $this->belongsTo(KeywordQuestionVariant::class, 'question_variant_id');
    }
}
