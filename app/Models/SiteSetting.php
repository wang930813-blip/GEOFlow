<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    use BelongsToSite;

    protected $table = 'site_settings';

    protected $fillable = [
        'site_id',
        'setting_key',
        'setting_value',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
        ];
    }
}
