@php
    $metrics = $record['metrics'] ?? ['score' => 0, 'mention_rate' => 0, 'average_rank' => '0', 'mention_count' => 0, 'sentiment_rate' => 0];
    $questions = $record['questions'] ?? [];
    $sources = $record['sources'] ?? [];
    $conversations = $record['conversations'] ?? [];
    $rankings = $record['rankings'] ?? ['mention_rate' => [], 'mention_count' => [], 'average_rank' => []];
    $platformOptions = array_values(array_filter($record['platform_options'] ?? [], static fn ($option) => ($option['value'] ?? '') !== 'all'));
    $platformData = $record['platform_data'] ?? [];
    $brandInitial = mb_substr((string) $record['brand'], 0, 1, 'UTF-8');
    $averageRankValue = (float) ($metrics['average_rank'] ?? 0);
    $sourceDomains = collect($sources)->pluck('category')->filter()->unique()->values();
    $targetRateRow = collect($rankings['mention_rate'] ?? [])->firstWhere('is_target_brand', true);
    $targetCountRow = collect($rankings['mention_count'] ?? [])->firstWhere('is_target_brand', true);
    $targetRankRow = collect($rankings['average_rank'] ?? [])->firstWhere('is_target_brand', true);
    $competitorRows = collect($rankings['mention_count'] ?? [])->reject(static fn ($row) => (bool) ($row['is_target_brand'] ?? false))->take(10)->values();
    $competitorCounts = $competitorRows->map(static fn ($row) => (int) ($row['count'] ?? 0))->all();
    $maxCompetitorCount = max([1, ...$competitorCounts]);
    $widthClasses = ['w-0', 'w-1/12', 'w-2/12', 'w-3/12', 'w-4/12', 'w-5/12', 'w-6/12', 'w-7/12', 'w-8/12', 'w-9/12', 'w-10/12', 'w-11/12', 'w-full'];
    $barWidthClass = static function (int $value, int $max) use ($widthClasses): string {
        if ($value <= 0 || $max <= 0) {
            return $widthClasses[0];
        }

        return $widthClasses[max(1, min(12, (int) ceil(($value / $max) * 12)))];
    };
    $metricCards = [
        ['label' => '品牌得分', 'value' => (string) $metrics['score'], 'suffix' => '/100', 'icon' => 'gauge', 'tone' => 'orange'],
        ['label' => '品牌提及率', 'value' => (string) $metrics['mention_rate'], 'suffix' => '%', 'icon' => 'radio-tower', 'tone' => 'blue'],
        ['label' => '平均提及排名', 'value' => (string) $metrics['average_rank'], 'suffix' => '名', 'icon' => 'list-ordered', 'tone' => 'indigo'],
        ['label' => '品牌提及次数', 'value' => (string) $metrics['mention_count'], 'suffix' => '次', 'icon' => 'hash', 'tone' => 'emerald'],
        ['label' => '正面/中性倾向', 'value' => (string) $metrics['sentiment_rate'], 'suffix' => '%', 'icon' => 'smile', 'tone' => 'rose'],
    ];
    $recommendations = [];
    if ((int) $metrics['mention_rate'] <= 0) {
        $recommendations[] = '目标品牌当前未被 AI 回答或引用来源有效提及，优先补充品牌介绍页、案例页、FAQ 与第三方可检索内容。';
    } elseif ((int) $metrics['mention_rate'] < 30) {
        $recommendations[] = '目标品牌提及率偏低，建议围绕高频问题建设可被引用的品牌内容，并增加行业词、场景词覆盖。';
    }
    if ((int) $metrics['mention_count'] < 3) {
        $recommendations[] = '目标品牌提及次数较少，建议在权威来源、案例报道和对比型内容中稳定出现品牌全称与核心服务描述。';
    }
    if ($averageRankValue <= 0 || $averageRankValue > 3) {
        $recommendations[] = '目标品牌平均排名未进入靠前位置，建议补充竞品对比、差异化卖点和可验证结果，提升被推荐优先级。';
    }
    if ((int) $metrics['sentiment_rate'] < 80) {
        $recommendations[] = '情感倾向仍有优化空间，建议增加正向案例、客户评价、资质背书和风险澄清内容。';
    }
    if (count($sources) < 3) {
        $recommendations[] = '引用来源样本不足，建议增加可被搜索引擎和 AI 平台抓取的公开页面与媒体报道。';
    }
    if ($recommendations === []) {
        $recommendations[] = '当前诊断表现相对稳定，建议保持内容更新频率，并持续监控新问题、新竞品和引用来源变化。';
    }
