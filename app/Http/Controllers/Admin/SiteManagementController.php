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
        $sites = Site::query()
            ->with([
                'owner:id,username,display_name',
                'members:id,username,display_name,role,status',
                'planSubscriptions' => fn ($query) => $query->with('plan:id,name,code')->latest()->limit(1),
            ])
            ->withCount('members')
            ->orderBy('id')
            ->get();

        $admins = Admin::query()
            ->select(['id', 'username', 'display_name', 'role', 'status'])
            ->orderByRaw("CASE WHEN LOWER(COALESCE(role, '')) IN ('super_admin', 'superadmin') THEN 0 ELSE 1 END")
            ->orderBy('username')
            ->get();

        return view('admin.sites.index', [
            'pageTitle' => '站点管理',
            'activeMenu' => 'sites',
            'adminSiteName' => AdminWeb::siteName(),
            'sites' => $sites,
            'admins' => $admins,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validatedPayload($request);

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
        $payload = $this->validatedPayload($request, $site);

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
        $site->update([
            'status' => $site->status === 'active' ? 'inactive' : 'active',
        ]);

        return redirect()->route('admin.sites.manage.index')->with('message', '站点状态已更新');
    }

    public function destroy(Site $site): RedirectResponse
    {
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
     * @return array{owner_admin_id:int|null,name:string,domain:string,status:string,member_ids:array<int,int>}
     */
    private function validatedPayload(Request $request, ?Site $site = null): array
    {
        $request->merge([
            'domain' => $this->normalizeDomain((string) $request->input('domain', '')),
        ]);

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
            'owner_admin_id' => ['nullable', 'integer', Rule::exists('admins', 'id')],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer', Rule::exists('admins', 'id')],
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

        return [
            'owner_admin_id' => $ownerAdminId > 0 ? $ownerAdminId : null,
            'name' => trim((string) $payload['name']),
            'domain' => trim((string) ($payload['domain'] ?? '')),
            'status' => (string) $payload['status'],
            'customer_mode' => (string) ($payload['customer_mode'] ?? 'internal'),
            'agent_admin_id' => (string) ($payload['customer_mode'] ?? 'internal') === 'agent' && $ownerAdminId > 0 ? $ownerAdminId : null,
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
}
