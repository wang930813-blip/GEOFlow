<?php

/**
 * Created by Codex.
 * @Date: 2026-11-12
 * @Time: 14:55:53
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： WebsitePermissionCatalog.php
 * @Description: 定义网站后台权限树及旧角色权限的兼容映射。
 */

namespace App\Support;

final class WebsitePermissionCatalog
{
    private const VERSION = '_website_permissions_v2';

    private const TREE = [
        '概览' => ['数据总览' => ['dashboard.read' => '查看']],
        '内容' => [
            '分类管理' => ['categories.read' => '查看', 'categories.create' => '新建', 'categories.edit' => '编辑', 'categories.delete' => '删除'],
            '文章管理' => ['articles.read' => '查看', 'articles.create' => '新建', 'articles.edit' => '编辑', 'articles.delete' => '删除', 'articles.publish' => '发布', 'articles.ai' => 'AI 写作'],
            '产品管理' => ['products.read' => '查看', 'products.create' => '新建', 'products.edit' => '编辑', 'products.delete' => '删除', 'products.publish' => '发布'],
            '单页管理' => ['pages.read' => '查看', 'pages.create' => '新建', 'pages.edit' => '编辑', 'pages.delete' => '删除', 'pages.publish' => '发布'],
        ],
        '系统管理' => [
            '网站设置' => ['site.settings.read' => '查看', 'site.settings.basic' => '基本信息', 'site.settings.seo' => 'SEO 设置'],
            '网站装修' => ['site.design.read' => '查看', 'site.design.edit' => '编辑'],
            '插件列表' => ['plugins.read' => '查看', 'plugins.configure' => '配置和启停'],
            '权限角色' => ['roles.read' => '查看', 'roles.create' => '新建', 'roles.edit' => '编辑', 'roles.delete' => '删除'],
            '管理员' => ['users.read' => '查看', 'users.create' => '新建', 'users.edit' => '编辑', 'users.delete' => '删除'],
        ],
    ];

    private const LEGACY = [
        'site.settings' => ['site.settings.read', 'site.settings.basic', 'site.settings.seo'],
        'categories.write' => ['categories.create', 'categories.edit', 'categories.delete'],
        'articles.write' => ['articles.create', 'articles.edit', 'articles.delete', 'articles.ai'],
        'products.write' => ['products.create', 'products.edit', 'products.delete'],
        'pages.write' => ['pages.create', 'pages.edit', 'pages.delete'],
    ];

    /**
     * @Name: tree
     * @Description: 返回分组、功能和操作三级权限定义，供角色表单使用。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 14:55:53
     * @UpdateTime: 2026-11-12 17:09:24
     * @Return array 权限树
     * @Throws 无
     */
    public static function tree(): array
    {
        return self::TREE;
    }

    /**
     * @Name: keys
     * @Description: 返回允许由角色表单提交的操作权限键。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 14:55:53
     * @UpdateTime: 2026-11-12 16:29:58
     * @Return list<string> 权限键列表
     * @Throws 无
     */
    public static function keys(): array
    {
        $keys = [];
        foreach (self::TREE as $modules) {
            foreach ($modules as $actions) {
                array_push($keys, ...array_keys($actions));
            }
        }

        return $keys;
    }

    /**
     * @Name: acceptedKeys
     * @Description: 同时接受旧版本提交的权限键，保存时统一展开为细分权限。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 14:55:53
     * @UpdateTime: 2026-11-12 14:55:53
     * @Return list<string> 可接收权限键
     * @Throws 无
     */
    public static function acceptedKeys(): array
    {
        return array_merge(self::keys(), array_keys(self::LEGACY));
    }

    /**
     * @Name: forStorage
     * @Description: 将新角色权限保存为明确的细分版本，避免旧角色概览兼容逻辑影响新角色。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 15:23:35
     * @UpdateTime: 2026-11-12 15:23:35
     * @Param array $permissions 已验证的表单权限
     * @Return list<string> 角色持久化权限
     * @Throws 无
     */
    public static function forStorage(array $permissions): array
    {
        return [...self::expand([...$permissions, self::VERSION]), self::VERSION];
    }

    /**
     * @Name: expand
     * @Description: 将旧角色粗粒度权限映射为等价操作权限，避免旧账号失权。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 14:55:53
     * @UpdateTime: 2026-11-12 15:23:35
     * @Param array $permissions 角色保存的权限列表
     * @Return list<string> 有效操作权限列表
     * @Throws 无
     */
    public static function expand(array $permissions): array
    {
        $effective = [];
        if (! in_array(self::VERSION, $permissions, true)
            && array_intersect($permissions, ['site.settings', 'articles.read', 'categories.read', 'products.read', 'pages.read']) !== []) {
            $effective[] = 'dashboard.read';
        }
        foreach ($permissions as $permission) {
            if (! is_string($permission)) {
                continue;
            }
            if (in_array($permission, self::keys(), true)) {
                $effective[] = $permission;
            }
            array_push($effective, ...(self::LEGACY[$permission] ?? []));
        }

        return array_values(array_unique($effective));
    }
}
