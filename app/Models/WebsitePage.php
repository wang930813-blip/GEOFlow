<?php

/**
 * Created by Codex.
 * @Date: 2026-11-11
 * @Time: 16:49:23
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： WebsitePage.php
 * @Description: 保存客户官网的独立页面正文、导航和搜索元数据。
 */

namespace App\Models;

use App\Models\Concerns\BelongsToAdminOwner;
use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class WebsitePage extends Model
{
    use BelongsToAdminOwner;
    use BelongsToSite;
    use SoftDeletes;

    protected $table = 'website_pages';

    protected $fillable = [
        'site_id',
        'owner_admin_id',
        'title',
        'slug',
        'content',
        'meta_title',
        'meta_description',
        'status',
        'show_in_navigation',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
            'show_in_navigation' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @Name: scopePublished
     * @Description: 只向官网读取已发布且未软删除的单页内容。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-11 16:49:23
     * @Param Builder<WebsitePage> $query 页面查询构造器
     * @Return Builder<WebsitePage> 已发布页面查询
     * @Throws 无
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }
}
