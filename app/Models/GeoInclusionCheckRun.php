<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use App\Models\Concerns\BelongsToAdminOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoInclusionCheckRun extends Model
{
    use BelongsToSite;
    use BelongsToAdminOwner;

    protected $table = 'geo_inclusion_check_runs';

    protected $fillable = [
        'site_id',
        'owner_admin_id',
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
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
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
