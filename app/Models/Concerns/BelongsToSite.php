<?php

namespace App\Models\Concerns;

use App\Models\Admin;
use App\Support\AdminDataScope;
use App\Support\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

trait BelongsToSite
{
    public static function bootBelongsToSite(): void
    {
        static::addGlobalScope('current_site', function (Builder $builder): void {
            $siteId = app(CurrentSite::class)->id();
            if ($siteId !== null && $siteId > 0) {
                $admin = Auth::guard('admin')->user();
                $builder->where(function (Builder $scopedQuery) use ($builder, $siteId, $admin): void {
                    $scopedQuery->where($builder->getModel()->getTable().'.site_id', $siteId);

                    // 超级管理员需要读取升级前遗留的主站配置（site_id=NULL）；普通账号仍严格限定当前站点。
                    if ($admin instanceof Admin && $admin->isSuperAdmin()) {
                        $scopedQuery->orWhereNull($builder->getModel()->getTable().'.site_id');
                    }
                });

                return;
            }

            $admin = Auth::guard('admin')->user();
            if ($admin instanceof Admin && $admin->isAgentAdmin()) {
                app(AdminDataScope::class)->applySiteScope(
                    $builder,
                    $admin,
                    $builder->getModel()->getTable().'.site_id'
                );
            }
        });

        static::creating(function ($model): void {
            if (! empty($model->site_id)) {
                return;
            }

            $siteId = app(CurrentSite::class)->id();
            if ($siteId !== null && $siteId > 0) {
                $model->site_id = $siteId;
            }
        });
    }

    public function scopeForSite(Builder $query, int $siteId): Builder
    {
        return $query->withoutGlobalScope('current_site')->where($this->getTable().'.site_id', $siteId);
    }
}
