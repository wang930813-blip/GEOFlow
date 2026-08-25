<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;

class AdminDataScope
{
    /**
     * @return list<int>|null
     */
    public function visibleSiteIds(Admin $admin): ?array
    {
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
     * @return list<int>|null
     */
    public function visibleOwnerAdminIds(Admin $admin): ?array
    {
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
