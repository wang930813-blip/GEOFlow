<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrandDiagnosisLookupJob extends Model
{
    protected $table = 'brand_diagnosis_lookup_jobs';

    protected $fillable = [
        'lookup_id',
        'brand_word',
        'canonical_key',
        'includes',
        'status',
        'result',
        'error_code',
        'error_status',
        'error_message',
        'attempts',
        'started_at',
        'completed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'includes' => 'array',
            'result' => 'array',
            'error_status' => 'integer',
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
