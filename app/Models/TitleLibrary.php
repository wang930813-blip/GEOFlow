<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use App\Models\Concerns\BelongsToAdminOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TitleLibrary extends Model
{
    use BelongsToSite;
    use BelongsToAdminOwner;

    protected $table = 'title_libraries';

    protected $fillable = [
        'site_id',
        'owner_admin_id',
        'name',
        'description',
        'title_count',
        'generation_type',
        'keyword_library_id',
        'ai_model_id',
        'prompt_id',
        'generation_rounds',
        'is_ai_generated',
    ];

    protected function casts(): array
    {
        return [
            'title_count' => 'integer',
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
            'keyword_library_id' => 'integer',
            'ai_model_id' => 'integer',
            'prompt_id' => 'integer',
            'generation_rounds' => 'integer',
            'is_ai_generated' => 'integer',
        ];
    }

    public function keywordLibrary(): BelongsTo
    {
        return $this->belongsTo(KeywordLibrary::class, 'keyword_library_id');
    }

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class, 'prompt_id');
    }

    public function titles(): HasMany
    {
        return $this->hasMany(Title::class, 'library_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'title_library_id');
    }
}
