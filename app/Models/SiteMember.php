<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteMember extends Model
{
    protected $fillable = [
        'site_id',
        'admin_id',
        'role',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'admin_id' => 'integer',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
