<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\Article;
use App\Models\MediaResource;
use App\Models\MediaSubmission;
use App\Services\Api\IdempotencyService;
use App\Services\MediaDistribution\MediaSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function store(Request $request, MediaSubmissionService $submissionService): JsonResponse
    {
        $cached = IdempotencyService::maybeReplayJson($request, 'POST /media/submissions');
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

        $admin = Admin::query()->whereKey($this->auth($request)->auditAdminId)->first();
        if (! $admin instanceof Admin) {
            throw new ApiException('admin_not_found', '系统中不存在可用的管理员账号', 500);
        }

        $created = [];
        $errors = [];
        $articleIds = collect($payload['article_ids'])->map(fn ($id): int => (int) $id)->unique()->values();
        $resourceIds = collect($payload['media_resource_ids'])->map(fn ($id): int => (int) $id)->unique()->values();

        foreach ($articleIds as $articleId) {
            $article = Article::withoutGlobalScope('current_site')
                ->when($this->auth($request)->siteId !== null, fn ($q) => $q->where('site_id', $this->auth($request)->siteId))
                ->whereKey($articleId)
                ->first();
            if (! $article instanceof Article) {
                $errors[] = ['article_id' => $articleId, 'message' => '文章不存在'];

                continue;
            }

            foreach ($resourceIds as $resourceId) {
                $resource = MediaResource::query()->whereKey($resourceId)->first();
                if (! $resource instanceof MediaResource) {
                    $errors[] = ['article_id' => $articleId, 'media_resource_id' => $resourceId, 'message' => '媒体资源不存在'];

                    continue;
                }

                try {
                    $submission = $submissionService->submit($article, $resource, $admin, trim((string) ($payload['remark'] ?? '')));
                    $created[] = $this->submissionPayload($submission->load(['article:id,title', 'resource:id,title,platform_id,source_type,sale_price', 'site:id,name']));
                } catch (Throwable $e) {
                    $errors[] = ['article_id' => $articleId, 'media_resource_id' => $resourceId, 'message' => $e->getMessage()];
                }
            }
        }

        if ($created === [] && $errors !== []) {
            throw new ApiException('media_submission_failed', '媒体投稿失败', 422, ['errors' => $errors]);
        }

        return $this->success($request, [
            'submissions' => $created,
            'errors' => $errors,
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

            throw new ApiException('media_submission_sync_failed', $e->getMessage(), 422);
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
            throw new ApiException('media_submission_cancel_failed', $e->getMessage(), 422);
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
            throw new ApiException('media_submission_appeal_failed', $e->getMessage(), 422);
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
            'last_error_message' => (string) ($submission->last_error_message ?? ''),
            'submitted_at' => $submission->submitted_at?->format('Y-m-d H:i:s'),
            'last_synced_at' => $submission->last_synced_at?->format('Y-m-d H:i:s'),
            'created_at' => $submission->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $submission->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
