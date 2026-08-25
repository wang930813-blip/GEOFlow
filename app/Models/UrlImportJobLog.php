<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use App\Models\Concerns\BelongsToAdminOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UrlImportJobLog extends Model
{
    use BelongsToSite;
    use BelongsToAdminOwner;

    public const UPDATED_AT = null;

    protected $table = 'url_import_job_logs';

    protected $fillable = [
        'job_id',
        'site_id',
        'owner_admin_id',
        'step',
        'level',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'job_id' => 'integer',
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
            'step' => 'string',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(UrlImportJob::class, 'job_id');
    }
}
