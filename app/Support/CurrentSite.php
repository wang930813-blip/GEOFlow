<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\Site;
use Illuminate\Http\Request;

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
 * @File： CurrentSite.php
 *
 * @Description: 保存当前后台请求的站点上下文及超管显式切换后的账号范围。
 */
class CurrentSite
{
    private const ACCOUNT_SCOPE_SESSION_KEY = 'current_site_account_scope';

    private ?Site $site = null;

    private bool $accountScopeEnabled = false;

    /** @var list<int>|null */
    private ?array $accountSiteIds = null;

    /**
     * @Name: set
     *
     * @Description: 设置当前请求的站点，并标记是否启用所选站点所属账号的数据范围。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-03 11:20:14
     *
     * @UpdateTime: 2026-11-03 11:37:29
     *
     * @Param: ?Site $site 当前请求的站点
     *
     * @Param: bool $accountScopeEnabled 是否启用账号范围
     *
     * @Return: void
     *
     * @Throws: 无
     */
    public function set(?Site $site, bool $accountScopeEnabled = false): void
    {
        $this->site = $site;
        $this->accountScopeEnabled = $site instanceof Site
            && $accountScopeEnabled
            && $this->ownerAdminId() !== null;
        $this->accountSiteIds = null;
    }

    public function get(): ?Site
    {
        return $this->site;
    }

    public function id(): ?int
    {
        return $this->site?->id;
    }

    /**
     * @Name: ownerAdminId
     *
     * @Description: 返回当前站点的所属账号 ID；平台站点或未初始化时返回 null。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-03 11:20:14
     *
     * @UpdateTime: 2026-11-03 11:37:29
     *
     * @Return: ?int 当前站点所属账号 ID
     *
     * @Throws: 无
     */
    public function ownerAdminId(): ?int
    {
        $ownerAdminId = (int) ($this->site?->owner_admin_id ?? 0);

        return $ownerAdminId > 0 ? $ownerAdminId : null;
    }

    /**
     * @Name: ownerAdminIdForAdmin
     *
     * @Description: 解析后台操作产生的数据所有者；管理员显式切换账号后使用该站点所属账号。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-03 11:31:09
     *
     * @UpdateTime: 2026-11-03 11:37:29
     *
     * @Param: Admin $admin 实际执行操作的管理员
     *
     * @Return: int 数据所属账号 ID
     *
     * @Throws: 无
     */
    public function ownerAdminIdForAdmin(Admin $admin): int
    {
        if ($this->accountScopeEnabled) {
            return (int) ($this->ownerAdminId() ?? $admin->id);
        }

        return (int) $admin->id;
    }

    /**
     * @Name: accountSiteIds
     *
     * @Description: 返回当前选中账号的全部站点 ID，并在当前请求内缓存查询结果。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-03 11:20:14
     *
     * @UpdateTime: 2026-11-03 11:37:29
     *
     * @Return: list<int>|null 账号可见站点 ID 列表；未启用账号范围时返回 null
     *
     * @Throws: 无
     */
    public function accountSiteIds(): ?array
    {
        if (! $this->accountScopeEnabled) {
            return null;
        }

        if ($this->accountSiteIds !== null) {
            return $this->accountSiteIds;
        }

        $ownerAdminId = $this->ownerAdminId();
        if ($ownerAdminId === null) {
            $siteId = $this->id();
            $this->accountSiteIds = $siteId !== null && $siteId > 0 ? [(int) $siteId] : [];

            return $this->accountSiteIds;
        }

        $this->accountSiteIds = Site::query()
            ->where('owner_admin_id', $ownerAdminId)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return $this->accountSiteIds;
    }

    /**
     * @Name: isAccountScopeEnabled
     *
     * @Description: 判断管理员是否通过站点切换器显式进入所选站点所属账号的数据范围。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-03 11:20:14
     *
     * @UpdateTime: 2026-11-03 11:37:29
     *
     * @Return: bool 当前请求是否启用账号范围
     *
     * @Throws: 无
     */
    public function isAccountScopeEnabled(): bool
    {
        return $this->accountScopeEnabled;
    }

    /**
     * @Name: ensureForAdmin
     *
     * @Description: 恢复已授权的会话站点及账号范围，并兼容升级前已选中的其他账号站点。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-03 11:20:14
     *
     * @UpdateTime: 2026-11-03 13:45:54
     *
     * @Param: Admin $admin 当前后台管理员
     *
     * @Param: Request $request 当前 HTTP 请求
     *
     * @Return: ?Site 当前站点；没有可用站点时返回 null
     *
     * @Throws: 无
     */
    public function ensureForAdmin(Admin $admin, Request $request): ?Site
    {
        $sessionSiteId = (int) $request->session()->get('current_site_id', 0);
        $site = $this->resolveSessionSite($admin, $request);
        $ownerAdminId = (int) ($site?->owner_admin_id ?? 0);
        $accountScopeSelected = (bool) $request->session()->get(self::ACCOUNT_SCOPE_SESSION_KEY, false);
        $legacyAccountSelection = $sessionSiteId > 0
            && $site instanceof Site
            && $ownerAdminId > 0
            && $ownerAdminId !== (int) $admin->id;
        $accountScopeEnabled = $site instanceof Site
            && $ownerAdminId > 0
            && ($accountScopeSelected || $legacyAccountSelection);

        if ($accountScopeEnabled && ! $accountScopeSelected) {
            $request->session()->put(self::ACCOUNT_SCOPE_SESSION_KEY, true);
        }

        if ($sessionSiteId > 0 && ! $site instanceof Site) {
            $request->session()->forget(['current_site_id', self::ACCOUNT_SCOPE_SESSION_KEY]);
        }

        if (! $accountScopeEnabled && $request->session()->get(self::ACCOUNT_SCOPE_SESSION_KEY, false)) {
            $request->session()->forget(self::ACCOUNT_SCOPE_SESSION_KEY);
        }

        $site ??= $this->firstMemberSite($admin);

        if (! $site instanceof Site && ! $admin->isAgentAdmin()) {
            $site = $this->createDefaultSite($admin);
        }

        if (! $site instanceof Site) {
            $request->session()->forget(['current_site_id', self::ACCOUNT_SCOPE_SESSION_KEY]);
            $this->set(null);

            return null;
        }

        $request->session()->put('current_site_id', $site->id);
        $this->set($site, $accountScopeEnabled);

        return $site;
    }

    /**
     * @Name: switchForAdmin
     *
     * @Description: 切换到管理员可访问的站点；所选站点有所属账号时启用该账号的数据范围。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-03 11:20:14
     *
     * @UpdateTime: 2026-11-03 11:37:29
     *
     * @Param: Admin $admin 当前后台管理员
     *
     * @Param: int $siteId 目标站点 ID
     *
     * @Param: Request $request 当前 HTTP 请求
     *
     * @Return: ?Site 成功切换后的站点；无访问权限时返回 null
     *
     * @Throws: 无
     */
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
        $accountScopeEnabled = (int) ($site->owner_admin_id ?? 0) > 0;
        if ($accountScopeEnabled) {
            $request->session()->put(self::ACCOUNT_SCOPE_SESSION_KEY, true);
        } else {
            $request->session()->forget(self::ACCOUNT_SCOPE_SESSION_KEY);
        }

        $this->set($site, $accountScopeEnabled);

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
