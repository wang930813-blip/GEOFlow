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
 * @File： OpenPlatformDataController.php
 *
 * @Description: 向授权应用提供当前站点范围内的报告、诊断记录、平台字典和问题数据。
 */

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\BrandDiagnosisQuestion;
use App\Models\BrandDiagnosisResult;
use App\Models\BrandDiagnosisRun;
use App\Models\OpenPlatformApplication;
use App\Models\Site;
use App\Services\BrandDiagnosis\BrandDiagnosisPlatform;
use App\Services\MonitoringCenter\MonitoringDashboardDataService;
use App\Support\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class OpenPlatformDataController extends BaseApiController
{
    /**
     * 报告数据
     * 返回与新版监测中心报告导出相同筛选范围内的摘要、平台、问题、来源、对话及作品引用数据。
     *
     * @Url [GET] /api/v1/open-platform/report
     *      登录 是
     *
     *      筛选参数：
     *      run_id integer 可选 诊断记录编号
     *      platform string 可选 平台键
     *      question_id integer 可选 诊断问题编号
     *      start_date string 可选 诊断完成起始日期，格式 YYYY-MM-DD
     *      end_date string 可选 诊断完成结束日期，格式 YYYY-MM-DD
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-19 12:11:31
     *
     * @UpdateTime: 2026-11-19 12:34:09
     *
     * @Return JsonResponse 筛选条件、诊断记录摘要和完整报告明细
     *
     * @Throws ApiException|ValidationException 令牌权限不足、记录越权或筛选参数无效
     */
    public function report(
        Request $request,
        CurrentSite $currentSite,
        MonitoringDashboardDataService $dashboardData
    ): JsonResponse {
        [$application, $admin, $site] = $this->openPlatformContext($request, $currentSite);
        $filters = $this->validateReportFilters($request);
        $dashboard = $dashboardData->build($admin, $site, $filters);
        $records = collect($dashboard['diagnosis_records'] ?? []);

        if (isset($filters['run_id']) && ! $records->contains('id', $filters['run_id'])) {
            throw new ApiException('diagnosis_record_not_found', '诊断记录不存在或不属于当前应用', 404);
        }

        $effectiveRunId = $filters['run_id'] ?? (int) ($records->first()['id'] ?? 0);
        if (isset($filters['question_id']) && $effectiveRunId <= 0) {
            throw new ApiException('diagnosis_record_not_found', '当前筛选范围内没有可读取的诊断记录', 404);
        }
        if (isset($filters['question_id']) && $effectiveRunId > 0) {
            $questionExists = $this->questionQuery($application, $site, $effectiveRunId)
                ->whereKey($filters['question_id'])
                ->exists();
            if (! $questionExists) {
                throw new ApiException('diagnosis_question_not_found', '问题不存在或不属于所选诊断记录', 404);
            }
        }

        if (isset($filters['platform']) && $effectiveRunId > 0) {
            $platformExists = BrandDiagnosisResult::query()
                ->withoutGlobalScopes(['current_site', 'admin_owner'])
                ->where('site_id', (int) $site->id)
                ->where('owner_admin_id', (int) $application->owner_admin_id)
                ->where('run_id', $effectiveRunId)
                ->where('platform', $filters['platform'])
                ->exists();
            if (! $platformExists) {
                throw new ApiException('diagnosis_platform_not_found', '平台不属于所选诊断记录', 404);
            }
        }

        return $this->success($request, [
            'filters' => $dashboard['filters'] ?? [],
            'diagnosis_records' => $dashboard['diagnosis_records'] ?? [],
            'report' => $dashboard['latest_diagnosis'] ?? null,
        ]);
    }

    /**
     * 诊断记录列表
     * 分页返回应用所属站点和账号的已完成品牌诊断记录及摘要指标。
     *
     * @Url [GET] /api/v1/open-platform/diagnosis-records
     *      登录 是
     *
     *      分页参数：
     *      page integer 可选 页码，默认 1
     *      per_page integer 可选 每页条数，默认 20，最大 100
     *
     *      筛选参数：
     *      start_date string 可选 诊断完成起始日期，格式 YYYY-MM-DD
     *      end_date string 可选 诊断完成结束日期，格式 YYYY-MM-DD
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-19 12:11:31
     *
     * @UpdateTime: 2026-11-19 12:11:31
     *
     * @Return JsonResponse 诊断记录条目和分页信息
     *
     * @Throws ApiException|ValidationException 令牌权限不足或筛选参数无效
     */
    public function diagnosisRecords(Request $request, CurrentSite $currentSite): JsonResponse
    {
        [$application, , $site] = $this->openPlatformContext($request, $currentSite);
        $filters = $this->validateDateFilters($request, [
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 20);
        $query = $this->runQuery($application, $site)
            ->where('status', 'completed')
            ->whereNotNull('brand_name')
            ->where('brand_name', '<>', '')
            ->when(isset($filters['start_date']), fn (Builder $builder): Builder => $builder->whereDate('completed_at', '>=', $filters['start_date']))
            ->when(isset($filters['end_date']), fn (Builder $builder): Builder => $builder->whereDate('completed_at', '<=', $filters['end_date']))
            ->orderByDesc('completed_at')
            ->orderByDesc('id');
        $paginator = $query->paginate($perPage, [
            'id', 'brand_name', 'created_at', 'completed_at', 'brand_score', 'mention_rate',
            'average_rank', 'mention_count', 'sentiment_rate', 'total_questions', 'completed_questions',
        ], 'page', $page);

        return $this->success($request, [
            'items' => $paginator->getCollection()->map(fn (BrandDiagnosisRun $run): array => [
                'id' => (int) $run->id,
                'brand' => (string) $run->brand_name,
                'created_at' => $run->created_at?->format('Y-m-d H:i:s') ?? '',
                'completed_at' => $run->completed_at?->format('Y-m-d H:i:s') ?? '',
                'summary' => [
                    'brand_score' => (int) ($run->brand_score ?? 0),
                    'mention_rate' => (int) ($run->mention_rate ?? 0),
                    'average_rank' => (float) ($run->average_rank ?? 0),
                    'mention_count' => (int) ($run->mention_count ?? 0),
                    'sentiment_rate' => (int) ($run->sentiment_rate ?? 0),
                    'total_questions' => (int) ($run->total_questions ?? 0),
                    'completed_questions' => (int) ($run->completed_questions ?? 0),
                ],
            ])->values()->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
            'filters' => [
                'start_date' => $filters['start_date'] ?? null,
                'end_date' => $filters['end_date'] ?? null,
            ],
        ]);
    }

    /**
     * 平台字典
     * 返回品牌诊断当前支持的平台键、名称、缩写和图标地址。
     *
     * @Url [GET] /api/v1/open-platform/platforms
     *      登录 是
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-19 12:11:31
     *
     * @UpdateTime: 2026-11-19 12:11:31
     *
     * @Return JsonResponse 支持的平台字典
     *
     * @Throws ApiException 令牌权限不足
     */
    public function platforms(Request $request): JsonResponse
    {
        $items = collect(BrandDiagnosisPlatform::keys())
            ->map(fn (string $platform): array => [
                'key' => $platform,
                'label' => BrandDiagnosisPlatform::label($platform),
                'icon' => BrandDiagnosisPlatform::icon($platform),
                'logo_url' => BrandDiagnosisPlatform::logoUrl($platform),
            ])
            ->all();

        return $this->success($request, ['items' => $items]);
    }

    /**
     * 诊断问题列表
     * 返回所选诊断记录的问题、完成状态和平台回答概况，不包含其他账号的问题。
     *
     * @Url [GET] /api/v1/open-platform/questions
     *      登录 是
     *
     *      筛选参数：
     *      run_id integer 必选 诊断记录编号
     *      platform string 可选 平台键，仅返回该平台有回答的问题
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-19 12:11:31
     *
     * @UpdateTime: 2026-11-19 12:11:31
     *
     * @Return JsonResponse 当前诊断记录的问题和平台回答概况
     *
     * @Throws ApiException|ValidationException 记录越权、令牌权限不足或筛选参数无效
     */
    public function questions(Request $request, CurrentSite $currentSite): JsonResponse
    {
        [$application, , $site] = $this->openPlatformContext($request, $currentSite);
        $validated = $request->validate([
            'run_id' => ['required', 'integer', 'min:1'],
            'platform' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);
        $platform = trim((string) ($validated['platform'] ?? ''));
        if ($platform !== '' && ! BrandDiagnosisPlatform::isSupported($platform)) {
            throw ValidationException::withMessages(['platform' => '平台键不受支持']);
        }

        $run = $this->runQuery($application, $site)
            ->whereKey((int) $validated['run_id'])
            ->where('status', 'completed')
            ->first();
        if (! $run instanceof BrandDiagnosisRun) {
            throw new ApiException('diagnosis_record_not_found', '诊断记录不存在或不属于当前应用', 404);
        }

        $questions = $this->questionQuery($application, $site, (int) $run->id)
            ->when($platform !== '', fn (Builder $query): Builder => $query->whereHas('results', function (Builder $resultQuery) use ($application, $site, $platform): void {
                $resultQuery->withoutGlobalScopes(['current_site', 'admin_owner'])
                    ->where('site_id', (int) $site->id)
                    ->where('owner_admin_id', (int) $application->owner_admin_id)
                    ->where('platform', $platform);
            }))
            ->with(['results' => function ($query) use ($application, $site, $platform): void {
                $query->withoutGlobalScopes(['current_site', 'admin_owner'])
                    ->where('site_id', (int) $site->id)
                    ->where('owner_admin_id', (int) $application->owner_admin_id)
                    ->when($platform !== '', fn (Builder $builder): Builder => $builder->where('platform', $platform))
                    ->orderBy('platform')
                    ->select(['id', 'question_id', 'platform', 'status']);
            }])
            ->orderBy('sort_order')
            ->get(['id', 'run_id', 'question', 'question_type', 'sort_order', 'status'])
            ->map(fn (BrandDiagnosisQuestion $question): array => [
                'id' => (int) $question->id,
                'question' => (string) $question->question,
                'question_type' => (string) $question->question_type,
                'sort_order' => (int) $question->sort_order,
                'status' => (string) $question->status,
                'result_count' => $question->results->count(),
                'completed_result_count' => $question->results->where('status', 'success')->count(),
                'platforms' => $question->results
                    ->groupBy('platform')
                    ->map(fn ($results, string $key): array => [
                        'key' => $key,
                        'label' => BrandDiagnosisPlatform::label($key),
                        'status' => (string) ($results->first()->status ?? ''),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        return $this->success($request, [
            'run' => [
                'id' => (int) $run->id,
                'brand' => (string) $run->brand_name,
                'completed_at' => $run->completed_at?->format('Y-m-d H:i:s') ?? '',
            ],
            'items' => $questions,
        ]);
    }

    /**
     * @Name: openPlatformContext
     *
     * @Description: 获取已认证应用、账号和绑定站点，拒绝未建立开放平台鉴权上下文的请求。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-19 12:11:31
     *
     * @UpdateTime: 2026-11-19 12:11:31
     *
     * @Param: Request $request 当前请求
     *
     * @Param: CurrentSite $currentSite 当前站点上下文
     *
     * @Return: array{0:OpenPlatformApplication,1:Admin,2:Site} 已认证应用、账号和站点
     *
     * @Throws: ApiException 应用、账号或站点上下文无效
     */
    private function openPlatformContext(Request $request, CurrentSite $currentSite): array
    {
        $application = $request->attributes->get('open_platform_application');
        $admin = auth('admin')->user();
        $site = $currentSite->get();

        if (! $application instanceof OpenPlatformApplication
            || ! $admin instanceof Admin
            || ! $site instanceof Site) {
            throw new ApiException('unauthorized', '开放平台应用未认证', 401);
        }

        return [$application, $admin, $site];
    }

    /**
     * @Name: runQuery
     *
     * @Description: 同时限定站点、数据所有者和完成状态，作为所有开放诊断查询的租户边界。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-19 12:11:31
     *
     * @UpdateTime: 2026-11-19 12:11:31
     *
     * @Param: OpenPlatformApplication $application 已认证应用
     *
     * @Param: Site $site 应用绑定站点
     *
     * @Return: Builder<BrandDiagnosisRun> 当前应用可读取的诊断记录查询
     *
     * @Throws: 无
     */
    private function runQuery(OpenPlatformApplication $application, Site $site): Builder
    {
        return BrandDiagnosisRun::query()
            ->withoutGlobalScopes(['current_site', 'admin_owner'])
            ->where('site_id', (int) $site->id)
            ->where('owner_admin_id', (int) $application->owner_admin_id);
    }

    /**
     * @Name: questionQuery
     *
     * @Description: 以已验证诊断记录为父范围，并显式校验问题所属站点和数据所有者。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-19 12:11:31
     *
     * @UpdateTime: 2026-11-19 12:11:31
     *
     * @Param: OpenPlatformApplication $application 已认证应用
     *
     * @Param: Site $site 应用绑定站点
     *
     * @Param: int $runId 已授权诊断记录编号
     *
     * @Return: Builder<BrandDiagnosisQuestion> 当前应用可读取的问题查询
     *
     * @Throws: 无
     */
    private function questionQuery(OpenPlatformApplication $application, Site $site, int $runId): Builder
    {
        return BrandDiagnosisQuestion::query()
            ->withoutGlobalScopes(['current_site', 'admin_owner'])
            ->where('site_id', (int) $site->id)
            ->where('owner_admin_id', (int) $application->owner_admin_id)
            ->where('run_id', $runId);
    }

    /**
     * @Name: validateReportFilters
     *
     * @Description: 按监测中心报告导出的参数规则校验筛选条件，并阻止非法日期范围。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-19 12:11:31
     *
     * @UpdateTime: 2026-11-19 12:11:31
     *
     * @Param: Request $request 当前请求
     *
     * @Return: array<string,mixed> 标准化报告筛选值
     *
     * @Throws: ValidationException 日期倒置或平台键无效
     */
    private function validateReportFilters(Request $request): array
    {
        $filters = $this->validateDateFilters($request, [
            'run_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'platform' => ['sometimes', 'nullable', 'string', 'max:64'],
            'question_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        $platform = trim((string) ($filters['platform'] ?? ''));
        if ($platform !== '' && ! BrandDiagnosisPlatform::isSupported($platform)) {
            throw ValidationException::withMessages(['platform' => '平台键不受支持']);
        }

        return [
            'run_id' => isset($filters['run_id']) ? (int) $filters['run_id'] : null,
            'platform' => $platform !== '' ? $platform : null,
            'question_id' => isset($filters['question_id']) ? (int) $filters['question_id'] : null,
            'start_date' => $filters['start_date'] ?? null,
            'end_date' => $filters['end_date'] ?? null,
        ];
    }

    /**
     * @Name: validateDateFilters
     *
     * @Description: 校验报告和诊断列表共用的完成日期筛选参数及调用方传入的分页规则。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-19 12:11:31
     *
     * @UpdateTime: 2026-11-19 12:11:31
     *
     * @Param: Request $request 当前请求
     *
     * @Param: array<string,array<int,mixed>> $additionalRules 额外的接口参数规则
     *
     * @Return: array<string,mixed> 已验证筛选参数
     *
     * @Throws: ValidationException 日期格式错误或结束日期早于开始日期
     */
    private function validateDateFilters(Request $request, array $additionalRules = []): array
    {
        $filters = $request->validate([
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            ...$additionalRules,
        ]);
        $startDate = $filters['start_date'] ?? null;
        $endDate = $filters['end_date'] ?? null;
        if (is_string($startDate) && is_string($endDate) && $endDate < $startDate) {
            throw ValidationException::withMessages(['end_date' => '结束日期不能早于开始日期']);
        }

        return $filters;
    }
}
