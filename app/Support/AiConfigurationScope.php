<?php

namespace App\Support;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class AiConfigurationScope
{
    public function canManage(Admin $admin): bool
    {
        return $admin->isSuperAdmin() || $admin->isAgentAdmin();
    }

    public function canUse(Admin $admin): bool
    {
        return ! $admin->isAgentAdmin();
    }

    public function ownerAdminIdForManager(Admin $admin): ?int
    {
        return $admin->isAgentAdmin() ? (int) $admin->id : null;
    }

    public function ownerAdminIdForConsumer(Admin $admin): ?int
    {
        if ($admin->isSiteUser() && (int) ($admin->created_by ?? 0) > 0) {
            $creator = Admin::query()->whereKey((int) $admin->created_by)->first(['id', 'role']);

            return $creator instanceof Admin && $creator->isAgentAdmin() ? (int) $creator->id : null;
        }

        if ($admin->isAgentAdmin()) {
            return (int) $admin->id;
        }

        return null;
    }

    public function currentOwnerAdminIdForManager(): ?int
    {
        $admin = Auth::guard('admin')->user();

        return $admin instanceof Admin ? $this->ownerAdminIdForManager($admin) : null;
    }

    public function currentOwnerAdminIdForConsumer(): ?int
    {
        $admin = Auth::guard('admin')->user();

        return $admin instanceof Admin ? $this->ownerAdminIdForConsumer($admin) : null;
    }

    public function applyManagerScope(Builder $query, Admin $admin, string $column = 'owner_admin_id'): Builder
    {
        return $this->applyNullableOwnerScope($query, $this->ownerAdminIdForManager($admin), $column);
    }

    public function applyConsumerScope(Builder $query, Admin $admin, string $column = 'owner_admin_id'): Builder
    {
        return $this->applyNullableOwnerScope($query, $this->ownerAdminIdForConsumer($admin), $column);
    }

    public function applyCurrentConsumerScope(Builder $query, string $column = 'owner_admin_id'): Builder
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return $this->applyNullableOwnerScope($query, null, $column);
        }

        return $this->applyConsumerScope($query, $admin, $column);
    }

    public function applyOwnerIdScope(Builder $query, ?int $ownerAdminId, string $column = 'owner_admin_id'): Builder
    {
        return $this->applyNullableOwnerScope($query, $ownerAdminId, $column);
    }

    private function applyNullableOwnerScope(Builder $query, ?int $ownerAdminId, string $column): Builder
    {
        if ($ownerAdminId !== null && $ownerAdminId > 0) {
            return $query->where($column, $ownerAdminId);
        }

        return $query->whereNull($column);
    }
}
