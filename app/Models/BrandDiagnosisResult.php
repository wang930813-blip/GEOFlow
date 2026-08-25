<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use App\Models\Concerns\BelongsToAdminOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BrandDiagnosisResult extends Model
{
    use BelongsToSite;
    use BelongsToAdminOwner;

    protected $fillable = [
        'site_id',
        'owner_admin_id',
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
        'official_share_url',
        'official_share_updated_by',
        'official_share_updated_at',
        'checked_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $result): void {
            if (trim((string) $result->snapshot_token) === '') {
                $result->snapshot_token = Str::random(48);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
            'run_id' => 'integer',
            'question_id' => 'integer',
            'brand_mentioned' => 'boolean',
            'mention_count' => 'integer',
            'mention_rank' => 'integer',
            'raw_response' => 'array',
            'meta' => 'array',
            'snapshot_payload' => 'array',
            'official_share_updated_by' => 'integer',
            'official_share_updated_at' => 'datetime',
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

    public function officialShareUpdater(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'official_share_updated_by');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(BrandDiagnosisSource::class, 'result_id');
    }

    public function brandMentions(): HasMany
    {
        return $this->hasMany(BrandDiagnosisBrandMention::class, 'result_id');
    }
}
