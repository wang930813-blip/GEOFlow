<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoInclusionCheckRun extends Model
{
    protected $table = 'geo_inclusion_check_runs';

    protected $fillable = [
        'keyword_library_id',
        'platforms',
        'status',
        'total_checks',
        'completed_checks',
        'failed_checks',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'keyword_library_id' => 'integer',
            'platforms' => 'array',
            'total_checks' => 'integer',
            'completed_checks' => 'integer',
            'failed_checks' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function keywordLibrary(): BelongsTo
    {
        return $this->belongsTo(KeywordLibrary::class, 'keyword_library_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(GeoInclusionCheckResult::class, 'run_id');
    }
}
