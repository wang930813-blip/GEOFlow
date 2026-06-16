<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAdminOwner;
use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeBase extends Model
{
    use BelongsToAdminOwner;
    use BelongsToSite;

    protected $table = 'knowledge_bases';

    protected $fillable = [
        'site_id',
        'owner_admin_id',
        'name',
        'description',
        'content',
        'character_count',
        'used_task_count',
        'file_type',
        'file_path',
        'word_count',
        'usage_count',
    ];

    protected function casts(): array
    {
        return [
            'character_count' => 'integer',
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
            'used_task_count' => 'integer',
            'word_count' => 'integer',
            'usage_count' => 'integer',
        ];
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class, 'knowledge_base_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'knowledge_base_id');
    }
}
