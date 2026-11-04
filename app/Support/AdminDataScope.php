<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;

/**
 * Created by Codex.
 *
 * @Date: 2026-11-03
 *
 * @Time: 11:37:29
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： AdminDataScope.php
 *
 * @Description: 根据管理员角色及显式选择的站点账号计算后台数据可见范围。
 */
class AdminDataScope
{
    /**
     * @Name: visibleSiteIds
     *
     * @Description: 返回管理员可见站点；显式切换账号后只返回所选账号内且当前管理员仍有权访问的站点。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-03 11:20:14
     *
     * @UpdateTime: 2026-11-03 11:37:29
     *
     * @Param: Admin $admin 当前后台管理员
     *
     * @Return: list<int>|null 站点 ID 列表；null 表示不限制站点
     *
     * @Throws: 无
     */
    public function visibleSiteIds(Admin $admin): ?array
    {
        $currentSite = app(CurrentSite::class);
        if ($currentSite->isAccountScopeEnabled()) {
            $accountSiteIds = $currentSite->accountSiteIds() ?? [];
            if ($accountSiteIds === []) {
                return [];
            }

            if ($admin->isSuperAdmin()) {
                return $accountSiteIds;
            }

            if ($admin->isAgentAdmin()) {
                $childUserIds = $this->agentChildUserIds($admin);

                return Site::query()
                    ->whereIn('id', $accountSiteIds)
                    ->where('customer_mode', 'agent')
                    ->where(function (Builder $query) use ($admin, $childUserIds): void {
                        $query->where('agent_admin_id', (int) $admin->id);

                        if ($childUserIds !== []) {
                            $query->orWhereIn('owner_admin_id', $childUserIds);
                        }
                    })
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();
            }

            $memberSiteIds = $admin->sites()
                ->whereIn('sites.id', $accountSiteIds)
                ->pluck('sites.id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            return array_values(array_intersect($accountSiteIds, $memberSiteIds));
        }

        if ($admin->isSuperAdmin()) {
            return null;
        }

        if ($admin->isAgentAdmin()) {
            $childUserIds = $this->agentChildUserIds($admin);

            return Site::query()
                ->where(function (Builder $query) use ($admin, $childUserIds): void {
                    $query->where('agent_admin_id', (int) $admin->id);

                    if ($childUserIds !== []) {
                        $query->orWhereIn('owner_admin_id', $childUserIds);
                    }
                })
                ->where('customer_mode', 'agent')
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        $currentSiteId = app(CurrentSite::class)->id();
        if ($currentSiteId !== null && $currentSiteId > 0) {
            return [(int) $currentSiteId];
        }

        return $admin->sites()
            ->pluck('sites.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @Name: visibleOwnerAdminIds
     *
     * @Description: 返回管理员可见的数据所属账号；显式切换后以所选站点所属账号作为数据所有者。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-03 11:20:14
     *
     * @UpdateTime: 2026-11-03 11:37:29
     *
     * @Param: Admin $admin 当前后台管理员
     *
     * @Return: list<int>|null 账号 ID 列表；null 表示不限制所有者
     *
     * @Throws: 无
     */
    public function visibleOwnerAdminIds(Admin $admin): ?array
    {
        $currentSite = app(CurrentSite::class);
        if ($currentSite->isAccountScopeEnabled()) {
            $ownerAdminId = $currentSite->ownerAdminId();

            return $ownerAdminId !== null ? [$ownerAdminId] : [];
        }

        if ($admin->isSuperAdmin()) {
            return null;
        }

        if ($admin->isAgentAdmin()) {
            return $this->agentChildUserIds($admin);
        }

        return [(int) $admin->id];
    }

    public function applySiteScope(Builder $query, Admin $admin, ?string $column = null): Builder
    {
        $siteIds = $this->visibleSiteIds($admin);
        if ($siteIds === null) {
            return $query;
        }

        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column ?? $query->getModel()->getTable().'.site_id', $siteIds);
    }

    public function applyOwnerScope(Builder $query, Admin $admin, ?string $column = null): Builder
    {
        $ownerIds = $this->visibleOwnerAdminIds($admin);
        if ($ownerIds === null) {
            return $query;
        }

        if ($ownerIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column ?? $query->getModel()->getTable().'.owner_admin_id', $ownerIds);
    }

    /**
     * @return list<int>
     */
    private function agentChildUserIds(Admin $admin): array
    {
        return Admin::query()
            ->where('created_by', (int) $admin->id)
            ->where('role', 'site_user')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
