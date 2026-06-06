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
            'platforms.*' => ['string', 'in:doubao'],
        ], [
            'brand_name.required' => '请输入品牌名称',
            'platforms.*.in' => '当前版本先支持豆包诊断',
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
            ['name' => '千问', 'key' => 'qianwen', 'initial' => '千', 'color' => 'bg-violet-600', 'desc' => '通义问答', 'deep' => true, 'available' => false],
            ['name' => '文心一言', 'key' => 'wenxin', 'initial' => '文', 'color' => 'bg-emerald-600', 'desc' => '千帆搜索', 'deep' => false, 'available' => false],
            ['name' => 'DeepSeek', 'key' => 'deepseek', 'initial' => 'DS', 'color' => 'bg-indigo-600', 'desc' => '深度推理', 'deep' => true, 'available' => false],
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
                'questions' => fn ($query) => $query->orderBy('sort_order')->with(['results.sources']),
                'sources',
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
        $questions = $run->questions->map(static fn ($question): array => [
            'rank' => (int) $question->sort_order,
            'text' => (string) $question->question,
            'type' => (string) $question->question_type,
            'status' => (string) $question->status,
        ])->values()->all();

        $sources = $run->sources->map(static fn ($source): array => [
            'platform' => '豆包',
            'category' => $source->domain !== '' ? (string) $source->domain : '网页来源',
            'title' => (string) ($source->title ?: $source->url),
            'questions' => 1,
            'models' => 1,
            'icon' => 'DB',
            'url' => (string) $source->url,
        ])->values()->all();

        $conversations = $run->questions->map(function ($question) use ($run): array {
            $result = $question->results->first();
            $brands = [];
            if ($result && (bool) $result->brand_mentioned) {
                $brands[] = (string) $run->brand_name;
            }

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
        if ($record !== null) {
            return [
                ['brand' => (string) $record['brand'], 'rate' => (int) $record['metrics']['mention_rate']],
            ];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>|null  $record
     * @return list<array{brand:string,count:int}>
     */
    private function mentionCountRanking(?array $record): array
    {
        if ($record !== null) {
            return [
                ['brand' => (string) $record['brand'], 'count' => (int) $record['metrics']['mention_count']],
            ];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>|null  $record
     * @return list<array{brand:string,rate:int,rank:string}>
     */
    private function averageRankings(?array $record): array
    {
        if ($record !== null) {
            return [
                [
                    'brand' => (string) $record['brand'],
                    'rate' => (int) $record['metrics']['mention_rate'],
                    'rank' => (string) $record['metrics']['average_rank'].'名',
                ],
            ];
        }

        return [];
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
}
