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
