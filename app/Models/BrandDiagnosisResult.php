<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrandDiagnosisResult extends Model
{
    use BelongsToSite;

    protected $fillable = [
        'site_id',
        'run_id',
        'question_id',
        'platform',
        'answer',
        'brand_mentioned',
        'mention_count',
        'mention_rank',
        'sentiment',
        'status',
        'error_message',
        'raw_response',
        'meta',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'run_id' => 'integer',
            'question_id' => 'integer',
            'brand_mentioned' => 'boolean',
            'mention_count' => 'integer',
            'mention_rank' => 'integer',
            'raw_response' => 'array',
            'meta' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BrandDiagnosisRun::class, 'run_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(BrandDiagnosisQuestion::class, 'question_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(BrandDiagnosisSource::class, 'result_id');
    }
}
