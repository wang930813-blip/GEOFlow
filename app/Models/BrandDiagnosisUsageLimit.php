<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandDiagnosisUsageLimit extends Model
{
    use BelongsToSite;

    protected $fillable = [
        'site_id',
        'admin_id',
        'usage_date',
        'free_runs_used',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'admin_id' => 'integer',
            'usage_date' => 'date',
            'free_runs_used' => 'integer',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}
