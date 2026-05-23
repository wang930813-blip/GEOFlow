<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Site extends Model
{
    protected $fillable = [
        'owner_admin_id',
        'name',
        'domain',
        'status',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'owner_admin_id' => 'integer',
            'settings' => 'array',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'owner_admin_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Admin::class, 'site_members')
            ->withPivot('role')
            ->withTimestamps();
    }
}
