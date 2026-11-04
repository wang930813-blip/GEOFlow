<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminPlanSubscription;
use App\Models\Site;
use App\Models\SitePlanSubscription;
use App\Models\SiteSetting;
use App\Services\CustomerDomain\CustomerDomainDeploymentException;
use App\Services\CustomerDomain\CustomerDomainDeploymentManager;
use App\Services\MonitoringCenter\MonitoringReportLogoResolver;
use App\Support\AdminDataScope;
use App\Support\AdminWeb;
use App\Support\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

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
 * @File： SiteManagementController.php
 *
 * @Description: 管理站点资料，并按当前所选客户账号限制超管的站点管理范围。
 */
class SiteManagementController extends Controller
{
    public function __construct(
        private readonly MonitoringReportLogoResolver $reportLogoResolver,
        private readonly CustomerDomainDeploymentManager $customerDomainDeploymentManager,
        private readonly AdminDataScope $adminDataScope,
    ) {}

    public function index(): View
    {
        $admin = $this->authorizedAdmin();
        $isAgentSiteManager = $admin->isAgentAdmin();
        $perPage = max(1, min(100, (int) config('geoflow.admin_items_per_page', 20)));

        $sites = $this->visibleSitesQuery($admin)
            ->with([
                'owner:id,username,display_name',
                'members:id,username,display_name,role,status',
                'planSubscriptions' => fn ($query) => $query->with('plan:id,name,code')->latest()->limit(1),
            ])
            ->withCount('members')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
        $siteCollection = $sites->getCollection();

        $admins = $this->manageableAdminsQuery($admin)->get();

        return view('admin.sites.index', [
            'pageTitle' => '站点管理',
            'activeMenu' => 'sites',
            'adminSiteName' => AdminWeb::siteName(),
            'sites' => $sites,
            'admins' => $admins,
            'accountPlanSubscriptionsBySite' => $this->accountPlanSubscriptionsBySite($siteCollection),
            'monitoringReportLogosBySite' => $this->monitoringReportLogosBySite($siteCollection),
            'isAgentSiteManager' => $isAgentSiteManager,
            'isSuperSiteManager' => $admin->isSuperAdmin(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $admin = $this->authorizedAdmin();
        $payload = $this->validatedPayload($request, null, $admin);

        $site = DB::transaction(function () use ($admin, $payload): Site {
            $site = Site::query()->create(Arr::only($payload, [
                'owner_admin_id',
                'name',
                'domain',
                'custom_domain',
                'status',
                'customer_mode',
                'agent_admin_id',
            ]));

            $this->syncMembers($site, $payload);
            $this->saveMonitoringReportLogo($admin, $site, $payload);

            return $site;
        });

        if (trim((string) $site->custom_domain) !== '') {
            try {
                $this->customerDomainDeploymentManager->synchronize($site);
            } catch (CustomerDomainDeploymentException $exception) {
                return back()
                    ->withErrors(['custom_domain' => $exception->getMessage()])
                    ->withInput($request->except(['custom_https_private_key', 'custom_https_private_key_file']));
            }
        }

        return redirect()->route('admin.sites.manage.index')->with('message', '站点已创建');
    }

    public function update(Site $site, Request $request): RedirectResponse
    {
        $admin = $this->authorizedAdmin();
        $this->authorizeSiteAccess($admin, $site);

        $payload = $this->validatedPayload($request, $site, $admin);
        $customDomainChanged = (string) $site->custom_domain !== (string) $payload['custom_domain'];
        $siteStatusChanged = (string) $site->status !== (string) $payload['status'];

        DB::transaction(function () use ($admin, $site, $payload): void {
            $siteUpdate = Arr::only($payload, [
                'owner_admin_id',
                'name',
                'custom_domain',
                'status',
                'customer_mode',
                'agent_admin_id',
            ]);

            if ((string) $site->custom_domain !== (string) $payload['custom_domain'] && (bool) $site->custom_https_enabled) {
                // 域名更换后旧证书不再可信，必须重新配置证书后才能恢复 HTTPS 强制跳转。
                $siteUpdate['custom_https_enabled'] = false;
                $siteUpdate['custom_https_certificate'] = null;
                $siteUpdate['custom_https_private_key'] = null;
            }

            $site->forceFill($siteUpdate)->save();

            $this->syncMembers($site, $payload);
            $this->saveMonitoringReportLogo($admin, $site, $payload);
        });

        if ($customDomainChanged || $siteStatusChanged) {
            try {
                $site = $site->refresh();
                if ($site->status === 'active') {
                    $this->customerDomainDeploymentManager->synchronize($site);
                } else {
                    $this->customerDomainDeploymentManager->remove($site);
                }
            } catch (CustomerDomainDeploymentException $exception) {
                return back()
                    ->withErrors(['custom_domain' => $exception->getMessage()])
                    ->withInput($request->except(['custom_https_private_key', 'custom_https_private_key_file']));
            }
        }

        return redirect()->route('admin.sites.manage.index')->with('message', '站点已更新');
    }

    public function toggleStatus(Site $site): RedirectResponse
    {
        $admin = $this->authorizedAdmin();
        $this->authorizeSiteAccess($admin, $site);

        $site->update(['status' => $site->status === 'active' ? 'inactive' : 'active']);

        try {
            if ($site->status === 'active') {
                $this->customerDomainDeploymentManager->synchronize($site->refresh());
            } else {
                $this->customerDomainDeploymentManager->remove($site);
            }
        } catch (CustomerDomainDeploymentException $exception) {
            return back()->withErrors(['custom_domain' => $exception->getMessage()]);
        }

        return redirect()->route('admin.sites.manage.index')->with('message', '站点状态已更新');
    }

    public function destroy(Site $site): RedirectResponse
    {
        $admin = $this->authorizedAdmin();
        $this->authorizeSiteAccess($admin, $site);

        try {
            $this->customerDomainDeploymentManager->remove($site);
        } catch (CustomerDomainDeploymentException $exception) {
            return back()->withErrors(['custom_domain' => $exception->getMessage()]);
        }

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
     * @Name: validatedPayload
     *
     * @Description: 校验站点资料，并在管理员切换客户账号后固定站点所有者为当前账号。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-03 11:20:14
     *
     * @UpdateTime: 2026-11-03 11:37:29
     *
     * @Param: Request $request 当前 HTTP 请求
     *
     * @Param: ?Site $site 被更新的站点；创建时为 null
     *
     * @Param: ?Admin $actor 执行操作的管理员
     *
     * @Return: array{owner_admin_id:int|null,name:string,domain:string,custom_domain:string,status:string,customer_mode:string,agent_admin_id:int|null,member_ids:array<int,int>,monitoring_report_logo:string} 校验并规范化后的站点资料
     *
     * @Throws: ValidationException 字段验证失败或域名冲突时抛出
     */
    private function validatedPayload(Request $request, ?Site $site = null, ?Admin $actor = null): array
    {
        $actor ??= $this->authorizedAdmin();
        // 系统默认域名只在创建时写入，更新请求始终保留原值，避免被客户域名覆盖。
        $domainInput = $site instanceof Site
            ? (string) $site->domain
            : (string) $request->input('domain', '');
        $customDomainInput = $request->exists('custom_domain')
            ? (string) $request->input('custom_domain', '')
            : (string) ($site?->custom_domain ?? '');
        $request->merge([
            'domain' => $this->normalizeDomain($domainInput),
            'custom_domain' => $this->normalizeDomain($customDomainInput),
        ]);

        $currentSite = app(CurrentSite::class);

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

        if ($currentSite->isAccountScopeEnabled()) {
            $manageableAdminIds = $this->manageableAdminsQuery($actor)
                ->pluck('admins.id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $memberExistsRule = Rule::exists('admins', 'id')->whereIn('id', $manageableAdminIds);
        }

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'domain' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^[A-Za-z0-9.-]+$/',
            ],
            'custom_domain' => [
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
            'monitoring_report_logo' => ['nullable', 'string', 'max:500'],
        ], [
            'name.required' => '请填写站点名称',
            'domain.regex' => '系统默认域名只填写域名，不要包含路径或特殊字符',
            'custom_domain.regex' => '客户自定义域名只填写域名，不要包含路径或特殊字符',
            'status.in' => '站点状态不正确',
        ]);

        if ($currentSite->isAccountScopeEnabled()) {
            $payload['owner_admin_id'] = $currentSite->ownerAdminId();
        }

        $postedReportLogo = trim((string) ($payload['monitoring_report_logo'] ?? ''));
        $monitoringReportLogo = $actor->isSuperAdmin()
            ? $this->reportLogoResolver->normalizeLogoUrl($postedReportLogo)
            : '';

        if ($actor->isSuperAdmin() && $postedReportLogo !== '' && $monitoringReportLogo === '') {
            throw ValidationException::withMessages([
                'monitoring_report_logo' => '监测中心报表 Logo 只支持 http(s) 图片地址或站内绝对路径',
            ]);
        }

        if (($payload['domain'] ?? '') !== '') {
            $domainExists = Site::query()
                ->when($site instanceof Site, fn ($query) => $query->whereKeyNot($site->id))
                ->where(function ($query) use ($payload): void {
                    $query->where('domain', $payload['domain'])
                        ->orWhere('custom_domain', $payload['domain']);
                })
                ->exists();

            if ($domainExists) {
                throw ValidationException::withMessages([
                    'domain' => '该系统默认域名已经绑定到其他站点',
                ]);
            }
        }

        if (($payload['custom_domain'] ?? '') !== '') {
            $domainExists = Site::query()
                ->when($site instanceof Site, fn ($query) => $query->whereKeyNot($site->id))
                ->where(function ($query) use ($payload): void {
                    $query->where('domain', $payload['custom_domain'])
                        ->orWhere('custom_domain', $payload['custom_domain']);
                })
                ->exists();

            if ($domainExists) {
                throw ValidationException::withMessages([
                    'custom_domain' => '该客户自定义域名已经绑定到其他站点',
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
            'custom_domain' => trim((string) ($payload['custom_domain'] ?? '')),
            'status' => (string) $payload['status'],
            'customer_mode' => $customerMode,
            'agent_admin_id' => $agentAdminId,
            'member_ids' => $memberIds,
            'monitoring_report_logo' => $monitoringReportLogo,
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

    /**
     * @param  Collection<int,Site>  $sites
     * @return Collection<int,string>
     */
    private function monitoringReportLogosBySite(Collection $sites): Collection
    {
        $siteIds = $sites->pluck('id')->map(fn ($id): int => (int) $id)->filter()->unique()->values();
        if ($siteIds->isEmpty()) {
            return collect();
        }

        return SiteSetting::withoutGlobalScope('current_site')
            ->whereIn('site_id', $siteIds)
            ->where('setting_key', MonitoringReportLogoResolver::SETTING_KEY)
            ->pluck('setting_value', 'site_id')
            ->map(fn ($value): string => $this->reportLogoResolver->normalizeLogoUrl((string) $value));
    }

    /**
     * @param  array{owner_admin_id:int|null,monitoring_report_logo:string}  $payload
     */
    private function saveMonitoringReportLogo(Admin $actor, Site $site, array $payload): void
    {
        if (! $actor->isSuperAdmin()) {
            return;
        }

        $logoUrl = (string) ($payload['monitoring_report_logo'] ?? '');
        $query = SiteSetting::withoutGlobalScope('current_site')
            ->where('site_id', (int) $site->id)
            ->where('setting_key', MonitoringReportLogoResolver::SETTING_KEY);

        if ($logoUrl === '') {
            $query->delete();

            return;
        }

        SiteSetting::withoutGlobalScope('current_site')->updateOrCreate(
            [
                'site_id' => (int) $site->id,
                'setting_key' => MonitoringReportLogoResolver::SETTING_KEY,
            ],
            [
                'owner_admin_id' => (int) ($payload['owner_admin_id'] ?? 0) ?: null,
                'setting_value' => $logoUrl,
            ]
        );
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

    /**
     * @Name: authorizeSiteAccess
     *
     * @Description: 检查站点管理权限；切换客户账号后只能管理该账号所属且当前管理员有权访问的站点。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-03 11:20:14
     *
     * @UpdateTime: 2026-11-03 11:37:29
     *
     * @Param: Admin $admin 当前后台管理员
     *
     * @Param: Site $site 待访问的站点
     *
     * @Return: void
     *
     * @Throws: NotFoundHttpException 当前管理员无权访问该站点时返回 404
     */
    private function authorizeSiteAccess(Admin $admin, Site $site): void
    {
        $currentSite = app(CurrentSite::class);
        if ($currentSite->isAccountScopeEnabled()) {
            $visibleSiteIds = $this->adminDataScope->visibleSiteIds($admin) ?? [];
            abort_unless(in_array((int) $site->id, $visibleSiteIds, true), 404);

            return;
        }

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

    /**
     * @Name: visibleSitesQuery
     *
     * @Description: 生成站点管理列表查询；管理员切换账号后仅列出所选账号且当前管理员有权访问的站点。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-03 11:20:14
     *
     * @UpdateTime: 2026-11-03 11:37:29
     *
     * @Param: Admin $admin 当前后台管理员
     *
     * @Return: Builder<Site> 站点管理列表查询
     *
     * @Throws: 无
     */
    private function visibleSitesQuery(Admin $admin): Builder
    {
        $query = Site::query()
            ->when($admin->isAgentAdmin(), fn ($query) => $query
                ->where('customer_mode', 'agent')
                ->where('agent_admin_id', (int) $admin->id));

        $currentSite = app(CurrentSite::class);
        if ($admin->isSuperAdmin() || $currentSite->isAccountScopeEnabled()) {
            $this->adminDataScope->applySiteScope($query, $admin, 'sites.id');
        }

        return $query;
    }

    /**
     * @Name: manageableAdminsQuery
     *
     * @Description: 查询站点成员选项；切换账号后仅返回该账号站点内且当前管理员可管理的成员。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-03 11:20:14
     *
     * @UpdateTime: 2026-11-03 11:37:29
     *
     * @Param: Admin $admin 当前后台管理员
     *
     * @Return: Builder<Admin> 可管理管理员列表查询
     *
     * @Throws: 无
     */
    private function manageableAdminsQuery(Admin $admin): Builder
    {
        $query = Admin::query()
            ->select(['id', 'username', 'display_name', 'role', 'status'])
            ->when($admin->isAgentAdmin(), fn ($query) => $query
                ->where('role', 'site_user')
                ->where('created_by', (int) $admin->id))
            ->when($admin->isSuperAdmin(), fn ($query) => $query
                ->orderByRaw("CASE WHEN LOWER(COALESCE(role, '')) IN ('super_admin', 'superadmin') THEN 0 ELSE 1 END"))
            ->orderBy('username');

        $currentSite = app(CurrentSite::class);
        if ($currentSite->isAccountScopeEnabled()) {
            $siteIds = $this->adminDataScope->visibleSiteIds($admin) ?? [];
            $ownerAdminId = $currentSite->ownerAdminId();

            $query->where(function (Builder $accountQuery) use ($ownerAdminId, $siteIds): void {
                if ($ownerAdminId !== null) {
                    $accountQuery->whereKey($ownerAdminId);
                }

                if ($siteIds !== []) {
                    if ($ownerAdminId === null) {
                        $accountQuery->whereHas('sites', fn (Builder $siteQuery) => $siteQuery->whereIn('sites.id', $siteIds));
                    } else {
                        $accountQuery->orWhereHas('sites', fn (Builder $siteQuery) => $siteQuery->whereIn('sites.id', $siteIds));
                    }
                } elseif ($ownerAdminId === null) {
                    $accountQuery->whereRaw('1 = 0');
                }
            });
        }

        return $query;
    }

    /**
     * @param  Collection<int,Site>  $sites
     * @return Collection<string,AdminPlanSubscription>
     */
    private function accountPlanSubscriptionsBySite(Collection $sites): Collection
    {
        $siteIds = $sites->pluck('id')->map(fn ($id): int => (int) $id)->filter()->unique()->values();
        $ownerIds = $sites->pluck('owner_admin_id')->map(fn ($id): int => (int) $id)->filter()->unique()->values();

        if ($siteIds->isEmpty() || $ownerIds->isEmpty()) {
            return collect();
        }

        return AdminPlanSubscription::query()
            ->with('plan:id,name,code')
            ->whereIn('site_id', $siteIds)
            ->whereIn('admin_id', $ownerIds)
            ->activeNow()
            ->orderByDesc('ends_at')
            ->orderByDesc('id')
            ->get()
            ->unique(fn (AdminPlanSubscription $subscription): string => (int) $subscription->admin_id.':'.(int) $subscription->site_id)
            ->keyBy(fn (AdminPlanSubscription $subscription): string => (int) $subscription->admin_id.':'.(int) $subscription->site_id);
    }
}
