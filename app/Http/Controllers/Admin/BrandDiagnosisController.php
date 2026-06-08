<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\BrandDiagnosisRun;
use App\Services\BrandDiagnosis\BrandDiagnosisLimitExceededException;
use App\Services\BrandDiagnosis\BrandDiagnosisRunService;
use App\Support\AdminWeb;
use App\Support\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Throwable;

class BrandDiagnosisController extends Controller
{
    public function __construct(private readonly BrandDiagnosisRunService $runService) {}

    public function index(): View
    {
        $records = $this->diagnosisRecords();
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
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'brand_name' => ['required', 'string', 'max:120'],
            'platforms' => ['nullable', 'array'],
            'platforms.*' => ['string', 'in:doubao,deepseek'],
        ], [
            'brand_name.required' => '请输入品牌名称',
            'platforms.*.in' => '当前版本先支持豆包和 DeepSeek 诊断',
        ]);

        $admin = auth('admin')->user();
        if (! $admin instanceof Admin) {
            abort(403);
        }

        try {
            $this->runService->create(
                $admin,
                (string) $payload['brand_name'],
                (array) ($payload['platforms'] ?? ['doubao'])
            );
        } catch (BrandDiagnosisLimitExceededException $exception) {
            return back()->withErrors(['brand_name' => $exception->getMessage()])->withInput();
        } catch (Throwable $exception) {
            return back()->withErrors(['brand_name' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('admin.brand-diagnosis.index')
            ->with('message', '品牌诊断任务已创建，结果生成后会显示在诊断记录中。');
    }

    /**
     * @return list<array{name:string,key:string,initial:string,color:string,desc:string,deep:bool,available:bool}>
     */
    private function models(): array
    {
        return [
            ['name' => '豆包', 'key' => 'doubao', 'initial' => '豆', 'color' => 'bg-blue-600', 'desc' => '网页问答', 'deep' => true, 'available' => true],
            ['name' => 'DeepSeek', 'key' => 'deepseek', 'initial' => 'DS', 'color' => 'bg-indigo-600', 'desc' => '深度推理', 'deep' => true, 'available' => true],
            ['name' => '千问', 'key' => 'qianwen', 'initial' => '千', 'color' => 'bg-violet-600', 'desc' => '通义问答', 'deep' => true, 'available' => false],
            ['name' => '文心一言', 'key' => 'wenxin', 'initial' => '文', 'color' => 'bg-emerald-600', 'desc' => '千帆搜索', 'deep' => false, 'available' => false],
        ];
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
     *     sources:list<array{platform:string,category:string,title:string,questions:int,models:int,icon:string,url:string}>,
     *     conversations:list<array{question:string,brands:list<string>,answer:string,status:string}>
     * }>
     */
    private function diagnosisRecords(): array
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
            ])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->values()
            ->map(fn (BrandDiagnosisRun $run, int $index): array => $this->formatRecord($run, $index === 0))
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function formatRecord(BrandDiagnosisRun $run, bool $expanded): array
    {
        $rankings = $this->brandRankings($run);
        $questions = $run->questions->map(static fn ($question): array => [
            'rank' => (int) $question->sort_order,
            'text' => (string) $question->question,
            'type' => (string) $question->question_type,
            'status' => (string) $question->status,
        ])->values()->all();

        $sources = $run->sources->map(fn ($source): array => [
            'platform' => $this->platformLabel((string) $source->platform),
            'category' => $source->domain !== '' ? (string) $source->domain : '网页来源',
            'title' => (string) ($source->title ?: $source->url),
            'questions' => 1,
            'models' => 1,
            'icon' => $this->platformIcon((string) $source->platform),
            'url' => (string) $source->url,
        ])->values()->all();

        $conversations = $run->questions->map(function ($question) use ($run): array {
            $result = $question->results->first();
            $brands = $result
                ? $result->brandMentions->pluck('brand_name')->filter()->unique()->values()->all()
                : [];

            return [
                'question' => (string) $question->question,
                'brands' => $brands,
                'answer' => (string) ($result?->answer ?? ''),
                'status' => (string) ($result?->status ?? $question->status),
            ];
        })->values()->all();

        return [
            'id' => (int) $run->id,
            'brand' => (string) $run->brand_name,
            'status' => $this->statusLabel((string) $run->status),
            'raw_status' => (string) $run->status,
            'created_at' => $run->created_at?->format('Y-m-d H:i:s') ?? '',
            'expanded' => $expanded,
            'has_report' => in_array((string) $run->status, ['completed', 'failed'], true),
            'metrics' => [
                'score' => (int) $run->brand_score,
                'mention_rate' => (int) $run->mention_rate,
                'average_rank' => $this->formatAverageRank((float) $run->average_rank),
                'mention_count' => (int) $run->mention_count,
                'sentiment_rate' => (int) $run->sentiment_rate,
            ],
            'questions' => $questions,
            'sources' => $sources,
            'conversations' => $conversations,
            'rankings' => $rankings,
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => '排队中',
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
     * @return list<array{platform:string,category:string,title:string,questions:int,models:int,icon:string,url:string}>
     */
    private function sources(?array $record): array
    {
        if ($record === null) {
            return [];
        }

        /** @var list<array{platform:string,category:string,title:string,questions:int,models:int,icon:string,url:string}> $sources */
        $sources = $record['sources'];

        return $sources;
    }

    /**
     * @param  array<string,mixed>|null  $record
     * @return list<array{question:string,brands:list<string>,answer:string,status:string}>
     */
    private function conversations(?array $record): array
    {
        if ($record === null) {
            return [];
        }

        /** @var list<array{question:string,brands:list<string>,answer:string,status:string}> $conversations */
        $conversations = $record['conversations'];

        return $conversations;
    }

    /**
     * @return array{
     *   mention_rate:list<array{brand:string,rate:int,is_target_brand:bool}>,
     *   mention_count:list<array{brand:string,count:int,is_target_brand:bool}>,
     *   average_rank:list<array{brand:string,rate:int,rank:string,rank_value:float,is_target_brand:bool}>
     * }
     */
    private function brandRankings(BrandDiagnosisRun $run): array
    {
        $mentions = $run->brandMentions;
        $successResults = $run->questions->flatMap(static fn ($question) => $question->results)->where('status', 'success');
        $totalConversations = max(1, $successResults->count());

        $grouped = $mentions
            ->groupBy(static fn ($mention): string => mb_strtolower(trim((string) $mention->brand_name), 'UTF-8'))
            ->map(function (Collection $group) use ($totalConversations): array {
                $first = $group->first();
                $conversationCount = $group->pluck('result_id')->unique()->count();
                $mentionCount = (int) $group->sum('mention_count');
                $averageRank = (float) ($group->where('mention_rank', '>', 0)->avg('mention_rank') ?: 0);

                return [
                    'brand' => (string) $first?->brand_name,
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
            'rate' => (int) $run->mention_rate,
            'count' => (int) $run->mention_count,
            'rank_value' => (float) $run->average_rank,
            'rank_sort' => (float) $run->average_rank > 0 ? (float) $run->average_rank : 999999,
            'rank' => $this->formatAverageRank((float) $run->average_rank),
            'is_target_brand' => true,
        ];

        return [
            'mention_rate' => $this->topRowsWithTargetLast($grouped, 'rate', true, $targetRow)->all(),
            'mention_count' => $this->topRowsWithTargetLast($grouped, 'count', true, $targetRow)->all(),
            'average_rank' => $this->topRowsWithTargetLast($grouped, 'rank_sort', false, $targetRow)
                ->map(static fn (array $row): array => [
                    'brand' => (string) $row['brand'],
                    'rate' => (int) $row['rate'],
                    'rank' => (string) $row['rank'],
                    'rank_value' => (float) $row['rank_value'],
                    'is_target_brand' => (bool) $row['is_target_brand'],
                ])
                ->all(),
        ];
    }

    /**
     * @param  Collection<int,array{brand:string,rate:int,count:int,rank_value:float,rank:string,is_target_brand:bool}>  $rows
     * @return Collection<int,array{brand:string,rate:int,count:int,rank_value:float,rank:string,is_target_brand:bool}>
     */
    private function topRowsWithTargetLast(Collection $rows, string $sortKey, bool $descending, array $targetRow): Collection
    {
        $topRows = $rows
            ->reject(static fn (array $row): bool => (bool) $row['is_target_brand'])
            ->when($descending, fn (Collection $collection): Collection => $collection->sortByDesc($sortKey), fn (Collection $collection): Collection => $collection->sortBy($sortKey))
            ->take(9)
            ->values();

        return $topRows->push($targetRow);
    }

    private function platformLabel(string $platform): string
    {
        return match ($platform) {
            'deepseek' => 'DeepSeek',
            'doubao' => '豆包',
            default => $platform,
        };
    }

    private function platformIcon(string $platform): string
    {
        return match ($platform) {
            'deepseek' => 'DS',
            'doubao' => 'DB',
            default => mb_strtoupper(mb_substr($platform, 0, 2, 'UTF-8'), 'UTF-8'),
        };
    }
}
