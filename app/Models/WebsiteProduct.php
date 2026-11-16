<?php

/**
 * Created by Codex.
 * @Date: 2026-11-11
 * @Time: 16:49:23
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： WebsiteProduct.php
 * @Description: 保存客户官网使用的产品展示内容及发布状态。
 */

namespace App\Models;

use App\Models\Concerns\BelongsToAdminOwner;
use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class WebsiteProduct extends Model
{
    use BelongsToAdminOwner;
    use BelongsToSite;
    use SoftDeletes;

    protected $table = 'website_products';

    protected $fillable = [
        'site_id',
        'owner_admin_id',
        'category_id',
        'title',
        'slug',
        'summary',
        'cover_image',
        'gallery',
        'content',
        'meta_title',
        'meta_description',
        'status',
        'is_featured',
        'sort_order',
    ];

    /**
     * @Name: casts
     * @Description: 将产品相册 JSON 读取为图片地址列表，供后台编辑和官网详情页复用。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-13 09:25:56
     * @Return array<string,string> 产品字段的 Eloquent 类型映射
     * @Throws 无
     */
    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
            'category_id' => 'integer',
            'gallery' => 'array',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @Name: category
     * @Description: 返回官网产品共用内容分类；删除分类时产品分类可为空。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-11 16:49:23
     * @Return BelongsTo 产品所属的共用分类关系
     * @Throws 无
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * @Name: scopePublished
     * @Description: 限定官网前台允许展示的已发布且未软删除产品。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-11 16:49:23
     * @Param Builder<WebsiteProduct> $query 产品查询构造器
     * @Return Builder<WebsiteProduct> 已发布产品查询
     * @Throws 无
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }
}
