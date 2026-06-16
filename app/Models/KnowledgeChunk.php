<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use App\Models\Concerns\BelongsToAdminOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeChunk extends Model
{
    use BelongsToSite;
    use BelongsToAdminOwner;

    protected $table = 'knowledge_chunks';

    protected $fillable = [
        'site_id',
        'owner_admin_id',
        'knowledge_base_id',
        'chunk_index',
        'content',
        'content_hash',
        'token_count',
        'embedding_json',
        'embedding_model_id',
        'embedding_dimensions',
        'embedding_provider',
        'embedding_vector',
    ];

    protected function casts(): array
    {
        return [
            'knowledge_base_id' => 'integer',
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
            'chunk_index' => 'integer',
            'token_count' => 'integer',
            'embedding_model_id' => 'integer',
            'embedding_dimensions' => 'integer',
        ];
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class, 'knowledge_base_id');
    }
}
