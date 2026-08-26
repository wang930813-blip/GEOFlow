<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Site;
use App\Models\VideoGenerationJob;
use App\Services\Crebee\SelfMediaVideoPublishService as CrebeeVideoPublishService;
use App\Services\SelfMedia\SelfMediaVideoPublishService as AiToEarnVideoPublishService;
use App\Support\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class VideoSelfMediaPublishController extends Controller
{
    public function __construct(
        private readonly CrebeeVideoPublishService $crebeePublishService,
        private readonly AiToEarnVideoPublishService $aiToEarnPublishService,
    ) {}

    public function store(Request $request, VideoGenerationJob $videoGeneration): RedirectResponse
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

        try {
            $service = (bool) config('aitoearn.enabled', false)
                ? $this->aiToEarnPublishService
                : $this->crebeePublishService;

            $jobs = $service->publish(
                video: $videoGeneration,
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
                ->withErrors([$accountField => '自媒体视频发布任务创建失败，请稍后重试']);
        }

        $itemCount = collect($jobs)->sum(fn ($job): int => (int) $job->items()->count());

        return redirect()
            ->route('admin.video-generations.index')
            ->with('message', '自媒体视频发布任务已提交，共 '.$itemCount.' 个平台');
    }
}
