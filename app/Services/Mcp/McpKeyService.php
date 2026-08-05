<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-07-29
 *
 * @Time: 15:41:12
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： McpKeyService.php
 *
 * @Description: 管理 ceying-geo 业务 MCP Key，复用现有 API Token 的哈希存储、站点隔离、权限和套餐额度。
 */

namespace App\Services\Mcp;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Services\Api\ApiTokenService;
use App\Services\Billing\AdminResourceQuotaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class McpKeyService
{
    /** @var list<string> */
    public const BUSINESS_SCOPES = [
        'catalog:read',
        'tasks:read',
        'tasks:write',
        'jobs:read',
        'materials:read',
        'materials:write',
        'articles:read',
        'articles:write',
        'articles:site-publish',
        'media:read',
        'media:submit',
        'videos:read',
        'videos:write',
        'brand-diagnoses:read',
        'brand-diagnoses:write',
    ];

    public function __construct(
        private readonly ApiTokenService $apiTokenService,
        private readonly AdminResourceQuotaService $quotaService,
    ) {}

    /**
     * @Name: listKeys
     *
     * @Description: 查询当前账号在当前站点创建的 MCP Key，仅返回元数据和权限，不返回密钥明文。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 16:38:47
     *
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Param: Admin $admin 当前登录管理员
     *
     * @Param: Site $site 当前站点
     *
     * @Return: array<int, array<string, mixed>> MCP Key 元数据列表
     */
    public function listKeys(Admin $admin, Site $site): array
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', Admin::class)
            ->where('tokenable_id', (int) $admin->id)
            ->where('site_id', (int) $site->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (PersonalAccessToken $token): bool => $this->isMcpToken($token))
            ->map(fn (PersonalAccessToken $token): array => $this->serializeKey($token))
            ->values()
            ->all();
    }

    /**
     * @Name: createKey
     *
     * @Description: 为当前账号和站点创建 MCP Key；创建前校验套餐订阅及 API Token 数量上限，明文仅返回一次。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 16:38:47
     *
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Param: Admin $admin 当前登录管理员
     *
     * @Param: Site $site 当前站点
     *
     * @Param: string $name MCP Key 名称
     *
     * @Param: array<int, string> $scopes GEO 业务权限列表
     *
     * @Param: string|null $expiresAt 过期时间
     *
     * @Param: bool $neverExpires 是否明确创建永不过期 Key
     *
     * @Return: array{token: string, record: array<string, mixed>} 新 MCP Key 明文及元数据
     */
    public function createKey(
        Admin $admin,
        Site $site,
        string $name,
        array $scopes,
        ?string $expiresAt,
        bool $neverExpires = false,
    ): array {
        return DB::transaction(function () use ($admin, $site, $name, $scopes, $expiresAt, $neverExpires): array {
            $lockedAdmin = Admin::query()
                ->whereKey((int) $admin->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            if (! $lockedAdmin instanceof Admin) {
                throw new ApiException('admin_not_available', '当前账号不存在或已停用', 403);
            }

            $this->assertCanCreateKey($lockedAdmin, $site);

            $normalizedScopes = array_values(array_intersect(self::BUSINESS_SCOPES, $scopes));
            if ($normalizedScopes === []) {
                throw new ApiException('validation_failed', '至少选择一项 GEO 业务权限', 422);
            }

            return $this->apiTokenService->createToken(
                trim($name),
                [ApiTokenService::MCP_CONNECT_SCOPE, ...$normalizedScopes],
                (int) $lockedAdmin->id,
                $expiresAt,
                (int) $site->id,
                $neverExpires,
            );
        });
    }

    /**
     * @Name: revokeKey
     *
     * @Description: 撤销当前账号在当前站点拥有的 MCP Key，禁止跨账号或跨站点撤销。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 16:38:47
     *
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Param: Admin $admin 当前登录管理员
     *
     * @Param: Site $site 当前站点
     *
     * @Param: int $keyId MCP Key 数据库编号
     *
     * @Return: void
     */
    public function revokeKey(Admin $admin, Site $site, int $keyId): void
    {
        $token = PersonalAccessToken::query()
            ->where('tokenable_type', Admin::class)
            ->where('tokenable_id', (int) $admin->id)
            ->where('site_id', (int) $site->id)
            ->whereKey($keyId)
            ->first();

        if (! $token instanceof PersonalAccessToken || ! $this->isMcpToken($token)) {
            throw new ApiException('mcp_key_not_found', 'MCP Key 不存在', 404);
        }

        $token->delete();
    }

    /**
     * @Name: updateKeyScopes
     *
     * @Description: 修改当前账号在当前站点拥有的 MCP Key 业务权限；仅更新权限集合，不重置明文、不改变有效期和最近使用时间。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-08-05 23:05:06
     *
     * @UpdateTime: 2026-08-05 23:05:06
     *
     * @Param: Admin $admin 当前登录管理员
     *
     * @Param: Site $site 当前站点
     *
     * @Param: int $keyId MCP Key 数据库编号
     *
     * @Param: array<int, string> $scopes GEO 业务权限列表
     *
     * @Return: array<string, mixed> 更新后的 MCP Key 安全元数据
     */
    public function updateKeyScopes(Admin $admin, Site $site, int $keyId, array $scopes): array
    {
        $normalizedScopes = array_values(array_intersect(self::BUSINESS_SCOPES, $scopes));
        if ($normalizedScopes === []) {
            throw new ApiException('validation_failed', '至少选择一项 GEO 业务权限', 422);
        }

        $token = PersonalAccessToken::query()
            ->where('tokenable_type', Admin::class)
            ->where('tokenable_id', (int) $admin->id)
            ->where('site_id', (int) $site->id)
            ->whereKey($keyId)
            ->first();

        if (! $token instanceof PersonalAccessToken || ! $this->isMcpToken($token)) {
            throw new ApiException('mcp_key_not_found', 'MCP Key 不存在', 404);
        }

        $token->forceFill([
            'abilities' => [ApiTokenService::MCP_CONNECT_SCOPE, ...$normalizedScopes],
        ])->save();

        return $this->serializeKey($token->refresh());
    }

    /**
     * @Name: scopeCatalog
     *
     * @Description: 返回 ceying-geo MCP 可授权权限及其业务说明，作为用户侧权限选择的唯一来源。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 16:38:47
     *
     * @UpdateTime: 2026-08-05 21:05:15
     *
     * @Return: array<string, array{label: string, description: string, risk: string}> 权限目录
     */
    public function scopeCatalog(): array
    {
        return [
            'catalog:read' => [
                'label' => '目录读取',
                'description' => '读取当前站点的模型、提示词、标题库、知识库、作者和分类。',
                'risk' => '只读',
            ],
            'tasks:read' => [
                'label' => '任务读取',
                'description' => '查询任务列表、任务详情及任务执行记录。',
                'risk' => '只读',
            ],
            'tasks:write' => [
                'label' => '任务执行',
                'description' => '允许向现有任务投递一次文章生成执行。',
                'risk' => '会消耗额度',
            ],
            'jobs:read' => [
                'label' => '执行详情读取',
                'description' => '查询单次任务执行的状态、结果和错误信息。',
                'risk' => '只读',
            ],
            'materials:read' => [
                'label' => '素材读取',
                'description' => '读取分类、作者、关键词库、标题库、图片库、知识库及其条目。',
                'risk' => '只读',
            ],
            'materials:write' => [
                'label' => '素材管理',
                'description' => '创建、更新、删除素材库，以及新增和批量删除素材条目。',
                'risk' => '写入数据',
            ],
            'articles:read' => [
                'label' => '文章读取',
                'description' => '查询当前站点文章列表和文章详情。',
                'risk' => '只读',
            ],
            'articles:write' => [
                'label' => '文章写入',
                'description' => '允许外部 AI 应用将已完成编写的文章保存到当前站点。',
                'risk' => '写入数据',
            ],
            'articles:site-publish' => [
                'label' => '文章站内发布',
                'description' => '允许将当前账号未被拒绝的文章自动通过并发布到绑定的 GEO 用户站点。',
                'risk' => '公开发布',
            ],
            'media:read' => [
                'label' => '媒体渠道读取',
                'description' => '查询可投递媒体渠道、当前站点售价和投稿状态。',
                'risk' => '只读',
            ],
            'media:submit' => [
                'label' => '媒体投稿',
                'description' => '允许将当前账号文章投递到指定媒体渠道。',
                'risk' => '会扣除余额',
            ],
            'videos:read' => [
                'label' => '视频读取',
                'description' => '查询当前账号的视频生成任务和生成结果。',
                'risk' => '只读',
            ],
            'videos:write' => [
                'label' => '视频生成',
                'description' => '创建视频生成任务。',
                'risk' => '会消耗额度',
            ],
            'brand-diagnoses:read' => [
                'label' => '品牌诊断读取',
                'description' => '查询当前账号的品牌诊断任务、问题、模型回答、引用来源和排名。',
                'risk' => '只读',
            ],
            'brand-diagnoses:write' => [
                'label' => '品牌诊断执行',
                'description' => '创建品牌诊断、确认问题并按现有套餐规则启动正式诊断。',
                'risk' => '会消耗额度',
            ],
        ];
    }

    /**
     * @Name: toolCatalog
     *
     * @Description: 返回 ceying-geo MCP 工具清单，用于用户侧说明并与独立 MCP 服务保持同一业务边界。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 16:38:47
     *
     * @UpdateTime: 2026-08-05 21:05:15
     *
     * @Return: array<int, array{name: string, scope: string, description: string, billing: string}> 工具目录
     */
    public function toolCatalog(): array
    {
        return [
            ['name' => 'geo_get_catalog', 'scope' => 'catalog:read', 'description' => '获取当前站点 ceying-geo 任务所需目录数据', 'billing' => '不扣费'],
            ['name' => 'geo_list_tasks', 'scope' => 'tasks:read', 'description' => '分页查询当前站点任务', 'billing' => '不扣费'],
            ['name' => 'geo_get_task', 'scope' => 'tasks:read', 'description' => '查询单个任务及运行概况', 'billing' => '不扣费'],
            ['name' => 'geo_run_task', 'scope' => 'tasks:write', 'description' => '向现有任务投递一次文章生成执行', 'billing' => '成功生成后扣文章生成额度'],
            ['name' => 'geo_list_task_runs', 'scope' => 'tasks:read', 'description' => '查询任务的执行记录', 'billing' => '不扣费'],
            ['name' => 'geo_get_task_run', 'scope' => 'jobs:read', 'description' => '查询单次执行状态和结果', 'billing' => '不扣费'],
            ['name' => 'geo_get_material_summary', 'scope' => 'materials:read', 'description' => '查询六类 ceying-geo 素材数量摘要', 'billing' => '不扣费'],
            ['name' => 'geo_list_materials', 'scope' => 'materials:read', 'description' => '分页查询指定类型素材', 'billing' => '不扣费'],
            ['name' => 'geo_get_material', 'scope' => 'materials:read', 'description' => '查询单个素材详情', 'billing' => '不扣费'],
            ['name' => 'geo_list_material_items', 'scope' => 'materials:read', 'description' => '分页查询素材库条目或知识库切块', 'billing' => '不扣费'],
            ['name' => 'geo_create_material', 'scope' => 'materials:write', 'description' => '创建分类、作者或素材库', 'billing' => '不扣费'],
            ['name' => 'geo_update_material', 'scope' => 'materials:write', 'description' => '更新指定素材', 'billing' => '不扣费'],
            ['name' => 'geo_delete_material', 'scope' => 'materials:write', 'description' => '删除未被业务数据占用的素材', 'billing' => '不扣费'],
            ['name' => 'geo_create_material_item', 'scope' => 'materials:write', 'description' => '新增关键词、标题或图片条目', 'billing' => '不扣费'],
            ['name' => 'geo_delete_material_items', 'scope' => 'materials:write', 'description' => '批量删除关键词、标题或图片条目', 'billing' => '不扣费'],
            ['name' => 'geo_list_articles', 'scope' => 'articles:read', 'description' => '分页查询当前站点文章', 'billing' => '不扣费'],
            ['name' => 'geo_get_article', 'scope' => 'articles:read', 'description' => '查询单篇文章完整内容', 'billing' => '不扣费'],
            ['name' => 'geo_create_article', 'scope' => 'articles:write', 'description' => '保存外部 AI 已完成编写的文章', 'billing' => '不扣费'],
            ['name' => 'geo_publish_article_to_site', 'scope' => 'articles:site-publish', 'description' => '将未被拒绝的文章自动通过并发布到当前 GEO 用户站点', 'billing' => '不扣费'],
            ['name' => 'geo_list_media_channels', 'scope' => 'media:read', 'description' => '查询可投稿媒体渠道和当前站点售价', 'billing' => '不扣费'],
            ['name' => 'geo_get_media_channel', 'scope' => 'media:read', 'description' => '查询单个媒体渠道详情和售价', 'billing' => '不扣费'],
            ['name' => 'geo_list_media_submissions', 'scope' => 'media:read', 'description' => '查询媒体投稿记录和最新状态', 'billing' => '不扣费'],
            ['name' => 'geo_get_media_submission', 'scope' => 'media:read', 'description' => '查询单个投稿订单和发布链接', 'billing' => '不扣费'],
            ['name' => 'geo_submit_article_to_media', 'scope' => 'media:submit', 'description' => '将当前账号已有文章投递到指定媒体渠道', 'billing' => '按渠道实际售价扣费，失败退款'],
            ['name' => 'geo_publish_article_to_media', 'scope' => 'articles:write + media:submit', 'description' => '保存 AI 文章并立即投递到指定媒体渠道', 'billing' => '按渠道实际售价扣费，失败退款'],
            ['name' => 'geo_list_videos', 'scope' => 'videos:read', 'description' => '分页查询当前账号的视频生成任务', 'billing' => '不扣费'],
            ['name' => 'geo_get_video', 'scope' => 'videos:read', 'description' => '查询单个视频生成任务的状态和生成结果地址', 'billing' => '不扣费'],
            ['name' => 'geo_create_video', 'scope' => 'videos:write', 'description' => '创建视频生成任务并投递异步生成队列', 'billing' => '按生成数量扣减视频生成额度'],
            ['name' => 'geo_list_brand_diagnoses', 'scope' => 'brand-diagnoses:read', 'description' => '分页查询当前账号的品牌诊断任务', 'billing' => '不扣费'],
            ['name' => 'geo_get_brand_diagnosis', 'scope' => 'brand-diagnoses:read', 'description' => '查询诊断进度、问题、回答、来源和排名', 'billing' => '不扣费'],
            ['name' => 'geo_create_brand_diagnosis', 'scope' => 'brand-diagnoses:write', 'description' => '创建品牌诊断问题生成任务', 'billing' => '不扣费'],
            ['name' => 'geo_confirm_brand_diagnosis', 'scope' => 'brand-diagnoses:write', 'description' => '确认问题并启动正式品牌诊断', 'billing' => '扣减一次品牌诊断额度'],
        ];
    }

    /**
     * @Name: assertCanCreateKey
     *
     * @Description: 按现有 API Token 资源权益校验并发有效凭证数量，超级管理员沿用项目现有免额度规则。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 16:38:47
     *
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Param: Admin $admin 当前登录管理员
     *
     * @Param: Site $site 当前站点
     *
     * @Return: void
     */
    private function assertCanCreateKey(Admin $admin, Site $site): void
    {
        if ($admin->isSuperAdmin()) {
            return;
        }

        $remaining = $this->quotaService->remaining(
            (int) $admin->id,
            (int) $site->id,
            PlatformPlan::RESOURCE_API_TOKENS,
        );
        if ($remaining['quota'] === null) {
            return;
        }

        $activeTokenCount = PersonalAccessToken::query()
            ->where('tokenable_type', Admin::class)
            ->where('tokenable_id', (int) $admin->id)
            ->where('site_id', (int) $site->id)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->count();

        if ($activeTokenCount >= (int) $remaining['quota']) {
            throw new ApiException('quota_exceeded', '当前规格 API Token 数量不足，请撤销闲置凭证或升级规格', 422);
        }
    }

    /**
     * @Name: isMcpToken
     *
     * @Description: 根据专用连接权限识别 MCP Key，不依赖名称前缀或可变展示字段。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 16:38:47
     *
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Param: PersonalAccessToken $token Sanctum Token 模型
     *
     * @Return: bool 是否为 MCP Key
     */
    private function isMcpToken(PersonalAccessToken $token): bool
    {
        return in_array(ApiTokenService::MCP_CONNECT_SCOPE, (array) $token->abilities, true);
    }

    /**
     * @Name: serializeKey
     *
     * @Description: 输出用户侧展示所需的安全元数据，过滤内部连接权限并计算有效状态。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 16:38:47
     *
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Param: PersonalAccessToken $token Sanctum Token 模型
     *
     * @Return: array<string, mixed> MCP Key 安全元数据
     */
    private function serializeKey(PersonalAccessToken $token): array
    {
        $expiresAt = $token->expires_at instanceof Carbon ? $token->expires_at : null;

        return [
            'id' => (int) $token->id,
            'name' => (string) $token->name,
            'scopes' => array_values(array_intersect(self::BUSINESS_SCOPES, (array) $token->abilities)),
            'status' => $expiresAt?->isPast() === true ? 'expired' : 'active',
            'last_used_at' => $token->last_used_at?->format('Y-m-d H:i:s'),
            'expires_at' => $expiresAt?->format('Y-m-d H:i:s'),
            'created_at' => $token->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