@endphp

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $reportFileName }}</title>
    <script src="{{ asset('js/tailwindcss.play-cdn.js') }}"></script>
    <script src="{{ asset('js/lucide.min.js') }}"></script>
    <style>
        @media print {
            @page {
                size: A4;
                margin: 12mm;
            }

            .print-hidden {
                display: none !important;
            }

            .report-page {
                background: #ffffff !important;
            }

            .report-section {
                break-inside: avoid;
                page-break-inside: avoid;
            }

            .report-print-shadow {
                box-shadow: none !important;
            }

            a {
                color: inherit !important;
                text-decoration: none !important;
            }
        }
    </style>
</head>
<body class="report-page bg-slate-100 text-slate-900 antialiased" data-auto-print="{{ $autoPrint ? '1' : '0' }}">
    <header class="print-hidden sticky top-0 z-40 border-b border-slate-200 bg-white/95 backdrop-blur">
        <div class="mx-auto flex max-w-7xl flex-col gap-3 px-4 py-3 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
                <div class="text-xs font-semibold uppercase tracking-wide text-orange-600">GEO Brand Diagnosis Report</div>
                <h1 class="truncate text-lg font-bold text-slate-950">{{ $record['brand'] }} 品牌诊断报告</h1>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.brand-diagnosis.index') }}" class="inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    <i data-lucide="arrow-left" class="h-4 w-4"></i>
                    返回诊断
                </a>
                @if ($record['has_report'] ?? false)
                    <a href="{{ route('admin.brand-diagnosis.report.download', ['run' => $record['id']]) }}" data-report-download class="inline-flex h-10 items-center gap-2 rounded-md bg-orange-600 px-4 text-sm font-semibold text-white hover:bg-orange-700">
                        <i data-lucide="download" class="h-4 w-4"></i>
                        导出PDF
                    </a>
                @endif
            </div>
        </div>
    </header>

    <div class="mx-auto grid max-w-7xl gap-6 px-4 py-6 sm:px-6 lg:grid-cols-[240px_1fr] lg:py-8">
        <aside class="print-hidden hidden lg:block">
            <nav class="sticky top-24 space-y-2 rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                @foreach ([
                    ['href' => '#summary', 'icon' => 'file-text', 'label' => '报告简介'],
                    ['href' => '#performance', 'icon' => 'gauge', 'label' => '整体表现'],
                    ['href' => '#visibility', 'icon' => 'radar', 'label' => 'AI可见度分析'],
                    ['href' => '#sentiment', 'icon' => 'smile', 'label' => 'AI舆情分析'],
                    ['href' => '#sources', 'icon' => 'link', 'label' => '引用源分析'],
                    ['href' => '#dialogues', 'icon' => 'messages-square', 'label' => 'AI问题与对话明细'],
                    ['href' => '#recommendations', 'icon' => 'sparkles', 'label' => '优化建议'],
                ] as $navItem)
                    <a href="{{ $navItem['href'] }}" class="flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-slate-600 hover:bg-orange-50 hover:text-orange-700">
                        <i data-lucide="{{ $navItem['icon'] }}" class="h-4 w-4"></i>
                        {{ $navItem['label'] }}
                    </a>
                @endforeach
            </nav>
        </aside>

        <main class="min-w-0 space-y-8">
            <section id="summary" data-report-section class="report-section overflow-hidden rounded-lg bg-slate-950 text-white shadow-lg report-print-shadow">
                <div class="grid gap-6 p-6 lg:grid-cols-[1fr_220px] lg:p-8">
                    <div class="min-w-0">
                        <div class="mb-4 inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1 text-xs font-semibold text-orange-100">
                            <i data-lucide="radar" class="h-3.5 w-3.5"></i>
                            AI 搜索可见度诊断
                        </div>
                        <h2 class="text-3xl font-bold leading-tight sm:text-4xl">{{ $record['brand'] }}</h2>
                        <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-300">
                            本报告基于本次品牌诊断采集的问题、AI 平台回答、引用来源与品牌提及数据生成，用于判断品牌在 AI 搜索场景下的可见度、提及强度、排名位置和内容引用基础。
                        </p>
                        <div class="mt-6 grid gap-3 sm:grid-cols-3">
                            <div class="rounded-lg border border-white/10 bg-white/10 p-4">
                                <div class="text-xs text-slate-300">报告文件</div>
                                <div class="mt-1 break-all text-sm font-semibold text-white">{{ $reportFileName }}</div>
                            </div>
                            <div class="rounded-lg border border-white/10 bg-white/10 p-4">
                                <div class="text-xs text-slate-300">诊断时间</div>
                                <div class="mt-1 text-sm font-semibold text-white">{{ $record['created_at'] }}</div>
                            </div>
                            <div class="rounded-lg border border-white/10 bg-white/10 p-4">
                                <div class="text-xs text-slate-300">数据平台</div>
                                <div class="mt-1 text-sm font-semibold text-white">{{ collect($platformOptions)->pluck('label')->join('、') ?: '全部平台' }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center justify-center lg:justify-end">
                        <div class="flex h-44 w-44 flex-col items-center justify-center rounded-full border-[12px] border-orange-500 bg-white text-slate-950 shadow-xl">
                            <span class="text-5xl font-black">{{ $metrics['score'] }}</span>
                            <span class="mt-1 text-xs font-semibold text-slate-500">品牌得分 / 100</span>
                        </div>
                    </div>
                </div>
            </section>

            <section id="performance" data-report-section class="report-section">
                <div class="mb-4 flex flex-col gap-1">
                    <h2 class="text-xl font-bold text-slate-950">整体表现</h2>
                    <p class="text-sm text-slate-600">核心指标按本次采集到的 AI 回答、引用来源和品牌提及记录汇总计算。</p>
                </div>
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                    @foreach ($metricCards as $card)
                        <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="mb-4 flex h-10 w-10 items-center justify-center rounded-md bg-{{ $card['tone'] }}-50 text-{{ $card['tone'] }}-600">
                                <i data-lucide="{{ $card['icon'] }}" class="h-5 w-5"></i>
                            </div>
                            <div class="flex items-end gap-1">
                                <span class="text-3xl font-black tracking-normal text-slate-950">{{ $card['value'] }}</span>
                                <span class="pb-1 text-sm font-semibold text-slate-500">{{ $card['suffix'] }}</span>
                            </div>
                            <div class="mt-2 text-sm font-medium text-slate-600">{{ $card['label'] }}</div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4 grid gap-4 lg:grid-cols-3">
                    @foreach ($platformOptions as $option)
                        @php
                            $platformMetrics = $platformData[$option['value']]['metrics'] ?? null;
                        @endphp
                        @if ($platformMetrics)
                            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="font-semibold text-slate-900">{{ $option['label'] }}</div>
                                    <span class="rounded-md bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">平台维度</span>
                                </div>
                                <div class="mt-4 grid grid-cols-3 gap-3 text-center">
                                    <div>
                                        <div class="text-lg font-bold text-orange-600">{{ $platformMetrics['mention_rate'] }}%</div>
                                        <div class="mt-1 text-xs text-slate-500">提及率</div>
                                    </div>
                                    <div>
                                        <div class="text-lg font-bold text-slate-900">{{ $platformMetrics['mention_count'] }}</div>
                                        <div class="mt-1 text-xs text-slate-500">提及次数</div>
                                    </div>
                                    <div>
                                        <div class="text-lg font-bold text-slate-900">{{ $platformMetrics['average_rank'] }}</div>
                                        <div class="mt-1 text-xs text-slate-500">平均排名</div>
                                    </div>
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            </section>

            <section id="visibility" data-report-section class="report-section">
                <div class="mb-4 flex flex-col gap-1">
                    <h2 class="text-xl font-bold text-slate-950">AI可见度分析</h2>
                    <p class="text-sm text-slate-600">展示本次诊断中竞品和目标品牌在 AI 回答与引用内容中的提及强度。</p>
                </div>
                <div class="grid gap-4 xl:grid-cols-[1.1fr_0.9fr]">
                    <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="mb-4 flex items-center justify-between gap-3">
                            <div>
                                <h3 class="font-semibold text-slate-950">竞品提及次数 Top 10</h3>
                                <p class="mt-1 text-xs text-slate-500">同一回答或同一引用文章内多次出现同一品牌，按一次有效提及统计。</p>
                            </div>
                            <span class="rounded-md bg-orange-50 px-2 py-1 text-xs font-semibold text-orange-700">计数排名</span>
                        </div>
                        <div class="space-y-3">
                            @forelse ($competitorRows as $index => $row)
                                @php
                                    $count = (int) ($row['count'] ?? 0);
                                @endphp
                                <div class="grid grid-cols-[28px_minmax(0,130px)_1fr_42px] items-center gap-3 text-sm">
                                    <span class="text-xs font-semibold text-slate-400">{{ $row['display_rank'] ?? ($index + 1) }}</span>
                                    <span class="truncate font-semibold text-slate-700" title="{{ $row['brand'] ?? '-' }}">{{ $row['brand'] ?? '-' }}</span>
                                    <span class="h-2 overflow-hidden rounded-full bg-slate-100">
                                        <span class="{{ $barWidthClass($count, $maxCompetitorCount) }} block h-full rounded-full bg-blue-600"></span>
                                    </span>
                                    <span class="text-right text-xs font-bold text-blue-700">{{ $count }}</span>
                                </div>
                            @empty
                                <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">暂无竞品提及数据</div>
                            @endforelse
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-3 xl:grid-cols-1">
                        <div class="rounded-lg border border-orange-200 bg-orange-50 p-5">
                            <div class="text-xs font-semibold text-orange-700">目标品牌提及率排名</div>
                            <div class="mt-2 text-3xl font-black text-orange-700">{{ $targetRateRow['display_rank'] ?? '99+' }}</div>
                            <div class="mt-1 text-sm text-orange-900">{{ $targetRateRow['rate'] ?? 0 }}% 提及率</div>
                        </div>
                        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-5">
                            <div class="text-xs font-semibold text-emerald-700">目标品牌提及次数排名</div>
                            <div class="mt-2 text-3xl font-black text-emerald-700">{{ $targetCountRow['display_rank'] ?? '99+' }}</div>
                            <div class="mt-1 text-sm text-emerald-900">{{ $targetCountRow['count'] ?? 0 }} 次提及</div>
                        </div>
                        <div class="rounded-lg border border-indigo-200 bg-indigo-50 p-5">
                            <div class="text-xs font-semibold text-indigo-700">目标品牌平均提及排名</div>
                            <div class="mt-2 text-3xl font-black text-indigo-700">{{ $targetRankRow['display_rank'] ?? '99+' }}</div>
                            <div class="mt-1 text-sm text-indigo-900">平均第 {{ $targetRankRow['rank'] ?? '0' }} 名</div>
                        </div>
                    </div>
                </div>
            </section>

            <section id="sentiment" data-report-section class="report-section">
                <div class="mb-4 flex flex-col gap-1">
                    <h2 class="text-xl font-bold text-slate-950">AI舆情分析</h2>
                    <p class="text-sm text-slate-600">正面/中性倾向用于评估目标品牌被提及时的整体语义温度。</p>
                </div>
                <div class="grid gap-4 lg:grid-cols-[280px_1fr]">
                    <div class="rounded-lg border border-slate-200 bg-white p-6 text-center shadow-sm">
                        <div class="mx-auto flex h-24 w-24 items-center justify-center rounded-full border-[10px] border-rose-500 bg-rose-50">
                            <span class="text-2xl font-black text-rose-700">{{ $metrics['sentiment_rate'] }}%</span>
                        </div>
                        <div class="mt-4 font-semibold text-slate-950">正面/中性情感倾向</div>
                        <p class="mt-2 text-sm leading-6 text-slate-500">该指标由目标品牌被提及时的正面与中性情感占比计算。</p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="text-sm text-slate-500">AI问题数</div>
                            <div class="mt-2 text-3xl font-black text-slate-950">{{ count($questions) }}</div>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="text-sm text-slate-500">AI对话记录</div>
                            <div class="mt-2 text-3xl font-black text-slate-950">{{ count($conversations) }}</div>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="text-sm text-slate-500">引用来源</div>
                            <div class="mt-2 text-3xl font-black text-slate-950">{{ count($sources) }}</div>
                        </div>
                    </div>
                </div>
            </section>

            <section id="sources" data-report-section class="report-section">
                <div class="mb-4 flex flex-col gap-1">
                    <h2 class="text-xl font-bold text-slate-950">引用源分析</h2>
                    <p class="text-sm text-slate-600">引用源代表 AI 回答中可追溯的网页、文章或搜索结果。</p>
                </div>
                <div class="mb-4 flex flex-wrap gap-2">
                    @forelse ($sourceDomains as $domain)
                        <span class="rounded-md bg-white px-3 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">{{ $domain }}</span>
                    @empty
                        <span class="rounded-md bg-white px-3 py-1 text-xs font-semibold text-slate-500 ring-1 ring-slate-200">暂无引用域名</span>
                    @endforelse
                </div>
                <div class="grid gap-4 lg:grid-cols-2">
                    @forelse ($sources as $source)
                        <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="mb-3 flex items-center justify-between gap-3">
                                <span class="inline-flex h-8 w-8 items-center justify-center rounded-md bg-slate-900 text-xs font-bold text-white">{{ $source['icon'] ?? 'AI' }}</span>
                                <span class="rounded-md bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">{{ $source['models'] ?? $source['platform'] }}</span>
                            </div>
                            @if (! empty($source['url']))
                                <a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer" class="block text-base font-semibold leading-6 text-slate-950 hover:text-orange-700">{{ $source['title'] }}</a>
                            @else
                                <h3 class="text-base font-semibold leading-6 text-slate-950">{{ $source['title'] }}</h3>
                            @endif
                            <div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                <span>{{ $source['category'] }}</span>
                                <span class="h-1 w-1 rounded-full bg-slate-300"></span>
                                <span>引用问题 {{ $source['questions'] }} 个</span>
                            </div>
                        </article>
                    @empty
                        <div class="rounded-lg border border-dashed border-slate-300 bg-white px-5 py-10 text-center text-sm text-slate-500">暂无引用来源数据</div>
                    @endforelse
                </div>
            </section>

            <section id="dialogues" data-report-section class="report-section">
                <div class="mb-4 flex flex-col gap-1">
                    <h2 class="text-xl font-bold text-slate-950">AI问题与对话明细</h2>
                    <p class="text-sm text-slate-600">展示本次用于诊断的问题、平台回答、提及品牌和引用记录。</p>
                </div>
                <div class="mb-4 flex flex-wrap gap-2">
                    @forelse ($questions as $question)
                        <span class="inline-flex items-center gap-2 rounded-md bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700">
                            <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-blue-600 text-[11px] text-white">{{ $question['rank'] }}</span>
                            {{ $question['text'] }}
                        </span>
                    @empty
                        <span class="rounded-md bg-white px-3 py-2 text-xs font-semibold text-slate-500 ring-1 ring-slate-200">暂无 AI 问题</span>
                    @endforelse
                </div>
                <div class="space-y-4">
                    @forelse ($conversations as $conversation)
                        <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <div class="mb-2 flex flex-wrap items-center gap-2">
                                        <span class="rounded-md bg-orange-50 px-2 py-1 text-xs font-semibold text-orange-700">{{ $conversation['platform'] ?? 'AI' }}</span>
                                        <span class="rounded-md bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">{{ $conversation['status'] ?? '' }}</span>
                                    </div>
                                    <h3 class="text-base font-semibold leading-6 text-slate-950">{{ $conversation['question'] }}</h3>
                                </div>
                                <div class="flex max-w-md flex-wrap gap-1.5">
                                    @forelse ($conversation['visible_brands'] ?? [] as $brand)
                                        <span class="rounded-md bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700" title="{{ implode('、', $conversation['brands'] ?? []) }}">{{ $brand }}</span>
                                    @empty
                                        <span class="rounded-md bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-500">未提及品牌</span>
                                    @endforelse
                                    @if (($conversation['hidden_brand_count'] ?? 0) > 0)
                                        <span class="rounded-md bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-500" title="{{ implode('、', $conversation['brands'] ?? []) }}">...</span>
                                    @endif
                                </div>
                            </div>
                            <p class="whitespace-pre-wrap text-sm leading-7 text-slate-700">{{ $conversation['answer'] ?: '暂无回答内容。' }}</p>
                            @if (! empty($conversation['sources']))
                                <div class="mt-4 border-t border-slate-200 pt-4">
                                    <div class="mb-2 text-xs font-semibold text-slate-500">引用记录</div>
                                    <div class="grid gap-2 sm:grid-cols-2">
                                        @foreach ($conversation['sources'] as $conversationSource)
                                            <a href="{{ $conversationSource['url'] }}" target="_blank" rel="noopener noreferrer" class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-medium text-slate-700 hover:border-orange-200 hover:text-orange-700">
                                                <span class="block truncate">{{ $conversationSource['title'] }}</span>
                                                <span class="mt-1 block text-xs text-slate-500">{{ $conversationSource['domain'] ?: '网页来源' }}</span>
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </article>
                    @empty
                        <div class="rounded-lg border border-dashed border-slate-300 bg-white px-5 py-10 text-center text-sm text-slate-500">暂无 AI 对话记录</div>
                    @endforelse
                </div>
            </section>

            <section id="recommendations" data-report-section class="report-section">
                <div class="mb-4 flex flex-col gap-1">
                    <h2 class="text-xl font-bold text-slate-950">优化建议</h2>
                    <p class="text-sm text-slate-600">基于本次诊断数据生成的优先处理方向。</p>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($recommendations as $index => $recommendation)
                        <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="mb-3 flex items-center gap-3">
                                <span class="inline-flex h-8 w-8 items-center justify-center rounded-md bg-orange-600 text-sm font-bold text-white">{{ $index + 1 }}</span>
                                <h3 class="font-semibold text-slate-950">建议 {{ $index + 1 }}</h3>
                            </div>
                            <p class="text-sm leading-6 text-slate-600">{{ $recommendation }}</p>
                        </div>
                    @endforeach
                </div>
            </section>
        </main>
    </div>

    <script>
        window.lucide?.createIcons?.();

        if (document.body.dataset.autoPrint === '1') {
            window.setTimeout(() => {
                window.location.href = @json(route('admin.brand-diagnosis.report.download', ['run' => $record['id']]));
            }, 300);
        }
    </script>
</body>
</html>
