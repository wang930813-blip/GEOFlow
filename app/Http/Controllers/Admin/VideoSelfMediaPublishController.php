<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Site;
use App\Models\VideoGenerationJob;
use App\Services\Crebee\SelfMediaVideoPublishService;
use App\Support\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class VideoSelfMediaPublishController extends Controller
{
    public function __construct(
        private readonly SelfMediaVideoPublishService $publishService,
    ) {}

    public function store(Request $request, VideoGenerationJob $videoGeneration): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        $site = app(CurrentSite::class)->get();
        abort_unless($site instanceof Site, 403);

        $payload = $request->validate([
            'crebee_account_ids' => ['required', 'array', 'min:1'],
            'crebee_account_ids.*' => ['integer', 'min:1'],
        ], [
            'crebee_account_ids.required' => '请选择要发布的自媒体平台',
            'crebee_account_ids.array' => '请选择要发布的自媒体平台',
            'crebee_account_ids.min' => '请选择要发布的自媒体平台',
        ]);

        try {
            $jobs = $this->publishService->publish(
                video: $videoGeneration,
                admin: $admin,
                site: $site,
                accountIds: $payload['crebee_account_ids'] ?? []
            );
        } catch (RuntimeException $exception) {
            return back()
                ->withInput()
                ->withErrors(['crebee_account_ids' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->withErrors(['crebee_account_ids' => '自媒体视频发布任务创建失败，请稍后重试']);
        }

        $itemCount = collect($jobs)->sum(fn ($job): int => (int) $job->items()->count());

        return redirect()
            ->route('admin.video-generations.index')
            ->with('message', '自媒体视频发布任务已提交，共 '.$itemCount.' 个平台');
    }
}
