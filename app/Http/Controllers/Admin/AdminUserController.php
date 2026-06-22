<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Services\Billing\AdminPlanSubscriptionService;
use App\Services\Billing\PlanSubscriptionService;
use App\Support\AdminWeb;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * 管理员管理控制器（超级管理员专用）。
 *
 * 对齐 bak/admin/admin-users.php 核心能力：
 * 1. 查看管理员列表及统计；
 * 2. 创建普通管理员账号；
 * 3. 编辑、启停、删除普通管理员账号。
 */
class AdminUserController extends Controller
{
    public function __construct(
        private readonly PlanSubscriptionService $subscriptionService,
        private readonly AdminPlanSubscriptionService $adminSubscriptionService
    ) {}

    /**
     * 管理员管理首页。
     */
    public function index(): View
    {
        $admins = $this->loadAdmins();

        return view('admin.admin-users.index', [
            'pageTitle' => __('admin.admin_users.page_title'),
            'activeMenu' => 'admin_users',
            'adminSiteName' => AdminWeb::siteName(),
            'admins' => $admins,
            'stats' => [
                'total_admins' => count($admins),
                'active_admins' => count(array_filter($admins, static fn (array $admin): bool => $admin['status'] === 'active')),
                'super_admins' => count(array_filter($admins, static fn (array $admin): bool => $admin['is_super_admin'])),
            ],
            'currentAdminId' => (int) (auth('admin')->id() ?? 0),
            'plans' => PlatformPlan::query()
                ->where('status', 'active')
                ->with('entitlements')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
        ]);
    }

    /**
     * 编辑管理员基础信息；超级管理员只能编辑自己，密码留空时不修改。
     */
    public function update(int $adminId, Request $request): RedirectResponse
    {
        if ($adminId <= 0) {
            return back()->withErrors(__('admin.admin_users.error.invalid_id'));
        }

        $targetAdmin = Admin::query()->whereKey($adminId)->firstOrFail();
        $currentAdminId = (int) (auth('admin')->id() ?? 0);
        $isSelf = (int) $targetAdmin->id === $currentAdminId;
        if ($targetAdmin->isSuperAdmin() && ! $isSelf) {
            return back()->withErrors(__('admin.admin_users.error.cannot_edit_super_admin'));
        }

        $payload = $request->validate([
            'username' => [
                'required',
                'string',
                'regex:/^[A-Za-z0-9_.-]{3,50}$/',
                Rule::unique('admins', 'username')->ignore($targetAdmin->id),
            ],
            'display_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:191'],
            'role' => ['nullable', Rule::in(['admin', 'agent_admin', 'direct_admin', 'site_user'])],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'password' => ['nullable', 'string', 'min:8', 'same:confirm_password'],
            'confirm_password' => ['nullable', 'string', 'min:8'],
        ], [
            'username.required' => __('admin.admin_users.error.username_required'),
            'username.regex' => __('admin.admin_users.error.username_invalid'),
            'username.unique' => __('admin.admin_users.error.username_exists'),
            'status.required' => __('admin.admin_users.error.status_invalid'),
            'status.in' => __('admin.admin_users.error.status_invalid'),
            'password.same' => __('admin.admin_users.error.password_mismatch'),
            'password.min' => __('admin.admin_users.error.password_too_short'),
            'confirm_password.min' => __('admin.admin_users.error.password_too_short'),
        ]);

