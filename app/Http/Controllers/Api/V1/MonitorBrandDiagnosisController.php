<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-11-23
 *
 * @Time: 11:21:41
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： MonitorBrandDiagnosisController.php
 *
 * @Description: 为新版监测中心提供站点隔离的品牌诊断工作流接口。
 */

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\BrandDiagnosisRun;
use App\Models\Site;
use App\Services\BrandDiagnosis\BrandDiagnosisModelSettings;
use App\Services\BrandDiagnosis\BrandDiagnosisPlatform;
use App\Services\BrandDiagnosis\BrandDiagnosisRunService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class MonitorBrandDiagnosisController extends BaseApiController
{
    private const PLATFORM_OPTIONS = [
        ['key' => BrandDiagnosisPlatform::DOUBAO, 'description' => '联网问答'],
        ['key' => BrandDiagnosisPlatform::DEEPSEEK, 'description' => '深度推理'],
        ['key' => BrandDiagnosisPlatform::QIANWEN, 'description' => '通义问答'],
        ['key' => BrandDiagnosisPlatform::WENXIN, 'description' => '千帆搜索'],
        ['key' => BrandDiagnosisPlatform::YUANBAO, 'description' => '混元模型'],
    ];

    /**
     * 获取当前账号的品牌诊断记录
     * 按当前站点和账号分页返回诊断摘要，完整问题仅在打开单条记录时读取。
     *
     * @Url [GET] /api/v1/monitor/brand-diagnosis
     *      登录 是
     *
     *      分页参数：
     *      page integer 可选 页码，最小值为 1
     *      per_page integer 可选 每页记录数，范围为 1 至 50
     *
     *      筛选参数：
     *      无
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 15:22:09
     *
     * @UpdateTime: 2026-11-23 15:31:23
     *
     * @Return JsonResponse 当前账号的品牌诊断记录和分页信息
     *
     * @Throws ApiException 监测中心凭证无效或当前站点不可用
     */
    public function index(Request $request): JsonResponse
    {
        [$admin, $site] = $this->monitorContext($request);
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $perPage = min(50, max(1, (int) ($validated['per_page'] ?? 10)));
        $records = $this->visibleRunQuery($admin, $site)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return $this->success($request, [
            'items' => $records->getCollection()
                ->map(fn (BrandDiagnosisRun $record): array => $this->recordPayload($record))
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
            ],
        ]);
    }

    /**
     * 获取品牌诊断工作台选项
     * 返回当前运维启用且完成接口配置的平台状态及标准诊断问题数量。
     *
     * @Url [GET] /api/v1/monitor/brand-diagnosis/options
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
     * @CreateTime: 2026-11-23 11:21:41
     *
     * @UpdateTime: 2026-11-23 12:02:31
     *
     * @Return JsonResponse 当前账号可使用的平台及诊断设置
     *
     * @Throws ApiException 监测中心凭证无效或当前站点不可用
     */
    public function options(Request $request, BrandDiagnosisModelSettings $modelSettings): JsonResponse
    {
        $this->monitorContext($request);
        $platforms = array_map(static function (array $platform) use ($modelSettings): array {
            $key = $platform['key'];
            $enabled = $modelSettings->isEnabled($key);
            $configured = $modelSettings->isConfigured($key);

            return [
                ...$platform,
                'name' => BrandDiagnosisPlatform::label($key),
                'icon' => BrandDiagnosisPlatform::icon($key),
                'logo_url' => BrandDiagnosisPlatform::logoUrl($key),
                'enabled' => $enabled,
                'configured' => $configured,
                'available' => $enabled && $configured,
            ];
        }, self::PLATFORM_OPTIONS);

        return $this->success($request, [
            'platforms' => $platforms,
            'standard_question_count' => min(30, max(1, (int) config('brand_diagnosis.question_count', 10))),
            'deep_question_count' => 30,
        ]);
    }

    /**
     * 创建品牌诊断问题生成任务
     * 使用现有诊断服务生成当前站点品牌的问题集，尚未确认的问题不会预留诊断额度。
     *
     * @Url [POST] /api/v1/monitor/brand-diagnosis
     *      登录 是
     *      brand_name string 必选 品牌名称
     *      platforms string[] 必选 本次诊断使用的平台键
     *      diagnosis_mode string 必选 standard 或 deep
     *      website_url string 可选 品牌官网地址
     *      brand_profile string 可选 用户补充的品牌简介
     *      brand_terms string[] 可选 品牌核心词，最多五个
     *      competitors object[] 可选 竞品名称和相关词
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 11:21:41
     *
     * @UpdateTime: 2026-11-23 12:02:31
     *
     * @Return JsonResponse 新建的品牌诊断任务和当前问题列表
     *
     * @Throws ApiException 模型不可用、当前站点不可用或问题生成任务无法创建
     */
    public function store(
        Request $request,
        BrandDiagnosisModelSettings $modelSettings,
        BrandDiagnosisRunService $runService
    ): JsonResponse {
        [$admin] = $this->monitorContext($request);
        $validated = $request->validate([
            'brand_name' => ['required', 'string', 'max:120'],
            'platforms' => ['required', 'array', 'min:1', 'max:5'],
            'platforms.*' => ['required', 'string', 'distinct', BrandDiagnosisPlatform::validationRule()],
            'diagnosis_mode' => ['required', 'string', 'in:standard,deep'],
            'website_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'brand_profile' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'brand_terms' => ['sometimes', 'array', 'max:5'],
            'brand_terms.*' => ['string', 'max:80'],
            'competitors' => ['sometimes', 'array', 'max:10'],
            'competitors.*.name' => ['required', 'string', 'max:80'],
            'competitors.*.terms' => ['sometimes', 'array', 'max:10'],
            'competitors.*.terms.*' => ['string', 'max:80'],
        ]);

        $this->ensurePlatformsAvailable((array) $validated['platforms'], $modelSettings);
        $brandContext = [
            'website_url' => trim((string) ($validated['website_url'] ?? '')),
            'brand_profile' => trim((string) ($validated['brand_profile'] ?? '')),
            'brand_terms' => array_values((array) ($validated['brand_terms'] ?? [])),
            'competitors' => array_values((array) ($validated['competitors'] ?? [])),
        ];

        try {
            $run = $runService->create(
                $admin,
                (string) $validated['brand_name'],
                array_values((array) $validated['platforms']),
                false,
                (string) $validated['diagnosis_mode'],
                null,
                $brandContext
            );
        } catch (RuntimeException $exception) {
            throw new ApiException('brand_diagnosis_create_failed', $exception->getMessage(), 422);
        }

        return $this->success($request, $this->runPayload($run), 201);
    }

    /**
     * 读取品牌诊断任务状态
     * 仅返回当前登录账号所属站点的诊断状态、问题和进度。
     *
     * @Url [GET] /api/v1/monitor/brand-diagnosis/{run}
     *      登录 是
     *
     *      分页参数：
     *      无
     *
     *      筛选参数：
     *      run integer 必选 当前站点品牌诊断任务编号
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 11:21:41
     *
     * @UpdateTime: 2026-11-23 12:02:31
     *
     * @Return JsonResponse 诊断状态、进度和问题内容
     *
     * @Throws ApiException 任务不属于当前账号或站点不可用
     */
    public function show(Request $request, int $run): JsonResponse
    {
        [$admin, $site] = $this->monitorContext($request);
        $diagnosisRun = $this->visibleRunQuery($admin, $site)
            ->whereKey($run)
            ->with([
                'questions' => fn ($query) => $query
                    ->withoutGlobalScopes(['current_site', 'admin_owner'])
                    ->orderBy('sort_order'),
            ])
            ->first();

        if (! $diagnosisRun instanceof BrandDiagnosisRun) {
            throw new ApiException('brand_diagnosis_not_found', '诊断记录不存在或无权访问', 404);
        }

        return $this->success($request, $this->runPayload($diagnosisRun));
    }

    /**
     * 确认品牌诊断问题并开始执行
     * 校验当前站点任务和可用模型后复用原诊断服务保存问题、预留额度并派发诊断任务。
     *
     * @Url [POST] /api/v1/monitor/brand-diagnosis/{run}/confirm
     *      登录 是
     *      run integer 必选 当前站点品牌诊断任务编号
     *      platforms string[] 必选 本次诊断使用的平台键
     *      questions object[] 必选 经用户确认的问题 ID 和文本
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 11:21:41
     *
     * @UpdateTime: 2026-11-23 12:02:31
     *
     * @Return JsonResponse 已启动的诊断任务状态
     *
     * @Throws ApiException 任务不可确认、模型不可用或诊断额度不足
     */
    public function confirm(
        Request $request,
        int $run,
        BrandDiagnosisModelSettings $modelSettings,
        BrandDiagnosisRunService $runService
    ): JsonResponse {
        [$admin, $site] = $this->monitorContext($request);
        $validated = $request->validate([
            'platforms' => ['required', 'array', 'min:1', 'max:5'],
            'platforms.*' => ['required', 'string', 'distinct', BrandDiagnosisPlatform::validationRule()],
            'questions' => ['required', 'array', 'min:1', 'max:30'],
            'questions.*.id' => ['required', 'integer', 'min:1'],
            'questions.*.question' => ['nullable', 'string', 'max:240'],
        ]);
        $this->ensurePlatformsAvailable((array) $validated['platforms'], $modelSettings);

        $diagnosisRun = $this->visibleRunQuery($admin, $site)->whereKey($run)->first();
        if (! $diagnosisRun instanceof BrandDiagnosisRun) {
            throw new ApiException('brand_diagnosis_not_found', '诊断记录不存在或无权访问', 404);
        }

        $questions = collect($validated['questions'])
            ->mapWithKeys(static fn (array $question): array => [
                (int) $question['id'] => (string) ($question['question'] ?? ''),
            ])
            ->all();

        try {
            $confirmedRun = $runService->confirm(
                $admin,
                $diagnosisRun,
                $questions,
                array_values((array) $validated['platforms'])
            );
        } catch (RuntimeException $exception) {
            throw new ApiException('brand_diagnosis_confirm_failed', $exception->getMessage(), 422);
        }

        return $this->success($request, $this->runPayload($confirmedRun), 202);
    }

    /**
     * 获取监测中心鉴权上下文
     * 从监测中心认证中间件写入的数据中读取前端账号和默认站点。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 11:21:41
     *
     * @UpdateTime: 2026-11-23 12:02:31
     *
     * @Param: Request $request 当前 API 请求
     *
     * @Return array{0:Admin,1:Site} 当前账号和站点
     *
     * @Throws: ApiException 监测中心身份或站点不合法
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
     * 校验用户选择的平台均已由运维启用且配置完整。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 11:21:41
     *
     * @UpdateTime: 2026-11-23 12:02:31
     *
     * @Param: list<string> $platforms 本次请求选择的平台键
     *
     * @Param: BrandDiagnosisModelSettings $modelSettings 当前诊断模型设置服务
     *
     * @Return: void 所有平台均可调用时正常返回
     *
     * @Throws: ApiException 平台不可用时抛出
     */
    private function ensurePlatformsAvailable(array $platforms, BrandDiagnosisModelSettings $modelSettings): void
    {
        $unavailable = collect($platforms)
            ->filter(fn (string $platform): bool => ! $modelSettings->isEnabled($platform) || ! $modelSettings->isConfigured($platform))
            ->map(fn (string $platform): string => BrandDiagnosisPlatform::label($platform))
            ->values()
            ->all();

        if ($unavailable !== []) {
            throw new ApiException(
                'brand_diagnosis_model_unavailable',
                implode('、', $unavailable).'当前不可用，请刷新模型列表后重试',
                422
            );
        }
    }

    /**
     * 按当前站点和账号查询品牌诊断任务，并兼容历史未写入所有者 ID 的自有记录。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 11:21:41
     *
     * @UpdateTime: 2026-11-23 12:02:31
     *
     * @Param: Admin $admin 当前监测中心账号
     *
     * @Param: Site $site 当前监测站点
     *
     * @Return Builder<BrandDiagnosisRun> 已限制站点与账号的数据查询
     *
     * @Throws: 无
     */
    private function visibleRunQuery(Admin $admin, Site $site): Builder
    {
        return BrandDiagnosisRun::query()
            ->withoutGlobalScopes(['current_site', 'admin_owner'])
            ->where('site_id', (int) $site->id)
            ->where(function (Builder $query) use ($admin): void {
                $query->where('owner_admin_id', (int) $admin->id)
                    ->orWhere(function (Builder $legacyQuery) use ($admin): void {
                        $legacyQuery->whereNull('owner_admin_id')
                            ->where('admin_id', (int) $admin->id);
                    });
            });
    }

    /**
     * 将诊断任务转为记录列表所需的摘要数据。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 15:22:09
     *
     * @UpdateTime: 2026-11-23 15:31:23
     *
     * @Param: BrandDiagnosisRun $run 当前诊断记录
     *
     * @Return array<string,mixed> 不包含问题明细的诊断记录摘要
     *
     * @Throws: 无
     */
    private function recordPayload(BrandDiagnosisRun $run): array
    {
        $total = max(0, (int) $run->total_questions);
        $finished = max(0, (int) $run->completed_questions + (int) $run->failed_questions);
        $platforms = array_values((array) $run->platforms);

        return [
            'id' => (int) $run->id,
            'brand_name' => (string) $run->brand_name,
            'status' => (string) $run->status,
            'platforms' => $platforms,
            'platform_names' => array_map(
                static fn (mixed $platform): string => BrandDiagnosisPlatform::label((string) $platform),
                $platforms
            ),
            'total_questions' => $total,
            'completed_questions' => (int) $run->completed_questions,
            'failed_questions' => (int) $run->failed_questions,
            'progress' => $total > 0 ? (int) min(100, round($finished * 100 / $total)) : 0,
            'website_url' => (string) data_get($run->brand_profile_meta, 'brand_context.website_url', ''),
            'diagnosis_mode' => (string) data_get($run->brand_profile_meta, 'diagnosis_options.mode', 'standard'),
            'created_at' => $run->created_at?->format('Y-m-d H:i:s'),
            'completed_at' => $run->completed_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * 将诊断任务转为工作台所需的最小响应数据。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 11:21:41
     *
     * @UpdateTime: 2026-11-23 12:02:31
     *
     * @Param: BrandDiagnosisRun $run 当前诊断任务
     *
     * @Return array<string,mixed> 诊断进度、模型和问题数据
     *
     * @Throws: 无
     */
    private function runPayload(BrandDiagnosisRun $run): array
    {
        $questions = $run->relationLoaded('questions')
            ? $run->questions
            : $run->questions()->orderBy('sort_order')->get();
        $total = max(0, (int) $run->total_questions);
        $finished = max(0, (int) $run->completed_questions + (int) $run->failed_questions);

        return [
            'id' => (int) $run->id,
            'brand_name' => (string) $run->brand_name,
            'status' => (string) $run->status,
            'platforms' => array_values((array) $run->platforms),
            'total_questions' => $total,
            'completed_questions' => (int) $run->completed_questions,
            'failed_questions' => (int) $run->failed_questions,
            'progress' => $total > 0 ? (int) min(100, round($finished * 100 / $total)) : 0,
            'error_message' => (string) ($run->error_message ?? ''),
            'created_at' => $run->created_at?->format('Y-m-d H:i:s'),
            'completed_at' => $run->completed_at?->format('Y-m-d H:i:s'),
            'diagnosis_mode' => (string) data_get($run->brand_profile_meta, 'diagnosis_options.mode', 'standard'),
            'brand_profile' => (string) ($run->brand_profile ?? ''),
            'brand_context' => (array) data_get($run->brand_profile_meta, 'brand_context', []),
            'questions' => $questions->map(static fn ($question): array => [
                'id' => (int) $question->id,
                'question' => (string) $question->question,
                'type' => (string) $question->question_type,
                'core_term' => (string) ($question->core_term ?? ''),
                'sort_order' => (int) $question->sort_order,
            ])->values()->all(),
        ];
    }
}
