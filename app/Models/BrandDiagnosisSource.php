<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandDiagnosisSource extends Model
{
    use BelongsToSite;

    protected $fillable = [
        'site_id',
        'run_id',
        'question_id',
        'result_id',
        'platform',
        'title',
        'url',
        'domain',
        'source_type',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'run_id' => 'integer',
            'question_id' => 'integer',
            'result_id' => 'integer',
            'meta' => 'array',
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

    public function result(): BelongsTo
    {
        return $this->belongsTo(BrandDiagnosisResult::class, 'result_id');
    }
}
