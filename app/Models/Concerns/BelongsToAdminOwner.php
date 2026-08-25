<?php

namespace App\Models\Concerns;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

trait BelongsToAdminOwner
{
    public static function bootBelongsToAdminOwner(): void
    {
        static::addGlobalScope('admin_owner', function (Builder $builder): void {
            $admin = self::currentAdmin();

            if (! self::shouldApplyOwnerScope($admin)) {
                return;
            }

            $builder->where($builder->getModel()->getTable().'.owner_admin_id', (int) $admin->id);
        });

        static::creating(function ($model): void {
            if (! empty($model->owner_admin_id)) {
                return;
            }

            $admin = self::currentAdmin();

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
