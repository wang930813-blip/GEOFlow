<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminPlanSubscription;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Services\Billing\AdminPlanSubscriptionService;
use App\Support\AdminWeb;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class AgentUserController extends Controller
{
    public function __construct(
        private readonly AdminPlanSubscriptionService $adminSubscriptionService
    ) {}

    public function index(): View
    {
        $agent = auth('admin')->user();
        abort_unless($agent instanceof Admin && $agent->isAgentAdmin(), 403);

        $members = Admin::query()
            ->select(['id', 'username', 'display_name', 'email', 'role', 'status', 'created_by'])
            ->where('role', 'site_user')
            ->where('created_by', (int) $agent->id)
            ->with(['sites' => fn ($query) => $query
                ->where('customer_mode', 'agent')
                ->where('agent_admin_id', (int) $agent->id)
                ->select(['sites.id', 'sites.name', 'sites.domain', 'sites.owner_admin_id', 'sites.customer_mode', 'sites.agent_admin_id'])])
            ->with(['accountPlanSubscriptions' => fn ($query) => $query
                ->select(['id', 'admin_id', 'site_id', 'plan_id', 'mode', 'status', 'starts_at', 'ends_at', 'created_at'])
                ->where('mode', 'agent_user')
                ->activeNow()
                ->with('plan:id,name,code')
                ->orderByDesc('ends_at')
                ->orderByDesc('id')])
            ->orderBy('id')
            ->get();

        return view('admin.agent-users.index', [
            'pageTitle' => '代理用户管理',
            'activeMenu' => 'agent_users',
            'adminSiteName' => AdminWeb::siteName(),
            'members' => $members,
            'quota' => $this->teamMemberQuota($agent),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $agent = auth('admin')->user();
        abort_unless($agent instanceof Admin && $agent->isAgentAdmin(), 403);

        $payload = $request->validate([
            'username' => [
                'required',
                'string',
                'regex:/^[A-Za-z0-9_.-]{3,50}$/',
                Rule::unique('admins', 'username')->whereNull('deleted_at'),
            ],
            'display_name' => ['nullable', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:8', 'same:confirm_password'],
            'confirm_password' => ['required', 'string', 'min:8'],
        ], [
            'username.required' => '请填写用户名',
            'username.regex' => '用户名只能包含字母、数字、下划线、点和短横线',
            'username.unique' => '用户名已存在',
            'password.same' => '两次输入的密码不一致',
        ]);

        if (! $this->canCreateMember($agent)) {
            return back()->withErrors(['username' => '子账号数量已达到当前规格上限'])->withInput();
        }

        DB::transaction(function () use ($payload, $agent): void {
            $admin = Admin::query()->create([
                'username' => trim((string) $payload['username']),
                'display_name' => trim((string) ($payload['display_name'] ?? '')),
                'email' => '',
                'password' => (string) $payload['password'],
                'role' => 'site_user',
                'status' => 'active',
                'created_by' => (int) $agent->id,
            ]);

            $sitePrefix = $this->randomUserSitePrefix();
            $userSite = Site::query()->create([
                'owner_admin_id' => (int) $admin->id,
                'name' => $sitePrefix,
                'domain' => $this->customerSiteDomain($sitePrefix),
                'status' => 'active',
                'customer_mode' => 'agent',
                'agent_admin_id' => (int) $agent->id,
            ]);
            $userSite->members()->attach((int) $admin->id, ['role' => 'owner']);

            $this->adminSubscriptionService->inheritForAgentUserFromAccount(
                agent: $agent,
                user: $admin,
                userSite: $userSite,
                operator: $agent
            );
        });

        return redirect()->route('admin.agent-users.index')->with('message', '普通用户已创建');
    }

    public function update(int $adminId, Request $request): RedirectResponse
    {
        $agent = auth('admin')->user();
        abort_unless($agent instanceof Admin && $agent->isAgentAdmin(), 403);

        $target = $this->agentMemberQuery($agent)
            ->whereKey($adminId)
            ->firstOrFail();

        $payload = $request->validate([
            'display_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:191'],
            'password' => ['nullable', 'string', 'min:8', 'same:confirm_password'],
            'confirm_password' => ['nullable', 'string', 'min:8'],
        ], [
            'password.same' => '两次输入的密码不一致',
            'password.min' => '密码不能少于 8 位',
            'confirm_password.min' => '密码不能少于 8 位',
        ]);

        $attributes = [];

        if (array_key_exists('display_name', $payload)) {
            $attributes['display_name'] = trim((string) ($payload['display_name'] ?? ''));
        }

        if (array_key_exists('email', $payload)) {
            $attributes['email'] = trim((string) ($payload['email'] ?? ''));
        }

        if (filled($payload['password'] ?? null)) {
            $attributes['password'] = (string) $payload['password'];
        }

        $target->update($attributes);

        return redirect()->route('admin.agent-users.index')->with('message', '用户信息已更新');
    }

    public function toggleStatus(int $adminId, Request $request): RedirectResponse
    {
        $agent = auth('admin')->user();
        abort_unless($agent instanceof Admin && $agent->isAgentAdmin(), 403);

        $target = $this->agentMemberQuery($agent)
            ->whereKey($adminId)
            ->firstOrFail();

        $nextStatus = (string) $request->input('next_status', '') === 'active' ? 'active' : 'inactive';
        if ($nextStatus === 'active' && (string) $target->status !== 'active' && ! $this->canCreateMember($agent)) {
            return back()->withErrors(['user' => '子账号数量已达到当前规格上限']);
        }

        $target->update(['status' => $nextStatus]);

        return redirect()->route('admin.agent-users.index')->with('message', '用户状态已更新');
    }

    /**
     * @return array{quota:int|null,used:int,remaining:int|null,period:string}
     */
    private function teamMemberQuota(Admin $agent): array
    {
        $activeMemberCount = $this->activeMemberCount($agent);

        try {
            $subscription = $this->adminSubscriptionService->activeAgentOwnerSubscription($agent);
            if (! $subscription instanceof AdminPlanSubscription) {
                throw new RuntimeException('No active agent subscription.');
            }

            $entitlement = (array) data_get((array) $subscription->entitlements_snapshot, PlatformPlan::RESOURCE_TEAM_MEMBERS, []);
            if (! (bool) ($entitlement['enabled'] ?? false)) {
                throw new RuntimeException('Team member quota is not enabled.');
            }

            $quotaValue = (int) ($entitlement['quota_value'] ?? 0);
            $period = (string) ($entitlement['quota_period'] ?? 'cycle');
            $isUnlimited = $period === 'unlimited' || $quotaValue <= 0;

            return [
                'quota' => $isUnlimited ? null : $quotaValue,
                'used' => $activeMemberCount,
                'remaining' => $isUnlimited ? null : max(0, $quotaValue - $activeMemberCount),
                'period' => $isUnlimited ? 'unlimited' : $period,
            ];
        } catch (\Throwable) {
            return ['quota' => 0, 'used' => $activeMemberCount, 'remaining' => 0, 'period' => 'cycle'];
        }
    }

    private function canCreateMember(Admin $agent): bool
    {
        $quota = $this->teamMemberQuota($agent);
        if ($quota['quota'] === null) {
            return true;
        }

        return $this->activeMemberCount($agent) < (int) $quota['quota'];
    }

    private function activeMemberCount(Admin $agent): int
    {
        return $this->agentMemberQuery($agent)
            ->where('status', 'active')
            ->count();
    }

    /**
     * @return Builder<Admin>
     */
    private function agentMemberQuery(Admin $agent): Builder
    {
        return Admin::query()
            ->where('role', 'site_user')
            ->where('created_by', (int) $agent->id);
    }

    private function randomUserSitePrefix(): string
    {
        do {
            $name = Str::lower(Str::random(8));
        } while (
            Site::query()->where('name', $name)->exists()
            || Site::query()->where('domain', $this->customerSiteDomain($name))->exists()
        );

        return $name;
    }

    private function customerSiteDomain(string $prefix): string
    {
        $base = trim((string) config('geoflow.customer_site_domain_base', 'geo.xinzhidi.cn'), " \t\n\r\0\x0B.");

        return $base !== '' ? $prefix.'.'.$base : '';
    }
}
