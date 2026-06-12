<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\PlatformPlan;
use App\Models\SiteMember;
use App\Services\Billing\ResourceQuotaService;
use App\Support\AdminWeb;
use App\Support\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AgentUserController extends Controller
{
    public function __construct(
        private readonly ResourceQuotaService $quotaService
    ) {}

    public function index(): View
    {
        $site = app(CurrentSite::class)->get();
        abort_unless($site !== null, 403);

        $members = Admin::query()
            ->whereHas('sites', fn ($query) => $query->where('sites.id', (int) $site->id))
            ->where('role', 'site_user')
            ->orderBy('id')
            ->get();

        return view('admin.agent-users.index', [
            'pageTitle' => '代理用户管理',
            'activeMenu' => 'agent_users',
            'adminSiteName' => AdminWeb::siteName(),
            'members' => $members,
            'quota' => $this->teamMemberQuota((int) $site->id),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $site = app(CurrentSite::class)->get();
        abort_unless($site !== null, 403);

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

        if (! $this->canCreateMember((int) $site->id)) {
            return back()->withErrors(['username' => '子账号数量已达到当前规格上限'])->withInput();
        }

        DB::transaction(function () use ($payload, $site): void {
            $admin = Admin::query()->create([
                'username' => trim((string) $payload['username']),
                'display_name' => trim((string) ($payload['display_name'] ?? '')),
                'email' => trim((string) ($payload['email'] ?? '')),
                'password' => (string) $payload['password'],
                'role' => 'site_user',
                'status' => 'active',
                'created_by' => (int) (auth('admin')->id() ?? 0),
            ]);

            $site->members()->attach((int) $admin->id, ['role' => 'member']);
        });

        return redirect()->route('admin.agent-users.index')->with('message', '普通用户已创建');
    }

    public function toggleStatus(int $adminId, Request $request): RedirectResponse
    {
        $site = app(CurrentSite::class)->get();
        abort_unless($site !== null, 403);

        $target = Admin::query()
            ->whereKey($adminId)
            ->where('role', 'site_user')
            ->whereHas('sites', fn ($query) => $query->where('sites.id', (int) $site->id))
            ->firstOrFail();
        $nextStatus = (string) $request->input('next_status', '') === 'active' ? 'active' : 'inactive';
        if ($nextStatus === 'active' && (string) $target->status !== 'active' && ! $this->canCreateMember((int) $site->id)) {
            return back()->withErrors(['user' => '子账号数量已达到当前规格上限']);
        }

        $target->update(['status' => $nextStatus]);

        return redirect()->route('admin.agent-users.index')->with('message', '用户状态已更新');
    }

    /**
     * @return array{quota:int|null,used:int,remaining:int|null,period:string}
     */
    private function teamMemberQuota(int $siteId): array
    {
        $activeMemberCount = $this->activeMemberCount($siteId);

        try {
            $quota = $this->quotaService->remaining($siteId, PlatformPlan::RESOURCE_TEAM_MEMBERS);

            return [
                'quota' => $quota['quota'],
                'used' => $activeMemberCount,
                'remaining' => $quota['quota'] === null ? null : max(0, (int) $quota['quota'] - $activeMemberCount),
                'period' => $quota['period'],
            ];
        } catch (\Throwable) {
            return ['quota' => 0, 'used' => $activeMemberCount, 'remaining' => 0, 'period' => 'cycle'];
        }
    }

    private function canCreateMember(int $siteId): bool
    {
        $quota = $this->teamMemberQuota($siteId);
        if ($quota['quota'] === null) {
            return true;
        }

        return $this->activeMemberCount($siteId) < (int) $quota['quota'];
    }

    private function activeMemberCount(int $siteId): int
    {
        return SiteMember::query()
            ->where('site_id', $siteId)
            ->whereHas('admin', fn ($query) => $query->where('role', 'site_user')->where('status', 'active'))
            ->count();
    }
}
