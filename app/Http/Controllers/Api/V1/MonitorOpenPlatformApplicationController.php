<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-11-19
 *
 * @Time: 12:11:31
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： MonitorOpenPlatformApplicationController.php
 *
 * @Description: 为监测中心用户管理开放平台应用及其只读接口权限。
 */

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\OpenPlatformApplication;
use App\Models\Site;
use App\Services\OpenPlatform\OpenPlatformCredentialService;
use App\Support\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MonitorOpenPlatformApplicationController extends BaseApiController
{
    /**
     * 应用列表
     * 返回当前账号和站点创建的应用，不返回应用密钥哈希或已签发令牌。
     *
     * @Url [GET] /api/v1/monitor/open-platform/apps
     *      登录 是
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-19 12:11:31
     *
     * @UpdateTime: 2026-11-19 12:11:31
     *
     * @Return JsonResponse 当前账号和站点的开放平台应用列表
     *
     * @Throws ApiException 当前账号或站点上下文不可用
     */
    public function index(Request $request, CurrentSite $currentSite): JsonResponse
    {
        [$admin, $site] = $this->monitorContext($currentSite);

        $applications = $this->applicationQuery($admin, $site)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (OpenPlatformApplication $application): array => $this->presentApplication($application))
            ->all();

        return $this->success($request, ['items' => $applications]);
    }

    /**
     * 创建应用
     * 创建与当前账号和站点绑定的应用，并且只在本次响应返回一次应用密钥明文。
     *
     * @Url [POST] /api/v1/monitor/open-platform/apps
     *      登录 是
     *      name string 必选 应用名称
     *      description string 可选 应用用途说明
     *      scopes string[] 必选 已授权的只读接口权限
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-19 12:11:31
     *
     * @UpdateTime: 2026-11-19 12:41:17
     *
     * @Return JsonResponse 新应用信息与仅本次可见的 client_id 和 client_secret
     *
     * @Throws ApiException|ValidationException 当前账号或站点上下文不可用或参数无效
     */
    public function store(
        Request $request,
        CurrentSite $currentSite,
        OpenPlatformCredentialService $credentialService
    ): JsonResponse {
        [$admin, $site] = $this->monitorContext($currentSite);
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', 'distinct', Rule::in(OpenPlatformApplication::AVAILABLE_SCOPES)],
        ]);
        $ownerAdminId = (int) ($site->owner_admin_id ?: $admin->id);
        $credentials = $credentialService->createClientCredentials();
        $application = OpenPlatformApplication::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => $ownerAdminId,
            'created_by_admin_id' => (int) $admin->id,
            'name' => trim($validated['name']),
            'description' => isset($validated['description']) ? trim((string) $validated['description']) : null,
            'client_id' => $credentials['client_id'],
            'client_secret_hash' => $credentials['client_secret_hash'],
            'scopes' => array_values($validated['scopes']),
            'is_active' => true,
        ]);

        return $this->successNoStore($request, [
            'application' => $this->presentApplication($application),
            'credentials' => [
                'client_id' => $credentials['client_id'],
                'client_secret' => $credentials['client_secret'],
            ],
        ], 201);
    }

    /**
     * 更新应用
     * 更新当前账号和站点应用的基本资料、只读权限或启用状态。
     *
     * @Url [PATCH] /api/v1/monitor/open-platform/apps/{application}
     *      登录 是
     *      application integer 必选 当前账号和站点的应用编号
     *      name string 可选 应用名称
     *      description string|null 可选 应用用途说明
     *      scopes string[] 可选 已授权的只读接口权限
     *      is_active boolean 可选 应用启用状态
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-19 12:11:31
     *
     * @UpdateTime: 2026-11-19 12:11:31
     *
     * @Return JsonResponse 更新后的应用资料
     *
     * @Throws ApiException|ValidationException 应用不存在、上下文不可用或参数无效
     */
    public function update(
        Request $request,
        int $application,
        CurrentSite $currentSite,
        OpenPlatformCredentialService $credentialService
    ): JsonResponse {
        [$admin, $site] = $this->monitorContext($currentSite);
        $applicationModel = $this->findApplication($admin, $site, $application);
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'scopes' => ['sometimes', 'required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', 'distinct', Rule::in(OpenPlatformApplication::AVAILABLE_SCOPES)],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);

        $applicationModel->fill($validated);
        $applicationModel->save();

        if (array_key_exists('is_active', $validated) && ! $applicationModel->is_active) {
            $credentialService->revokeAccessTokens($applicationModel);
        }

        return $this->success($request, [
            'application' => $this->presentApplication($applicationModel->refresh()),
        ]);
    }

    /**
     * 轮换应用密钥
     * 生成新密钥并立即撤销该应用此前签发的所有访问令牌。
     *
     * @Url [POST] /api/v1/monitor/open-platform/apps/{application}/rotate-secret
     *      登录 是
     *      application integer 必选 当前账号和站点的应用编号
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-19 12:11:31
     *
     * @UpdateTime: 2026-11-19 12:41:17
     *
     * @Return JsonResponse 新的 client_id 和仅本次可见的 client_secret
     *
     * @Throws ApiException 应用不存在或上下文不可用
     */
    public function rotateSecret(
        Request $request,
        int $application,
        CurrentSite $currentSite,
        OpenPlatformCredentialService $credentialService
    ): JsonResponse {
        [$admin, $site] = $this->monitorContext($currentSite);
        $applicationModel = $this->findApplication($admin, $site, $application);
        $credentials = $credentialService->rotateClientSecret($applicationModel);

        return $this->successNoStore($request, [
            'application' => $this->presentApplication($applicationModel->refresh()),
            'credentials' => $credentials,
        ]);
    }

    /**
     * 移除应用
     * 软删除当前账号和站点应用并撤销其全部访问令牌。
     *
     * @Url [DELETE] /api/v1/monitor/open-platform/apps/{application}
     *      登录 是
     *      application integer 必选 当前账号和站点的应用编号
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-19 12:11:31
     *
     * @UpdateTime: 2026-11-19 12:11:31
     *
     * @Return JsonResponse 删除完成标记
     *
     * @Throws ApiException 应用不存在或上下文不可用
     */
    public function destroy(
        Request $request,
        int $application,
        CurrentSite $currentSite,
        OpenPlatformCredentialService $credentialService
    ): JsonResponse {
        [$admin, $site] = $this->monitorContext($currentSite);
        $applicationModel = $this->findApplication($admin, $site, $application);
        $credentialService->revokeAccessTokens($applicationModel);
        $applicationModel->delete();

        return $this->success($request, ['deleted' => true]);
    }

    /**
     * @Name: monitorContext
     *
     * @Description: 读取监测中心已认证账号和当前站点，阻止无站点请求创建或管理开放平台应用。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-19 12:11:31
     *
     * @UpdateTime: 2026-11-19 12:11:31
     *
     * @Param: CurrentSite $currentSite 监测中心请求站点上下文
     *
     * @Return: array{0:Admin,1:Site} 已认证账号及当前站点
     *
     * @Throws: ApiException 缺少有效账号或站点
     */
    private function monitorContext(CurrentSite $currentSite): array
    {
        $admin = auth('admin')->user();
        $site = $currentSite->get();

        if (! $admin instanceof Admin || ! $site instanceof Site || (string) $site->status !== 'active') {
            throw new ApiException('monitor_site_unavailable', '当前账号没有可用站点', 403);
        }

        return [$admin, $site];
    }

    /**
     * @Name: applicationQuery
     *
     * @Description: 以站点和数据所有者同时限制应用列表，避免监测账号间相互读取。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-19 12:11:31
     *
     * @UpdateTime: 2026-11-19 12:11:31
     *
     * @Param: Admin $admin 当前监测账号
     *
     * @Param: Site $site 当前监测站点
     *
     * @Return: Builder<OpenPlatformApplication> 当前账号和站点的应用查询
     *
     * @Throws: 无
     */
    private function applicationQuery(Admin $admin, Site $site): Builder
    {
        $ownerAdminId = (int) ($site->owner_admin_id ?: $admin->id);

        return OpenPlatformApplication::query()
            ->where('site_id', (int) $site->id)
            ->where('owner_admin_id', $ownerAdminId);
    }

    /**
     * @Name: findApplication
     *
     * @Description: 仅从当前账号和站点作用域查找应用，外部编号不能扩大数据访问范围。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-19 12:11:31
     *
     * @UpdateTime: 2026-11-19 12:11:31
     *
     * @Param: Admin $admin 当前监测账号
     *
     * @Param: Site $site 当前监测站点
     *
     * @Param: int $applicationId 应用编号
     *
     * @Return: OpenPlatformApplication 当前账号和站点的应用
     *
     * @Throws: ApiException 应用不存在或不属于当前账号站点
     */
    private function findApplication(Admin $admin, Site $site, int $applicationId): OpenPlatformApplication
    {
        $application = $this->applicationQuery($admin, $site)->whereKey($applicationId)->first();
        if (! $application instanceof OpenPlatformApplication) {
            throw new ApiException('open_platform_application_not_found', '应用不存在', 404);
        }

        return $application;
    }

    /**
     * @Name: presentApplication
     *
     * @Description: 返回非敏感应用字段及格式化时间，不序列化密钥哈希和令牌。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-19 12:11:31
     *
     * @UpdateTime: 2026-11-19 12:11:31
     *
     * @Param: OpenPlatformApplication $application 当前应用
     *
     * @Return: array<string,mixed> 可供监测中心管理页面展示的应用资料
     *
     * @Throws: 无
     */
    private function presentApplication(OpenPlatformApplication $application): array
    {
        return [
            'id' => (int) $application->id,
            'name' => (string) $application->name,
            'description' => (string) ($application->description ?? ''),
            'client_id' => (string) $application->client_id,
            'scopes' => array_values((array) $application->scopes),
            'is_active' => (bool) $application->is_active,
            'last_used_at' => $application->last_used_at?->toIso8601String(),
            'created_at' => $application->created_at?->toIso8601String(),
        ];
    }
}
