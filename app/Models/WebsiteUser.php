<?php

/**
 * Created by Codex.
 * @Date: 2026-11-11
 * @Time: 10:09:52
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： WebsiteUser.php
 * @Description: 保存客户站点独立的网站后台成员账号。
 */

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

class WebsiteUser extends Model
{
    use BelongsToSite;

    protected $hidden = ['password'];

    protected $fillable = [
        'site_id', 'website_role_id', 'username', 'display_name', 'password',
        'status', 'created_by_admin_id', 'last_login',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'website_role_id' => 'integer',
            'created_by_admin_id' => 'integer',
            'password' => 'hashed',
            'last_login' => 'datetime',
        ];
    }

    /**
     * @Name: role
     * @Description: 返回成员在所属站点内的权限角色。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:09:52
     * @UpdateTime: 2026-11-11 10:09:52
     * @Return BelongsTo 当前成员的角色关系
     * @Throws 无
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(WebsiteRole::class, 'website_role_id');
    }

    /**
     * @Name: verifyPassword
     * @Description: 使用 Laravel 密码哈希校验成员密码，不暴露存储哈希。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:09:52
     * @UpdateTime: 2026-11-11 10:09:52
     * @Param string $password 登录请求中的明文密码
     * @Return bool 密码是否匹配
     * @Throws 无
     */
    public function verifyPassword(string $password): bool
    {
        return Hash::check($password, (string) $this->password);
    }
}
