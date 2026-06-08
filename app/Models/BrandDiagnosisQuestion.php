<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrandDiagnosisQuestion extends Model
{
    use BelongsToSite;

    protected $fillable = [
        'site_id',
        'run_id',
        'question',
        'question_type',
        'sort_order',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'run_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BrandDiagnosisRun::class, 'run_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(BrandDiagnosisResult::class, 'question_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(BrandDiagnosisSource::class, 'question_id');
    }

    public function brandMentions(): HasMany
    {
        return $this->hasMany(BrandDiagnosisBrandMention::class, 'question_id');
    }
}
