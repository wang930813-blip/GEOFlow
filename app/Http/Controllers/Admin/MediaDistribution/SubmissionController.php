<?php

namespace App\Http\Controllers\Admin\MediaDistribution;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\MediaResource;
use App\Models\MediaSubmission;
use App\Models\SiteCreditAccount;
use App\Services\MediaDistribution\MediaSubmissionService;
use App\Services\MediaDistribution\SiteCreditService;
use App\Support\AdminWeb;
use App\Support\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;
use Throwable;

class SubmissionController extends Controller
{
    public function index(): View
    {
        $admin = auth('admin')->user();
        $isSuperAdmin = (bool) $admin?->isSuperAdmin();
        $siteId = app(CurrentSite::class)->id();
        $account = $siteId !== null ? app(SiteCreditService::class)->accountForSite($siteId) : null;

        $submissions = MediaSubmission::query()
            ->when($isSuperAdmin, fn ($query) => $query->withoutGlobalScope('current_site'))
            ->with(['article:id,title', 'resource:id,title,source_type,sale_price,cost_price', 'site:id,name'])
            ->orderByDesc('id')
            ->paginate(20);

        return view('admin.media-distribution.submissions', [
            'pageTitle' => '媒体投稿订单',
            'activeMenu' => 'media_distribution',
            'adminSiteName' => AdminWeb::siteName(),
            'submissions' => $submissions,
            'articles' => Article::query()->select(['id', 'title'])->whereNull('deleted_at')->orderByDesc('id')->limit(100)->get(),
            'resources' => MediaResource::query()->active()->orderBy('sale_price')->limit(200)->get(),
            'selectedResourceId' => (int) request('media_resource_id', 0),
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
            'media_resource_id' => ['required', 'integer', 'min:1'],
            'remark' => ['nullable', 'string', 'max:1000'],
        ]);

        $resource = MediaResource::query()->whereKey((int) $payload['media_resource_id'])->firstOrFail();
        $created = 0;
        $errors = [];
        foreach (array_unique(array_map('intval', $payload['article_ids'])) as $articleId) {
            try {
                $article = Article::query()->whereKey($articleId)->firstOrFail();
                $submissions->submit($article, $resource, auth('admin')->user(), trim((string) ($payload['remark'] ?? '')));
                $created++;
            } catch (Throwable $e) {
                $errors[] = '#'.$articleId.' '.$e->getMessage();
            }
        }

        if ($created === 0 && $errors !== []) {
            return back()->withErrors($errors)->withInput();
        }

        return redirect()
            ->route('admin.media-distribution.submissions.index')
            ->with('message', '批量投稿已提交 '.$created.' 篇'.($errors !== [] ? '，失败 '.count($errors).' 篇' : ''));
    }

    public function show(int $submission): View
    {
        $submission = $this->findSubmission($submission);
        $this->authorizeSubmission($submission);

        return view('admin.media-distribution.submission-show', [
            'pageTitle' => '媒体投稿详情',
            'activeMenu' => 'media_distribution',
            'adminSiteName' => AdminWeb::siteName(),
            'submission' => $submission->load(['article:id,title,slug', 'resource:id,title,source_type,remarks,case_link,cost_price,sale_price', 'site:id,name']),
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
                        $submission->status,
                    ];
                    if ($isSuperAdmin) {
                        $row = [
                            $submission->id,
                            $submission->site?->name,
                            $submission->title_snapshot,
                            $submission->resource?->title,
                            $submission->external_order_nid,
                            $submission->status,
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
}
