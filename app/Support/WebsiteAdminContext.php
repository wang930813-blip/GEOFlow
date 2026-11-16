<?php

/**
 * Created by Codex.
 * @Date: 2026-11-11
 * @Time: 10:09:52
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： WebsiteAdminContext.php
 * @Description: 表示完成认证的网站后台用户、客户站点和权限。
 */

namespace App\Support;

use App\Models\Admin;
use App\Models\Site;
use App\Models\WebsiteUser;

final class WebsiteAdminContext
{
    /**
     * @param list<string> $permissions 当前角色权限
     */
    public function __construct(
        public readonly Site $site,
        public readonly Admin $owner,
        public readonly ?WebsiteUser $user,
        public readonly bool $isOwner,
        public readonly array $permissions,
        public readonly string $displayName
    ) {}

    /**
     * @Name: allows
     * @Description: 校验当前上下文是否拥有指定权限，站点 GEO 账号始终保留完整控制权。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:09:52
     * @UpdateTime: 2026-11-12 14:55:53
     * @Param string $permission 权限键
     * @Return bool 是否允许执行操作
     * @Throws 无
     */
    public function allows(string $permission): bool
    {
        return $this->isOwner || in_array($permission, WebsitePermissionCatalog::expand($this->permissions), true);
    }
}
