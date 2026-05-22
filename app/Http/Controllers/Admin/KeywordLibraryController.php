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
use Illuminate\View\View;
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

    private function loadLatestInclusionResults(int $libraryId): Collection
    {
        return GeoInclusionCheckResult::query()
            ->where('keyword_library_id', $libraryId)
            ->with(['keyword:id,keyword'])
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();
    }
}
