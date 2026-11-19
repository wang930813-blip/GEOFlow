<?php

/**
 * Created by Codex.
 * @Date: 2026-11-18
 * @Time: 10:06:26
 * @UpdateTime: 2026-11-18 17:57:54
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： MonitorMediaPublishingController.php
 * @Description: 为新版监测中心提供账号及站点隔离的媒资资源、投稿和积分接口。
 */

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\ApiAuthContext;
use App\Models\Admin;
use App\Models\AdminCreditLedger;
use App\Models\AdminPlanSubscription;
use App\Models\Article;
use App\Models\MediaResource;
use App\Models\MediaResourceSitePrice;
use App\Models\MediaSubmission;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Services\Api\IdempotencyService;
use App\Services\Billing\AdminResourceQuotaService;
use App\Services\MediaDistribution\AdminCreditService;
use App\Services\MediaDistribution\MediaSubmissionService;
use App\Support\MediaDistribution\MediaPlatform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

final class MonitorMediaPublishingController extends BaseApiController
{
    /**
     * 媒体资源列表
     * 获取当前登录账号可投稿的媒体资源及当前站点适用售价。
     * @Url [GET] /api/v1/monitor/media/resources
     *      登录 是
     *      page integer 可选 页码
     *      per_page integer 可选 每页条数，最大 100
     *      platform_id integer 可选 媒体平台编号
     *      source_type string 可选 资源类型：website_media 或 zi_media
     *      search string 可选 媒体名称、分类或说明关键词
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-18 10:06:26
     * @UpdateTime: 2026-11-18 10:06:26
     *
     * @Return JsonResponse 当前站点可投稿媒体、筛选项和分页信息
     * @Throws ApiException 监测中心凭证或站点上下文无效
     */
    public function resources(Request $request): JsonResponse
    {
        ['site' => $site] = $this->monitorContext($request);
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'platform_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'source_type' => ['sometimes', 'nullable', 'string', 'in:website_media,zi_media'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 24);
        $query = MediaResource::query()->active();

        if ((int) ($filters['platform_id'] ?? 0) > 0) {
            $query->where('platform_id', (int) $filters['platform_id']);
        }
        if (($filters['source_type'] ?? '') !== '') {
            $query->where('source_type', (string) $filters['source_type']);
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.$this->escapeLike(mb_strtolower($search)).'%';
            $query->where(function ($builder) use ($like): void {
                $builder->whereRaw('LOWER(title) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(category, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(remarks, \'\')) LIKE ?', [$like]);
            });
        }

        $total = (clone $query)->count();
        $resources = $query
            ->orderBy('sale_price')
            ->orderBy('id')
            ->forPage($page, $perPage)
            ->get();
        $sitePrices = $resources->isEmpty()
            ? collect()
            : MediaResourceSitePrice::query()
                ->where('site_id', (int) $site->id)
                ->whereIn('media_resource_id', $resources->pluck('id'))
                ->pluck('sale_price', 'media_resource_id');

        return $this->success($request, [
            'items' => $resources->map(fn (MediaResource $resource): array => $this->resourcePayload(
                $resource,
                $sitePrices->get($resource->id)
            ))->all(),
            'filters' => [
                'platforms' => MediaPlatform::labels(),
                'source_types' => [
                    ['value' => MediaResource::SOURCE_WEBSITE, 'label' => '网站媒体'],
                    ['value' => MediaResource::SOURCE_ZI_MEDIA, 'label' => '第三方自媒体'],
                ],
            ],
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * 可投稿文章列表
     * 获取当前账号及站点已发布且正文非空的官网文章供媒资投稿选择。
     * @Url [GET] /api/v1/monitor/media/articles
     *      登录 是
     *      page integer 可选 页码
     *      per_page integer 可选 每页条数，最大 100
     *      search string 可选 文章标题或编号关键词
     *      article_id integer 可选 精确筛选官网文章编号
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-18 10:06:26
     * @UpdateTime: 2026-11-18 18:02:45
     *
     * @Return JsonResponse 当前账号可投稿文章和分页信息
     * @Throws ApiException 监测中心凭证或站点上下文无效
     */
    public function articles(Request $request): JsonResponse
    {
        ['admin' => $admin, 'site' => $site] = $this->monitorContext($request);
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'article_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 100);
        $query = Article::query()
            ->where('site_id', (int) $site->id)
            ->where('owner_admin_id', (int) $admin->id)
            ->where('status', 'published')
            ->whereNotNull('content')
            ->whereRaw("TRIM(content) <> ''");
        if (isset($filters['article_id'])) {
            $query->whereKey((int) $filters['article_id']);
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.$this->escapeLike(mb_strtolower($search)).'%';
            $query->where(function ($builder) use ($like, $search): void {
                $builder->whereRaw('LOWER(title) LIKE ?', [$like]);
                if (ctype_digit($search)) {
                    $builder->orWhere('id', (int) $search);
                }
            });
        }

        $total = (clone $query)->count();
        $articles = $query
            ->select(['id', 'title', 'status', 'review_status', 'created_at'])
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        return $this->success($request, [
            'items' => $articles->map(static fn (Article $article): array => [
                'id' => (int) $article->id,
                'title' => (string) $article->title,
                'status' => (string) $article->status,
                'review_status' => (string) $article->review_status,
                'created_at' => $article->created_at?->format('Y-m-d H:i:s'),
            ])->all(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * 媒资积分与流水
     * 获取当前账号在当前站点的积分余额、套餐额度状态和最近流水。
     * @Url [GET] /api/v1/monitor/media/credits
     *      登录 是
     *      page integer 可选 流水页码
     *      per_page integer 可选 每页流水条数，最大 100
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-18 10:06:26
     * @UpdateTime: 2026-11-18 10:50:26
     *
     * @Return JsonResponse 当前账号积分账户、额度状态和流水分页
     * @Throws ApiException 监测中心凭证或站点上下文无效
     */
    public function credits(
        Request $request,
        AdminCreditService $creditService,
        AdminResourceQuotaService $quotaService
    ): JsonResponse {
        ['admin' => $admin, 'site' => $site] = $this->monitorContext($request);
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 20);
        $account = $creditService->accountForAdmin((int) $admin->id, (int) $site->id);
        $ledgerQuery = AdminCreditLedger::query()
            ->where('admin_id', (int) $admin->id)
            ->where('site_id', (int) $site->id)
            ->orderByDesc('id');
        $total = (clone $ledgerQuery)->count();
        $ledger = $ledgerQuery->forPage($page, $perPage)->get();
        $subscription = null;
        if (! $admin->isSuperAdmin()) {
            try {
                $subscription = $quotaService->activeSubscriptionForAdmin((int) $admin->id, (int) $site->id);
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() !== '当前账号规格已到期，请联系平台续费') {
                    throw $exception;
                }
            }
        }
        $creditEntitlement = $subscription instanceof AdminPlanSubscription
            ? data_get((array) $subscription->entitlements_snapshot, PlatformPlan::RESOURCE_CREDITS)
            : null;
        $unlimited = $admin->isSuperAdmin()
            || (is_array($creditEntitlement)
                && (bool) ($creditEntitlement['enabled'] ?? false)
                && (int) ($creditEntitlement['quota_value'] ?? 0) <= 0);

        return $this->success($request, [
            'account' => [
                'balance' => (string) $account->balance,
                'frozen_balance' => (string) $account->frozen_balance,
                'total_granted' => (string) $account->total_granted,
                'total_consumed' => (string) $account->total_consumed,
                'unlimited' => $unlimited,
            ],
            'ledger' => $ledger->map(static fn (AdminCreditLedger $entry): array => [
                'id' => (int) $entry->id,
                'type' => (string) $entry->type,
                'type_label' => match ((string) $entry->type) {
                    'grant' => '发放',
                    'adjust' => '调整',
                    'deduct' => '投稿扣除',
                    'refund' => '退回',
                    default => (string) $entry->type,
                },
                'amount' => (string) $entry->amount,
                'balance_after' => (string) $entry->balance_after,
                'remark' => (string) ($entry->remark ?? ''),
                'created_at' => $entry->created_at?->format('Y-m-d H:i:s'),
            ])->all(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * 媒资投稿订单列表
     * 获取当前账号在当前站点创建的投稿订单，并按状态或文章筛选。
     * @Url [GET] /api/v1/monitor/media/submissions
     *      登录 是
     *      page integer 可选 页码
     *      per_page integer 可选 每页条数，最大 100
     *      status string 可选 投稿状态
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-18 10:06:26
     * @UpdateTime: 2026-11-18 10:06:26
     *
     * @Return JsonResponse 当前账号投稿订单和分页信息
     * @Throws ApiException 监测中心凭证或站点上下文无效
     */
    public function submissions(Request $request): JsonResponse
    {
        ['admin' => $admin, 'site' => $site] = $this->monitorContext($request);
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'nullable', 'string', 'in:submitting,submitted,publishing,published,failed,rejected,cancelled,appealing'],
        ]);
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 20);
        $query = MediaSubmission::query()
            ->where('site_id', (int) $site->id)
            ->where('owner_admin_id', (int) $admin->id)
            ->with(['article:id,title', 'resource:id,title,platform_id,source_type', 'site:id,name'])
            ->when(($filters['status'] ?? '') !== '', fn ($builder) => $builder->where('status', (string) $filters['status']))
            ->orderByDesc('id');
        $total = (clone $query)->count();
        $items = $query->forPage($page, $perPage)->get();

        return $this->success($request, [
            'items' => $items->map(fn (MediaSubmission $submission): array => $this->submissionPayload($submission))->all(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * 创建媒资投稿
     * 按所选文章和媒体逐一创建投稿订单，沿用原有订阅校验、积分扣除及第三方投稿服务。
     * @Url [POST] /api/v1/monitor/media/submissions
     *      登录 是
     *      article_ids array<int> 必选 当前账号当前站点已发布官网文章编号，最多 50 篇
     *      media_resource_ids array<int> 必选 可投稿媒体编号，最多 50 个
     *      remark string 可选 投稿备注
     *      X-Idempotency-Key string 可选 相同操作重试时使用的幂等键
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-18 10:06:26
     * @UpdateTime: 2026-11-18 18:02:45
     *
     * @Return JsonResponse 已创建订单及逐项失败原因
     * @Throws ApiException 未认证、无可投稿订单或全部投稿失败
     */
    public function store(Request $request, MediaSubmissionService $submissionService): JsonResponse
    {
        ['admin' => $admin, 'site' => $site] = $this->monitorContext($request);
        $context = new ApiAuthContext(['id' => 0], (int) $admin->id, (int) $site->id);
        $cached = IdempotencyService::maybeReplayJsonForContext($request, 'POST /monitor/media/submissions', $context);
        if ($cached !== null) {
            return $cached;
        }

        $payload = $request->validate([
            'article_ids' => ['required', 'array', 'min:1', 'max:50'],
            'article_ids.*' => ['integer', 'min:1'],
            'media_resource_ids' => ['required', 'array', 'min:1', 'max:50'],
            'media_resource_ids.*' => ['integer', 'min:1'],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);
        $articleIds = collect($payload['article_ids'])->map(static fn ($id): int => (int) $id)->unique()->values();
        $resourceIds = collect($payload['media_resource_ids'])->map(static fn ($id): int => (int) $id)->unique()->values();
        $articles = Article::query()
            ->where('site_id', (int) $site->id)
            ->where('owner_admin_id', (int) $admin->id)
            ->whereIn('id', $articleIds)
            ->where('status', 'published')
            ->whereNotNull('content')
            ->whereRaw("TRIM(content) <> ''")
            ->get()
            ->keyBy('id');
        $resources = MediaResource::query()
            ->active()
            ->whereIn('id', $resourceIds)
            ->get()
            ->keyBy('id');
        $created = [];
        $errors = [];

        foreach ($articleIds as $articleId) {
            $article = $articles->get($articleId);
            if (! $article instanceof Article) {
                $errors[] = ['article_id' => $articleId, 'message' => '官网文章不存在、尚未发布或当前账号无权投稿'];
                continue;
            }

            foreach ($resourceIds as $resourceId) {
                $resource = $resources->get($resourceId);
                if (! $resource instanceof MediaResource) {
                    $errors[] = [
                        'article_id' => $articleId,
                        'media_resource_id' => $resourceId,
                        'message' => '媒体资源不存在或当前不可投稿',
                    ];
                    continue;
                }

                try {
                    $submission = $submissionService->submit(
                        $article,
                        $resource,
                        $admin,
                        trim((string) ($payload['remark'] ?? ''))
                    );
                    $created[] = $this->submissionPayload($submission->load([
                        'article:id,title',
                        'resource:id,title,platform_id,source_type',
                        'site:id,name',
                    ]));
                } catch (Throwable $exception) {
                    report($exception);
                    $errors[] = [
                        'article_id' => $articleId,
                        'media_resource_id' => $resourceId,
                        'message' => $this->publicErrorMessage($exception->getMessage()),
                    ];
                }
            }
        }

        if ($created === []) {
            throw new ApiException('media_submission_failed', '没有投稿成功的订单', 422, ['errors' => $errors]);
        }

        return $this->success($request, [
            'submissions' => $created,
            'errors' => $errors,
        ], 201, 'POST /monitor/media/submissions');
    }

    /**
     * 同步投稿订单状态
     * 按当前账号和站点权限同步单个投稿订单的第三方状态。
     * @Url [POST] /api/v1/monitor/media/submissions/{submission}/sync
     *      登录 是
     *      submission integer 必选 投稿订单编号
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-18 10:06:26
     * @UpdateTime: 2026-11-18 10:06:26
     *
     * @Return JsonResponse 已同步的投稿订单
     * @Throws ApiException 订单不存在、无权限或第三方状态同步失败
     */
    public function sync(Request $request, int $submission, MediaSubmissionService $submissionService): JsonResponse
    {
        $mediaSubmission = $this->findSubmission($request, $submission);
        try {
            $submissionService->syncStatus($mediaSubmission);
        } catch (Throwable $exception) {
            $mediaSubmission->forceFill([
                'last_error_message' => $exception->getMessage(),
                'last_synced_at' => now(),
            ])->save();
            report($exception);
            throw new ApiException('media_submission_sync_failed', '媒体投稿状态同步失败，请稍后重试', 422);
        }

        return $this->success($request, $this->submissionPayload(
            $mediaSubmission->refresh()->load(['article:id,title', 'resource:id,title,platform_id,source_type', 'site:id,name'])
        ));
    }

    /**
     * 取消投稿订单
     * 对当前账号拥有且仍可取消的投稿订单调用原有取消及积分退回流程。
     * @Url [POST] /api/v1/monitor/media/submissions/{submission}/cancel
     *      登录 是
     *      submission integer 必选 投稿订单编号
     *      reason string 必选 取消原因
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-18 10:06:26
     * @UpdateTime: 2026-11-18 10:06:26
     *
     * @Return JsonResponse 已取消的投稿订单
     * @Throws ApiException 订单不存在、无权限或当前状态不能取消
     */
    public function cancel(Request $request, int $submission, MediaSubmissionService $submissionService): JsonResponse
    {
        $payload = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $mediaSubmission = $this->findSubmission($request, $submission);
        try {
            $submissionService->cancel($mediaSubmission, trim((string) $payload['reason']));
        } catch (Throwable $exception) {
            report($exception);
            throw new ApiException('media_submission_cancel_failed', $this->actionErrorMessage($exception->getMessage()), 422);
        }

        return $this->success($request, $this->submissionPayload(
            $mediaSubmission->refresh()->load(['article:id,title', 'resource:id,title,platform_id,source_type', 'site:id,name'])
        ));
    }

    /**
     * 申诉投稿订单
     * 对当前账号拥有的失败或退稿订单提交申诉内容。
     * @Url [POST] /api/v1/monitor/media/submissions/{submission}/appeal
     *      登录 是
     *      submission integer 必选 投稿订单编号
     *      content string 必选 申诉内容
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-18 10:06:26
     * @UpdateTime: 2026-11-18 10:06:26
     *
     * @Return JsonResponse 已提交申诉的投稿订单
     * @Throws ApiException 订单不存在、无权限或当前状态不支持申诉
     */
    public function appeal(Request $request, int $submission, MediaSubmissionService $submissionService): JsonResponse
    {
        $payload = $request->validate([
            'content' => ['required', 'string', 'max:2000'],
        ]);
        $mediaSubmission = $this->findSubmission($request, $submission);
        try {
            $submissionService->appeal($mediaSubmission, trim((string) $payload['content']));
        } catch (Throwable $exception) {
            report($exception);
            throw new ApiException('media_submission_appeal_failed', $this->actionErrorMessage($exception->getMessage()), 422);
        }

        return $this->success($request, $this->submissionPayload(
            $mediaSubmission->refresh()->load(['article:id,title', 'resource:id,title,platform_id,source_type', 'site:id,name'])
        ));
    }

    /**
     * @Name: monitorContext
     * @Description: 从监测中心认证中间件写入的身份中读取管理员和站点，拒绝请求参数提供的身份替代值。
     * @Author: cdkay
     * @CreateTime: 2026-11-18 10:06:26
     * @UpdateTime: 2026-11-18 10:06:26
     * @Param: Request $request 当前请求
     * @Return: array{admin:Admin,site:Site} 已验证的管理员和站点
     * @Throws: ApiException 未认证或站点上下文无效
     */
    private function monitorContext(Request $request): array
    {
        $identity = $request->attributes->get('monitor_auth');
        $admin = is_array($identity) ? ($identity['admin'] ?? null) : null;
        $site = is_array($identity) ? ($identity['site'] ?? null) : null;
        if (! $admin instanceof Admin || ! $site instanceof Site) {
            throw new ApiException('unauthorized', '监测中心登录凭证无效', 401);
        }

        return ['admin' => $admin, 'site' => $site];
    }

    /**
     * @Name: findSubmission
     * @Description: 显式限定管理员账号及站点后读取订单，阻止通过更换订单编号访问其他账号数据。
     * @Author: cdkay
     * @CreateTime: 2026-11-18 10:06:26
     * @UpdateTime: 2026-11-18 10:06:26
     * @Param: Request $request 当前请求
     * @Param: int $submissionId 投稿订单编号
     * @Return: MediaSubmission 当前账号拥有的投稿订单
     * @Throws: ApiException 订单不存在或不属于当前账号及站点
     */
    private function findSubmission(Request $request, int $submissionId): MediaSubmission
    {
        ['admin' => $admin, 'site' => $site] = $this->monitorContext($request);
        $submission = MediaSubmission::query()
            ->where('site_id', (int) $site->id)
            ->where('owner_admin_id', (int) $admin->id)
            ->whereKey($submissionId)
            ->with(['article:id,title', 'resource:id,title,platform_id,source_type', 'site:id,name'])
            ->first();
        if (! $submission instanceof MediaSubmission) {
            throw new ApiException('media_submission_not_found', '投稿订单不存在', 404);
        }

        return $submission;
    }

    /**
     * @Name: resourcePayload
     * @Description: 生成客户可见的媒体目录字段，并用站点专属售价覆盖平台默认售价。
     * @Author: cdkay
     * @CreateTime: 2026-11-18 10:06:26
     * @UpdateTime: 2026-11-18 10:06:26
     * @Param: MediaResource $resource 媒体资源
     * @Param: mixed $sitePrice 当前站点专属售价
     * @Return: array<string,mixed> 媒体目录响应字段
     * @Throws: 无
     */
    private function resourcePayload(MediaResource $resource, mixed $sitePrice): array
    {
        return [
            'id' => (int) $resource->id,
            'platform_id' => (int) $resource->platform_id,
            'platform_label' => $resource->platformLabel(),
            'source_type' => (string) $resource->source_type,
            'source_label' => $resource->sourceLabel(),
            'title' => (string) $resource->title,
            'category' => (string) $resource->category,
            'remarks' => (string) $resource->remarks,
            'case_link' => (string) $resource->case_link,
            'sale_price' => number_format((float) ($sitePrice ?? $resource->sale_price), 2, '.', ''),
        ];
    }

    /**
     * @Name: submissionPayload
     * @Description: 返回订单摘要和状态操作所需信息，不向前端暴露第三方原始请求及响应载荷。
     * @Author: cdkay
     * @CreateTime: 2026-11-18 10:06:26
     * @UpdateTime: 2026-11-18 10:06:26
     * @Param: MediaSubmission $submission 已授权的投稿订单
     * @Return: array<string,mixed> 投稿订单公开字段
     * @Throws: 无
     */
    private function submissionPayload(MediaSubmission $submission): array
    {
        return [
            'id' => (int) $submission->id,
            'article_id' => (int) $submission->article_id,
            'article_title' => (string) ($submission->article?->title ?? $submission->title_snapshot),
            'media_resource_id' => (int) $submission->media_resource_id,
            'media_title' => (string) ($submission->resource?->title ?? ''),
            'platform_id' => (int) $submission->platform_id,
            'platform_label' => $submission->platformLabel(),
            'source_type' => (string) $submission->source_type,
            'external_order_nid' => (string) $submission->external_order_nid,
            'status' => (string) $submission->status,
            'status_label' => $submission->statusLabel(),
            'points_amount' => (string) $submission->points_amount,
            'published_url' => (string) $submission->published_url,
            'remark' => (string) ($submission->remark ?? ''),
            'last_error_message' => $this->publicErrorMessage((string) ($submission->last_error_message ?? '')),
            'submitted_at' => $submission->submitted_at?->format('Y-m-d H:i:s'),
            'last_synced_at' => $submission->last_synced_at?->format('Y-m-d H:i:s'),
            'created_at' => $submission->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @Name: publicErrorMessage
     * @Description: 仅显示可操作的业务错误，第三方原始异常统一隐藏并写入服务端日志。
     * @Author: cdkay
     * @CreateTime: 2026-11-18 10:06:26
     * @UpdateTime: 2026-11-18 10:06:26
     * @Param: string $message 内部异常消息
     * @Return: string 面向用户的错误说明
     * @Throws: 无
     */
    private function publicErrorMessage(string $message): string
    {
        $message = trim($message);
        if (in_array($message, [
            '当前账号积分不足',
            '媒体资源不可投稿',
            '文章内容不能为空',
            '文章缺少站点归属',
            '当前站点积分不足',
        ], true)) {
            return $message;
        }

        return '媒体平台暂时不可用，请稍后重试';
    }

    /**
     * @Name: actionErrorMessage
     * @Description: 保留订单状态校验错误并隐藏第三方取消或申诉接口的内部响应。
     * @Author: cdkay
     * @CreateTime: 2026-11-18 10:06:26
     * @UpdateTime: 2026-11-18 10:06:26
     * @Param: string $message 内部异常消息
     * @Return: string 面向用户的错误说明
     * @Throws: 无
     */
    private function actionErrorMessage(string $message): string
    {
        $message = trim($message);
        if (in_array($message, [
            '投稿订单缺少第三方订单号',
            '当前订单状态不能取消',
            '仅失败或拒稿订单可以申诉',
        ], true)) {
            return $message;
        }

        return '媒体平台暂时无法处理该订单，请稍后重试';
    }

    /**
     * @Name: escapeLike
     * @Description: 转义搜索词中的 SQL LIKE 通配字符，确保其按普通文本匹配。
     * @Author: cdkay
     * @CreateTime: 2026-11-18 10:06:26
     * @UpdateTime: 2026-11-18 10:06:26
     * @Param: string $value 小写搜索词
     * @Return: string 转义后的搜索词
     * @Throws: 无
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
