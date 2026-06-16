<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessUrlImportJob;
use App\Models\Admin;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\PlatformPlan;
use App\Models\TitleLibrary;
use App\Models\UrlImportJob;
use App\Models\UrlImportJobLog;
use App\Services\Billing\AdminResourceQuotaService;
use App\Services\GeoFlow\UrlImportProcessingService;
use App\Support\CurrentSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class UrlImportController extends Controller
{
    public function __construct(
        private readonly UrlImportProcessingService $urlImportProcessingService,
        private readonly AdminResourceQuotaService $quotaService,
    ) {}

    public function index(): View
    {
        return view('admin.url-import.index', [
            'pageTitle' => __('admin.url_import.page_title'),
            'activeMenu' => 'materials',
            'stats' => $this->loadStats(),
            'aiModelReady' => $this->urlImportProcessingService->hasReadyAnalysisModel(),
            'aiModelConfigUrl' => route('admin.ai-models.index'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'project_name' => ['nullable', 'string', 'max:120'],
            'source_label' => ['nullable', 'string', 'max:120'],
            'content_language' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'outputs' => ['array'],
            'outputs.*' => ['string', 'in:knowledge,keywords,titles'],
        ]);

        try {
            $normalized = $this->urlImportProcessingService->normalizeInputUrl((string) $validated['url']);
        } catch (\InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['url' => $exception->getMessage()]);
        }

        try {
            $this->urlImportProcessingService->assertAnalysisModelReady();
        } catch (\Throwable $exception) {
            return redirect()
                ->route('admin.ai-models.index')
                ->withInput()
                ->withErrors(['ai_model' => $exception->getMessage()]);
        }
        $siteId = (int) (app(CurrentSite::class)->id() ?? 0);
        $admin = Auth::guard('admin')->user();
        if ($siteId > 0 && ! ($admin instanceof Admin && $admin->isSuperAdmin())) {
            try {
                $this->quotaService->assertCanUse($this->currentAdminId($admin), $siteId, PlatformPlan::RESOURCE_URL_IMPORTS, 1, $admin instanceof Admin ? $admin : null);
            } catch (\Throwable $exception) {
                return back()->withInput()->withErrors(['url' => $exception->getMessage()]);
            }
        }

        $job = UrlImportJob::query()->create([
            'site_id' => $siteId > 0 ? $siteId : null,
            'owner_admin_id' => $admin instanceof Admin ? (int) $admin->id : null,
            'url' => $validated['url'],
            'normalized_url' => $normalized['url'],
            'source_domain' => $normalized['host'],
            'page_title' => $validated['project_name'] ?? '',
            'status' => 'queued',
            'current_step' => 'queued',
            'progress_percent' => 0,
            'options_json' => json_encode([
                'project_name' => $validated['project_name'] ?? '',
                'source_label' => $validated['source_label'] ?? '',
                'content_language' => $validated['content_language'] ?? '',
                'notes' => $validated['notes'] ?? '',
                'outputs' => $validated['outputs'] ?? ['knowledge', 'keywords', 'titles'],
            ], JSON_UNESCAPED_UNICODE),
            'result_json' => '',
            'error_message' => '',
            'created_by' => Auth::guard('admin')->user()?->username ?? '',
        ]);
        if ($siteId > 0 && ! ($admin instanceof Admin && $admin->isSuperAdmin())) {
            $this->quotaService->consume($this->currentAdminId($admin), $siteId, PlatformPlan::RESOURCE_URL_IMPORTS, 1, [
                'actor_admin_id' => $admin instanceof Admin ? (int) $admin->id : null,
                'subject_type' => UrlImportJob::class,
                'subject_id' => (int) $job->id,
                'idempotency_key' => 'url-import:'.(int) $job->id,
                'remark' => 'URL 采集任务消耗',
            ]);
        }

        UrlImportJobLog::query()->create([
            'job_id' => $job->id,
            'site_id' => $siteId > 0 ? $siteId : null,
            'owner_admin_id' => $admin instanceof Admin ? (int) $admin->id : null,
            'step' => 'queued',
            'level' => 'info',
            'message' => __('admin.url_import.section.new_job_desc'),
        ]);

        return redirect()->route('admin.url-import.show', ['jobId' => $job->id]);
    }

    public function run(int $jobId): JsonResponse
    {
        $job = UrlImportJob::query()->whereKey($jobId)->firstOrFail();

        if (in_array($job->status, ['queued', 'failed'], true)) {
            try {
                $this->urlImportProcessingService->assertAnalysisModelReady();
            } catch (\Throwable $exception) {
                $job->update([
                    'status' => 'failed',
                    'progress_percent' => max(1, (int) $job->progress_percent),
                    'error_message' => $exception->getMessage(),
                    'finished_at' => now(),
                ]);

                UrlImportJobLog::query()->create([
                    'job_id' => $job->id,
                    'step' => $job->current_step ?: 'queued',
                    'level' => 'error',
                    'message' => __('admin.url_import.log.failed', ['message' => $exception->getMessage()]),
                ]);

                return response()->json($this->statusPayload($job->refresh()), 422);
            }

            if ((string) config('queue.default') === 'sync') {
                $job = $this->urlImportProcessingService->process($job);
            } else {
                $job->update([
                    'status' => 'running',
                    'current_step' => $job->current_step ?: 'queued',
                    'progress_percent' => max(0, (int) $job->progress_percent),
                    'error_message' => '',
                    'started_at' => $job->started_at ?: now(),
                ]);

                ProcessUrlImportJob::dispatch((int) $job->id)->onQueue('geoflow');
            }
        }

        return response()->json($this->statusPayload($job->refresh()));
    }

    public function status(int $jobId): JsonResponse
    {
        $job = UrlImportJob::query()->whereKey($jobId)->firstOrFail();

        return response()->json($this->statusPayload($job));
    }

    public function commit(int $jobId): RedirectResponse
    {
        $job = UrlImportJob::query()->whereKey($jobId)->firstOrFail();

        try {
            $summary = $this->urlImportProcessingService->commit($job);
        } catch (\Throwable $exception) {
            return back()->withErrors(__('admin.url_import.error.commit_failed').': '.$exception->getMessage());
        }

        return redirect()
            ->route('admin.url-import.show', ['jobId' => $jobId])
            ->with('message', __('admin.url_import.commit.success').'：'.__('admin.url_import_history.import.summary', [
                'knowledge_base' => $summary['knowledge_base'],
                'keywords' => $summary['keywords'],
                'titles' => $summary['titles'],
            ]));
    }

    public function show(int $jobId): View
    {
        $job = UrlImportJob::query()->findOrFail($jobId);

        $job->load(['logs' => fn ($query) => $query->oldest()->limit(120)]);

        return view('admin.url-import.show', [
            'pageTitle' => __('admin.url_import.page_title'),
            'activeMenu' => 'materials',
            'job' => $job,
            'result' => $this->decodeJson((string) $job->result_json),
            'logs' => $job->logs,
        ]);
    }

    public function history(): View
    {
        return view('admin.url-import.history', [
            'pageTitle' => __('admin.url_import_history.page_title'),
            'activeMenu' => 'materials',
            'jobs' => UrlImportJob::query()->latest()->paginate(20),
            'stats' => [
                'total' => UrlImportJob::query()->count(),
                'completed' => UrlImportJob::query()->where('status', 'completed')->count(),
                'running' => UrlImportJob::query()->whereIn('status', ['queued', 'running'])->count(),
                'failed' => UrlImportJob::query()->where('status', 'failed')->count(),
            ],
        ]);
    }

    public function bulkDelete(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'job_ids' => ['required', 'array', 'min:1'],
            'job_ids.*' => ['integer', 'min:1'],
        ]);

        $jobIds = collect($validated['job_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($jobIds->isEmpty()) {
            return redirect()
                ->route('admin.url-import.history')
                ->withErrors(__('admin.url_import_history.bulk_delete.none_selected'));
        }

        $deletableIds = UrlImportJob::query()
            ->whereIn('id', $jobIds->all())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($deletableIds === []) {
            return redirect()
                ->route('admin.url-import.history')
                ->withErrors(__('admin.url_import_history.bulk_delete.none_selected'));
        }

        DB::transaction(function () use ($deletableIds): void {
            UrlImportJobLog::query()
                ->whereIn('job_id', $deletableIds)
                ->delete();

            UrlImportJob::query()
                ->whereIn('id', $deletableIds)
                ->delete();
        });

        return redirect()
            ->route('admin.url-import.history')
            ->with('message', __('admin.url_import_history.bulk_delete.success', ['count' => count($deletableIds)]));
    }

    private function loadStats(): array
    {
        return [
            'knowledge_bases' => KnowledgeBase::query()->count(),
            'keyword_libraries' => KeywordLibrary::query()->count(),
            'title_libraries' => TitleLibrary::query()->count(),
        ];
    }

    private function currentAdminId(mixed $admin): int
    {
        if (! $admin instanceof Admin || (int) $admin->id <= 0) {
            abort(403);
        }

        return (int) $admin->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function statusPayload(UrlImportJob $job): array
    {
        $logs = UrlImportJobLog::query()
            ->where('job_id', (int) $job->id)
            ->oldest()
            ->limit(120)
            ->get();
        $latestLogStep = (string) ($logs->last()?->step ?: '');
        $storedStep = (string) $job->current_step;
        $currentStep = $latestLogStep !== '' && ! ($latestLogStep === 'queued' && $storedStep !== 'queued')
            ? $latestLogStep
            : $storedStep;

        return [
            'id' => (int) $job->id,
            'status' => (string) $job->status,
            'status_label' => __('admin.url_import_history.status.'.$job->status),
            'current_step' => $currentStep,
            'stored_step' => $storedStep,
            'progress_percent' => (int) $job->progress_percent,
            'error_message' => (string) $job->error_message,
            'result_ready' => (string) $job->result_json !== '',
            'finished_at' => optional($job->finished_at)->format('Y-m-d H:i:s'),
            'logs' => $logs
                ->map(fn (UrlImportJobLog $log): array => [
                    'step' => (string) ($log->step ?: ''),
                    'level' => (string) $log->level,
                    'message' => (string) $log->message,
                    'created_at' => optional($log->created_at)->format('Y-m-d H:i:s'),
                ])
                ->all(),
        ];
    }
}
