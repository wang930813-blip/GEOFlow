<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\Site;
use Illuminate\Http\Request;

class CurrentSite
{
    private ?Site $site = null;

    public function set(?Site $site): void
    {
        $this->site = $site;
    }

    public function get(): ?Site
    {
        return $this->site;
    }

    public function id(): ?int
    {
        return $this->site?->id;
    }

    public function ensureForAdmin(Admin $admin, Request $request): ?Site
    {
        $site = $this->resolveSessionSite($admin, $request)
            ?? $this->firstMemberSite($admin);

        if (! $site instanceof Site && ! $admin->isAgentAdmin()) {
            $site = $this->createDefaultSite($admin);
        }

        if (! $site instanceof Site) {
            $request->session()->forget('current_site_id');
            $this->set(null);

            return null;
        }

        $request->session()->put('current_site_id', $site->id);
        $this->set($site);

        return $site;
    }

    public function switchForAdmin(Admin $admin, int $siteId, Request $request): ?Site
    {
        $site = Site::query()
            ->whereKey($siteId)
            ->when(! $admin->isSuperAdmin(), fn ($query) => $query->whereHas('members', fn ($memberQuery) => $memberQuery->where('admins.id', $admin->id)))
            ->first();

        if (! $site instanceof Site) {
            return null;
        }

        $request->session()->put('current_site_id', $site->id);
        $this->set($site);

        return $site;
    }

    private function resolveSessionSite(Admin $admin, Request $request): ?Site
    {
        $siteId = (int) $request->session()->get('current_site_id', 0);
        if ($siteId <= 0) {
            return null;
        }

        return Site::query()
            ->whereKey($siteId)
            ->when(! $admin->isSuperAdmin(), fn ($query) => $query->whereHas('members', fn ($memberQuery) => $memberQuery->where('admins.id', $admin->id)))
            ->first();
    }

    private function firstMemberSite(Admin $admin): ?Site
    {
        return Site::query()
            ->when(! $admin->isSuperAdmin(), fn ($query) => $query->whereHas('members', fn ($memberQuery) => $memberQuery->where('admins.id', $admin->id)))
            ->orderBy('id')
            ->first();
    }

    private function createDefaultSite(Admin $admin): Site
    {
        $site = Site::query()->create([
            'owner_admin_id' => $admin->id,
            'name' => $admin->name.' 的默认站点',
            'status' => 'active',
        ]);

        $site->members()->attach($admin->id, ['role' => 'owner']);

        return $site;
    }
}
