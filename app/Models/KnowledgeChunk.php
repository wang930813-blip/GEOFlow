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
        'generation_key',
        'chunk_index',
        'content',
        'content_hash',
        'chunk_title',
        'section_path',
        'chunk_strategy',
        'metadata_json',
        'source_hash',
        'token_count',
        'embedding_json',
        'embedding_model_id',
        'embedding_dimensions',
        'embedding_provider',
        'embedding_fingerprint',
        'embedding_profile_version',
        'embedding_profile_digest',
        'embedding_config_revision',
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
            'embedding_profile_version' => 'integer',
        ];
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class, 'knowledge_base_id');
    }
}
