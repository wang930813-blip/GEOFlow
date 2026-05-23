<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessGeoInclusionCheckJob;
use App\Models\Article;
use App\Models\GeoInclusionCheckResult;
use App\Models\GeoInclusionCheckRun;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KeywordQuestionVariant;
use App\Services\GeoFlow\GeoKeywordSuggestionService;
use App\Services\GeoFlow\GeoQuestionVariantService;
use App\Support\AdminWeb;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * 关键词库管理控制器。
 */
class KeywordLibraryController extends Controller
{
    private const DETAIL_PER_PAGE = 50;

    public function __construct(
        private readonly GeoKeywordSuggestionService $keywordSuggestionService,
        private readonly GeoQuestionVariantService $questionVariantService,
    ) {}

    /**
     * 列表页。
     */
    public function index(): View
    {
        return view('admin.keyword-libraries.index', [
            'pageTitle' => __('admin.keyword_libraries.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'libraries' => $this->loadLibraries(),
            'stats' => $this->loadStats(),
        ]);
    }

    /**
     * 关键词库详情页。
     */
    public function detail(Request $request, int $libraryId): View|RedirectResponse
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        $search = trim((string) $request->query('search', ''));
        $keywords = $this->loadDetailKeywords($libraryId, $search);
        $usageTotal = $this->loadUsageTotal($libraryId);

        return view('admin.keyword-libraries.detail', [
            'pageTitle' => (string) $library->name.__('admin.keyword_detail.page_title_suffix'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'library' => $library,
            'search' => $search,
            'keywords' => $keywords,
            'usageTotal' => $usageTotal,
            'inclusionRuns' => $this->loadInclusionRuns($libraryId),
            'inclusionResults' => $this->loadLatestInclusionResults($libraryId),
            'inclusionDailyReports' => $this->loadDailyInclusionReports($libraryId),
            'inclusionRealtime' => $this->inclusionRealtimeConfig($libraryId),
        ]);
    }

    public function exportInclusionResults(int $libraryId): StreamedResponse
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();
        $filename = sprintf(
            'geo-inclusion-%d-%s.csv',
            (int) $library->id,
            now()->format('YmdHis')
        );

        return response()->streamDownload(function () use ($libraryId): void {
            echo "\xEF\xBB\xBF";

            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, [
                '日期',
                '检测时间',
                '平台',
                '关键词',
                '问题',
                '关键词命中',
                '品牌命中',
                '状态',
                '回答摘要',
                '错误信息',
            ]);

            $this->inclusionResultsQuery($libraryId)
                ->chunk(500, function (Collection $results) use ($handle): void {
                    foreach ($results as $result) {
                        $checkedAt = $result->checked_at ?? $result->created_at;

                        fputcsv($handle, [
                            optional($checkedAt)->format('Y-m-d') ?? '',
                            optional($checkedAt)->format('Y-m-d H:i:s') ?? '',
                            $this->platformLabel((string) $result->platform),
                            (string) ($result->keyword?->keyword ?? ''),
                            (string) $result->question,
                            $result->keyword_hit ? '是' : '否',
                            $result->brand_hit ? '是' : '否',
                            (string) $result->status,
                            Str::limit((string) ($result->answer ?? ''), 200, '...'),
                            (string) ($result->error_message ?? ''),
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function inclusionSnapshot(int $libraryId): JsonResponse
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();
        $inclusionRuns = $this->loadInclusionRuns($libraryId);
        $inclusionDailyReports = $this->loadDailyInclusionReports($libraryId);
        $hasRunning = $this->hasRunningInclusionRun($inclusionRuns);

        return response()->json([
            'success' => true,
            'has_running' => $hasRunning,
            'latest_run_id' => (int) optional($inclusionRuns->first())->id,
            'runs_html' => view('admin.keyword-libraries.partials.inclusion-runs', [
                'inclusionRuns' => $inclusionRuns,
            ])->render(),
            'daily_reports_html' => view('admin.keyword-libraries.partials.inclusion-daily-reports', [
                'library' => $library,
                'inclusionDailyReports' => $inclusionDailyReports,
            ])->render(),
        ]);
    }

    /**
     * 在详情页中新增关键词。
     */
    public function storeKeyword(Request $request, int $libraryId): RedirectResponse
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        $payload = $request->validate([
            'keyword' => ['required', 'string', 'max:200'],
        ], [
            'keyword.required' => __('admin.keyword_detail.error.keyword_required'),
        ]);

        $keyword = trim((string) $payload['keyword']);
        if ($keyword === '') {
            return back()->withErrors(__('admin.keyword_detail.error.keyword_required'));
        }

        $exists = Keyword::query()
            ->where('library_id', $libraryId)
            ->where('keyword', $keyword)
            ->exists();
        if ($exists) {
            return back()->withErrors(__('admin.keyword_detail.error.keyword_exists'));
        }

        Keyword::query()->create([
            'library_id' => $libraryId,
            'keyword' => $keyword,
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $this->refreshKeywordLibraryCount($libraryId);

        return redirect()->route('admin.keyword-libraries.detail', ['libraryId' => $libraryId])->with('message', __('admin.keyword_detail.message.add_success'));
    }

    public function bulkStoreKeywords(Request $request, int $libraryId): RedirectResponse
    {
        KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        $payload = $request->validate([
            'keywords' => ['required', 'array', 'min:1'],
            'keywords.*' => ['nullable', 'string', 'max:200'],
        ]);

        $keywords = collect((array) $payload['keywords'])
            ->map(static fn (mixed $keyword): string => trim((string) $keyword))
            ->filter(static fn (string $keyword): bool => $keyword !== '')
            ->unique()
            ->values();

        if ($keywords->isEmpty()) {
            return back()->withErrors(__('admin.keyword_libraries.error.keywords_required'));
        }

        $insertedCount = 0;
        $duplicateCount = 0;

        DB::transaction(function () use ($keywords, $libraryId, &$insertedCount, &$duplicateCount): void {
            foreach ($keywords as $keyword) {
                $exists = Keyword::query()
                    ->where('library_id', $libraryId)
                    ->where('keyword', $keyword)
                    ->exists();

                if ($exists) {
                    $duplicateCount++;

                    continue;
                }

                Keyword::query()->create([
                    'library_id' => $libraryId,
                    'keyword' => $keyword,
                    'used_count' => 0,
                    'usage_count' => 0,
                ]);
                $insertedCount++;
            }

            $this->refreshKeywordLibraryCount($libraryId);
        });

        $message = '已加入 '.$insertedCount.' 个关键词';
        if ($duplicateCount > 0) {
            $message .= '，跳过 '.$duplicateCount.' 个重复关键词';
        }

        return redirect()
            ->route('admin.keyword-libraries.detail', ['libraryId' => $libraryId])
            ->with('message', $message);
    }

    public function updateKeyword(Request $request, int $libraryId, int $keywordId): RedirectResponse
    {
        $keywordModel = $this->findKeywordInLibrary($libraryId, $keywordId);

        $payload = $request->validate([
            'keyword' => ['required', 'string', 'max:200'],
        ], [
            'keyword.required' => __('admin.keyword_detail.error.keyword_required'),
        ]);

        $keyword = trim((string) $payload['keyword']);
        if ($keyword === '') {
            return back()->withErrors(__('admin.keyword_detail.error.keyword_required'));
        }

        $exists = Keyword::query()
            ->where('library_id', $libraryId)
            ->where('keyword', $keyword)
            ->whereKeyNot((int) $keywordModel->id)
            ->exists();
        if ($exists) {
            return back()->withErrors(__('admin.keyword_detail.error.keyword_exists'));
        }

        $keywordModel->update(['keyword' => $keyword]);

        return redirect()
            ->route('admin.keyword-libraries.detail', ['libraryId' => $libraryId])
            ->with('message', '关键词已更新');
    }

    public function suggestKeywords(Request $request, int $libraryId): JsonResponse
    {
        KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        $payload = $request->validate([
            'seed_keyword' => ['required', 'string', 'max:200'],
            'count' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $suggestions = $this->keywordSuggestionService->suggest(
                (string) $payload['seed_keyword'],
                (int) ($payload['count'] ?? 20)
            );

            return response()->json([
                'success' => true,
                'suggestions' => $suggestions,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function storeQuestion(Request $request, int $libraryId, int $keywordId): RedirectResponse
    {
        $keyword = $this->findKeywordInLibrary($libraryId, $keywordId);

        $payload = $request->validate([
            'question' => ['required', 'string', 'max:500'],
        ]);

        $question = $this->normalizeQuestion((string) $payload['question']);
        if ($question === '') {
            return back()->withErrors('请输入有效的问题变体');
        }

        KeywordQuestionVariant::query()->firstOrCreate([
            'keyword_id' => (int) $keyword->id,
            'question' => $question,
        ]);

        return redirect()
            ->route('admin.keyword-libraries.detail', ['libraryId' => $libraryId])
            ->with('message', '问题变体已保存');
    }

    public function updateQuestion(Request $request, int $libraryId, int $keywordId, int $questionId): RedirectResponse
    {
        $keyword = $this->findKeywordInLibrary($libraryId, $keywordId);
        $variant = KeywordQuestionVariant::query()
            ->where('keyword_id', (int) $keyword->id)
            ->whereKey($questionId)
            ->firstOrFail();

        $payload = $request->validate([
            'question' => ['required', 'string', 'max:500'],
        ]);

        $question = $this->normalizeQuestion((string) $payload['question']);
        if ($question === '') {
            return back()->withErrors('请输入有效的问题变体');
        }

        $exists = KeywordQuestionVariant::query()
            ->where('keyword_id', (int) $keyword->id)
            ->where('question', $question)
            ->whereKeyNot((int) $variant->id)
            ->exists();
        if ($exists) {
            return back()->withErrors('这个问题变体已经存在');
        }

        $variant->update(['question' => $question]);

        return redirect()
            ->route('admin.keyword-libraries.detail', ['libraryId' => $libraryId])
            ->with('message', '问题变体已更新');
    }

    public function destroyQuestion(int $libraryId, int $keywordId, int $questionId): RedirectResponse
    {
        $keyword = $this->findKeywordInLibrary($libraryId, $keywordId);

        $variant = KeywordQuestionVariant::query()
            ->where('keyword_id', (int) $keyword->id)
            ->whereKey($questionId)
            ->firstOrFail();

        $variant->delete();

        return redirect()
            ->route('admin.keyword-libraries.detail', ['libraryId' => $libraryId])
            ->with('message', '问题变体已删除');
    }

    public function generateQuestions(Request $request, int $libraryId, int $keywordId): JsonResponse
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();
        $keyword = $this->findKeywordInLibrary($libraryId, $keywordId);

        $payload = $request->validate([
            'count' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        try {
            $questions = $this->questionVariantService->generate($keyword, $library, (int) ($payload['count'] ?? 5));
            foreach ($questions as $question) {
                KeywordQuestionVariant::query()->firstOrCreate([
                    'keyword_id' => (int) $keyword->id,
                    'question' => $question,
                ]);
            }

            return response()->json([
                'success' => true,
                'questions' => $questions,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function storeInclusionCheck(Request $request, int $libraryId): RedirectResponse
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();
        $payload = $request->validate([
            'platforms' => ['nullable', 'array'],
            'platforms.*' => ['string'],
        ]);

        $platforms = $this->normalizePlatforms((array) ($payload['platforms'] ?? []));
        $questions = KeywordQuestionVariant::query()
            ->whereIn('keyword_id', Keyword::query()->select('id')->where('library_id', (int) $library->id))
            ->with('keyword')
            ->orderBy('id')
            ->get();

        if ($questions->isEmpty()) {
            return back()->withErrors('请先为关键词添加问题变体，再发起收录检测');
        }

        $totalChecks = $questions->count() * count($platforms);
        $run = GeoInclusionCheckRun::query()->create([
            'keyword_library_id' => (int) $library->id,
            'platforms' => $platforms,
            'status' => 'pending',
            'total_checks' => $totalChecks,
            'completed_checks' => 0,
            'failed_checks' => 0,
        ]);

        foreach ($questions as $question) {
            foreach ($platforms as $platform) {
                ProcessGeoInclusionCheckJob::dispatch(
                    runId: (int) $run->id,
                    keywordId: (int) $question->keyword_id,
                    questionVariantId: (int) $question->id,
                    platform: $platform
                )->onQueue('geoflow');
            }
        }

        return redirect()
            ->route('admin.keyword-libraries.detail', ['libraryId' => $libraryId])
            ->with('message', '收录检测任务已创建，共 '.$totalChecks.' 个检测项');
    }

    /**
     * 在详情页中删除关键词（支持单条/批量）。
     */
    public function destroyKeywords(Request $request, int $libraryId): RedirectResponse
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        /** @var array<int, mixed> $rawIds */
        $rawIds = (array) $request->input('keyword_ids', []);
        $keywordIds = collect($rawIds)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values();

        if ($keywordIds->isEmpty()) {
            return back()->withErrors(__('admin.keyword_detail.error.select_required'));
        }

        $deletedCount = Keyword::query()
            ->where('library_id', $libraryId)
            ->whereIn('id', $keywordIds->all())
            ->delete();
        $this->refreshKeywordLibraryCount($libraryId);

        return redirect()->route('admin.keyword-libraries.detail', ['libraryId' => $libraryId])->with(
            'message',
            __('admin.keyword_detail.message.delete_success', ['count' => $deletedCount])
        );
    }

    /**
     * 在详情页中更新关键词库基础信息。
     */
    public function updateFromDetail(Request $request, int $libraryId): RedirectResponse
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'company_name' => ['nullable', 'string', 'max:200'],
            'domain_keyword' => ['nullable', 'string', 'max:200'],
            'industry' => ['nullable', 'string', 'max:100'],
            'brand_description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:20'],
        ], [
            'name.required' => __('admin.keyword_detail.error.library_name_required'),
        ]);

        $library->update($this->normalizeProjectPayload($payload, (string) ($library->status ?? 'active')));

        return redirect()->route('admin.keyword-libraries.detail', ['libraryId' => $libraryId])->with('message', __('admin.keyword_detail.message.update_success'));
    }

    /**
     * 在详情页中导入关键词（逐行 + 逗号分隔）。
     */
    public function importKeywords(Request $request, int $libraryId): RedirectResponse
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        $payload = $request->validate([
            'keywords_text' => ['required', 'string'],
        ], [
            'keywords_text.required' => __('admin.keyword_libraries.error.keywords_required'),
        ]);

        $keywords = $this->parseKeywordImportText((string) $payload['keywords_text']);
        if ($keywords->isEmpty()) {
            return back()->withErrors(__('admin.keyword_libraries.error.keywords_required'));
        }

        $importedCount = 0;
        $duplicateCount = 0;

        DB::transaction(function () use ($keywords, $libraryId, &$importedCount, &$duplicateCount): void {
            foreach ($keywords as $keyword) {
                $exists = Keyword::query()
                    ->where('library_id', $libraryId)
                    ->where('keyword', $keyword)
                    ->exists();
                if ($exists) {
                    $duplicateCount++;

                    continue;
                }

                Keyword::query()->create([
                    'library_id' => $libraryId,
                    'keyword' => $keyword,
                    'used_count' => 0,
                    'usage_count' => 0,
                ]);
                $importedCount++;
            }

            $this->refreshKeywordLibraryCount($libraryId);
        });

        $message = __('admin.keyword_libraries.message.import_success', ['count' => $importedCount]);
        if ($duplicateCount > 0) {
            $message .= __('admin.keyword_libraries.message.import_skip', ['count' => $duplicateCount]);
        }

        return redirect()->route('admin.keyword-libraries.detail', ['libraryId' => $libraryId])->with('message', $message);
    }

    /**
     * 创建表单页。
     */
    public function create(): View
    {
        return view('admin.keyword-libraries.form', [
            'pageTitle' => __('admin.keyword_libraries.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => false,
            'libraryId' => 0,
            'libraryForm' => $this->emptyForm(),
        ]);
    }

    /**
     * 创建关键词库。
     */
    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'company_name' => ['nullable', 'string', 'max:200'],
            'domain_keyword' => ['nullable', 'string', 'max:200'],
            'industry' => ['nullable', 'string', 'max:100'],
            'brand_description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:20'],
        ], [
            'name.required' => __('admin.keyword_libraries.error.name_required'),
        ]);

        KeywordLibrary::query()->create($this->normalizeProjectPayload($payload, 'active') + [
            'keyword_count' => 0,
        ]);

        return redirect()->route('admin.keyword-libraries.index')->with('message', __('admin.keyword_libraries.message.create_success'));
    }

    /**
     * 编辑表单页。
     */
    public function edit(int $libraryId): View|RedirectResponse
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        return view('admin.keyword-libraries.form', [
            'pageTitle' => __('admin.keyword_libraries.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => true,
            'libraryId' => (int) $library->id,
            'libraryForm' => [
                'name' => (string) $library->name,
                'description' => (string) ($library->description ?? ''),
                'company_name' => (string) ($library->company_name ?? ''),
                'domain_keyword' => (string) ($library->domain_keyword ?? ''),
                'industry' => (string) ($library->industry ?? ''),
                'brand_description' => (string) ($library->brand_description ?? ''),
                'status' => (string) ($library->status ?? 'active'),
            ],
        ]);
    }

    /**
     * 更新关键词库。
     */
    public function update(Request $request, int $libraryId): RedirectResponse
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'company_name' => ['nullable', 'string', 'max:200'],
            'domain_keyword' => ['nullable', 'string', 'max:200'],
            'industry' => ['nullable', 'string', 'max:100'],
            'brand_description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:20'],
        ], [
            'name.required' => __('admin.keyword_libraries.error.name_required'),
        ]);

        $library->update($this->normalizeProjectPayload($payload, (string) ($library->status ?? 'active')));

        return redirect()->route('admin.keyword-libraries.index')->with('message', __('admin.keyword_libraries.message.update_success'));
    }

    /**
     * 删除关键词库（包含词条）。
     */
    public function destroy(int $libraryId): RedirectResponse
    {
        $library = KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        Keyword::query()->where('library_id', $libraryId)->delete();
        $library->delete();

        return redirect()->route('admin.keyword-libraries.index')->with('message', __('admin.keyword_libraries.message.delete_success'));
    }

    /**
     * @return array<int, array{id:int,name:string,description:string,actual_count:int,created_at:?string,updated_at:?string}>
     */
    private function loadLibraries(): array
    {
        $query = KeywordLibrary::query()
            ->select(['id', 'name', 'description', 'created_at', 'updated_at'])
            ->withCount('keywords as actual_count')
            ->orderByDesc('created_at');

        return $query->get()->map(static function (KeywordLibrary $library): array {
            return [
                'id' => (int) $library->id,
                'name' => (string) $library->name,
                'description' => (string) ($library->description ?? ''),
                'actual_count' => (int) ($library->actual_count ?? 0),
                'created_at' => $library->created_at?->format('Y-m-d H:i:s'),
                'updated_at' => $library->updated_at?->format('Y-m-d H:i:s'),
            ];
        })->all();
    }

    /**
     * @return array{total_libraries:int,total_keywords:int,avg_keywords:float}
     */
    private function loadStats(): array
    {
        $totalLibraries = KeywordLibrary::query()->count();
        $totalKeywords = Keyword::query()->count();

        return [
            'total_libraries' => $totalLibraries,
            'total_keywords' => $totalKeywords,
            'avg_keywords' => $totalLibraries > 0 ? round($totalKeywords / $totalLibraries, 1) : 0.0,
        ];
    }

    /**
     * @return array{name:string,description:string}
     */
    private function emptyForm(): array
    {
        return [
            'name' => '',
            'description' => '',
            'company_name' => '',
            'domain_keyword' => '',
            'industry' => '',
            'brand_description' => '',
            'status' => 'active',
        ];
    }

    /**
     * @return LengthAwarePaginator<int, Keyword>
     */
    private function loadDetailKeywords(int $libraryId, string $search): LengthAwarePaginator
    {
        $query = Keyword::query()
            ->where('library_id', $libraryId)
            ->with(['questionVariants' => fn ($query) => $query->orderByDesc('created_at')])
            ->withCount('questionVariants')
            ->orderByDesc('created_at');
        if ($search !== '') {
            $query->where('keyword', 'like', '%'.$search.'%');
        }

        return $query->paginate(self::DETAIL_PER_PAGE)->withQueryString();
    }

    /**
     * @return Collection<int, string>
     */
    private function parseKeywordImportText(string $keywordsText): Collection
    {
        return collect(preg_split('/\R/u', $keywordsText) ?: [])
            ->flatMap(static function (string $line): array {
                return array_map('trim', explode(',', $line));
            })
            ->map(static fn (string $keyword): string => trim($keyword))
            ->filter(static fn (string $keyword): bool => $keyword !== '')
            ->unique()
            ->values();
    }

    /**
     * 维护关键词库缓存计数，避免列表统计偏差。
     */
    private function refreshKeywordLibraryCount(int $libraryId): void
    {
        $count = Keyword::query()->where('library_id', $libraryId)->count();
        KeywordLibrary::query()->whereKey($libraryId)->update([
            'keyword_count' => $count,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{name:string,description:string,company_name:string,domain_keyword:string,industry:string,brand_description:string,status:string}
     */
    private function normalizeProjectPayload(array $payload, string $defaultStatus): array
    {
        $status = trim((string) ($payload['status'] ?? $defaultStatus));

        return [
            'name' => trim((string) $payload['name']),
            'description' => trim((string) ($payload['description'] ?? '')),
            'company_name' => trim((string) ($payload['company_name'] ?? '')),
            'domain_keyword' => trim((string) ($payload['domain_keyword'] ?? '')),
            'industry' => trim((string) ($payload['industry'] ?? '')),
            'brand_description' => trim((string) ($payload['brand_description'] ?? '')),
            'status' => $status !== '' ? $status : 'active',
        ];
    }

    private function findKeywordInLibrary(int $libraryId, int $keywordId): Keyword
    {
        KeywordLibrary::query()->whereKey($libraryId)->firstOrFail();

        return Keyword::query()
            ->where('library_id', $libraryId)
            ->whereKey($keywordId)
            ->firstOrFail();
    }

    private function normalizeQuestion(string $question): string
    {
        $question = preg_replace('/\s+/u', ' ', trim($question)) ?? trim($question);

        return mb_strlen($question, 'UTF-8') <= 500 ? $question : '';
    }

    /**
     * 按 legacy 页面口径统计关键词总使用次数。
     *
     * 统计规则与 bak/admin/keyword-library-detail.php 一致：
     * 通过文章表 original_keyword 与关键词库中的 keyword 进行匹配计数。
     */
    private function loadUsageTotal(int $libraryId): int
    {
        if (! Schema::hasColumn('articles', 'original_keyword')) {
            return 0;
        }

        return (int) Article::query()
            ->whereIn('original_keyword', function ($query) use ($libraryId): void {
                $query->select('keyword')
                    ->from('keywords')
                    ->where('library_id', $libraryId);
            })
            ->count();
    }

    private function normalizePlatforms(array $platforms): array
    {
        $allowed = ['doubao', 'qianwen', 'deepseek'];
        $normalized = collect($platforms)
            ->map(static fn (mixed $platform): string => strtolower(trim((string) $platform)))
            ->filter(static fn (string $platform): bool => in_array($platform, $allowed, true))
            ->unique()
            ->values()
            ->all();

        return $normalized !== [] ? $normalized : $allowed;
    }

    private function loadInclusionRuns(int $libraryId): Collection
    {
        return GeoInclusionCheckRun::query()
            ->where('keyword_library_id', $libraryId)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();
    }

    private function hasRunningInclusionRun(Collection $inclusionRuns): bool
    {
        return $inclusionRuns->contains(
            static fn ($run): bool => in_array((string) $run->status, ['pending', 'running'], true)
        );
    }

    private function loadLatestInclusionResults(int $libraryId): Collection
    {
        return $this->inclusionResultsQuery($libraryId)
            ->limit(10)
            ->get();
    }

    private function loadDailyInclusionReports(int $libraryId): Collection
    {
        return $this->inclusionResultsQuery($libraryId)
            ->limit(200)
            ->get()
            ->groupBy(function (GeoInclusionCheckResult $result): string {
                $checkedAt = $result->checked_at ?? $result->created_at;

                return optional($checkedAt)->format('Y-m-d') ?? '未记录日期';
            })
            ->map(function (Collection $results, string $date): array {
                $runReports = $results
                    ->groupBy('run_id')
                    ->map(fn (Collection $runResults): array => $this->buildInclusionRunReport($runResults))
                    ->sortByDesc(static fn (array $runReport): int => (int) ($runReport['run_id'] ?? 0))
                    ->values();

                return [
                    'date' => $date,
                    'total' => $results->count(),
                    'keyword_hits' => $results->where('keyword_hit', true)->count(),
                    'brand_hits' => $results->where('brand_hit', true)->count(),
                    'matched_keywords' => $this->matchedKeywords($results),
                    'missed_keywords' => $this->missedKeywords($results),
                    'platforms' => $this->platformBreakdown($results),
                    'runs' => $runReports,
                ];
            })
            ->values();
    }

    private function buildInclusionRunReport(Collection $results): array
    {
        /** @var GeoInclusionCheckResult|null $firstResult */
        $firstResult = $results->first();
        $run = $firstResult?->run;

        return [
            'run_id' => (int) ($firstResult?->run_id ?? 0),
            'status' => (string) ($run?->status ?? $firstResult?->status ?? ''),
            'created_at' => $run?->created_at,
            'completed_at' => $run?->completed_at,
            'total' => $results->count(),
            'total_checks' => (int) ($run?->total_checks ?? $results->count()),
            'completed_checks' => (int) ($run?->completed_checks ?? $results->count()),
            'failed_checks' => (int) ($run?->failed_checks ?? $results->where('status', 'failed')->count()),
            'keyword_hits' => $results->where('keyword_hit', true)->count(),
            'brand_hits' => $results->where('brand_hit', true)->count(),
            'matched_keywords' => $this->matchedKeywords($results),
            'missed_keywords' => $this->missedKeywords($results),
            'platforms' => $this->platformBreakdown($results),
            'results' => $results->values(),
        ];
    }

    private function matchedKeywords(Collection $results): Collection
    {
        return $results
            ->filter(static fn (GeoInclusionCheckResult $result): bool => (bool) $result->keyword_hit)
            ->map(static fn (GeoInclusionCheckResult $result): ?string => $result->keyword?->keyword)
            ->filter()
            ->unique()
            ->values();
    }

    private function missedKeywords(Collection $results): Collection
    {
        return $results
            ->reject(static fn (GeoInclusionCheckResult $result): bool => (bool) $result->keyword_hit)
            ->map(static fn (GeoInclusionCheckResult $result): ?string => $result->keyword?->keyword)
            ->filter()
            ->unique()
            ->values();
    }

    private function platformBreakdown(Collection $results): Collection
    {
        return $results
            ->groupBy('platform')
            ->map(fn (Collection $items, string $platform): array => [
                'label' => $this->platformLabel($platform),
                'total' => $items->count(),
                'keyword_hits' => $items->where('keyword_hit', true)->count(),
                'brand_hits' => $items->where('brand_hit', true)->count(),
            ])
            ->values();
    }

    private function inclusionResultsQuery(int $libraryId)
    {
        return GeoInclusionCheckResult::query()
            ->where('keyword_library_id', $libraryId)
            ->with(['keyword:id,keyword', 'run:id,status,total_checks,completed_checks,failed_checks,created_at,completed_at'])
            ->orderByDesc('checked_at')
            ->orderByDesc('id');
    }

    private function platformLabel(string $platform): string
    {
        return match (strtolower($platform)) {
            'doubao' => '豆包',
            'qianwen' => '千问',
            'deepseek' => 'DeepSeek',
            default => strtoupper($platform),
        };
    }

    /**
     * @return array{enabled:bool,key:string,host:string,port:int,scheme:string,channel:string,snapshot_url:string}
     */
    private function inclusionRealtimeConfig(int $libraryId): array
    {
        $reverbApp = config('reverb.apps.apps.0', []);
        $host = (string) (config('reverb.servers.reverb.hostname') ?: config('app.url'));
        $parsedHost = parse_url($host, PHP_URL_HOST);

        return [
            'enabled' => (string) config('broadcasting.default') === 'reverb',
            'key' => (string) ($reverbApp['key'] ?? ''),
            'host' => $parsedHost ? (string) $parsedHost : (string) $host,
            'port' => (int) (config('reverb.apps.apps.0.options.port') ?: 443),
            'scheme' => (string) (config('reverb.apps.apps.0.options.scheme') ?: 'https'),
            'channel' => 'admin.keyword-libraries.'.$libraryId,
            'snapshot_url' => route('admin.keyword-libraries.inclusion-snapshot', ['libraryId' => $libraryId]),
        ];
    }
}
