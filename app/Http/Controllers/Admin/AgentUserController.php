<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminPlanSubscription;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Services\Billing\AdminPlanSubscriptionService;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                ->select(['sites.id', 'sites.name', 'sites.owner_admin_id', 'sites.customer_mode', 'sites.agent_admin_id'])])
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
            'username' => ['required', 'string', 'regex:/^[A-Za-z0-9_.-]{3,50}$/', 'unique:admins,username'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:191'],
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
                'email' => trim((string) ($payload['email'] ?? '')),
                'password' => (string) $payload['password'],
                'role' => 'site_user',
                'status' => 'active',
                'created_by' => (int) $agent->id,
            ]);

            $userSite = Site::query()->create([
                'owner_admin_id' => (int) $admin->id,
                'name' => $this->defaultUserSiteName($admin),
                'domain' => '',
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

    public function toggleStatus(int $adminId, Request $request): RedirectResponse
    {
        $agent = auth('admin')->user();
        abort_unless($agent instanceof Admin && $agent->isAgentAdmin(), 403);

        $target = Admin::query()
            ->whereKey($adminId)
            ->where('role', 'site_user')
            ->where('created_by', (int) $agent->id)
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
        return Admin::query()
            ->where('role', 'site_user')
            ->where('created_by', (int) $agent->id)
            ->where('status', 'active')
            ->count();
    }

    private function defaultUserSiteName(Admin $admin): string
    {
        return $admin->name.' 的前台站点';
    }
}
