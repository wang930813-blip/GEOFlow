<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

class SystemLog extends Model
{
    use BelongsToSite;

    public const UPDATED_AT = null;

    protected $table = 'system_logs';

    protected $fillable = [
        'type',
        'site_id',
        'message',
        'data',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
        ];
    }
}
