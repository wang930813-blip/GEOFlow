<?php

namespace App\Models\Concerns;

use App\Models\Admin;
use App\Support\AdminDataScope;
use App\Support\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

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
 * @File： BelongsToAdminOwner.php
 *
 * @Description: 为站点业务模型提供账号所有权查询范围和创建时的归属填充。
 */
trait BelongsToAdminOwner
{
    /**
     * @Name: bootBelongsToAdminOwner
     *
     * @Description: 注册账号范围查询及创建事件；管理员显式切换账号后按站点账号隔离并写入其所有者。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-03 11:20:14
     *
     * @UpdateTime: 2026-11-03 11:37:29
     *
     * @Return: void
     *
     * @Throws: 无
     */
    public static function bootBelongsToAdminOwner(): void
    {
        static::addGlobalScope('admin_owner', function (Builder $builder): void {
            $admin = self::currentAdmin();
            $currentSite = app(CurrentSite::class);

            if ($admin instanceof Admin && $currentSite->isAccountScopeEnabled()) {
                $ownerAdminId = $currentSite->ownerAdminId();
                $siteIds = app(AdminDataScope::class)->visibleSiteIds($admin) ?? [];
                $table = $builder->getModel()->getTable();

                if ($ownerAdminId === null) {
                    if ($siteIds === []) {
                        $builder->whereRaw('1 = 0');

                        return;
                    }

                    $builder->where(function (Builder $query) use ($admin, $siteIds, $table): void {
                        $query->whereIn($table.'.site_id', $siteIds)
                            ->where(function (Builder $ownerQuery) use ($admin, $table): void {
                                $ownerQuery->whereNull($table.'.owner_admin_id')
                                    ->orWhere($table.'.owner_admin_id', (int) $admin->id);
                            });
                    });

                    return;
                }

                $builder->where(function (Builder $query) use ($admin, $ownerAdminId, $siteIds, $table): void {
                    $query->where($table.'.owner_admin_id', $ownerAdminId);

                    if ($siteIds !== []) {
                        $query->orWhere(function (Builder $siteQuery) use ($admin, $siteIds, $table): void {
                            $siteQuery->whereIn($table.'.site_id', $siteIds)
                                ->where(function (Builder $ownerQuery) use ($admin, $table): void {
                                    $ownerQuery->whereNull($table.'.owner_admin_id')
                                        ->orWhere($table.'.owner_admin_id', (int) $admin->id);
                                });
                        });
                    }
                });

                return;
            }

            if (! self::shouldApplyOwnerScope($admin)) {
                return;
            }

            $builder->where($builder->getModel()->getTable().'.owner_admin_id', (int) $admin->id);
        });

        static::creating(function ($model): void {
            $admin = self::currentAdmin();
            $currentSite = app(CurrentSite::class);

            if ($admin instanceof Admin && $currentSite->isAccountScopeEnabled()) {
                $model->owner_admin_id = $currentSite->ownerAdminId();

                return;
            }

            if (! empty($model->owner_admin_id)) {
                return;
            }

            if (! self::shouldFillOwner($admin)) {
                return;
            }

            $model->owner_admin_id = (int) $admin->id;
        });
    }

    public function scopeForOwnerAdmin(Builder $query, int $adminId): Builder
    {
        return $query->withoutGlobalScope('admin_owner')
            ->where($this->getTable().'.owner_admin_id', $adminId);
    }

    private static function currentAdmin(): ?Admin
    {
        $admin = Auth::guard('admin')->user();

        return $admin instanceof Admin ? $admin : null;
    }

    private static function shouldApplyOwnerScope(?Admin $admin): bool
    {
        return $admin !== null
            && ($admin->isSiteUser() || $admin->isDirectAdmin());
    }

    private static function shouldFillOwner(?Admin $admin): bool
    {
        return $admin !== null && ! $admin->isSuperAdmin();
    }
}
