<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Site;
use App\Services\Crebee\SelfMediaArticlePublishService as CrebeeArticlePublishService;
use App\Services\SelfMedia\SelfMediaArticlePublishService as AiToEarnArticlePublishService;
use App\Support\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class ArticleSelfMediaPublishController extends Controller
{
    public function __construct(
        private readonly CrebeeArticlePublishService $crebeePublishService,
        private readonly AiToEarnArticlePublishService $aiToEarnPublishService,
    ) {}

    public function store(Request $request, int $articleId): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);
        abort_if($admin->isAgentAdmin(), 403);

        $site = app(CurrentSite::class)->get();
        abort_unless($site instanceof Site, 403);

        $accountField = (bool) config('aitoearn.enabled', false) ? 'self_media_account_ids' : 'crebee_account_ids';
        $payload = $request->validate([
            $accountField => ['required', 'array', 'min:1'],
            $accountField.'.*' => ['integer', 'min:1'],
        ], [
            $accountField.'.required' => '请选择要发布的自媒体平台',
            $accountField.'.array' => '请选择要发布的自媒体平台',
            $accountField.'.min' => '请选择要发布的自媒体平台',
        ]);

        $article = Article::query()->whereKey($articleId)->firstOrFail();

        try {
            $service = (bool) config('aitoearn.enabled', false)
                ? $this->aiToEarnPublishService
                : $this->crebeePublishService;

            $jobs = $service->publish(
                article: $article,
                admin: $admin,
                site: $site,
                accountIds: $payload[$accountField] ?? []
            );
        } catch (RuntimeException $exception) {
            return back()
                ->withInput()
                ->withErrors([$accountField => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->withErrors([$accountField => '自媒体发布任务创建失败，请稍后重试']);
        }

        $itemCount = collect($jobs)->sum(fn ($job): int => (int) $job->items()->count());

        return redirect()
            ->route('admin.articles.index')
            ->with('message', '自媒体发布任务已提交，共 '.$itemCount.' 个平台');
    }
}
