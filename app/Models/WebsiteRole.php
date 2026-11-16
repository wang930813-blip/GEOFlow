<?php

/**
 * Created by Codex.
 * @Date: 2026-11-11
 * @Time: 10:09:52
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： WebsiteRole.php
 * @Description: 保存单个客户站点的网站后台角色及权限。
 */

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebsiteRole extends Model
{
    use BelongsToSite;

    protected $fillable = ['site_id', 'name', 'permissions'];

    protected function casts(): array
    {
        return ['site_id' => 'integer', 'permissions' => 'array'];
    }

    /**
     * @Name: users
     * @Description: 返回绑定到当前站点角色的后台成员。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:09:52
     * @UpdateTime: 2026-11-11 10:09:52
     * @Return HasMany 当前角色成员关系
     * @Throws 无
     */
    public function users(): HasMany
    {
        return $this->hasMany(WebsiteUser::class);
    }
}
