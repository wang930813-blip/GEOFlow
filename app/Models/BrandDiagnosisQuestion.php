<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use App\Models\Concerns\BelongsToAdminOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrandDiagnosisQuestion extends Model
{
    use BelongsToSite;
    use BelongsToAdminOwner;

    protected $fillable = [
        'site_id',
        'owner_admin_id',
        'run_id',
        'question',
        'question_type',
        'core_term',
        'sort_order',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
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
