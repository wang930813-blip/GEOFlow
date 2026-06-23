<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\CrebeeAccount;
use App\Models\CrebeeAgent;
use App\Models\CrebeeBindRequest;
use App\Models\Site;
use App\Support\AdminDataScope;
use App\Support\AdminWeb;
use App\Support\Crebee\SelfMediaPlatformCatalog;
use App\Support\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CrebeeAccountController extends Controller
{
    public function __construct(private readonly AdminDataScope $adminDataScope) {}

    public function index(Request $request): View
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        $site = app(CurrentSite::class)->get();

        abort_unless($site instanceof Site || $admin->isAgentAdmin() || $admin->isSuperAdmin(), 403);

        $canManage = $this->canManageBindings($admin);
        $platforms = $this->platformCatalog();
        $platformKeys = array_keys($platforms);

        $boundAccounts = $this->visibleBoundAccounts($admin, $site)
            ->with(['agent:id,name,agent_uid,last_seen_at,crebee_status,version', 'owner:id,username,display_name,role', 'site:id,name'])
            ->whereIn('platform', $platformKeys)
            ->where('status', 'bound')
            ->orderByDesc('bound_at')
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'bound_page')
            ->withQueryString();

        $availableAccounts = $canManage
            ? CrebeeAccount::query()
                ->with(['agent:id,name,agent_uid,last_seen_at,crebee_status,version'])
                ->whereNull('site_id')
                ->whereNull('owner_admin_id')
                ->whereIn('platform', $platformKeys)
                ->where('status', 'available')
                ->orderByDesc('last_synced_at')
                ->orderByDesc('id')
                ->paginate(10, ['*'], 'available_page')
                ->withQueryString()
            : collect();

        $platformStates = $this->platformStates($admin, $site, $platformKeys);
        $bindRequests = $this->visibleBindRequests($admin, $site)
            ->with(['owner:id,username,display_name,role', 'operator:id,username,display_name,role', 'agent:id,name,agent_uid'])
            ->whereIn('platform', $platformKeys)
            ->orderByRaw("case status when 'pending' then 0 when 'processing' then 1 when 'failed' then 2 when 'confirmed' then 3 else 4 end")
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'request_page')
            ->withQueryString();

        return view('admin.crebee-accounts.index', [
            'pageTitle' => '自媒体账号绑定',
            'activeMenu' => 'crebee_accounts',
            'adminSiteName' => AdminWeb::siteName(),
            'site' => $site,
            'canManage' => $canManage,
            'showPlatformCatalog' => $site instanceof Site || $admin->isSuperAdmin(),
            'canRequestBinding' => $site instanceof Site && ! $admin->isAgentAdmin() && ! $admin->isSuperAdmin(),
            'platforms' => $platforms,
            'platformStates' => $platformStates,
            'bindRequests' => $bindRequests,
            'members' => $canManage && $site instanceof Site ? $this->bindableMembers($site) : collect(),
            'agents' => $canManage
                ? CrebeeAgent::query()->withCount('accounts')->orderByDesc('last_seen_at')->orderByDesc('id')->get()
                : collect(),
            'availableAccounts' => $availableAccounts,
            'boundAccounts' => $boundAccounts,
            'platformLabels' => $this->platformLabels(),
        ]);
    }

    public function storeRequest(Request $request): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        $site = app(CurrentSite::class)->get();
        abort_unless($site instanceof Site && $this->adminBelongsToSite($admin, $site), 403);

        $platforms = $this->platformCatalog();
        $payload = $request->validate([
            'platform' => ['required', 'string', Rule::in(array_keys($platforms))],
        ], [
            'platform.required' => '请选择要绑定的平台',
            'platform.in' => '暂不支持该自媒体平台',
        ]);
        $platform = (string) $payload['platform'];

        $alreadyBound = CrebeeAccount::query()
            ->where('site_id', (int) $site->id)
            ->where('owner_admin_id', (int) $admin->id)
            ->where('platform', $platform)
            ->where('status', 'bound')
            ->exists();

        if ($alreadyBound) {
            return back()->withErrors(['platform' => '该平台账号已绑定，无需重复申请']);
        }

        $hasActiveRequest = CrebeeBindRequest::query()
            ->where('site_id', (int) $site->id)
            ->where('owner_admin_id', (int) $admin->id)
            ->where('platform', $platform)
            ->whereIn('status', $this->activeRequestStatuses())
            ->where(function (Builder $query): void {
                $query->whereNull('expired_at')
                    ->orWhere('expired_at', '>', now());
            })
            ->exists();

        if ($hasActiveRequest) {
            return back()->withErrors(['platform' => '该平台已有待处理绑定申请，请等待运营处理']);
        }

        CrebeeBindRequest::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'platform' => $platform,
            'status' => 'pending',
            'requested_at' => now(),
            'expired_at' => now()->addHours(2),
            'meta' => [
                'platform_label' => (string) ($platforms[$platform]['label'] ?? $platform),
            ],
        ]);

        return redirect()->route('admin.crebee-accounts.index')->with('message', '绑定申请已提交，请等待运营发送登录二维码');
    }

    public function markRequestProcessing(CrebeeBindRequest $bindRequest, Request $request): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin && $this->canManageBindings($admin), 403);

        $site = app(CurrentSite::class)->get();
        if (! $admin->isSuperAdmin()) {
            abort_unless($site instanceof Site, 403);
            $this->assertCurrentSiteRequest($bindRequest, $site);
        }

        abort_unless(in_array((string) $bindRequest->status, $this->activeRequestStatuses(), true), 404);

        $bindRequest->forceFill([
            'status' => 'processing',
            'operator_admin_id' => (int) $admin->id,
        ])->save();

        return redirect()->route('admin.crebee-accounts.index')->with('message', '绑定申请已标记为处理中');
    }

    public function failRequest(CrebeeBindRequest $bindRequest, Request $request): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin && $this->canManageBindings($admin), 403);

        $site = app(CurrentSite::class)->get();
        if (! $admin->isSuperAdmin()) {
            abort_unless($site instanceof Site, 403);
            $this->assertCurrentSiteRequest($bindRequest, $site);
        }

        $payload = $request->validate([
            'failure_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $bindRequest->forceFill([
            'status' => 'failed',
            'operator_admin_id' => (int) $admin->id,
            'failure_reason' => trim((string) ($payload['failure_reason'] ?? '')),
        ])->save();

        return redirect()->route('admin.crebee-accounts.index')->with('message', '绑定申请已标记失败');
    }

    public function bind(CrebeeAccount $account, Request $request): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin && $this->canManageBindings($admin), 403);

        $site = app(CurrentSite::class)->get();
        abort_unless($site instanceof Site, 403);

        $payload = $request->validate([
            'owner_admin_id' => ['required', 'integer'],
        ], [
            'owner_admin_id.required' => '请选择要绑定的系统用户',
        ]);

        abort_if($account->site_id !== null || $account->owner_admin_id !== null || (string) $account->status !== 'available', 404);

        $owner = $this->bindableMembers($site)
            ->where('id', (int) $payload['owner_admin_id'])
            ->first();

        if (! $owner instanceof Admin) {
            return back()->withErrors(['owner_admin_id' => '绑定用户不属于当前站点']);
        }

        $account->forceFill([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'status' => 'bound',
            'bound_at' => now(),
        ])->save();

        CrebeeBindRequest::query()
            ->where('site_id', (int) $site->id)
            ->where('owner_admin_id', (int) $owner->id)
            ->where('platform', (string) $account->platform)
            ->whereIn('status', $this->activeRequestStatuses())
            ->update([
                'status' => 'confirmed',
                'agent_id' => (int) $account->agent_id,
                'operator_admin_id' => (int) $admin->id,
                'confirmed_at' => now(),
                'updated_at' => now(),
            ]);

        return redirect()->route('admin.crebee-accounts.index')->with('message', '自媒体账号已绑定');
    }

    public function unbind(CrebeeAccount $account, Request $request): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin && $this->canManageBindings($admin), 403);

        $site = app(CurrentSite::class)->get();
        if (! $admin->isSuperAdmin()) {
            abort_unless($site instanceof Site, 403);
            abort_if((int) $account->site_id !== (int) $site->id, 404);
        }

        $account->forceFill([
            'site_id' => null,
            'owner_admin_id' => null,
            'status' => 'available',
            'bound_at' => null,
        ])->save();

        return redirect()->route('admin.crebee-accounts.index')->with('message', '自媒体账号已解绑');
    }

    private function canManageBindings(Admin $admin): bool
    {
        return $admin->isSuperAdmin();
    }

    private function visibleBoundAccounts(Admin $admin, ?Site $site): Builder
    {
        $query = CrebeeAccount::query();

        if ($admin->isSuperAdmin()) {
            return $query;
        }

        if ($site instanceof Site) {
            $query->where('site_id', (int) $site->id);
        } else {
            $this->adminDataScope->applySiteScope($query, $admin);
        }

        if ($admin->isSuperAdmin() || $admin->isAgentAdmin()) {
            return $query;
        }

        return $query->where('owner_admin_id', (int) $admin->id);
    }

    private function visibleBindRequests(Admin $admin, ?Site $site): Builder
    {
        $query = CrebeeBindRequest::query();

        if ($admin->isSuperAdmin()) {
            return $query;
        }

        if ($site instanceof Site) {
            $query->where('site_id', (int) $site->id);
        } else {
            $this->adminDataScope->applySiteScope($query, $admin);
        }

        if ($admin->isSuperAdmin() || $admin->isAgentAdmin()) {
            return $query;
        }

        return $query->where('owner_admin_id', (int) $admin->id);
    }

    private function adminBelongsToSite(Admin $admin, Site $site): bool
    {
        return $admin->sites()
            ->where('sites.id', (int) $site->id)
            ->exists();
    }

    private function assertCurrentSiteRequest(CrebeeBindRequest $bindRequest, Site $site): void
    {
        abort_if((int) $bindRequest->site_id !== (int) $site->id, 404);
    }

    /**
     * @param  array<int,string>  $platformKeys
     * @return array<string,array<string,mixed>>
     */
    private function platformStates(Admin $admin, ?Site $site, array $platformKeys): array
    {
        if (! $site instanceof Site) {
            return collect($platformKeys)
                ->mapWithKeys(static fn (string $platform): array => [$platform => [
                    'status' => 'available',
                    'account' => null,
                    'request' => null,
                    'can_request' => false,
                ]])
                ->all();
        }

        $boundAccounts = CrebeeAccount::query()
            ->where('site_id', (int) $site->id)
            ->where('owner_admin_id', (int) $admin->id)
            ->whereIn('platform', $platformKeys)
            ->where('status', 'bound')
            ->get()
            ->keyBy('platform');

        $requests = CrebeeBindRequest::query()
            ->where('site_id', (int) $site->id)
            ->where('owner_admin_id', (int) $admin->id)
            ->whereIn('platform', $platformKeys)
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('platform');

        $states = [];
        foreach ($platformKeys as $platform) {
            $account = $boundAccounts->get($platform);
            $latestRequest = $requests->get($platform)?->first();
            $latestRequestIsActive = $latestRequest instanceof CrebeeBindRequest
                && in_array((string) $latestRequest->status, $this->activeRequestStatuses(), true)
                && ($latestRequest->expired_at === null || $latestRequest->expired_at->gt(now()));
            $status = 'available';

            if ($account instanceof CrebeeAccount) {
                $status = 'bound';
            } elseif ($latestRequest instanceof CrebeeBindRequest) {
                $status = $latestRequestIsActive ? (string) $latestRequest->status : 'expired';
            }

            $states[$platform] = [
                'status' => $status,
                'account' => $account,
                'request' => $latestRequest,
                'can_request' => ! ($account instanceof CrebeeAccount)
                    && ! $latestRequestIsActive,
            ];
        }

        return $states;
    }

    private function bindableMembers(Site $site)
    {
        return Admin::query()
            ->where('status', 'active')
            ->whereHas('sites', fn (Builder $query) => $query->where('sites.id', (int) $site->id))
            ->orderByRaw("case role when 'agent_admin' then 0 when 'direct_admin' then 1 when 'site_user' then 2 else 3 end")
            ->orderBy('id')
            ->get(['id', 'username', 'display_name', 'role']);
    }

    /**
     * @return array<string,string>
     */
    private function platformLabels(): array
    {
        return SelfMediaPlatformCatalog::labels();
    }

    /**
     * @return array<string,array{label:string,desc:string,logo:string}>
     */
    private function platformCatalog(): array
    {
        return SelfMediaPlatformCatalog::all();
    }

    /**
     * @return array<int,string>
     */
    private function activeRequestStatuses(): array
    {
        return ['pending', 'processing'];
    }
}
