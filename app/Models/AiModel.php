<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiModel extends Model
{
    use BelongsToSite;

    protected $table = 'ai_models';

    protected $hidden = [
        'api_key',
    ];

    protected $fillable = [
        'name',
        'site_id',
        'version',
        'api_key',
        'model_id',
        'model_type',
        'api_url',
        'failover_priority',
        'daily_limit',
        'used_today',
        'total_used',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'failover_priority' => 'integer',
            'site_id' => 'integer',
            'daily_limit' => 'integer',
            'used_today' => 'integer',
            'total_used' => 'integer',
        ];
    }

    public function titleLibraries(): HasMany
    {
        return $this->hasMany(TitleLibrary::class, 'ai_model_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'ai_model_id');
    }

    public function scopeActiveStatus(Builder $query): Builder
    {
        return $query->whereRaw("LOWER(COALESCE(NULLIF(TRIM(status), ''), 'active')) = 'active'");
    }

    public function scopeEmbeddingType(Builder $query): Builder
    {
        return $query->whereRaw("LOWER(COALESCE(NULLIF(TRIM(model_type), ''), 'chat')) = 'embedding'");
    }
}
