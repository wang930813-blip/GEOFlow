<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAdminOwner;
use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BrandDiagnosisRun extends Model
{
    use BelongsToAdminOwner;
    use BelongsToSite;
    use SoftDeletes;

    protected $fillable = [
        'site_id',
        'api_task_key',
        'owner_admin_id',
        'admin_id',
        'brand_name',
        'brand_profile',
        'brand_profile_source',
        'brand_profile_model',
        'brand_profile_status',
        'brand_profile_meta',
        'platforms',
        'status',
        'total_questions',
        'completed_questions',
        'failed_questions',
        'brand_score',
        'mention_rate',
        'average_rank',
        'mention_count',
        'sentiment_rate',
        'billing_mode',
        'points_cost',
        'points_transaction_id',
        'limit_bypassed',
        'limit_bypass_reason',
        'usage_date',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
            'admin_id' => 'integer',
            'brand_profile_meta' => 'array',
            'platforms' => 'array',
            'total_questions' => 'integer',
            'completed_questions' => 'integer',
            'failed_questions' => 'integer',
            'brand_score' => 'integer',
            'mention_rate' => 'integer',
            'average_rank' => 'float',
            'mention_count' => 'integer',
            'sentiment_rate' => 'integer',
            'points_cost' => 'integer',
            'points_transaction_id' => 'integer',
            'limit_bypassed' => 'boolean',
            'usage_date' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(BrandDiagnosisQuestion::class, 'run_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(BrandDiagnosisResult::class, 'run_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(BrandDiagnosisSource::class, 'run_id');
    }

    public function brandMentions(): HasMany
    {
        return $this->hasMany(BrandDiagnosisBrandMention::class, 'run_id');
    }
}
