<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminPlanSubscription;
use App\Models\Site;
use App\Models\SitePlanSubscription;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SiteManagementController extends Controller
{
    public function index(): View
    {
        $admin = $this->authorizedAdmin();
        $isAgentSiteManager = $admin->isAgentAdmin();

        $sites = $this->visibleSitesQuery($admin)
            ->with([
                'owner:id,username,display_name',
                'members:id,username,display_name,role,status',
                'planSubscriptions' => fn ($query) => $query->with('plan:id,name,code')->latest()->limit(1),
            ])
            ->withCount('members')
            ->orderBy('id')
            ->get();

        $admins = $this->manageableAdminsQuery($admin)->get();

        return view('admin.sites.index', [
            'pageTitle' => '站点管理',
            'activeMenu' => 'sites',
            'adminSiteName' => AdminWeb::siteName(),
            'sites' => $sites,
            'admins' => $admins,
            'isAgentSiteManager' => $isAgentSiteManager,
            'isSuperSiteManager' => $admin->isSuperAdmin(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $admin = $this->authorizedAdmin();
        $payload = $this->validatedPayload($request, null, $admin);

        DB::transaction(function () use ($payload): void {
            $site = Site::query()->create(Arr::only($payload, [
                'owner_admin_id',
                'name',
                'domain',
                'status',
                'customer_mode',
                'agent_admin_id',
            ]));

            $this->syncMembers($site, $payload);
        });

        return redirect()->route('admin.sites.manage.index')->with('message', '站点已创建');
    }

    public function update(Site $site, Request $request): RedirectResponse
    {
        $admin = $this->authorizedAdmin();
        $this->authorizeSiteAccess($admin, $site);

        $payload = $this->validatedPayload($request, $site, $admin);

        DB::transaction(function () use ($site, $payload): void {
            $site->update(Arr::only($payload, [
                'owner_admin_id',
                'name',
                'domain',
                'status',
                'customer_mode',
                'agent_admin_id',
            ]));

            $this->syncMembers($site, $payload);
        });

        return redirect()->route('admin.sites.manage.index')->with('message', '站点已更新');
    }

    public function toggleStatus(Site $site): RedirectResponse
    {
        $admin = $this->authorizedAdmin();
        $this->authorizeSiteAccess($admin, $site);

        $site->update([
            'status' => $site->status === 'active' ? 'inactive' : 'active',
        ]);

        return redirect()->route('admin.sites.manage.index')->with('message', '站点状态已更新');
    }

    public function destroy(Site $site): RedirectResponse
    {
        $admin = $this->authorizedAdmin();
        $this->authorizeSiteAccess($admin, $site);

        DB::transaction(function () use ($site): void {
            SitePlanSubscription::query()
                ->where('site_id', (int) $site->id)
                ->where('status', 'active')
                ->update(['status' => 'cancelled']);

            AdminPlanSubscription::query()
                ->where('site_id', (int) $site->id)
                ->where('status', 'active')
                ->update(['status' => 'cancelled']);

            $site->members()->detach();
            $site->delete();
        });

        return redirect()->route('admin.sites.manage.index')->with('message', '站点已删除');
    }

    /**
     * @return array{owner_admin_id:int|null,name:string,domain:string,status:string,customer_mode:string,agent_admin_id:int|null,member_ids:array<int,int>}
     */
    private function validatedPayload(Request $request, ?Site $site = null, ?Admin $actor = null): array
    {
        $actor ??= $this->authorizedAdmin();
        $request->merge([
            'domain' => $this->normalizeDomain((string) $request->input('domain', '')),
        ]);

        $ownerExistsRule = $actor->isAgentAdmin()
            ? Rule::exists('admins', 'id')
                ->where('role', 'site_user')
                ->where('created_by', (int) $actor->id)
            : Rule::exists('admins', 'id');
        $memberExistsRule = $actor->isAgentAdmin()
            ? Rule::exists('admins', 'id')
                ->where('role', 'site_user')
                ->where('created_by', (int) $actor->id)
            : Rule::exists('admins', 'id');

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'domain' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^[A-Za-z0-9.-]+$/',
            ],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'customer_mode' => ['nullable', Rule::in(['agent', 'direct', 'internal'])],
            'owner_admin_id' => [$actor->isAgentAdmin() ? 'required' : 'nullable', 'integer', $ownerExistsRule],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer', $memberExistsRule],
        ], [
            'name.required' => '请填写站点名称',
            'domain.regex' => '前台域名只填写域名，不要包含路径或特殊字符',
            'status.in' => '站点状态不正确',
        ]);

        if (($payload['domain'] ?? '') !== '') {
            $domainExists = Site::query()
                ->where('domain', $payload['domain'])
                ->when($site instanceof Site, fn ($query) => $query->whereKeyNot($site->id))
                ->exists();

            if ($domainExists) {
                throw ValidationException::withMessages([
                    'domain' => '该前台域名已经绑定到其他站点',
                ]);
            }
        }

        $ownerAdminId = (int) ($payload['owner_admin_id'] ?? 0);
        $memberIds = collect($payload['member_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ownerAdminId > 0 && ! in_array($ownerAdminId, $memberIds, true)) {
            $memberIds[] = $ownerAdminId;
        }

        $customerMode = (string) ($payload['customer_mode'] ?? 'internal');
        $agentAdminId = $customerMode === 'agent' && $ownerAdminId > 0 ? $ownerAdminId : null;

        if ($actor->isAgentAdmin()) {
            $customerMode = 'agent';
            $agentAdminId = (int) $actor->id;
        }

        return [
            'owner_admin_id' => $ownerAdminId > 0 ? $ownerAdminId : null,
            'name' => trim((string) $payload['name']),
            'domain' => trim((string) ($payload['domain'] ?? '')),
            'status' => (string) $payload['status'],
            'customer_mode' => $customerMode,
            'agent_admin_id' => $agentAdminId,
            'member_ids' => $memberIds,
        ];
    }

    /**
     * @param  array{owner_admin_id:int|null,member_ids:array<int,int>}  $payload
     */
    private function syncMembers(Site $site, array $payload): void
    {
        $ownerAdminId = $payload['owner_admin_id'];
        $syncPayload = [];

        foreach ($payload['member_ids'] as $adminId) {
            $syncPayload[$adminId] = [
                'role' => $ownerAdminId !== null && $adminId === $ownerAdminId ? 'owner' : 'admin',
            ];
        }

        $site->members()->sync($syncPayload);
    }

    private function normalizeDomain(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (! str_contains($value, '://')) {
            $value = 'https://'.$value;
        }

        $host = parse_url($value, PHP_URL_HOST);

        return strtolower(trim((string) $host));
    }

    private function authorizedAdmin(): Admin
    {
        $admin = auth('admin')->user();
        abort_unless($admin instanceof Admin && ($admin->isSuperAdmin() || $admin->isAgentAdmin()), 403);

        return $admin;
    }

    private function authorizeSiteAccess(Admin $admin, Site $site): void
    {
        if ($admin->isSuperAdmin()) {
            return;
        }

        abort_unless(
            $admin->isAgentAdmin()
                && (string) ($site->customer_mode ?? '') === 'agent'
                && (int) $site->agent_admin_id === (int) $admin->id,
            403
        );
    }

    private function visibleSitesQuery(Admin $admin)
    {
        return Site::query()
            ->when($admin->isAgentAdmin(), fn ($query) => $query
                ->where('customer_mode', 'agent')
                ->where('agent_admin_id', (int) $admin->id));
    }

    private function manageableAdminsQuery(Admin $admin)
    {
        return Admin::query()
            ->select(['id', 'username', 'display_name', 'role', 'status'])
            ->when($admin->isAgentAdmin(), fn ($query) => $query
                ->where('role', 'site_user')
                ->where('created_by', (int) $admin->id))
            ->when($admin->isSuperAdmin(), fn ($query) => $query
                ->orderByRaw("CASE WHEN LOWER(COALESCE(role, '')) IN ('super_admin', 'superadmin') THEN 0 ELSE 1 END"))
            ->orderBy('username');
    }
}
