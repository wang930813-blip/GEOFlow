<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UrlImportJob extends Model
{
    use BelongsToSite;

    protected $table = 'url_import_jobs';

    protected $fillable = [
        'site_id',
        'url',
        'normalized_url',
        'source_domain',
        'page_title',
        'status',
        'current_step',
        'progress_percent',
        'options_json',
        'result_json',
        'error_message',
        'created_by',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'progress_percent' => 'integer',
            'site_id' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(UrlImportJobLog::class, 'job_id');
    }
}
