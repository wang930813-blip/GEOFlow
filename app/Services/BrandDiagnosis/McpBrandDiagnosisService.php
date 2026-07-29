<?php

/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-24
 *
 * @Time: 12:01
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： McpBrandDiagnosisService.php
 *
 * @Description: 提供 MCP 用户侧品牌诊断的账号隔离查询、任务创建、问题确认和结果读取能力。
 */

namespace App\Services\BrandDiagnosis;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\BrandDiagnosisRun;
use App\Support\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class McpBrandDiagnosisService
{
    /**
     * @Name: __construct
     *
     * @Description: 注入现有品牌诊断执行服务、结果转换器和请求级站点上下文，复用原业务费用与队列流程。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-24 12:01:13
     *
     * @UpdateTime: 2026-07-24 12:01:13
     *
     * @Param: BrandDiagnosisRunService $runs 品牌诊断任务业务服务
     *
     * @Param: BrandDiagnosisApiResultPresenter $presenter 品牌诊断结果转换器
     *
     * @Param: CurrentSite $currentSite 当前 MCP Key 绑定站点上下文
     *
     * @Return: void
     *
     * @Throws: 无
     */
    public function __construct(
        private readonly BrandDiagnosisRunService $runs,
        private readonly BrandDiagnosisApiResultPresenter $presenter,
        private readonly CurrentSite $currentSite,
    ) {}

    /**
     * @Name: list
     *
     * @Description: 分页查询 MCP Key 所属账号在绑定站点创建的品牌诊断，排除其他账号、其他站点和系统级开放接口任务。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-24 12:01:13
     *
     * @UpdateTime: 2026-07-24 12:01:13
     *
     * @Param: Admin $admin MCP Key 所属管理员
     *
     * @Param: int $siteId MCP Key 绑定站点编号
     *
     * @Param: int $page 页码
     *
     * @Param: int $perPage 每页数量
     *
     * @Param: string $status 可选任务状态
     *
     * @Param: string $search 可选品牌词
     *
     * @Return: array{items: list<array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, total_pages: int}} 品牌诊断分页结果
     *
     * @Throws: ApiException MCP 站点上下文无效
     */
    public function list(
        Admin $admin,
        int $siteId,
        int $page = 1,
        int $perPage = 20,
        string $status = '',
        string $search = '',
    ): array {
        $this->assertContext($admin, $siteId);

        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $status = trim($status);
        $search = trim($search);
        $query = $this->baseQuery($admin, $siteId)
            ->when($status !== '', fn (Builder $builder): Builder => $builder->where('status', $status))
            ->when($search !== '', fn (Builder $builder): Builder => $builder->where('brand_name', 'like', '%'.$search.'%'));
        $total = (clone $query)->count();
        $items = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (BrandDiagnosisRun $run): array => $this->presenter->mcpSummary($run))
            ->values()
            ->all();

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * @Name: create
     *
     * @Description: 在 MCP Key 账号和站点上下文中创建品牌诊断问题生成任务，创建阶段不消耗品牌诊断额度。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-24 12:01:13
     *
     * @UpdateTime: 2026-07-24 12:01:13
     *
     * @Param: Admin $admin MCP Key 所属管理员
     *
     * @Param: int $siteId MCP Key 绑定站点编号
     *
     * @Param: string $brandName 需要诊断的品牌词
     *
     * @Param: array<int, string> $models 诊断模型列表
     *
     * @Param: bool $reuseQuestions 是否复用当前账号同站点最近问题
     *
     * @Return: array<string, mixed> 新建品牌诊断任务摘要
     *
     * @Throws: ApiException MCP 站点上下文无效
     */
    public function create(
        Admin $admin,
        int $siteId,
        string $brandName,
        array $models,
        bool $reuseQuestions,
    ): array {
        $this->assertContext($admin, $siteId);
        $run = $this->runs->create($admin, $brandName, $models, $reuseQuestions);

        return $this->presenter->mcpSummary($run);
    }

    /**
     * @Name: detail
     *
     * @Description: 查询 MCP Key 所属账号和站点内单次品牌诊断的进度、问题、模型回答、来源和排名。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-24 12:01:13
     *
     * @UpdateTime: 2026-07-24 12:01:13
     *
     * @Param: Admin $admin MCP Key 所属管理员
     *
     * @Param: int $siteId MCP Key 绑定站点编号
     *
     * @Param: int $runId 品牌诊断任务编号
     *
     * @Return: array<string, mixed> 品牌诊断完整结果
     *
     * @Throws: ApiException 站点上下文无效或诊断任务不存在
     */
    public function detail(Admin $admin, int $siteId, int $runId): array
    {
        $this->assertContext($admin, $siteId);

        return $this->presenter->mcpDetail($this->findRun($admin, $siteId, $runId));
    }

    /**
     * @Name: confirm
     *
     * @Description: 确认系统生成的问题并启动正式诊断，沿用现有套餐品牌诊断次数校验和扣减流程。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-24 12:01:13
     *
     * @UpdateTime: 2026-07-24 12:01:13
     *
     * @Param: Admin $admin MCP Key 所属管理员
     *
     * @Param: int $siteId MCP Key 绑定站点编号
     *
     * @Param: int $runId 品牌诊断任务编号
     *
     * @Param: array<int|string, string> $questions 按问题编号索引的确认文本，空数组表示保留全部生成问题
     *
     * @Return: array<string, mixed> 已启动品牌诊断的完整状态
     *
     * @Throws: ApiException 诊断任务不存在、状态不可确认或套餐额度不足
     */
    public function confirm(Admin $admin, int $siteId, int $runId, array $questions): array
    {
        $this->assertContext($admin, $siteId);
        $run = $this->findRun($admin, $siteId, $runId);
        if (! in_array((string) $run->status, ['questions_ready', 'awaiting_confirmation'], true)) {
            throw new ApiException('brand_diagnosis_not_confirmable', '当前品牌诊断尚未生成问题或已经开始执行', 409);
        }
        $unknownQuestionIds = array_diff(
            array_map('intval', array_keys($questions)),
            $run->questions->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
        );
        if ($unknownQuestionIds !== []) {
            throw new ApiException('validation_failed', '问题列表包含不属于当前品牌诊断的编号', 422, [
                'field_errors' => ['questions' => '问题列表包含无效问题编号'],
            ]);
        }

        $confirmedRun = $this->runs->confirm($admin, $run, $questions);

        return $this->presenter->mcpDetail($this->findRun($admin, $siteId, (int) $confirmedRun->id));
    }

    /**
     * @Name: assertContext
     *
     * @Description: 复核服务收到的管理员和站点与请求级 MCP Key 上下文一致，拒绝无站点或上下文漂移。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-24 12:01:13
     *
     * @UpdateTime: 2026-07-24 12:01:13
     *
     * @Param: Admin $admin MCP Key 所属管理员
     *
     * @Param: int $siteId MCP Key 绑定站点编号
     *
     * @Return: void
     *
     * @Throws: ApiException 管理员或站点上下文无效
     */
    private function assertContext(Admin $admin, int $siteId): void
    {
        if ((int) $admin->id <= 0 || $siteId <= 0 || (int) ($this->currentSite->id() ?? 0) !== $siteId) {
            throw new ApiException('mcp_site_required', 'MCP Key 必须绑定有效站点', 403);
        }
    }

    /**
     * @Name: baseQuery
     *
     * @Description: 构造显式账号和站点双重约束查询，不依赖管理员角色差异导致的全局作用域行为。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-24 12:01:13
     *
     * @UpdateTime: 2026-07-24 12:01:13
     *
     * @Param: Admin $admin MCP Key 所属管理员
     *
     * @Param: int $siteId MCP Key 绑定站点编号
     *
     * @Return: Builder<BrandDiagnosisRun> 已限定账号、站点和用户侧任务的查询构造器
     *
     * @Throws: 无
     */
    private function baseQuery(Admin $admin, int $siteId): Builder
    {
        $query = BrandDiagnosisRun::query()
            ->withoutGlobalScopes(['current_site', 'admin_owner'])
            ->where('site_id', $siteId)
            ->where('owner_admin_id', (int) $admin->id);

        return Schema::hasColumn('brand_diagnosis_runs', 'api_task_key')
            ? $query->where(function (Builder $query): void {
                $query->whereNull('api_task_key')->orWhere('api_task_key', '');
            })
            : $query;
    }

    /**
     * @Name: findRun
     *
     * @Description: 按账号和站点查找品牌诊断并预载 MCP 结果输出需要的全部关联数据。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-24 12:01:13
     *
     * @UpdateTime: 2026-07-24 12:01:13
     *
     * @Param: Admin $admin MCP Key 所属管理员
     *
     * @Param: int $siteId MCP Key 绑定站点编号
     *
     * @Param: int $runId 品牌诊断任务编号
     *
     * @Return: BrandDiagnosisRun 已加载完整关联数据的品牌诊断任务
     *
     * @Throws: ApiException 诊断任务不属于当前账号和站点
     */
    private function findRun(Admin $admin, int $siteId, int $runId): BrandDiagnosisRun
    {
        $run = $this->baseQuery($admin, $siteId)
            ->with([
                'questions' => fn ($query) => $query
                    ->withoutGlobalScopes(['current_site', 'admin_owner'])
                    ->orderBy('sort_order')
                    ->with([
                        'results' => fn ($query) => $query
                            ->withoutGlobalScopes(['current_site', 'admin_owner'])
                            ->with([
                                'sources' => fn ($query) => $query->withoutGlobalScopes(['current_site', 'admin_owner']),
                                'brandMentions' => fn ($query) => $query->withoutGlobalScopes(['current_site', 'admin_owner']),
                            ]),
                    ]),
                'sources' => fn ($query) => $query->withoutGlobalScopes(['current_site', 'admin_owner']),
                'brandMentions' => fn ($query) => $query->withoutGlobalScopes(['current_site', 'admin_owner']),
            ])
            ->whereKey($runId)
            ->first();

        if (! $run instanceof BrandDiagnosisRun) {
            throw new ApiException('brand_diagnosis_not_found', '品牌诊断任务不存在', 404);
        }

        return $run;
    }
}
