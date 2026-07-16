<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\BrandDiagnosisRun;
use App\Services\BrandDiagnosis\BrandDiagnosisLimitExceededException;
use App\Services\BrandDiagnosis\BrandDiagnosisPdfService;
use App\Services\BrandDiagnosis\BrandDiagnosisPlatform;
use App\Services\BrandDiagnosis\BrandDiagnosisRunService;
use App\Services\BrandDiagnosis\BrandEntityResolver;
use App\Support\AdminWeb;
use App\Support\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class BrandDiagnosisController extends Controller
{
    public function __construct(
        private readonly BrandDiagnosisRunService $runService,
        private readonly BrandDiagnosisPdfService $pdfService,
        private readonly BrandEntityResolver $brandEntityResolver
    ) {}

    public function index(Request $request): View
    {
        $filters = $this->diagnosisRecordFilters($request);
        $recordPaginator = $this->diagnosisRecords($filters);
        $records = $recordPaginator->getCollection()->all();
        $activeRecord = $records[0] ?? null;

        return view('admin.brand-diagnosis.index', [
            'pageTitle' => '品牌诊断/报告',
            'activeMenu' => 'brand_diagnosis',
            'adminSiteName' => AdminWeb::siteName(),
            'models' => $this->models(),
            'questions' => $activeRecord['questions'] ?? $this->questions(),
            'mentionRateRanking' => $this->mentionRateRanking($activeRecord),
            'mentionCountRanking' => $this->mentionCountRanking($activeRecord),
            'averageRankings' => $this->averageRankings($activeRecord),
            'sources' => $this->sources($activeRecord),
            'conversations' => $this->conversations($activeRecord),
            'diagnosisRecords' => $records,
            'diagnosisRecordPaginator' => $recordPaginator,
            'diagnosisRecordFilters' => $filters,
            'reportRecords' => $this->reportRecords(),
        ]);
    }

    public function downloadReport(int $run): Response
    {
        $diagnosisRun = $this->findDownloadableReportRun($run);
        $record = $this->formatRecord($diagnosisRun, true);
        $reportFileName = $this->reportFileName($diagnosisRun);
        $pdf = $this->pdfService->render($diagnosisRun, $record, $reportFileName);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $this->contentDisposition($reportFileName),
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'brand_name' => ['required', 'string', 'max:120'],
            'platforms' => ['nullable', 'array'],
            'platforms.*' => ['string', BrandDiagnosisPlatform::validationRule()],
            'reuse_questions' => ['nullable', 'boolean'],
        ], [
            'brand_name.required' => '请输入品牌名称',
            'platforms.*.in' => '当前版本支持豆包、DeepSeek、千问和文心一言诊断',
        ]);

        $admin = auth('admin')->user();
        if (! $admin instanceof Admin) {
            abort(403);
        }

        try {
            $this->runService->create(
                $admin,
                (string) $payload['brand_name'],
                (array) ($payload['platforms'] ?? ['doubao']),
                $request->boolean('reuse_questions')
            );
        } catch (BrandDiagnosisLimitExceededException $exception) {
            return back()->withErrors(['brand_name' => $exception->getMessage()])->withInput();
        } catch (Throwable $exception) {
            return back()->withErrors(['brand_name' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('admin.brand-diagnosis.index')
            ->with('message', 'AI 问题生成任务已创建，问题生成后请确认诊断。');
    }

    public function reusableQuestions(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'brand_name' => ['required', 'string', 'max:120'],
        ]);

        $admin = auth('admin')->user();
        if (! $admin instanceof Admin) {
            abort(403);
        }

        $preview = $this->runService->reusableQuestionPreview($admin, (string) $payload['brand_name']);

        return response()->json([
            'available' => $preview !== null && count($preview['questions']) > 0,
            'preview' => $preview,
        ]);
    }

    public function confirm(int $run, Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'questions' => ['required', 'array', 'min:1'],
            'questions.*' => ['nullable', 'string', 'max:240'],
        ], [
            'questions.required' => '请确认至少一个 AI 问题',
            'questions.min' => '请确认至少一个 AI 问题',
            'questions.*.max' => 'AI 问题不能超过 240 个字符',
        ]);

        $admin = auth('admin')->user();
        if (! $admin instanceof Admin) {
            abort(403);
        }

        $diagnosisRun = $this->diagnosisRunQuery()
            ->whereKey($run)
            ->firstOrFail();

        try {
            $this->runService->confirm(
                $admin,
                $diagnosisRun,
                (array) $payload['questions']
            );
        } catch (BrandDiagnosisLimitExceededException $exception) {
            return back()->withErrors(['questions' => $exception->getMessage()])->withInput();
        } catch (Throwable $exception) {
            return back()->withErrors(['questions' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('admin.brand-diagnosis.index')
            ->with('message', '已确认诊断，系统开始调用所选模型抓取数据。');
    }

    public function report(int $run, Request $request): View
    {
        $diagnosisRun = $this->findReportRun($run);
        $record = $this->formatRecord($diagnosisRun, true);
        $reportFileName = $this->reportFileName($diagnosisRun);

        return view('admin.brand-diagnosis.report', [
            'pageTitle' => $reportFileName,
            'record' => $record,
            'run' => $diagnosisRun,
            'reportFileName' => $reportFileName,
            'autoPrint' => $request->boolean('print') && (string) $diagnosisRun->status === 'completed',
            'adminSiteName' => AdminWeb::siteName(),
        ]);
    }

    /**
     * @return list<array{name:string,key:string,initial:string,color:string,logo:string,desc:string,deep:bool,available:bool}>
     */
    private function models(): array
    {
        return [
            ['name' => '豆包', 'key' => 'doubao', 'initial' => '豆', 'color' => 'bg-blue-600', 'logo' => BrandDiagnosisPlatform::logoUrl('doubao'), 'desc' => '网页问答', 'deep' => true, 'available' => true],
            ['name' => 'DeepSeek', 'key' => 'deepseek', 'initial' => 'DS', 'color' => 'bg-indigo-600', 'logo' => BrandDiagnosisPlatform::logoUrl('deepseek'), 'desc' => '深度推理', 'deep' => true, 'available' => true],
            ['name' => '千问', 'key' => 'qianwen', 'initial' => '千', 'color' => 'bg-violet-600', 'logo' => BrandDiagnosisPlatform::logoUrl('qianwen'), 'desc' => '通义问答', 'deep' => true, 'available' => true],
            ['name' => '文心一言', 'key' => 'wenxin', 'initial' => '文', 'color' => 'bg-emerald-600', 'logo' => BrandDiagnosisPlatform::logoUrl('wenxin'), 'desc' => '千帆搜索', 'deep' => false, 'available' => true],
        ];
    }

    /**
     * @param  array{brand:string,start_date:string,end_date:string}  $filters
     * @return LengthAwarePaginator<int,array{
     *     id:int,
     *     brand:string,
     *     status:string,
     *     created_at:string,
     *     expanded:bool,
     *     has_report:bool,
     *     metrics:array{score:int,mention_rate:int,average_rank:string,mention_count:int,sentiment_rate:int},
     *     questions:list<array{text:string,type:string,rank:int,status:string}>,
     *     sources:list<array{platform:string,category:string,title:string,questions:int,models:string,icon:string,url:string}>,
     *     conversations:list<array{question:string,brands:list<string>,visible_brands:list<string>,hidden_brand_count:int,answer:string,status:string}>
     * }>
     */
    private function diagnosisRecords(array $filters): LengthAwarePaginator
    {
        $paginator = $this->diagnosisRunQuery()
            ->when($filters['brand'] !== '', fn (Builder $query): Builder => $query->where('brand_name', 'like', '%'.$filters['brand'].'%'))
            ->when($filters['start_date'] !== '', fn (Builder $query): Builder => $query->whereDate('created_at', '>=', $filters['start_date']))
            ->when($filters['end_date'] !== '', fn (Builder $query): Builder => $query->whereDate('created_at', '<=', $filters['end_date']))
            ->orderByDesc('created_at')
            ->paginate(5)
            ->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()
                ->values()
                ->map(fn (BrandDiagnosisRun $run, int $index): array => $this->formatRecord($run, $index === 0))
        );

        return $paginator;
    }

    /**
     * @return list<array{
     *     id:int,
     *     brand:string,
     *     status:string,
     *     created_at:string,
     *     expanded:bool,
     *     has_report:bool,
     *     metrics:array{score:int,mention_rate:int,average_rank:string,mention_count:int,sentiment_rate:int},
     *     questions:list<array{text:string,type:string,rank:int,status:string}>,
     *     sources:list<array{platform:string,category:string,title:string,questions:int,models:string,icon:string,url:string}>,
     *     conversations:list<array{question:string,brands:list<string>,visible_brands:list<string>,hidden_brand_count:int,answer:string,status:string}>
     * }>
     */
    private function reportRecords(): array
    {
        return $this->diagnosisRunQuery()
            ->where('status', 'completed')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->values()
            ->map(fn (BrandDiagnosisRun $run): array => $this->formatRecord($run, false))
            ->all();
    }

    /**
     * @return Builder<BrandDiagnosisRun>
     */
    private function diagnosisRunQuery(): Builder
    {
        $admin = auth('admin')->user();
        $isSuperAdmin = $admin instanceof Admin && $admin->isSuperAdmin();
        $siteId = app(CurrentSite::class)->id();

        return BrandDiagnosisRun::query()
            ->when($isSuperAdmin, fn ($query) => $query->withoutGlobalScope('current_site'))
            ->when(! $isSuperAdmin && $siteId !== null, fn ($query) => $query->where('site_id', $siteId))
            ->with([
                'questions' => fn ($query) => $query->orderBy('sort_order')->with(['results.sources', 'results.brandMentions']),
                'sources',
                'brandMentions',
            ]);
    }

    /**
     * @return array{brand:string,start_date:string,end_date:string}
     */
    private function diagnosisRecordFilters(Request $request): array
    {
        return [
            'brand' => Str::of((string) $request->query('brand', ''))->squish()->limit(120, '')->toString(),
            'start_date' => $this->validDateFilter((string) $request->query('start_date', '')),
            'end_date' => $this->validDateFilter((string) $request->query('end_date', '')),
        ];
    }

    private function validDateFilter(string $date): string
    {
        $date = trim($date);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : '';
    }

    private function findReportRun(int $runId): BrandDiagnosisRun
    {
        return $this->reportRunQuery()
            ->whereKey($runId)
            ->whereIn('status', ['completed', 'failed'])
            ->firstOrFail();
    }

    private function findDownloadableReportRun(int $runId): BrandDiagnosisRun
    {
        return $this->reportRunQuery()
            ->whereKey($runId)
            ->where('status', 'completed')
            ->firstOrFail();
    }

    /**
     * @return Builder<BrandDiagnosisRun>
     */
    private function reportRunQuery(): Builder
    {
        $admin = auth('admin')->user();
        $isSuperAdmin = $admin instanceof Admin && $admin->isSuperAdmin();
        $siteId = app(CurrentSite::class)->id();

        return BrandDiagnosisRun::query()
            ->when($isSuperAdmin, fn ($query) => $query->withoutGlobalScope('current_site'))
            ->when(! $isSuperAdmin && $siteId !== null, fn ($query) => $query->where('site_id', $siteId))
            ->with([
                'questions' => fn ($query) => $query->orderBy('sort_order')->with(['results.sources', 'results.brandMentions']),
                'sources',
                'brandMentions',
            ]);
    }

    private function reportFileName(BrandDiagnosisRun $run): string
    {
        $brand = Str::of((string) $run->brand_name)
            ->replaceMatches('/[\\\\\/:*?"<>|]+/u', '')
            ->squish()
            ->limit(80, '')
            ->toString();
        $date = $run->created_at?->format('Y-m-d') ?? now()->format('Y-m-d');

        return ($brand !== '' ? $brand : '品牌').'_'.$date.'_诊断报告.pdf';
    }

    private function contentDisposition(string $fileName): string
    {
        $fallback = Str::of($fileName)
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9._-]+/', '_')
            ->trim('_')
            ->toString();

        if ($fallback === '') {
            $fallback = 'brand-diagnosis-report.pdf';
        }

        return 'attachment; filename="'.$fallback.'"; filename*=UTF-8\'\''.rawurlencode($fileName);
    }

    /**
     * @return array<string,mixed>
     */
    private function formatRecord(BrandDiagnosisRun $run, bool $expanded): array
    {
        $questions = $run->questions->map(static fn ($question): array => [
            'id' => (int) $question->id,
            'rank' => (int) $question->sort_order,
            'text' => (string) $question->question,
            'type' => (string) $question->question_type,
            'status' => (string) $question->status,
        ])->values()->all();

        $platformData = $this->recordPlatformData($run);
        $allPlatformData = $platformData['all'];

        return [
            'id' => (int) $run->id,
            'brand' => (string) $run->brand_name,
            'status' => $this->statusLabel((string) $run->status),
            'raw_status' => (string) $run->status,
            'created_at' => $run->created_at?->format('Y-m-d H:i:s') ?? '',
            'expanded' => $expanded,
            'has_report' => (string) $run->status === 'completed',
            'metrics' => $allPlatformData['metrics'],
            'questions' => $questions,
            'sources' => $allPlatformData['sources'],
            'conversations' => $allPlatformData['conversations'],
            'rankings' => $allPlatformData['rankings'],
            'platform_options' => $this->recordPlatformOptions($run),
            'platform_data' => $platformData,
            'can_manage_official_links' => auth('admin')->user()?->isSuperAdmin() ?? false,
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => '排队中',
            'questions_generating' => '生成问题中',
            'questions_ready' => '待确认诊断',
            'awaiting_confirmation' => '待确认诊断',
            'running' => '诊断中',
            'completed' => '已完成',
            'failed' => '诊断失败',
            default => $status,
        };
    }

    private function formatAverageRank(float $rank): string
    {
        if ($rank <= 0) {
            return '0';
        }

        return rtrim(rtrim(number_format($rank, 2, '.', ''), '0'), '.');
    }

    /**
     * @return list<array{text:string,type:string,rank:int,status:string}>
     */
    private function questions(): array
    {
        return [
            ['rank' => 1, 'text' => '输入品牌名称后发起诊断，系统会自动生成品牌认知问题。', 'type' => '品牌认知', 'status' => 'pending'],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $record
     * @return list<array{brand:string,rate:int}>
     */
    private function mentionRateRanking(?array $record): array
    {
        return (array) ($record['rankings']['mention_rate'] ?? []);
    }

    /**
     * @param  array<string,mixed>|null  $record
     * @return list<array{brand:string,count:int}>
     */
    private function mentionCountRanking(?array $record): array
    {
        return (array) ($record['rankings']['mention_count'] ?? []);
    }

    /**
     * @param  array<string,mixed>|null  $record
     * @return list<array{brand:string,rate:int,rank:string}>
     */
    private function averageRankings(?array $record): array
    {
        return (array) ($record['rankings']['average_rank'] ?? []);
    }

    /**
     * @param  array<string,mixed>|null  $record
     * @return list<array{platform:string,category:string,title:string,questions:int,models:string,icon:string,url:string}>
     */
    private function sources(?array $record): array
    {
        if ($record === null) {
            return [];
        }

        /** @var list<array{platform:string,category:string,title:string,questions:int,models:string,icon:string,url:string}> $sources */
        $sources = $record['sources'];

        return $sources;
    }

    /**
     * @param  array<string,mixed>|null  $record
     * @return list<array{question:string,brands:list<string>,visible_brands:list<string>,hidden_brand_count:int,answer:string,status:string}>
     */
    private function conversations(?array $record): array
    {
        if ($record === null) {
            return [];
        }

        /** @var list<array{question:string,brands:list<string>,visible_brands:list<string>,hidden_brand_count:int,answer:string,status:string}> $conversations */
        $conversations = $record['conversations'];

        return $conversations;
    }

    /**
     * @return array<string,array{
     *   metrics:array{score:int,mention_rate:int,average_rank:string,mention_count:int,sentiment_rate:int},
     *   rankings:array{
     *     mention_rate:list<array{brand:string,rate:int,is_target_brand:bool}>,
     *     mention_count:list<array{brand:string,count:int,is_target_brand:bool}>,
     *     average_rank:list<array{brand:string,rate:int,rank:string,rank_value:float,is_target_brand:bool}>
     *   },
     *   sources:list<array{platform:string,platform_key:string,category:string,title:string,questions:int,models:string,icon:string,url:string}>,
     *   conversations:list<array{question:string,platform:string,platform_key:string,brands:list<string>,visible_brands:list<string>,hidden_brand_count:int,answer:string,status:string,sources:list<array{title:string,url:string,domain:string,type:string}>}>
     * }>
     */
    private function recordPlatformData(BrandDiagnosisRun $run): array
    {
        $platforms = collect((array) $run->platforms)
            ->map(static fn (mixed $platform): string => strtolower(trim((string) $platform)))
            ->filter(static fn (string $platform): bool => BrandDiagnosisPlatform::isSupported($platform))
            ->unique()
            ->values()
            ->all();

        $data = [
            'all' => [
                'metrics' => $this->platformMetrics($run),
                'rankings' => $this->brandRankings($run),
                'sources' => $this->sourceRows($run),
                'conversations' => $this->conversationRows($run),
            ],
        ];

        foreach ($platforms as $platform) {
            $data[$platform] = [
                'metrics' => $this->platformMetrics($run, $platform),
                'rankings' => $this->brandRankings($run, $platform),
                'sources' => $this->sourceRows($run, $platform),
                'conversations' => $this->conversationRows($run, $platform),
            ];
        }

        return $data;
    }

    /**
     * @return list<array{value:string,label:string,logo:string}>
     */
    private function recordPlatformOptions(BrandDiagnosisRun $run): array
    {
        $options = [
            ['value' => 'all', 'label' => '全部平台', 'logo' => ''],
        ];

        $platforms = collect((array) $run->platforms)
            ->map(static fn (mixed $platform): string => strtolower(trim((string) $platform)))
            ->filter(static fn (string $platform): bool => BrandDiagnosisPlatform::isSupported($platform))
            ->unique()
            ->values();

        foreach ($platforms as $platform) {
            $options[] = ['value' => $platform, 'label' => $this->platformLabel($platform), 'logo' => $this->platformLogo($platform)];
        }

        return $options;
    }

    /**
     * @return array{score:int,mention_rate:int,average_rank:string,mention_count:int,sentiment_rate:int}
     */
    private function platformMetrics(BrandDiagnosisRun $run, ?string $platform = null): array
    {
        $platform = $platform !== null ? strtolower(trim($platform)) : null;
        $results = $run->questions
            ->flatMap(static fn ($question) => $question->results)
            ->where('status', 'success')
            ->when($platform !== null, fn (Collection $items): Collection => $items->where('platform', $platform))
            ->values();
        $total = max(1, $results->count());
        $targetMentions = $run->brandMentions
            ->when($platform !== null, fn (Collection $items): Collection => $items->where('platform', $platform))
            ->where('is_target_brand', true)
            ->values();

        if ($targetMentions->isEmpty()) {
            $mentionRate = 0;
            $mentionCount = 0;
            $averageRank = 0.0;
            $sentimentRate = 0;
        } else {
            $mentionedConversationCount = $targetMentions->pluck('result_id')->unique()->count();
            $mentionRate = (int) round(($mentionedConversationCount / $total) * 100);
            $mentionCount = (int) $targetMentions->sum('mention_count');
            $averageRank = (float) ($targetMentions->where('mention_rank', '>', 0)->avg('mention_rank') ?: 0);
            $sentimentRate = (int) round(($targetMentions->whereIn('sentiment', ['positive', 'neutral'])->count() / max(1, $targetMentions->count())) * 100);
        }

        $rankScore = $averageRank > 0
            ? max(0, 100 - (($averageRank - 1) * 5))
            : 0;
        $score = (int) min(100, round(
            ($mentionRate * 0.75)
            + ($mentionCount * 0.1)
            + ($rankScore * 0.1)
            + ($sentimentRate * 0.05)
        ));

        // 仅在诊断完成后，于「显示层」叠加基础数值（不写入存储，不影响真实计算）。
        // 由 config('brand_diagnosis.display_baseline.enabled') 开关控制；关闭则展示真实值。
        $baseline = (array) config('brand_diagnosis.display_baseline', []);
        if ((string) $run->status === 'completed' && filter_var($baseline['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $scoreBase = (int) ($baseline['score'] ?? 60);
            $mentionRateBase = (int) ($baseline['mention_rate'] ?? 50);
            $mentionCountBase = (int) ($baseline['mention_count'] ?? 9);
            $rankCap = (int) ($baseline['rank_cap'] ?? 9);

            $score = (int) min(100, $score + $scoreBase);
            $mentionRate = (int) min(100, $mentionRate + $mentionRateBase);
            $mentionCount += $mentionCountBase;
            $averageRank = $averageRank > 0 ? min($averageRank, (float) $rankCap) : (float) $rankCap;
        }

        return [
            'score' => $score,
            'mention_rate' => $mentionRate,
            'average_rank' => $this->formatAverageRank($averageRank),
            'mention_count' => $mentionCount,
            'sentiment_rate' => $sentimentRate,
        ];
    }

    /**
     * @return list<array{platform:string,platform_key:string,category:string,title:string,questions:int,models:string,icon:string,logo:string,url:string}>
     */
    private function sourceRows(BrandDiagnosisRun $run, ?string $platform = null): array
    {
        return $run->sources
            ->when($platform !== null, fn (Collection $sources): Collection => $sources->where('platform', $platform))
            ->filter(static fn ($source): bool => trim((string) $source->url) !== '')
            ->groupBy(fn ($source): string => $this->sourceGroupKey((string) $source->platform, (string) $source->url, (string) ($source->title ?: $source->id)))
            ->map(function (Collection $group): array {
                $source = $group->first();
                $platformKeys = $group
                    ->pluck('platform')
                    ->map(static fn (mixed $platform): string => strtolower(trim((string) $platform)))
                    ->filter()
                    ->unique()
                    ->values();

                return [
                    'platform' => $platformKeys->map(fn (string $platform): string => $this->platformLabel($platform))->implode('、'),
                    'platform_key' => (string) ($source?->platform ?? ''),
                    'category' => (string) ($source?->domain ?: '网页来源'),
                    'title' => (string) ($source?->title ?: $source?->url),
                    'questions' => $group->pluck('question_id')->unique()->count(),
                    'models' => $platformKeys->map(fn (string $platform): string => $this->platformLabel($platform))->implode('、'),
                    'icon' => $this->platformIcon((string) ($source?->platform ?? '')),
                    'logo' => $this->platformLogo((string) ($source?->platform ?? '')),
                    'url' => (string) ($source?->url ?? ''),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{question:string,platform:string,platform_key:string,platform_logo:string,brands:list<string>,visible_brands:list<string>,hidden_brand_count:int,answer:string,status:string,sources:list<array{title:string,url:string,domain:string,type:string}>}>
     */
    private function conversationRows(BrandDiagnosisRun $run, ?string $platform = null): array
    {
        return $run->questions
            ->flatMap(function ($question) use ($platform): Collection {
                return $question->results
                    ->when($platform !== null, fn (Collection $results): Collection => $results->where('platform', $platform))
                    ->map(function ($result) use ($question): array {
                        $brands = $result->brandMentions
                            ->pluck('brand_name')
                            ->filter()
                            ->unique()
                            ->values()
                            ->all();
                        $sources = $result->sources
                            ->filter(static fn ($source): bool => trim((string) $source->url) !== '')
                            ->unique(fn ($source): string => $this->sourceGroupKey((string) $source->platform, (string) $source->url, (string) ($source->title ?: $source->id)))
                            ->map(static fn ($source): array => [
                                'title' => (string) ($source->title ?: $source->url),
                                'url' => (string) $source->url,
                                'domain' => (string) $source->domain,
                                'type' => (string) $source->source_type,
                            ])
                            ->values()
                            ->all();
                        $answer = (string) ($result->answer ?? '');
                        $status = (string) ($result->status ?? $question->status);
                        $platformKey = (string) $result->platform;
                        $officialShareUrl = trim((string) ($result->official_share_url ?? ''));

                        return [
                            'result_id' => (int) $result->id,
                            'question' => (string) $question->question,
                            'platform' => $this->platformLabel($platformKey),
                            'platform_key' => $platformKey,
                            'platform_logo' => $this->platformLogo($platformKey),
                            'brands' => $brands,
                            'visible_brands' => array_slice($brands, 0, 4),
                            'hidden_brand_count' => max(0, count($brands) - 4),
                            'answer' => $answer,
                            'status' => $status,
                            'sources' => $sources,
                            'snapshot_url' => $status === 'success'
                                && trim($answer) !== ''
                                && trim((string) ($result->snapshot_token ?? '')) !== ''
                                ? route('brand-diagnosis.snapshot', ['token' => $result->snapshot_token])
                                : '',
                            'official_share_url' => BrandDiagnosisPlatform::isOfficialShareUrl($platformKey, $officialShareUrl)
                                ? $officialShareUrl
                                : '',
                        ];
                    });
            })
            ->values()
            ->all();
    }

    private function sourceGroupKey(string $platform, string $url, string $fallback): string
    {
        $platform = strtolower(trim($platform));
        $url = trim($url);
        $key = $url !== '' ? mb_strtolower($url, 'UTF-8') : mb_strtolower(trim($fallback), 'UTF-8');

        return $platform.'|'.$key;
    }

    /**
     * @return array{
     *   mention_rate:list<array{brand:string,rate:int,count:int,rank:string,display_rank:int|string,is_target_brand:bool}>,
     *   mention_count:list<array{brand:string,rate:int,count:int,rank:string,display_rank:int|string,is_target_brand:bool}>,
     *   average_rank:list<array{brand:string,rate:int,count:int,rank:string,rank_value:float,display_rank:int|string,is_target_brand:bool}>
     * }
     */
    private function brandRankings(BrandDiagnosisRun $run, ?string $platform = null): array
    {
        $mentions = $run->brandMentions
            ->when($platform !== null, fn (Collection $items): Collection => $items->where('platform', $platform))
            ->values();
        $successResults = $run->questions
            ->flatMap(static fn ($question) => $question->results)
            ->where('status', 'success')
            ->when($platform !== null, fn (Collection $items): Collection => $items->where('platform', $platform))
            ->values();
        $totalConversations = max(1, $successResults->count());

        $grouped = $mentions
            ->groupBy(function ($mention): string {
                $meta = (array) ($mention->meta ?? []);
                $canonicalKey = trim((string) ($meta['canonical_key'] ?? ''));
                if ($canonicalKey !== '') {
                    return mb_strtolower($canonicalKey, 'UTF-8');
                }

                return $this->brandEntityResolver->canonicalKey((string) $mention->brand_name);
            })
            ->map(function (Collection $group) use ($totalConversations): array {
                $first = $group->first();
                $conversationCount = $group->pluck('result_id')->unique()->count();
                $mentionCount = (int) $group->sum('mention_count');
                $averageRank = (float) ($group->where('mention_rank', '>', 0)->avg('mention_rank') ?: 0);
                $aliases = $group
                    ->flatMap(function ($mention): array {
                        $meta = (array) ($mention->meta ?? []);
                        return array_merge(
                            [$mention->brand_name],
                            (array) ($meta['aliases'] ?? [])
                        );
                    })
                    ->filter()
                    ->map(fn (string $value): string => trim($value))
                    ->unique(fn (string $value): string => mb_strtolower($value, 'UTF-8'))
                    ->values()
                    ->all();
                $canonicalName = (string) (data_get($first, 'meta.canonical_name') ?: $first?->brand_name);
                $title = collect($aliases)
                    ->prepend($canonicalName)
                    ->filter()
                    ->unique(fn (string $value): string => mb_strtolower($value, 'UTF-8'))
                    ->implode('、');

                return [
                    'brand' => $canonicalName,
                    'aliases' => $aliases,
                    'title' => $title !== '' ? $title : $canonicalName,
                    'rate' => (int) round(($conversationCount / $totalConversations) * 100),
                    'count' => $mentionCount,
                    'rank_value' => $averageRank,
                    'rank_sort' => $averageRank > 0 ? $averageRank : 999999,
                    'rank' => $this->formatAverageRank($averageRank),
                    'is_target_brand' => (bool) ($first?->is_target_brand ?? false),
                ];
            })
            ->values();

        $targetRow = $grouped->firstWhere('is_target_brand', true) ?? [
            'brand' => (string) $run->brand_name,
            'aliases' => [$run->brand_name],
            'title' => (string) $run->brand_name,
            'rate' => 0,
            'count' => 0,
            'rank_value' => 0.0,
            'rank_sort' => 999999,
            'rank' => '0',
            'is_target_brand' => true,
        ];

        $rateRows = $this->withDisplayRanks($grouped, 'rate', true);
        $countRows = $this->withDisplayRanks($grouped, 'count', true);
        $averageRankRows = $this->withDisplayRanks($grouped, 'rank_sort', false);

        return [
            'mention_rate' => $this->topRowsWithTargetLast($rateRows, $targetRow, 'rate')->all(),
            'mention_count' => $this->topRowsWithTargetLast($countRows, $targetRow, 'count')->all(),
            'average_rank' => $this->topRowsWithTargetLast($averageRankRows, $targetRow, 'rank_sort')
                ->map(static fn (array $row): array => [
                    'brand' => (string) $row['brand'],
                    'aliases' => (array) ($row['aliases'] ?? []),
                    'title' => (string) ($row['title'] ?? $row['brand']),
                    'rate' => (int) $row['rate'],
                    'count' => (int) $row['count'],
                    'rank' => (string) $row['rank'],
                    'rank_value' => (float) $row['rank_value'],
                    'display_rank' => $row['display_rank'],
                    'is_target_brand' => (bool) $row['is_target_brand'],
                ])
                ->all(),
        ];
    }

    /**
     * @param  Collection<int,array{brand:string,rate:int,count:int,rank_value:float,rank_sort:float|int,rank:string,is_target_brand:bool}>  $rows
     * @return Collection<int,array{brand:string,rate:int,count:int,rank_value:float,rank_sort:float|int,rank:string,display_rank:int|string,is_target_brand:bool}>
     */
    private function withDisplayRanks(Collection $rows, string $sortKey, bool $descending): Collection
    {
        return $rows
            ->when($descending, fn (Collection $collection): Collection => $collection->sortByDesc($sortKey), fn (Collection $collection): Collection => $collection->sortBy($sortKey))
            ->values()
            ->map(function (array $row, int $index) use ($sortKey): array {
                $value = $row[$sortKey] ?? 0;
                $row['display_rank'] = ((bool) $row['is_target_brand'] && (! is_numeric($value) || (float) $value <= 0 || (float) $value >= 999999))
                    ? '99+'
                    : $index + 1;

                return $row;
            });
    }

    /**
     * @param  Collection<int,array{brand:string,rate:int,count:int,rank_value:float,rank_sort:float|int,rank:string,display_rank:int|string,is_target_brand:bool}>  $rows
     * @param  array{brand:string,rate:int,count:int,rank_value:float,rank_sort:float|int,rank:string,is_target_brand:bool}  $targetRow
     * @return Collection<int,array{brand:string,rate:int,count:int,rank_value:float,rank_sort:float|int,rank:string,display_rank:int|string,is_target_brand:bool}>
     */
    private function topRowsWithTargetLast(Collection $rows, array $targetRow, string $sortKey): Collection
    {
        $topRows = $rows
            ->reject(static fn (array $row): bool => (bool) $row['is_target_brand'])
            ->take(10)
            ->values();
        $rankedTargetRow = $rows->firstWhere('is_target_brand', true) ?? $targetRow;
        if (! isset($rankedTargetRow['display_rank'])) {
            $value = $rankedTargetRow[$sortKey] ?? 0;
            $rankedTargetRow['display_rank'] = (! is_numeric($value) || (float) $value <= 0 || (float) $value >= 999999)
                ? '99+'
                : 1;
        }

        return $topRows->push($rankedTargetRow);
    }

    private function platformLabel(string $platform): string
    {
        return BrandDiagnosisPlatform::label($platform);
    }

    private function platformIcon(string $platform): string
    {
        return BrandDiagnosisPlatform::icon($platform);
    }

    private function platformLogo(string $platform): string
    {
        return BrandDiagnosisPlatform::logoUrl($platform);
    }
}
