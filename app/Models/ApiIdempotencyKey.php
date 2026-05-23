<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

class ApiIdempotencyKey extends Model
{
    use BelongsToSite;

    protected $table = 'api_idempotency_keys';

    protected $fillable = [
        'site_id',
        'idempotency_key',
        'route_key',
        'request_hash',
        'response_body',
        'response_status',
    ];

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'site_id' => 'integer',
        ];
    }
}
