<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UrlImportJobLog extends Model
{
    use BelongsToSite;

    public const UPDATED_AT = null;

    protected $table = 'url_import_job_logs';

    protected $fillable = [
        'job_id',
        'site_id',
        'step',
        'level',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'job_id' => 'integer',
            'site_id' => 'integer',
            'step' => 'string',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(UrlImportJob::class, 'job_id');
    }
}