        try {
            $attributes = [
                'username' => trim((string) $payload['username']),
                'display_name' => trim((string) ($payload['display_name'] ?? '')),
                'email' => trim((string) ($payload['email'] ?? '')),
                'role' => $targetAdmin->isSuperAdmin() ? (string) $targetAdmin->role : (string) ($payload['role'] ?? $targetAdmin->role ?? 'admin'),
                'status' => $isSelf ? (string) $targetAdmin->status : (string) $payload['status'],
            ];

            if (filled($payload['password'] ?? null)) {
                $attributes['password'] = (string) $payload['password'];
            }

            $targetAdmin->update($attributes);

            return redirect()->route('admin.admin-users.index')->with('message', __('admin.admin_users.message.update_success'));
        } catch (Throwable $exception) {
            return back()->withErrors(__('admin.admin_users.message.update_error', ['message' => $exception->getMessage()]))->withInput();
        }
    }

    /**
     * 创建普通管理员。
     */
    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'site_domain' => $this->normalizeDomain((string) $request->input('site_domain', '')),
        ]);

        $payload = $request->validate([
            'username' => ['required', 'string', 'regex:/^[A-Za-z0-9_.-]{3,50}$/', 'unique:admins,username'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:191'],
            'role' => ['nullable', Rule::in(['admin', 'agent_admin', 'direct_admin'])],
            'password' => ['required', 'string', 'min:8', 'same:confirm_password'],
            'confirm_password' => ['required', 'string', 'min:8'],
            'open_customer_subscription' => ['nullable'],
            'site_name' => ['nullable', 'string', 'max:120'],
            'site_domain' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^[A-Za-z0-9.-]+$/',
            ],
            'plan_id' => ['nullable', 'integer', Rule::exists('platform_plans', 'id')],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'grant_credits' => ['nullable'],
            'subscription_remark' => ['nullable', 'string', 'max:1000'],
        ], [
            'username.required' => __('admin.admin_users.error.username_required'),
            'username.regex' => __('admin.admin_users.error.username_invalid'),
            'username.unique' => __('admin.admin_users.error.username_exists'),
            'site_domain.regex' => '站点域名只填写域名，不要包含协议、路径或特殊字符',
            'password.required' => __('admin.admin_users.error.password_required'),
            'confirm_password.required' => __('admin.admin_users.error.password_required'),
            'password.same' => __('admin.admin_users.error.password_mismatch'),
            'password.min' => __('admin.admin_users.error.password_too_short'),
            'confirm_password.min' => __('admin.admin_users.error.password_too_short'),
        ]);

        $role = (string) ($payload['role'] ?? 'admin');
        $shouldOpenSubscription = (bool) ($payload['open_customer_subscription'] ?? false);
        if ($shouldOpenSubscription && $role === 'agent_admin') {
            throw ValidationException::withMessages([
                'open_customer_subscription' => '代理账号只用于管理下级用户，不在创建账号时同步创建前台站点',
            ]);
        }
        if ($shouldOpenSubscription && ! in_array($role, ['agent_admin', 'direct_admin'], true)) {
            throw ValidationException::withMessages([
                'open_customer_subscription' => '只有代理管理员或直客管理员可以同步开户',
            ]);
        }
        if ($shouldOpenSubscription) {
            if (trim((string) ($payload['site_name'] ?? '')) === '') {
                throw ValidationException::withMessages(['site_name' => '同步开户时请填写站点名称']);
            }
            if ((int) ($payload['plan_id'] ?? 0) <= 0) {
                throw ValidationException::withMessages(['plan_id' => '同步开户时请选择规格']);
            }

            $mode = 'direct';
            $plan = PlatformPlan::query()
                ->whereKey((int) $payload['plan_id'])
                ->where('status', 'active')
                ->first();
            if (! $plan instanceof PlatformPlan) {
                throw ValidationException::withMessages(['plan_id' => '请选择有效规格']);
            }
            if (! in_array((string) $plan->audience, ['both', $mode], true)) {
                throw ValidationException::withMessages(['plan_id' => '所选规格不适用于当前客户角色']);
            }
            $domain = trim((string) ($payload['site_domain'] ?? ''));
            if ($domain !== '' && Site::query()->where('domain', $domain)->exists()) {
                throw ValidationException::withMessages(['site_domain' => '该站点域名已经绑定到其他站点']);
            }
        }

        try {
            DB::transaction(function () use ($payload, $role, $shouldOpenSubscription): void {
                $admin = Admin::query()->create([
                    'username' => trim((string) $payload['username']),
                    'display_name' => trim((string) ($payload['display_name'] ?? '')),
                    'email' => trim((string) ($payload['email'] ?? '')),
                    'password' => (string) $payload['password'],
                    'role' => $role,
                    'status' => 'active',
                    'created_by' => (int) (auth('admin')->id() ?? 0),
                ]);

                if (! $shouldOpenSubscription) {
                    return;
                }

                $mode = 'direct';
                $operator = auth('admin')->user();
                if (! $operator instanceof Admin) {
                    throw ValidationException::withMessages(['username' => '当前登录状态已失效']);
                }

                $site = Site::query()->create([
                    'owner_admin_id' => (int) $admin->id,
                    'name' => trim((string) $payload['site_name']),
                    'domain' => trim((string) ($payload['site_domain'] ?? '')),
                    'status' => 'active',
                    'customer_mode' => $mode,
                    'agent_admin_id' => null,
                ]);
                $site->members()->attach((int) $admin->id, ['role' => 'owner']);

                $startsAt = isset($payload['starts_at']) && (string) $payload['starts_at'] !== ''
                    ? Carbon::parse((string) $payload['starts_at'])
                    : now();
                $endsAt = isset($payload['ends_at']) && (string) $payload['ends_at'] !== ''
                    ? Carbon::parse((string) $payload['ends_at'])
                    : null;

                $plan = PlatformPlan::query()->with('entitlements')->findOrFail((int) $payload['plan_id']);
                $this->subscriptionService->open(
                    site: $site,
                    plan: $plan,
                    mode: $mode,
                    ownerAdmin: $admin,
                    operator: $operator,
                    startsAt: $startsAt,
                    endsAt: $endsAt,
                    grantCredits: (bool) ($payload['grant_credits'] ?? false),
                    remark: (string) ($payload['subscription_remark'] ?? '')
                );

                $this->adminSubscriptionService->openOwner(
                    admin: $admin,
                    site: $site,
                    plan: $plan,
                    mode: 'direct_owner',
                    operator: $operator,
                    startsAt: $startsAt,
                    endsAt: $endsAt,
                    grantCredits: (bool) ($payload['grant_credits'] ?? false),
                    remark: (string) ($payload['subscription_remark'] ?? '')
                );
            });

            return redirect()->route('admin.admin-users.index')->with('message', __('admin.admin_users.message.create_success'));
        } catch (Throwable $exception) {
            return back()->withErrors(__('admin.admin_users.message.create_error', ['message' => $exception->getMessage()]))->withInput();
        }
    }

    /**
     * 切换普通管理员状态（启用/停用）。
     */
    public function toggleStatus(int $adminId, Request $request): RedirectResponse
    {
        if ($adminId <= 0) {
            return back()->withErrors(__('admin.admin_users.error.invalid_id'));
        }

        $targetAdmin = Admin::query()->whereKey($adminId)->firstOrFail();
        $currentAdminId = (int) (auth('admin')->id() ?? 0);
        if ((int) $targetAdmin->id === $currentAdminId) {
            return back()->withErrors(__('admin.admin_users.error.cannot_toggle_self'));
        }
        if ($targetAdmin->isSuperAdmin()) {
            return back()->withErrors(__('admin.admin_users.error.cannot_toggle_super_admin'));
        }

        $requestedNextStatus = (string) $request->input('next_status', '');
        $nextStatus = $requestedNextStatus === 'active' ? 'active' : 'inactive';

        try {
            $targetAdmin->update([
                'status' => $nextStatus,
            ]);

            $messageKey = $nextStatus === 'active'
                ? 'admin.admin_users.message.enabled'
                : 'admin.admin_users.message.disabled';

            return redirect()->route('admin.admin-users.index')->with('message', __($messageKey));
        } catch (Throwable $exception) {
            return back()->withErrors(__('admin.admin_users.message.toggle_error', ['message' => $exception->getMessage()]));
        }
    }

    /**
     * 删除普通管理员账号。
     */
    public function destroy(int $adminId): RedirectResponse
    {
        if ($adminId <= 0) {
            return back()->withErrors(__('admin.admin_users.error.invalid_id'));
        }

        $targetAdmin = Admin::query()->whereKey($adminId)->firstOrFail();
        $currentAdminId = (int) (auth('admin')->id() ?? 0);
        if ((int) $targetAdmin->id === $currentAdminId) {
            return back()->withErrors(__('admin.admin_users.error.cannot_delete_self'));
        }
        if ($targetAdmin->isSuperAdmin()) {
            return back()->withErrors(__('admin.admin_users.error.cannot_delete_super_admin'));
        }

        try {
            DB::transaction(static function () use ($targetAdmin, $currentAdminId): void {
                DB::table('admins')
                    ->where('created_by', $targetAdmin->id)
                    ->update(['created_by' => null]);

                if (Schema::hasTable('article_reviews')) {
                    // article_reviews.admin_id is non-null in the legacy schema; keep old review rows valid.
                    DB::table('article_reviews')
                        ->where('admin_id', $targetAdmin->id)
                        ->update(['admin_id' => $currentAdminId]);
                }

                $targetAdmin->delete();
            });

            return redirect()->route('admin.admin-users.index')->with('message', __('admin.admin_users.message.delete_success'));
        } catch (Throwable $exception) {
            return back()->withErrors(__('admin.admin_users.message.delete_error', ['message' => $exception->getMessage()]));
        }
    }

    /**
     * @return array<int, array{
     *   id:int,
     *   username:string,
     *   email:string,
     *   display_name:string,
     *   role:string,
     *   status:string,
     *   is_super_admin:bool,
     *   last_login:string,
     *   created_at:string,
     *   creator_username:string,
     *   activity_count:int
     * }>
     */
    private function loadAdmins(): array
    {
        $query = Admin::query()
            ->select([
                'id',
                'username',
                'email',
                'display_name',
                'role',
                'status',
                'last_login',
                'created_at',
                'created_by',
            ])
            ->with(['creator:id,username,display_name,role'])
            // 与 bak 一致：超级管理员置顶，其余按创建时间和 ID 升序。
            ->orderByRaw("CASE WHEN LOWER(COALESCE(role, '')) IN ('super_admin', 'superadmin') THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->orderBy('id');

        if (Schema::hasTable('admin_activity_logs')) {
            $query->withCount('activityLogs as activity_count');
        }

        $admins = $query->get();

        return $admins->map(function (Admin $admin): array {
            return [
                'id' => (int) $admin->id,
                'username' => (string) ($admin->username ?? ''),
                'email' => (string) ($admin->email ?? ''),
                'display_name' => (string) ($admin->display_name ?? ''),
                'role' => (string) ($admin->role ?? 'admin'),
                'role_label' => $this->roleLabel((string) ($admin->role ?? 'admin')),
                'status' => (string) ($admin->status ?? 'active'),
                'is_super_admin' => $admin->isSuperAdmin(),
                'last_login' => $admin->last_login?->format('Y-m-d H:i:s') ?? '',
                'created_at' => $admin->created_at?->format('Y-m-d H:i:s') ?? '',
                'creator_username' => (string) ($admin->creator?->username ?? ''),
                'owner_label' => $this->ownerLabel($admin),
                'activity_count' => (int) ($admin->activity_count ?? 0),
            ];
        })->all();
    }

    private function roleLabel(string $role): string
    {
        return match ($role) {
            'super_admin', 'superadmin' => __('admin.admin_users.role_super_admin'),
            'agent_admin' => '代理管理员',
            'direct_admin' => '直客管理员',
            'site_user' => '站点普通用户',
            default => __('admin.admin_users.role_admin'),
        };
    }

    private function ownerLabel(Admin $admin): string
    {
        if ($admin->isSuperAdmin()) {
            return '平台管理';
        }

        if ($admin->isAgentAdmin()) {
            return '平台代理';
        }

        if ($admin->isDirectAdmin()) {
            return '平台直客';
        }

        if ($admin->isSiteUser() && $admin->creator instanceof Admin && $admin->creator->isAgentAdmin()) {
            return '代理：'.$admin->creator->name;
        }

        if ($admin->creator instanceof Admin) {
            return '归属：'.$admin->creator->name;
        }

        return '平台管理';
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
