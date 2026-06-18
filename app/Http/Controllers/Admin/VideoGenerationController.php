<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\CrebeeAccount;
use App\Models\Site;
use App\Models\VideoGenerationJob;
use App\Services\VideoGeneration\VideoGenerationService;
use App\Support\AdminWeb;
use App\Support\Crebee\SelfMediaPlatformCatalog;
use App\Support\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class VideoGenerationController extends Controller
{
    public function __construct(
        private readonly VideoGenerationService $service,
    ) {}

    public function index(Request $request): View
    {
        $admin = $this->admin($request);
        $site = $this->site();

        $videos = $this->visibleVideos($admin, $site)
            ->with('owner:id,username,display_name,role')
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();

        return view('admin.video-generations.index', [
            'pageTitle' => '生成视频',
            'activeMenu' => 'video_generations',
            'adminSiteName' => AdminWeb::siteName(),
            'site' => $site,
            'videos' => $videos,
            'platformLabels' => SelfMediaPlatformCatalog::videoPlatformLabels(),
        ]);
    }

    public function create(Request $request): View
    {
        $admin = $this->admin($request);
        $this->site();

        return view('admin.video-generations.create', [
            'pageTitle' => '创建生成视频',
            'activeMenu' => 'video_generations',
            'adminSiteName' => AdminWeb::siteName(),
            'admin' => $admin,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $admin = $this->admin($request);
        $site = $this->site();

        $payload = $request->validate([
            'subject' => ['required', 'string', 'max:500'],
            'script' => ['nullable', 'string', 'max:10000'],
            'terms' => ['nullable', 'string', 'max:1000'],
            'negative_terms' => ['nullable', 'string', 'max:1000'],
            'video_source' => ['nullable', 'string', 'in:pexels,pixabay,local'],
            'video_aspect' => ['nullable', 'string', 'in:9:16,16:9,1:1'],
            'video_count' => ['nullable', 'integer', 'min:1', 'max:5'],
            'cover_image' => ['nullable', 'string', 'max:1000'],
        ], [
            'subject.required' => '请填写视频主题',
            'video_aspect.in' => '请选择正确的视频比例',
            'video_count.min' => '生成数量不能小于 1',
            'video_count.max' => '单次最多生成 5 个视频',
        ]);

        try {
            $video = $this->service->create($admin, $site, $payload);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.video-generations.create')
                ->withInput()
                ->withErrors(['subject' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.video-generations.create')
                ->withInput()
                ->withErrors(['subject' => '视频生成任务创建失败，请稍后重试']);
        }

        return redirect()
            ->route('admin.video-generations.show', ['videoGeneration' => (int) $video->id])
            ->with('message', '视频生成任务已提交，请稍后查看生成结果');
    }

    public function show(Request $request, VideoGenerationJob $videoGeneration): View
    {
        $admin = $this->admin($request);
        $site = $this->site();
        $this->authorizeVideo($videoGeneration, $admin, $site);

        return view('admin.video-generations.show', [
            'pageTitle' => '视频详情',
            'activeMenu' => 'video_generations',
            'adminSiteName' => AdminWeb::siteName(),
            'site' => $site,
            'video' => $videoGeneration,
            'selfMediaVideoAccounts' => $this->loadSelfMediaVideoAccounts($admin, $site),
            'selfMediaPlatformLabels' => SelfMediaPlatformCatalog::videoPlatformLabels(),
            'selfMediaPlatformLogos' => collect(SelfMediaPlatformCatalog::videoPlatforms())
                ->mapWithKeys(fn (string $platform): array => [$platform => SelfMediaPlatformCatalog::logoPath($platform)])
                ->all(),
        ]);
    }

    public function updateCover(Request $request, VideoGenerationJob $videoGeneration): RedirectResponse
    {
        $admin = $this->admin($request);
        $site = $this->site();
        $this->authorizeVideo($videoGeneration, $admin, $site);

        $payload = $request->validate([
            'cover_image' => ['required', 'string', 'max:1000'],
        ], [
            'cover_image.required' => '请填写封面图地址',
        ]);

        $videoGeneration->forceFill([
            'cover_image' => trim((string) $payload['cover_image']),
        ])->save();

        return back()->with('message', '封面图已更新');
    }

    public function download(Request $request, VideoGenerationJob $videoGeneration): StreamedResponse
    {
        $admin = $this->admin($request);
        $site = $this->site();
        $this->authorizeVideo($videoGeneration, $admin, $site);

        abort_if((string) $videoGeneration->status !== 'success', 404);

        $videoUrl = $videoGeneration->firstVideoUrl();
        abort_if($videoUrl === '' || ! $this->isDownloadableUrl($videoUrl), 404);

        $remoteResponse = Http::timeout(300)
            ->connectTimeout(10)
            ->withOptions(['stream' => true])
            ->get($videoUrl);

        abort_unless($remoteResponse->successful(), 502, 'Video file download failed.');

        $contentType = trim((string) $remoteResponse->header('Content-Type')) ?: 'video/mp4';
        $contentLength = trim((string) $remoteResponse->header('Content-Length'));
        $stream = $remoteResponse->toPsrResponse()->getBody();
        $filename = $this->videoDownloadFilename($videoGeneration);

        $headers = [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ];
        if ($contentLength !== '') {
            $headers['Content-Length'] = $contentLength;
        }

        return response()->streamDownload(function () use ($stream, $remoteResponse): void {
            try {
                while (! $stream->eof()) {
                    echo $stream->read(1024 * 1024);
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            } finally {
                $remoteResponse->close();
            }
        }, $filename, $headers);
    }

    private function admin(Request $request): Admin
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        return $admin;
    }

    private function site(): Site
    {
        $site = app(CurrentSite::class)->get();
        abort_unless($site instanceof Site, 403);

        return $site;
    }

    private function visibleVideos(Admin $admin, Site $site): Builder
    {
        $query = VideoGenerationJob::query()->where('site_id', (int) $site->id);

        if (! ($admin->isSuperAdmin() || $admin->isAgentAdmin())) {
            $query->where('owner_admin_id', (int) $admin->id);
        }

        return $query;
    }

    private function authorizeVideo(VideoGenerationJob $video, Admin $admin, Site $site): void
    {
        abort_if((int) $video->site_id !== (int) $site->id, 404);

        if (! ($admin->isSuperAdmin() || $admin->isAgentAdmin())) {
            abort_if((int) $video->owner_admin_id !== (int) $admin->id, 404);
        }
    }

    private function loadSelfMediaVideoAccounts(Admin $admin, Site $site)
    {
        return CrebeeAccount::query()
            ->select(['id', 'agent_id', 'site_id', 'owner_admin_id', 'platform', 'crebee_account_id', 'account_name', 'avatar', 'status'])
            ->where('site_id', (int) $site->id)
            ->where('owner_admin_id', (int) $admin->id)
            ->where('status', 'bound')
            ->whereIn('platform', SelfMediaPlatformCatalog::videoPlatforms())
            ->orderBy('platform')
            ->orderBy('id')
            ->get();
    }

    private function isDownloadableUrl(string $url): bool
    {
        return preg_match('/^https?:\/\//i', trim($url)) === 1;
    }

    private function videoDownloadFilename(VideoGenerationJob $video): string
    {
        $title = trim((string) ($video->title ?: $video->subject));
        $safeTitle = Str::of($title)
            ->replaceMatches('/[\\\\\/:*?"<>|]+/u', '_')
            ->replaceMatches('/\s+/u', ' ')
            ->trim()
            ->limit(80, '')
            ->toString();

        return ($safeTitle !== '' ? $safeTitle : 'video-'.(int) $video->id).'.mp4';
    }
}
