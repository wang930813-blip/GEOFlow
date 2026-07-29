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
 * @File： McpBrandDiagnosisController.php
 *
 * @Description: 提供 MCP Key 专用的用户侧品牌诊断列表、创建、详情和确认接口。
 */

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Requests\Api\V1\ConfirmMcpBrandDiagnosisRequest;
use App\Http\Requests\Api\V1\StoreMcpBrandDiagnosisRequest;
use App\Models\Admin;
use App\Services\Api\IdempotencyService;
use App\Services\BrandDiagnosis\McpBrandDiagnosisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class McpBrandDiagnosisController extends BaseApiController
{
    /**
     * 查询品牌诊断列表
     * 分页查询 MCP Key 所属账号在绑定站点中的用户侧品牌诊断任务。
     *
     * @Url [GET] /api/v1/mcp/brand-diagnoses
     *      登录 是
     *
     *      分页参数：
     *      page int 可选 页码，默认 1
     *      per_page int 可选 每页数量，范围 1 至 100，默认 20
     *
     *      筛选参数：
     *      status string 可选 品牌诊断任务状态
     *      search string 可选 品牌词关键词
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-24 12:01:13
     *
     * @UpdateTime: 2026-07-24 12:01:13
     *
     * @Return JsonResponse 当前账号和站点的品牌诊断分页结果
     *
     * @Throws ApiException MCP Key 账号或绑定站点上下文无效
     */
    public function index(Request $request, McpBrandDiagnosisService $service): JsonResponse
    {
        $status = $request->query('status');
        $search = $request->query('search');

        return $this->success($request, $service->list(
            $this->admin($request),
            $this->siteId($request),
            $request->integer('page', 1),
            $request->integer('per_page', 20),
            is_string($status) ? mb_strimwidth(trim($status), 0, 32, '', 'UTF-8') : '',
            is_string($search) ? mb_strimwidth(trim($search), 0, 120, '', 'UTF-8') : '',
        ));
    }

    /**
     * 创建品牌诊断任务
     * 创建当前账号和站点的品牌诊断问题生成任务，创建阶段不消耗品牌诊断额度。
     *
     * @Url [POST] /api/v1/mcp/brand-diagnoses
     *      登录 是
     *      brand_name string 必选 需要诊断的品牌词，最长 120 个字符
     *      models array 必选 诊断模型列表，支持 doubao、deepseek、qianwen、wenxin
     *      reuse_questions bool 可选 是否复用当前账号同站点最近生成的问题
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-24 12:01:13
     *
     * @UpdateTime: 2026-07-24 12:01:13
     *
     * @Return JsonResponse 新建品牌诊断任务摘要
     *
     * @Throws ApiException MCP Key 账号、站点、权限或幂等请求无效
     */
    public function store(StoreMcpBrandDiagnosisRequest $request, McpBrandDiagnosisService $service): JsonResponse
    {
        $cached = IdempotencyService::maybeReplayJson($request, 'POST /mcp/brand-diagnoses');
        if ($cached !== null) {
            return $cached;
        }

        $payload = $request->validated();

        return $this->success($request, $service->create(
            $this->admin($request),
            $this->siteId($request),
            (string) $payload['brand_name'],
            (array) $payload['models'],
            $request->boolean('reuse_questions'),
        ), 201, 'POST /mcp/brand-diagnoses');
    }

    /**
     * 获取品牌诊断详情
     * 查询单次品牌诊断的状态、问题、模型回答、来源、品牌表现和排名。
     *
     * @Url [GET] /api/v1/mcp/brand-diagnoses/{run}
     *      登录 是
     *
     *      分页参数：
     *      无
     *
     *      筛选参数：
     *      run int 必选 品牌诊断任务编号
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-24 12:01:13
     *
     * @UpdateTime: 2026-07-24 12:01:13
     *
     * @Return JsonResponse 品牌诊断完整进度和结果
     *
     * @Throws ApiException 任务不存在或不属于当前账号和站点
     */
    public function show(Request $request, int $run, McpBrandDiagnosisService $service): JsonResponse
    {
        return $this->success($request, $service->detail(
            $this->admin($request),
            $this->siteId($request),
            $run,
        ));
    }

    /**
     * 确认品牌诊断问题
     * 确认系统生成的问题并开始正式诊断，此操作按现有套餐规则消耗一次品牌诊断额度。
     *
     * @Url [POST] /api/v1/mcp/brand-diagnoses/{run}/confirm
     *      登录 是
     *      run int 必选 品牌诊断任务编号
     *      questions array 可选 问题编号和确认文本列表，省略时保留全部系统生成问题
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-24 12:01:13
     *
     * @UpdateTime: 2026-07-24 12:01:13
     *
     * @Return JsonResponse 已启动品牌诊断的完整状态
     *
     * @Throws ApiException 任务不存在、状态不可确认、套餐额度不足或幂等请求无效
     */
    public function confirm(
        ConfirmMcpBrandDiagnosisRequest $request,
        int $run,
        McpBrandDiagnosisService $service,
    ): JsonResponse {
        $cached = IdempotencyService::maybeReplayJson($request, 'POST /mcp/brand-diagnoses/{run}/confirm');
        if ($cached !== null) {
            return $cached;
        }

        $questions = collect((array) $request->validated('questions', []))
            ->mapWithKeys(static fn (array $question): array => [
                (int) $question['id'] => (string) ($question['question'] ?? ''),
            ])
            ->all();

        return $this->success($request, $service->confirm(
            $this->admin($request),
            $this->siteId($request),
            $run,
            $questions,
        ), 200, 'POST /mcp/brand-diagnoses/{run}/confirm');
    }

    /**
     * @Name: admin
     *
     * @Description: 从 API Token 中间件建立的管理员 Guard 读取并复核 MCP Key 所属账号。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-24 12:01:13
     *
     * @UpdateTime: 2026-07-24 12:01:13
     *
     * @Param: Request $request 当前 API 请求
     *
     * @Return: Admin MCP Key 所属有效管理员
     *
     * @Throws: ApiException 管理员上下文不存在或与 Token 不一致
     */
    private function admin(Request $request): Admin
    {
        $context = $this->auth($request);
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin || (int) $admin->id !== (int) $context->auditAdminId) {
            throw new ApiException('admin_not_available', 'Token 所属账号不存在或已停用', 403);
        }

        return $admin;
    }

    /**
     * @Name: siteId
     *
     * @Description: 从 API Token 鉴权上下文读取绑定站点编号，品牌诊断 MCP 能力不接受全局 Token。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-24 12:01:13
     *
     * @UpdateTime: 2026-07-24 12:01:13
     *
     * @Param: Request $request 当前 API 请求
     *
     * @Return: int MCP Key 绑定站点编号
     *
     * @Throws: ApiException MCP Key 未绑定有效站点
     */
    private function siteId(Request $request): int
    {
        $siteId = (int) ($this->auth($request)->siteId ?? 0);
        if ($siteId <= 0) {
            throw new ApiException('mcp_site_required', 'MCP Key 必须绑定有效站点', 403);
        }

        return $siteId;
    }
}
