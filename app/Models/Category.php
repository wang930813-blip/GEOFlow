<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use App\Models\Concerns\BelongsToAdminOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use BelongsToSite;
    use BelongsToAdminOwner;

    public const UPDATED_AT = null;

    protected $table = 'categories';

    protected $fillable = [
        'name',
        'site_id',
        'owner_admin_id',
        'slug',
        'description',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
        ];
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class, 'category_id');
    }

    /**
     * @Name: products
     * @Description: 返回使用该共用分类的官网产品。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-11 16:49:23
     * @Return HasMany 官网产品关系
     * @Throws 无
     */
    public function products(): HasMany
    {
        return $this->hasMany(WebsiteProduct::class, 'category_id');
    }
}
