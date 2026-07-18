<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\Article;
use App\Models\MediaResource;
use App\Models\MediaSubmission;
use App\Services\Api\ApiTokenService;
use App\Services\Api\IdempotencyService;
use App\Services\MediaDistribution\MediaSubmissionBudgetGuard;
use App\Services\MediaDistribution\MediaSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class MediaSubmissionController extends BaseApiController
{
    public function index(Request $request, MediaSubmissionService $submissionService): JsonResponse
    {
        $page = max(1, $request->integer('page', 1));
        $perPage = max(1, min(100, $request->integer('per_page', 20)));
        $siteId = $this->auth($request)->siteId;
        $query = MediaSubmission::withoutGlobalScope('current_site')
            ->with(['article:id,title', 'resource:id,title,platform_id,source_type,sale_price', 'site:id,name'])
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->when($request->integer('site_id', 0) > 0, fn ($q) => $q->where('site_id', $request->integer('site_id')))
            ->when($request->integer('article_id', 0) > 0, fn ($q) => $q->where('article_id', $request->integer('article_id')))
            ->when($request->integer('media_resource_id', 0) > 0, fn ($q) => $q->where('media_resource_id', $request->integer('media_resource_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->query('status')))
            ->orderByDesc('id');

        $total = (clone $query)->count();
        $submissions = $query->forPage($page, $perPage)->get();
        $this->syncVisible($submissions, $submissionService);
        $submissions = MediaSubmission::withoutGlobalScope('current_site')
            ->with(['article:id,title', 'resource:id,title,platform_id,source_type,sale_price', 'site:id,name'])
            ->whereIn('id', $submissions->pluck('id'))
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->orderByDesc('id')
            ->get();

        return $this->success($request, [
            'items' => $submissions->map(fn (MediaSubmission $submission): array => $this->submissionPayload($submission))->all(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    public function store(
        Request $request,
        MediaSubmissionService $submissionService,
        MediaSubmissionBudgetGuard $budgetGuard,
    ): JsonResponse {
        $cached = IdempotencyService::maybeReplayJson($request, 'POST /media/submissions');
        if ($cached !== null) {
            return $cached;
        }

        $isMcpKey = in_array(ApiTokenService::MCP_CONNECT_SCOPE, (array) ($this->auth($request)->token['scopes'] ?? []), true);
        $maxArticles = $isMcpKey ? 1 : 50;
        $maxResources = $isMcpKey ? 20 : 50;
        $payload = $request->validate([
            'article_ids' => ['required', 'array', 'min:1', 'max:'.$maxArticles],
            'article_ids.*' => ['integer', 'min:1'],
            'media_resource_ids' => ['required', 'array', 'min:1', 'max:'.$maxResources],
            'media_resource_ids.*' => ['integer', 'min:1'],
            'remark' => ['nullable', 'string', 'max:1000'],
            'max_unit_price' => [Rule::requiredIf($isMcpKey), 'nullable', 'numeric', 'min:0.01', 'max:999999.99'],
            'max_total_price' => [Rule::requiredIf($isMcpKey), 'nullable', 'numeric', 'min:0.01', 'max:9999999.99'],
        ]);

        $admin = Admin::query()->whereKey($this->auth($request)->auditAdminId)->first();
        if (! $admin instanceof Admin) {
            throw new ApiException('admin_not_found', '系统中不存在可用的管理员账号', 500);
        }

        $created = [];
        $errors = [];
        $articleIds = collect($payload['article_ids'])->map(fn ($id): int => (int) $id)->unique()->values();
        $resourceIds = collect($payload['media_resource_ids'])->map(fn ($id): int => (int) $id)->unique()->values();
        $articles = Article::withoutGlobalScope('current_site')
            ->when($this->auth($request)->siteId !== null, fn ($query) => $query->where('site_id', $this->auth($request)->siteId))
            ->whereIn('id', $articleIds)
            ->get()
            ->keyBy('id');
        $resources = MediaResource::query()->whereIn('id', $resourceIds)->get()->keyBy('id');

        foreach ($articleIds as $articleId) {
            if (! $articles->has($articleId)) {
                $errors[] = ['article_id' => $articleId, 'message' => '文章不存在'];
            }
        }
        foreach ($resourceIds as $resourceId) {
            if (! $resources->has($resourceId)) {
                $errors[] = ['media_resource_id' => $resourceId, 'message' => '媒体资源不存在'];
            }
        }

        $budget = null;
        $confirmedResourcePrices = [];
        if ($isMcpKey && $articles->isNotEmpty() && $resources->isNotEmpty()) {
            $siteId = (int) ($this->auth($request)->siteId ?? 0);
            if ($siteId <= 0) {
                throw new ApiException('mcp_site_required', 'MCP Key 必须绑定有效站点', 403);
            }
            $budgetCheck = $budgetGuard->assertWithinBudget(
                $resources->values(),
                $siteId,
                $articles->count(),
                (string) $payload['max_unit_price'],
                (string) $payload['max_total_price'],
                $this->auth($request)->token,
            );
            $confirmedResourcePrices = $budgetCheck['resource_prices'];
            $budget = [
                'actual_total_price' => $budgetCheck['actual_total_price'],
                'max_unit_price' => $budgetCheck['max_unit_price'],
                'max_total_price' => $budgetCheck['max_total_price'],
            ];
        }

        foreach ($articleIds as $articleId) {
            $article = $articles->get($articleId);
            if (! $article instanceof Article) {
                continue;
            }

            foreach ($resourceIds as $resourceId) {
                $resource = $resources->get($resourceId);
                if (! $resource instanceof MediaResource) {
                    continue;
                }

                try {
                    $submission = $submissionService->submit(
                        $article,
                        $resource,
                        $admin,
                        trim((string) ($payload['remark'] ?? '')),
                        $isMcpKey ? (int) ($this->auth($request)->token['id'] ?? 0) : null,
                        $isMcpKey ? ($confirmedResourcePrices[(int) $resource->id] ?? null) : null,
                    );
                    $created[] = $this->submissionPayload($submission->load(['article:id,title', 'resource:id,title,platform_id,source_type,sale_price', 'site:id,name']));
                } catch (Throwable $e) {
                    report($e);
                    $errors[] = [
                        'article_id' => $articleId,
                        'media_resource_id' => $resourceId,
                        'message' => $this->publicErrorMessage($e->getMessage()),
                    ];
                }
            }
        }

        if ($created === [] && $errors !== []) {
            throw new ApiException('media_submission_failed', '媒体投稿失败', 422, ['errors' => $errors]);
        }

        return $this->success($request, [
            'submissions' => $created,
            'errors' => $errors,
            'budget' => $budget,
        ], 201, 'POST /media/submissions');
    }

    public function show(Request $request, int $submission, MediaSubmissionService $submissionService): JsonResponse
    {
        $mediaSubmission = $this->findSubmission($request, $submission);
        $this->syncVisible(collect([$mediaSubmission]), $submissionService);
        $mediaSubmission = $this->findSubmission($request, $submission)->load(['article:id,title', 'resource:id,title,platform_id,source_type,sale_price', 'site:id,name']);

        return $this->success($request, $this->submissionPayload($mediaSubmission));
    }

    public function sync(Request $request, int $submission, MediaSubmissionService $submissionService): JsonResponse
    {
        $mediaSubmission = $this->findSubmission($request, $submission);

        try {
            $submissionService->syncStatus($mediaSubmission);
        } catch (Throwable $e) {
            $mediaSubmission->forceFill([
                'last_error_message' => $e->getMessage(),
                'last_synced_at' => now(),
            ])->save();

            report($e);
            throw new ApiException('media_submission_sync_failed', '媒体投稿状态同步失败', 422);
        }

        $mediaSubmission->refresh()->load(['article:id,title', 'resource:id,title,platform_id,source_type,sale_price', 'site:id,name']);

        return $this->success($request, $this->submissionPayload($mediaSubmission));
    }

    public function cancel(Request $request, int $submission, MediaSubmissionService $submissionService): JsonResponse
    {
        $payload = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $mediaSubmission = $this->findSubmission($request, $submission);

        try {
            $submissionService->cancel($mediaSubmission, trim((string) $payload['reason']));
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('media_submission_cancel_failed', '媒体投稿取消失败', 422);
        }

        $mediaSubmission->refresh()->load(['article:id,title', 'resource:id,title,platform_id,source_type,sale_price', 'site:id,name']);

        return $this->success($request, $this->submissionPayload($mediaSubmission));
    }

    public function appeal(Request $request, int $submission, MediaSubmissionService $submissionService): JsonResponse
    {
        $payload = $request->validate([
            'content' => ['required', 'string', 'max:2000'],
        ]);
        $mediaSubmission = $this->findSubmission($request, $submission);

        try {
            $submissionService->appeal($mediaSubmission, trim((string) $payload['content']));
        } catch (Throwable $e) {
            report($e);
            throw new ApiException('media_submission_appeal_failed', '媒体投稿申诉失败', 422);
        }

        $mediaSubmission->refresh()->load(['article:id,title', 'resource:id,title,platform_id,source_type,sale_price', 'site:id,name']);

        return $this->success($request, $this->submissionPayload($mediaSubmission));
    }

    private function findSubmission(Request $request, int $submissionId): MediaSubmission
    {
        $submission = MediaSubmission::withoutGlobalScope('current_site')
            ->with(['article:id,title', 'resource:id,title,platform_id,source_type,sale_price', 'site:id,name'])
            ->when($this->auth($request)->siteId !== null, fn ($q) => $q->where('site_id', $this->auth($request)->siteId))
            ->whereKey($submissionId)
            ->first();
        if (! $submission instanceof MediaSubmission) {
            throw new ApiException('media_submission_not_found', '媒体投稿订单不存在', 404);
        }

        return $submission;
    }

    private function syncVisible($submissions, MediaSubmissionService $submissionService): void
    {
        foreach ($submissions as $submission) {
            if (! $submission instanceof MediaSubmission
                || (string) $submission->external_order_nid === ''
                || in_array((string) $submission->status, ['published', 'rejected', 'cancelled'], true)) {
                continue;
            }

            try {
                $submissionService->syncStatus($submission);
            } catch (Throwable $e) {
                $submission->forceFill([
                    'last_error_message' => $e->getMessage(),
                    'last_synced_at' => now(),
                ])->save();
            }
        }
    }

    private function submissionPayload(MediaSubmission $submission): array
    {
        return [
            'id' => (int) $submission->id,
            'site_id' => (int) $submission->site_id,
            'site_name' => (string) ($submission->site?->name ?? ''),
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
            'last_error_message' => $this->publicErrorMessage((string) ($submission->last_error_message ?? '')),
            'submitted_at' => $submission->submitted_at?->format('Y-m-d H:i:s'),
            'last_synced_at' => $submission->last_synced_at?->format('Y-m-d H:i:s'),
            'created_at' => $submission->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $submission->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function publicErrorMessage(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return '';
        }

        foreach (['当前账号积分不足', '媒体资源不可投稿', '文章内容不能为空', '文章缺少站点归属'] as $publicMessage) {
            if ($message === $publicMessage) {
                return $publicMessage;
            }
        }

        return '媒体平台暂时不可用，请稍后重试';
    }
}
