<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-11-23
 *
 * @Time: 10:47:47
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： MonitorBrandMonitoringController.php
 *
 * @Description: 为新版监测中心提供账号与站点隔离的品牌监测计划及执行历史接口。
 */

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\Site;
use App\Services\BrandDiagnosis\BrandMonitoringPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class MonitorBrandMonitoringController extends BaseApiController
{
    /**
     * 品牌监测计划列表
     * 读取当前账号和站点的计划、执行摘要以及可选诊断模型。
     *
     * @Url [GET] /api/v1/monitor/brand-monitoring
     *      登录 是
     *
     *      分页参数：
     *      无
     *
     *      筛选参数：
     *      无
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:17:20
     *
     * @UpdateTime: 2026-11-23 10:17:20
     *
     * @Return JsonResponse 当前账号的监测计划、状态汇总与模型选项
     *
     * @Throws ApiException 监测中心凭证或站点不可用
     */
    public function index(Request $request, BrandMonitoringPlanService $plans): JsonResponse
    {
        [, $site] = $this->monitorContext($request);

        return $this->success($request, $plans->list($site));
    }

    /**
     * 品牌资料与问题建议
     * 复用品牌诊断的模型接入生成品牌资料和监测问题草稿，不创建诊断记录或扣减额度。
     *
     * @Url [POST] /api/v1/monitor/brand-monitoring/suggestions
     *      登录 是
     *      brand_name string 必选 品牌名称
     *      platform string 必选 用于品牌资料和问题生成的模型键
     *      diagnosis_mode string 必选 standard 或 deep
     *      brand_profile string 可选 已维护的品牌简介
     *      brand_terms string[] 可选 品牌相关词
     *      products string[] 可选 产品或服务
     *      competitors string[] 可选 竞品
     *      keywords string[] 可选 搜索关键词
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:17:20
     *
     * @UpdateTime: 2026-11-23 10:17:20
     *
     * @Return JsonResponse 品牌简介来源、核心词与可编辑的问题草稿
     *
     * @Throws ApiException 模型不可用、品牌资料或问题生成失败
     */
    public function suggestions(Request $request, BrandMonitoringPlanService $plans): JsonResponse
    {
        [$admin, $site] = $this->monitorContext($request);
        $validated = $request->validate([
            'brand_name' => ['required', 'string', 'max:120'],
            'platform' => ['required', 'string', 'in:doubao,deepseek,qianwen,wenxin,yuanbao'],
            'diagnosis_mode' => ['required', 'string', 'in:standard,deep'],
            'brand_profile' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'brand_terms' => ['sometimes', 'array', 'max:30'],
            'brand_terms.*' => ['string', 'max:80'],
            'products' => ['sometimes', 'array', 'max:30'],
            'products.*' => ['string', 'max:80'],
            'competitors' => ['sometimes', 'array', 'max:30'],
            'competitors.*' => ['string', 'max:80'],
            'keywords' => ['sometimes', 'array', 'max:30'],
            'keywords.*' => ['string', 'max:80'],
        ]);

        try {
            return $this->success($request, $plans->suggest($admin, $site, $validated));
        } catch (RuntimeException $exception) {
            throw new ApiException('brand_monitoring_suggestion_failed', $exception->getMessage(), 422);
        }
    }

    /**
     * 创建品牌监测计划
     * 保存品牌、固定问题集、模型和周期配置，并可按选择立即执行首轮诊断。
     *
     * @Url [POST] /api/v1/monitor/brand-monitoring
     *      登录 是
     *      name string 必选 监测计划名称
     *      brand_name string 必选 品牌名称
     *      website_url string 可选 官网地址
     *      brand_profile string 可选 品牌简介
     *      brand_terms string[] 可选 品牌相关词
     *      products string[] 可选 产品或服务
     *      competitors string[] 可选 竞品
     *      keywords string[] 可选 搜索关键词
     *      questions object[] 必选 固定问题，问题数须与模式匹配
     *      platforms string[] 必选 启用且配置完整的诊断模型
     *      diagnosis_mode string 必选 standard 或 deep
     *      frequency string 必选 daily、weekly 或 monthly
     *      frequency_interval integer 必选 周期重复间隔
     *      run_time string 必选 每轮执行时间，格式 HH:mm
     *      weekdays integer[] 可选 每周执行日，0 为周日
     *      month_day integer 可选 每月执行日
     *      period_mode string 必选 indefinite 或 custom
     *      starts_on string 必选 开始日期 YYYY-MM-DD
     *      ends_on string 可选 自定义周期结束日期
     *      start_immediately boolean 可选 创建后是否立即执行
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:17:20
     *
     * @UpdateTime: 2026-11-23 10:17:20
     *
     * @Return JsonResponse 已创建计划和可选的首轮执行状态
     *
     * @Throws ApiException|ValidationException 计划配置无效、模型不可用或诊断额度不足
     */
    public function store(Request $request, BrandMonitoringPlanService $plans): JsonResponse
    {
        [$admin, $site] = $this->monitorContext($request);
        $validated = $this->validatePlan($request);

        try {
            $result = $plans->create($admin, $site, $validated, $request->boolean('start_immediately'));
        } catch (RuntimeException $exception) {
            throw new ApiException('brand_monitoring_create_failed', $exception->getMessage(), 422);
        }

        return $this->success($request, $result, 201);
    }

    /**
     * 品牌监测计划详情
     * 按当前站点读取计划完整配置及最近执行历史。
     *
     * @Url [GET] /api/v1/monitor/brand-monitoring/{plan}
     *      登录 是
     *      plan integer 必选 当前站点监测计划编号
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:17:20
     *
     * @UpdateTime: 2026-11-23 10:17:20
     *
     * @Return JsonResponse 当前计划配置及最多 50 条执行历史
     *
     * @Throws ApiException 计划不存在或不属于当前站点
     */
    public function show(Request $request, int $plan, BrandMonitoringPlanService $plans): JsonResponse
    {
        [, $site] = $this->monitorContext($request);

        return $this->respond($request, fn (): array => $plans->find($site, $plan));
    }

    /**
     * 更新品牌监测计划
     * 更新计划未来使用的品牌资料、问题集、模型和执行周期。
     *
     * @Url [PUT] /api/v1/monitor/brand-monitoring/{plan}
     *      登录 是
     *      plan integer 必选 当前站点监测计划编号
     *      payload object 必选 完整计划配置
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:17:20
     *
     * @UpdateTime: 2026-11-23 10:17:20
     *
     * @Return JsonResponse 更新后的监测计划
     *
     * @Throws ApiException|ValidationException 计划不存在、配置无效或模型不可用
     */
    public function update(Request $request, int $plan, BrandMonitoringPlanService $plans): JsonResponse
    {
        [$admin, $site] = $this->monitorContext($request);
        $validated = $this->validatePlan($request);

        return $this->respond($request, fn (): array => $plans->update($admin, $site, $plan, $validated));
    }

    /**
     * 暂停品牌监测计划
     * 暂停后续周期执行并保留已进入诊断队列的任务。
     *
     * @Url [POST] /api/v1/monitor/brand-monitoring/{plan}/pause
     *      登录 是
     *      plan integer 必选 当前站点监测计划编号
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:17:20
     *
     * @UpdateTime: 2026-11-23 10:17:20
     *
     * @Return JsonResponse 暂停后的计划
     *
     * @Throws ApiException 计划不存在或不属于当前站点
     */
    public function pause(Request $request, int $plan, BrandMonitoringPlanService $plans): JsonResponse
    {
        [, $site] = $this->monitorContext($request);

        return $this->respond($request, fn (): array => $plans->pause($site, $plan));
    }

    /**
     * 恢复品牌监测计划
     * 重新启用周期调度并计算下一次执行时间。
     *
     * @Url [POST] /api/v1/monitor/brand-monitoring/{plan}/resume
     *      登录 是
     *      plan integer 必选 当前站点监测计划编号
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:17:20
     *
     * @UpdateTime: 2026-11-23 10:17:20
     *
     * @Return JsonResponse 恢复后的计划
     *
     * @Throws ApiException 计划不存在、模型不可用或执行周期已结束
     */
    public function resume(Request $request, int $plan, BrandMonitoringPlanService $plans): JsonResponse
    {
        [, $site] = $this->monitorContext($request);

        return $this->respond($request, fn (): array => $plans->resume($site, $plan));
    }

    /**
     * 复制品牌监测计划
     * 复制品牌、模型、问题集和周期配置为一个暂停状态的新计划。
     *
     * @Url [POST] /api/v1/monitor/brand-monitoring/{plan}/duplicate
     *      登录 是
     *      plan integer 必选 当前站点监测计划编号
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:17:20
     *
     * @UpdateTime: 2026-11-23 10:17:20
     *
     * @Return JsonResponse 已复制的暂停计划
     *
     * @Throws ApiException 源计划不存在或不属于当前站点
     */
    public function duplicate(Request $request, int $plan, BrandMonitoringPlanService $plans): JsonResponse
    {
        [$admin, $site] = $this->monitorContext($request);

        return $this->respond($request, fn (): array => $plans->duplicate($admin, $site, $plan), 201);
    }

    /**
     * 立即执行品牌监测计划
     * 使用当前计划固定的问题集创建一次新的品牌诊断并按现有规则扣减额度。
     *
     * @Url [POST] /api/v1/monitor/brand-monitoring/{plan}/run
     *      登录 是
     *      plan integer 必选 当前站点监测计划编号
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:17:20
     *
     * @UpdateTime: 2026-11-23 10:17:20
     *
     * @Return JsonResponse 本轮执行记录及品牌诊断任务状态
     *
     * @Throws ApiException 计划正在运行、模型不可用或诊断额度不足
     */
    public function runNow(Request $request, int $plan, BrandMonitoringPlanService $plans): JsonResponse
    {
        [$admin, $site] = $this->monitorContext($request);

        return $this->respond($request, fn (): array => $plans->runNow($admin, $site, $plan));
    }

    /**
     * 删除品牌监测计划
     * 软删除当前站点计划并保留关联的品牌诊断报告。
     *
     * @Url [DELETE] /api/v1/monitor/brand-monitoring/{plan}
     *      登录 是
     *      plan integer 必选 当前站点监测计划编号
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:17:20
     *
     * @UpdateTime: 2026-11-23 10:17:20
     *
     * @Return JsonResponse 删除完成标记
     *
     * @Throws ApiException 计划不存在或不属于当前站点
     */
    public function destroy(Request $request, int $plan, BrandMonitoringPlanService $plans): JsonResponse
    {
        [, $site] = $this->monitorContext($request);

        return $this->respond($request, function () use ($plans, $site, $plan): array {
            $plans->delete($site, $plan);

            return ['deleted' => true];
        });
    }

    /**
     * @Name: validatePlan
     *
     * @Description: 验证完整的计划字段并校验自定义结束日不早于开始日，避免服务层接收不完整周期。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:17:20
     *
     * @UpdateTime: 2026-11-23 10:50:42
     *
     * @Param: Request $request 当前监测中心请求
     *
     * @Return array<string,mixed> 已验证的品牌监测配置
     *
     * @Throws ValidationException 请求字段无效或日期范围不合法
     */
    private function validatePlan(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'brand_name' => ['required', 'string', 'max:120'],
            'website_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'brand_profile' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'brand_terms' => ['present', 'array', 'max:30'],
            'brand_terms.*' => ['string', 'max:80'],
            'products' => ['present', 'array', 'max:30'],
            'products.*' => ['string', 'max:80'],
            'competitors' => ['present', 'array', 'max:30'],
            'competitors.*' => ['string', 'max:80'],
            'keywords' => ['present', 'array', 'max:30'],
            'keywords.*' => ['string', 'max:80'],
            'questions' => ['required', 'array', 'min:1', 'max:30'],
            'questions.*.question' => ['required', 'string', 'max:500'],
            'questions.*.type' => ['sometimes', 'nullable', 'string', 'max:80'],
            'questions.*.core_term' => ['sometimes', 'nullable', 'string', 'max:80'],
            'platforms' => ['required', 'array', 'min:1', 'max:5'],
            'platforms.*' => ['required', 'string', 'in:doubao,deepseek,qianwen,wenxin,yuanbao'],
            'diagnosis_mode' => ['required', 'string', 'in:standard,deep'],
            'frequency' => ['required', 'string', 'in:daily,weekly,monthly'],
            'frequency_interval' => ['required', 'integer', 'min:1', 'max:365'],
            'run_time' => ['required', 'date_format:H:i'],
            'weekdays' => ['present', 'array', 'max:7'],
            'weekdays.*' => ['integer', 'between:0,6'],
            'month_day' => ['sometimes', 'nullable', 'integer', 'between:1,31'],
            'period_mode' => ['required', 'string', 'in:indefinite,custom'],
            'starts_on' => $request->isMethod('post')
                ? ['required', 'date_format:Y-m-d', 'after_or_equal:today']
                : ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'start_immediately' => ['sometimes', 'boolean'],
        ]);

        if ($validated['frequency'] === 'weekly' && ($validated['weekdays'] ?? []) === []) {
            throw ValidationException::withMessages(['weekdays' => '每周周期至少需要选择一个执行日。']);
        }
        if ($validated['frequency'] === 'monthly' && empty($validated['month_day'])) {
            throw ValidationException::withMessages(['month_day' => '每月周期需要选择执行日期。']);
        }
        if ($validated['period_mode'] === 'custom' && empty($validated['ends_on'])) {
            throw ValidationException::withMessages(['ends_on' => '自定义周期需要设置结束日期。']);
        }

        return $validated;
    }

    /**
     * @Name: monitorContext
     *
     * @Description: 只从监测中心鉴权中间件提供的身份读取管理员和站点，并核验账号与站点所有权。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:17:20
     *
     * @UpdateTime: 2026-11-23 10:17:20
     *
     * @Param: Request $request 当前 API 请求
     *
     * @Return array{0:Admin,1:Site} 当前账号和站点
     *
     * @Throws: ApiException 身份无效、站点停用或账号与站点不匹配
     */
    private function monitorContext(Request $request): array
    {
        $identity = $request->attributes->get('monitor_auth');
        $admin = is_array($identity) ? ($identity['admin'] ?? null) : null;
        $site = is_array($identity) ? ($identity['site'] ?? null) : null;
        if (! $admin instanceof Admin || ! $site instanceof Site) {
            throw new ApiException('unauthorized', '监测中心登录凭证无效', 401);
        }
        if ((string) $site->status !== 'active' || (int) ($site->owner_admin_id ?? 0) !== (int) $admin->id) {
            throw new ApiException('monitor_site_unavailable', '当前账号没有可用的监测站点', 403);
        }

        return [$admin, $site];
    }

    /**
     * @Name: respond
     *
     * @Description: 统一将计划服务校验异常映射为可读的 422 API 错误，避免业务异常以服务器错误返回。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:17:20
     *
     * @UpdateTime: 2026-11-23 10:17:20
     *
     * @Param: Request $request 当前 API 请求
     *
     * @Param: callable():array<string,mixed> $callback 计划服务操作
     *
     * @Param: int $status 成功响应 HTTP 状态码
     *
     * @Return JsonResponse 统一 API 成功响应
     *
     * @Throws ApiException 计划校验或权限异常
     */
    private function respond(Request $request, callable $callback, int $status = 200): JsonResponse
    {
        try {
            return $this->success($request, $callback(), $status);
        } catch (ApiException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            throw new ApiException('brand_monitoring_operation_failed', $exception->getMessage(), 422);
        }
    }
}
