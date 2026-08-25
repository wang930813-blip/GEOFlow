<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Services\Billing\AdminResourceQuotaService;
use App\Services\Api\ApiTokenService;
use App\Support\AdminWeb;
use App\Support\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * API Token 管理控制器（超级管理员）。
 *
 * 对齐 bak/admin/api-tokens.php 的核心能力：
 * 1. 创建 Token（一次性明文回显）；
 * 2. 列表查看 Token 元数据；
 * 3. 撤销已发放 Token。
 */
class ApiTokenController extends Controller
{
    public function __construct(
        private readonly ApiTokenService $apiTokenService,
        private readonly AdminResourceQuotaService $quotaService,
    ) {}

    /**
     * API Token 管理页。
     */
    public function index(): View
    {
        $admin = auth('admin')->user();
        $isSuperAdmin = $admin instanceof Admin && $admin->isSuperAdmin();
        $currentSite = app(CurrentSite::class)->get();
        $siteScopeId = $isSuperAdmin ? null : $this->currentSiteId();

        return view('admin.api-tokens.index', [
            'pageTitle' => __('admin.api_tokens.page_title'),
            'activeMenu' => 'api_tokens',
            'adminSiteName' => AdminWeb::siteName(),
            'tokens' => $this->apiTokenService->listTokens($siteScopeId),
            'availableScopes' => $this->apiTokenService->getAvailableScopes(),
            'defaultExpiresAtInput' => $this->apiTokenService->defaultExpiresAtInputValue(),
            'sites' => $isSuperAdmin
                ? Site::query()->where('status', 'active')->orderBy('id')->get(['id', 'name'])
                : collect([$currentSite])->filter()->values(),
            'isSuperAdmin' => $isSuperAdmin,
            'currentSite' => $currentSite,
        ]);
    }

    /**
     * 创建 API Token。
     */
    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string'],
            'expires_at' => ['nullable', 'string', 'max:32'],
            'site_id' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $siteId = $this->tokenSiteId(isset($payload['site_id']) ? (int) $payload['site_id'] : null);
            if ($siteId !== null) {
                $this->assertCanCreateTokenForSite($siteId);
            }
            $created = $this->apiTokenService->createToken(
                (string) $payload['name'],
                is_array($payload['scopes']) ? $payload['scopes'] : [],
                auth('admin')->id() !== null ? (int) auth('admin')->id() : null,
                (string) ($payload['expires_at'] ?? ''),
                $siteId
            );

            return redirect()
                ->route('admin.api-tokens.index')
                ->with('message', __('admin.api_tokens.message.created'))
                ->with('new_api_token', (string) $created['token']);
        } catch (ApiException $exception) {
            return back()->withErrors($exception->getMessage())->withInput();
        } catch (Throwable $exception) {
            return back()->withErrors(__('admin.api_tokens.error.operation_failed', ['message' => $exception->getMessage()]))->withInput();
        }
    }

    /**
     * 撤销 API Token。
     */
    public function revoke(int $tokenId): RedirectResponse
    {
        if ($tokenId <= 0) {
            return back()->withErrors(__('admin.api_tokens.error.operation_failed', ['message' => 'Token ID 无效']));
        }

        try {
            $this->apiTokenService->revokeToken($tokenId, $this->tokenListSiteScopeId());

            return redirect()
                ->route('admin.api-tokens.index')
                ->with('message', __('admin.api_tokens.message.revoked'));
        } catch (ApiException $exception) {
            return back()->withErrors($exception->getMessage());
        } catch (Throwable $exception) {
            return back()->withErrors(__('admin.api_tokens.error.operation_failed', ['message' => $exception->getMessage()]));
        }
    }

    private function tokenSiteId(?int $requestedSiteId): ?int
    {
        $admin = auth('admin')->user();
        if ($admin instanceof Admin && $admin->isSuperAdmin()) {
            return $requestedSiteId !== null && $requestedSiteId > 0 ? $requestedSiteId : null;
        }

        return $this->currentSiteId();
    }

    private function tokenListSiteScopeId(): ?int
    {
        $admin = auth('admin')->user();

        return $admin instanceof Admin && $admin->isSuperAdmin() ? null : $this->currentSiteId();
    }

    private function currentSiteId(): int
    {
        $siteId = app(CurrentSite::class)->id();
        abort_unless($siteId !== null && $siteId > 0, 403);

        return (int) $siteId;
    }

    private function assertCanCreateTokenForSite(int $siteId): void
    {
        $admin = auth('admin')->user();
        if ($admin instanceof Admin && $admin->isSuperAdmin()) {
            return;
        }

        $adminId = $this->currentAdminId($admin);
        $remaining = $this->quotaService->remaining($adminId, $siteId, PlatformPlan::RESOURCE_API_TOKENS);
        if ($remaining['quota'] === null) {
            return;
        }

        $activeTokenCount = \Laravel\Sanctum\PersonalAccessToken::query()
            ->where('tokenable_type', Admin::class)
            ->where('tokenable_id', $adminId)
            ->where('site_id', $siteId)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->count();

        if ($activeTokenCount >= (int) $remaining['quota']) {
            throw new ApiException('quota_exceeded', '当前规格 API Token 数量不足', 422);
        }
    }

    private function currentAdminId(mixed $admin): int
    {
        if (! $admin instanceof Admin || (int) $admin->id <= 0) {
            abort(403);
        }

        return (int) $admin->id;
    }
}
