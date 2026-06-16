<?php

namespace App\Http\Controllers\Admin\MediaDistribution;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\MediaResource;
use App\Models\MediaSubmission;
use App\Services\MediaDistribution\MediaSubmissionService;
use App\Services\MediaDistribution\AdminCreditService;
use App\Support\AdminWeb;
use App\Support\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class SubmissionController extends Controller
{
    public function index(Request $request, MediaSubmissionService $submissionService): View
    {
        $admin = auth('admin')->user();
        $isSuperAdmin = (bool) $admin?->isSuperAdmin();
        $siteId = app(CurrentSite::class)->id();
        $account = ($siteId !== null && $admin !== null)
            ? app(AdminCreditService::class)->accountForAdmin((int) $admin->id, (int) $siteId)
            : null;

        $submissions = MediaSubmission::query()
            ->when($isSuperAdmin, fn ($query) => $query->withoutGlobalScope('current_site'))
            ->with(['article:id,title', 'resource:id,title,platform_id,source_type,sale_price,cost_price', 'site:id,name'])
            ->orderByDesc('id')
            ->paginate(20);
        $this->syncVisibleSubmissions($submissions->getCollection(), $submissionService);
        $submissions->setCollection($submissions->getCollection()->fresh(['article:id,title', 'resource:id,title,platform_id,source_type,sale_price,cost_price', 'site:id,name']));

        $selectedResourceIds = collect((array) $request->query('media_resource_ids', []))
            ->push($request->query('media_resource_id'))
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $resources = MediaResource::query()
            ->active()
            ->when($selectedResourceIds !== [], fn ($query) => $query->orderByRaw('CASE WHEN id IN ('.implode(',', array_fill(0, count($selectedResourceIds), '?')).') THEN 0 ELSE 1 END', $selectedResourceIds))
            ->orderBy('sale_price')
            ->limit(200)
            ->get();

        if ($selectedResourceIds !== []) {
            $missingSelectedResources = MediaResource::query()
                ->active()
                ->whereIn('id', array_diff($selectedResourceIds, $resources->pluck('id')->map(static fn ($id): int => (int) $id)->all()))
                ->get();
            $resources = $missingSelectedResources->concat($resources)->unique('id')->values();
        }

        return view('admin.media-distribution.submissions', [
            'pageTitle' => '媒体投稿订单',
            'activeMenu' => 'media_distribution',
            'adminSiteName' => AdminWeb::siteName(),
            'submissions' => $submissions,
            'articles' => Article::query()->select(['id', 'title'])->whereNull('deleted_at')->orderByDesc('id')->limit(100)->get(),
            'resources' => $resources,
            'selectedResourceId' => $selectedResourceIds[0] ?? 0,
            'selectedResourceIds' => $selectedResourceIds,
            'hasSelectedResources' => $selectedResourceIds !== [],
            'account' => $account,
            'isSuperAdmin' => $isSuperAdmin,
        ]);
    }

    public function store(Request $request, MediaSubmissionService $submissions): RedirectResponse
    {
        $payload = $request->validate([
            'article_id' => ['required', 'integer', 'min:1'],
            'media_resource_id' => ['required', 'integer', 'min:1'],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);

        $article = Article::query()->whereKey((int) $payload['article_id'])->firstOrFail();
        $resource = MediaResource::query()->whereKey((int) $payload['media_resource_id'])->firstOrFail();

        try {
            $submissions->submit($article, $resource, auth('admin')->user(), trim((string) ($payload['remark'] ?? '')));
        } catch (Throwable $e) {
            return back()->withErrors($e->getMessage())->withInput();
        }

        return redirect()->route('admin.media-distribution.submissions.index')->with('message', '媒体投稿已提交');
    }

    public function bulkStore(Request $request, MediaSubmissionService $submissions): RedirectResponse
    {
        $payload = $request->validate([
            'article_ids' => ['required', 'array', 'min:1', 'max:50'],
            'article_ids.*' => ['integer', 'min:1'],
            'media_resource_id' => ['nullable', 'integer', 'min:1'],
            'media_resource_ids' => ['nullable', 'array', 'max:50'],
            'media_resource_ids.*' => ['integer', 'min:1'],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);

        $resourceIds = collect((array) ($payload['media_resource_ids'] ?? []))
            ->push($payload['media_resource_id'] ?? null)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        if ($resourceIds->isEmpty()) {
            return back()->withErrors('请选择至少一个媒体')->withInput();
        }

        $resources = MediaResource::query()
            ->whereIn('id', $resourceIds->all())
            ->get()
            ->keyBy('id');
        $created = 0;
        $errors = [];
        foreach (array_unique(array_map('intval', $payload['article_ids'])) as $articleId) {
            foreach ($resourceIds as $resourceId) {
                try {
                    $article = Article::query()->whereKey($articleId)->firstOrFail();
                    $resource = $resources->get($resourceId);
                    if (! $resource instanceof MediaResource) {
                        throw new \RuntimeException('媒体不存在: #'.$resourceId);
                    }

                    $submissions->submit($article, $resource, auth('admin')->user(), trim((string) ($payload['remark'] ?? '')));
                    $created++;
                } catch (Throwable $e) {
                    $errors[] = '#'.$articleId.' / 媒体#'.$resourceId.' '.$e->getMessage();
                }
            }
        }

        if ($created === 0 && $errors !== []) {
            return back()->withErrors($errors)->withInput();
        }

        return redirect()
            ->route('admin.media-distribution.submissions.index')
            ->with('message', '批量投稿已提交 '.$created.' 个订单'.($errors !== [] ? '，失败 '.count($errors).' 个' : ''));
    }

    public function show(int $submission, MediaSubmissionService $submissions): View
    {
        $submission = $this->findSubmission($submission);
        $this->authorizeSubmission($submission);
        $this->syncVisibleSubmissions(collect([$submission]), $submissions);
        $submission->refresh();

        return view('admin.media-distribution.submission-show', [
            'pageTitle' => '媒体投稿详情',
            'activeMenu' => 'media_distribution',
            'adminSiteName' => AdminWeb::siteName(),
            'submission' => $submission->load(['article:id,title,slug,status', 'resource:id,title,platform_id,source_type,remarks,case_link,cost_price,sale_price', 'site:id,name,domain']),
            'articlePublicUrl' => $this->articlePublicUrl($submission),
            'isSuperAdmin' => (bool) auth('admin')->user()?->isSuperAdmin(),
        ]);
    }

    public function sync(int $submission, MediaSubmissionService $submissions): RedirectResponse
    {
        $submission = $this->findSubmission($submission);
        $this->authorizeSubmission($submission);

        try {
            $submissions->syncStatus($submission);
        } catch (Throwable $e) {
            $submission->forceFill([
                'last_error_message' => $e->getMessage(),
                'last_synced_at' => now(),
            ])->save();

            return back()->withErrors($e->getMessage());
        }

        return redirect()
            ->route('admin.media-distribution.submissions.show', ['submission' => $submission->id])
            ->with('message', '订单状态已同步');
    }

    public function cancel(Request $request, int $submission, MediaSubmissionService $submissions): RedirectResponse
    {
        $payload = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $submission = $this->findSubmission($submission);
        $this->authorizeSubmission($submission);

        try {
            $submissions->cancel($submission, trim((string) $payload['reason']));
        } catch (Throwable $e) {
            return back()->withErrors($e->getMessage());
        }

        return redirect()
            ->route('admin.media-distribution.submissions.show', ['submission' => $submission->id])
            ->with('message', '订单已取消');
    }

    public function appeal(Request $request, int $submission, MediaSubmissionService $submissions): RedirectResponse
    {
        $payload = $request->validate([
            'content' => ['required', 'string', 'max:2000'],
        ]);
        $submission = $this->findSubmission($submission);
        $this->authorizeSubmission($submission);

        try {
            $submissions->appeal($submission, trim((string) $payload['content']));
        } catch (Throwable $e) {
            return back()->withErrors($e->getMessage());
        }

        return redirect()
            ->route('admin.media-distribution.submissions.show', ['submission' => $submission->id])
            ->with('message', '订单申诉已提交');
    }

    public function export(): StreamedResponse
    {
        $admin = auth('admin')->user();
        $isSuperAdmin = (bool) $admin?->isSuperAdmin();
        $query = MediaSubmission::query()
            ->when($isSuperAdmin, fn ($query) => $query->withoutGlobalScope('current_site'))
            ->with(['site:id,name', 'resource:id,title'])
            ->orderByDesc('id');

        if (! $isSuperAdmin) {
            $query->where('site_id', app(CurrentSite::class)->id());
        }

        return response()->stream(function () use ($query, $isSuperAdmin): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $isSuperAdmin
                ? ['ID', '站点', '文章标题', '媒体', '第三方订单号', '状态', '成本价', '销售价', '积分', '发布时间']
                : ['ID', '文章标题', '媒体', '第三方订单号', '状态', '销售价', '积分', '发布时间']);
            $query->chunk(200, function ($rows) use ($out, $isSuperAdmin): void {
                foreach ($rows as $submission) {
                    $row = [
                        $submission->id,
                        $submission->title_snapshot,
                        $submission->resource?->title,
                        $submission->external_order_nid,
                        $submission->statusLabel(),
                    ];
                    if ($isSuperAdmin) {
                        $row = [
                            $submission->id,
                            $submission->site?->name,
                            $submission->title_snapshot,
                            $submission->resource?->title,
                            $submission->external_order_nid,
                            $submission->statusLabel(),
                            $submission->cost_price_snapshot,
                        ];
                    }
                    $row[] = $submission->sale_price_snapshot;
                    $row[] = $submission->points_amount;
                    $row[] = $submission->published_url;
                    fputcsv($out, $row);
                }
            });
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="media-submissions.csv"',
        ]);
    }

    private function authorizeSubmission(MediaSubmission $submission): void
    {
        if (auth('admin')->user()?->isSuperAdmin()) {
            return;
        }

        abort_unless((int) $submission->site_id === (int) app(CurrentSite::class)->id(), 403);
    }

    private function findSubmission(int $submissionId): MediaSubmission
    {
        $query = MediaSubmission::query();
        if (auth('admin')->user()?->isSuperAdmin()) {
            $query->withoutGlobalScope('current_site');
        }

        return $query->whereKey($submissionId)->firstOrFail();
    }

    private function articlePublicUrl(MediaSubmission $submission): string
    {
        $article = $submission->article;
        if (! $article instanceof Article || trim((string) $article->slug) === '' || (string) $article->status !== 'published') {
            return '';
        }

        $domain = trim((string) ($submission->site?->domain ?? ''));
        if ($domain !== '') {
            $baseUrl = preg_match('#^https?://#i', $domain) === 1 ? $domain : request()->getScheme().'://'.$domain;

            return rtrim($baseUrl, '/').'/article/'.rawurlencode((string) $article->slug);
        }

        return route('site.article', ['slug' => $article->slug]);
    }

    private function syncVisibleSubmissions($submissions, MediaSubmissionService $submissionService): void
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
}
