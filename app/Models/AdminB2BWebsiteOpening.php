<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAdminOwner;
use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

class AdminB2BWebsiteOpening extends Model
{
    use BelongsToAdminOwner;
    use BelongsToSite;

    protected $table = 'admin_b2b_website_openings';

    protected $fillable = [
        'site_id',
        'owner_admin_id',
        'website_key',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
        ];
    }
}
