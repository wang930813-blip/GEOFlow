<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use App\Models\Concerns\BelongsToAdminOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KeywordLibrary extends Model
{
    use BelongsToSite;
    use BelongsToAdminOwner;

    protected $table = 'keyword_libraries';

    protected $fillable = [
        'site_id',
        'owner_admin_id',
        'name',
        'description',
        'company_name',
        'domain_keyword',
        'industry',
        'brand_description',
        'status',
        'keyword_count',
    ];

    protected function casts(): array
    {
        return [
            'keyword_count' => 'integer',
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
        ];
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(Keyword::class, 'library_id');
    }

    public function titleLibraries(): HasMany
    {
        return $this->hasMany(TitleLibrary::class, 'keyword_library_id');
    }
}
