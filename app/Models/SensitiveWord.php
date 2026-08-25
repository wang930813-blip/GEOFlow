<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

class SensitiveWord extends Model
{
    use BelongsToSite;

    public const UPDATED_AT = null;

    protected $table = 'sensitive_words';

    protected $fillable = [
        'word',
        'site_id',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
        ];
    }
}
