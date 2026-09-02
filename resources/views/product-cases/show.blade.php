<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $pageTitle }} - {{ config('geoflow.site_name', config('app.name')) }}</title>
    <meta name="description" content="{{ $pageDescription }}">
    <script src="{{ asset('js/tailwindcss.play-cdn.js') }}"></script>
    <script src="{{ asset('js/lucide.min.js') }}"></script>
    <style>
        .case-content h1,.case-content h2,.case-content h3{color:#0f172a;font-weight:700;line-height:1.35;margin-top:1.7rem;margin-bottom:.7rem}
        .case-content h1{font-size:1.875rem}.case-content h2{font-size:1.35rem}.case-content h3{font-size:1.125rem}
        .case-content p,.case-content li,.case-content blockquote{color:#475569;font-size:1rem;line-height:1.9}
        .case-content p{margin:.85rem 0}.case-content ul,.case-content ol{margin:.85rem 0 .85rem 1.3rem}
        .case-content ul{list-style:disc}.case-content ol{list-style:decimal}
        .case-content blockquote{border-left:3px solid #f97316;background:#fff7ed;margin:1rem 0;padding:.75rem 1rem}
        .case-content .article-table-wrap{margin:1rem 0;overflow-x:auto;border:1px solid #e2e8f0;border-radius:.5rem}
        .case-content table{width:100%;border-collapse:collapse;background:#fff}
        .case-content th,.case-content td{border-bottom:1px solid #e2e8f0;padding:.75rem;text-align:left;font-size:.875rem;color:#475569}
        .case-content th{background:#f8fafc;color:#0f172a;font-weight:600}
        .case-content img{max-width:100%;border-radius:.5rem}
    </style>
    @php
        $schemaAtContext = chr(64).'context';
        $schemaAtType = chr(64).'type';
        $schemaJsonOptions = JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRETTY_PRINT
            | JSON_HEX_TAG
            | JSON_HEX_APOS
            | JSON_HEX_AMP
            | JSON_HEX_QUOT;
        $customerLevelRaw = trim((string) $case->customer_level);
        $customerLevelNumber = filter_var($customerLevelRaw, FILTER_VALIDATE_INT);
        $customerLevelRating = $customerLevelNumber !== false && $customerLevelNumber >= 1 && $customerLevelNumber <= 5
            ? (int) $customerLevelNumber
            : null;
        $brandProfile = (array) data_get($report, 'brand_profile', []);
        $overall = (array) data_get($report, 'overall', []);
        $competitors = (array) data_get($report, 'competitors', []);
        $sentimentOverall = (array) data_get($report, 'sentiment.overall', []);
        $maxCompetitorMentionCount = max(1, (int) collect($competitors)->max(fn ($competitor): int => (int) data_get($competitor, 'mention_count', 0)));
        $hasIndustryReport = ! empty($competitors)
            || (float) data_get($overall, 'top5_rate', 0) > 0
            || (float) data_get($sentimentOverall, 'positive_rate', 0) > 0
            || (float) data_get($sentimentOverall, 'neutral_rate', 0) > 0
            || (float) data_get($sentimentOverall, 'negative_rate', 0) > 0;
    @endphp
    <script type="application/ld+json">
{!! json_encode([
    $schemaAtContext => 'https://schema.org',
    $schemaAtType => 'Article',
    'headline' => $case->title,
    'description' => $pageDescription,
    'datePublished' => optional($case->published_at)->toAtomString(),
    'dateModified' => optional($case->updated_at)->toAtomString(),
    'author' => [
        $schemaAtType => 'Organization',
        'name' => $case->company_name ?: config('geoflow.site_name', config('app.name')),
    ],
], $schemaJsonOptions) !!}
    </script>
</head>
<body class="bg-[#f6f7f4] text-slate-900">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
            <a href="{{ route('product-cases.index') }}" class="inline-flex items-center gap-2 text-lg font-semibold">
                <span class="flex h-9 w-9 items-center justify-center rounded-md bg-slate-950 text-white">
                    <i data-lucide="briefcase-business" class="h-4 w-4"></i>
                </span>
                产品案例
            </a>
            <a href="{{ route('product-cases.index') }}" class="inline-flex items-center gap-1 text-sm font-medium text-slate-600 hover:text-slate-950">
                <i data-lucide="arrow-left" class="h-4 w-4"></i>
                返回案例列表
            </a>
        </div>
    </header>

    <main>
        <section class="bg-white">
            <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
                <div class="grid gap-8 lg:grid-cols-[1fr_380px] lg:items-end">
                    <div>
                        <div class="flex flex-wrap gap-2">
                            @foreach(array_filter([$case->industry, $case->region, $case->business_mode]) as $item)
                                <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">{{ $item }}</span>
                            @endforeach
                        </div>
                        <h1 class="mt-4 max-w-4xl text-3xl font-semibold tracking-normal text-slate-950 sm:text-5xl">{{ $case->title }}</h1>
                        @if($case->summary !== '')
                            <p class="mt-5 max-w-3xl text-base leading-8 text-slate-600">{{ $case->summary }}</p>
                        @endif
                    </div>

                    <aside class="rounded-lg border border-slate-200 bg-slate-50 p-5">
                        <div class="flex items-center gap-4">
                            <div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-md border border-slate-200 bg-white text-lg font-semibold text-slate-700">
                                @if(trim((string) $case->logo_url) !== '')
                                    <img src="{{ $case->logo_url }}" alt="{{ $case->company_name ?: $case->title }}" class="h-full w-full object-cover">
                                @else
                                    {{ mb_substr($case->company_name ?: $case->title, 0, 2, 'UTF-8') }}
                                @endif
                            </div>
                            <div class="min-w-0">
                                <div class="truncate text-base font-semibold text-slate-950">{{ $case->company_name ?: $case->title }}</div>
                            </div>
                        </div>
                        <dl class="mt-5 grid gap-3 text-sm">
                            <div class="flex justify-between gap-4 border-t border-slate-200 pt-3">
                                <dt class="text-slate-500">客户等级</dt>
                                <dd class="font-medium text-slate-900">
                                    @if($customerLevelRating !== null)
                                        <span class="inline-flex items-center gap-0.5" aria-label="{{ $customerLevelRating }} of 5 stars" title="{{ $customerLevelRating }} 星">
                                            @for($star = 1; $star <= 5; $star++)
                                                <span class="{{ $star <= $customerLevelRating ? 'text-amber-400' : 'text-slate-300' }}">★</span>
                                            @endfor
                                        </span>
                                    @else
                                        {{ $customerLevelRaw !== '' ? $customerLevelRaw : '未设置' }}
                                    @endif
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4 border-t border-slate-200 pt-3">
                                <dt class="text-slate-500">服务开始</dt>
                                <dd class="font-medium text-slate-900">{{ $case->started_at?->format('Y-m-d') ?: '未设置' }}</dd>
                            </div>
                            <div class="flex justify-between gap-4 border-t border-slate-200 pt-3">
                                <dt class="text-slate-500">浏览次数</dt>
                                <dd class="font-medium text-slate-900">{{ $case->view_count }}</dd>
                            </div>
                        </dl>
                    </aside>
                </div>
            </div>

            @if(trim((string) $case->cover_url) !== '')
                <div class="mx-auto max-w-7xl px-4 pb-10 sm:px-6 lg:px-8">
                    <img src="{{ $case->cover_url }}" alt="{{ $case->title }}" class="aspect-[21/8] w-full rounded-lg object-cover">
                </div>
            @endif
        </section>

        <section class="mx-auto grid max-w-7xl gap-8 px-4 py-10 sm:px-6 lg:grid-cols-[minmax(0,1fr)_360px] lg:px-8">
            <article class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                <div class="case-content">
                    @if($contentHtml !== '')
                        {!! $contentHtml !!}
                    @else
                        <p>暂无案例正文。</p>
                    @endif
                </div>
            </article>

            <aside class="space-y-5">
                <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-semibold uppercase tracking-wide text-orange-600">Case Profile</p>
                    <h2 class="mt-2 text-lg font-semibold text-slate-950">案例信息</h2>
                    @if(!empty($case->module_tags))
                        <div class="mt-4 flex flex-wrap gap-2">
                            @foreach((array) $case->module_tags as $tag)
                                <span class="rounded-md bg-orange-50 px-2 py-1 text-xs font-medium text-orange-700">{{ $tag }}</span>
                            @endforeach
                        </div>
                    @endif
                    <div class="mt-5 space-y-3 text-sm text-slate-600">
                        <div class="flex justify-between gap-3">
                            <span>行业</span>
                            <span class="font-medium text-slate-900">{{ $case->industry ?: '未设置' }}</span>
                        </div>
                        <div class="flex justify-between gap-3">
                            <span>地区</span>
                            <span class="font-medium text-slate-900">{{ $case->region ?: '未设置' }}</span>
                        </div>
                        <div class="flex justify-between gap-3">
                            <span>模式</span>
                            <span class="font-medium text-slate-900">{{ $case->business_mode ?: '未设置' }}</span>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-sm font-semibold uppercase tracking-wide text-orange-600">GEO Data</p>
                    <h2 class="mt-2 text-lg font-semibold text-slate-950">GEO 成效总览</h2>
                    <div class="mt-5 grid grid-cols-2 gap-3">
                        @foreach(data_get($report, 'summary.metrics', []) as $metric)
                            <div class="rounded-md bg-slate-50 p-4">
                                <div class="text-2xl font-semibold text-slate-950">{{ $metric['value'] }}</div>
                                <div class="mt-1 text-xs text-slate-500">{{ $metric['label'] }}</div>
                            </div>
                        @endforeach
                    </div>
                </section>
            </aside>
        </section>

        <section class="mx-auto max-w-7xl px-4 pb-12 sm:px-6 lg:px-8">
            <div class="grid gap-6 lg:grid-cols-2">
                <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-wide text-orange-600">AI Platforms</p>
                            <h2 class="mt-2 text-xl font-semibold text-slate-950">AI 平台表现</h2>
                        </div>
                    </div>
                    <div class="mt-5 space-y-3">
                        @forelse((array) data_get($report, 'platforms', []) as $platform)
                            @php
                                $topRate = (float) data_get($platform, 'top_rank_rates.top1', 0);
                                $positiveRate = (float) data_get($platform, 'positive_sentiment_rate', 0);
                            @endphp
                            <div class="rounded-md border border-slate-200 p-4">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="font-medium text-slate-950">{{ data_get($platform, 'platform', 'AI 平台') }}</div>
                                    <div class="text-sm text-slate-500">{{ (int) data_get($platform, 'analysis_count', 0) }} 次分析</div>
                                </div>
                                <div class="mt-3 grid grid-cols-2 gap-3 text-sm">
                                    <div class="rounded-md bg-slate-50 px-3 py-2">
                                        <div class="font-semibold text-slate-950">{{ $topRate }}%</div>
                                        <div class="mt-0.5 text-xs text-slate-500">TOP1 率</div>
                                    </div>
                                    <div class="rounded-md bg-slate-50 px-3 py-2">
                                        <div class="font-semibold text-slate-950">{{ $positiveRate }}%</div>
                                        <div class="mt-0.5 text-xs text-slate-500">正向倾向</div>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <p class="rounded-md bg-slate-50 px-4 py-3 text-sm text-slate-500">暂无 AI 平台表现数据。</p>
                        @endforelse
                    </div>
                </section>

                <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-wide text-orange-600">Search Report</p>
                        <h2 class="mt-2 text-xl font-semibold text-slate-950">搜索报表摘要</h2>
                    </div>
                    <div class="mt-5 overflow-x-auto">
                        @if(!empty(data_get($report, 'search_rows', [])))
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-xs font-medium uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th class="px-3 py-3 text-left">问题</th>
                                        <th class="px-3 py-3 text-left">平台</th>
                                        <th class="px-3 py-3 text-left">转化目标</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200">
                                    @foreach((array) data_get($report, 'search_rows', []) as $row)
                                        <tr>
                                            <td class="max-w-80 px-3 py-3 text-slate-700">{{ data_get($row, 'question', '-') }}</td>
                                            <td class="whitespace-nowrap px-3 py-3 text-slate-600">{{ data_get($row, 'platform', '-') }}</td>
                                            <td class="whitespace-nowrap px-3 py-3 text-slate-600">{{ data_get($row, 'target', '-') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @else
                            <p class="rounded-md bg-slate-50 px-4 py-3 text-sm text-slate-500">暂无搜索报表数据。</p>
                        @endif
                    </div>
                </section>
            </div>
        </section>

        @if($hasIndustryReport)
            <section class="mx-auto max-w-7xl px-4 pb-14 sm:px-6 lg:px-8">
                <div class="mb-6">
                    <p class="text-sm font-semibold uppercase tracking-wide text-orange-600">Industry Report</p>
                    <h2 class="mt-2 text-2xl font-semibold text-slate-950">行业竞争力</h2>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">基于品牌诊断结果整理品牌画像、竞品提及、排名曝光和情感倾向，作为案例效果的补充证明。</p>
                </div>

                <div class="grid gap-6 lg:grid-cols-[minmax(0,1.25fr)_minmax(300px,.75fr)]">
                    <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-sm font-semibold uppercase tracking-wide text-orange-600">Competitors</p>
                                <h3 class="mt-2 text-xl font-semibold text-slate-950">竞品表现</h3>
                            </div>
                            <span class="rounded-md bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600">{{ count($competitors) }} 个对象</span>
                        </div>

                        <div class="mt-5 space-y-4">
                            @forelse($competitors as $competitor)
                                @php
                                    $mentionCount = (int) data_get($competitor, 'mention_count', 0);
                                    $bestRank = (int) data_get($competitor, 'best_rank', 0);
                                    $barWidth = min(100, max(6, (int) round($mentionCount * 100 / $maxCompetitorMentionCount)));
                                    $platforms = collect((array) data_get($competitor, 'platforms', []))
                                        ->filter(fn ($platform): bool => (int) data_get($platform, 'mention_count', 0) > 0 || (float) data_get($platform, 'rate', 0) > 0)
                                        ->take(3);
                                @endphp
                                <div class="border-b border-slate-100 pb-4 last:border-0 last:pb-0">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="min-w-0">
                                            <div class="truncate font-medium text-slate-950">{{ data_get($competitor, 'brand_name', '-') }}</div>
                                            <div class="mt-1 text-xs text-slate-500">
                                                提及 {{ $mentionCount }} 次
                                                @if($bestRank > 0)
                                                    <span class="mx-1 text-slate-300">/</span>
                                                    最好排名第 {{ $bestRank }}
                                                @endif
                                            </div>
                                        </div>
                                        <span class="shrink-0 rounded-md bg-orange-50 px-2 py-1 text-xs font-medium text-orange-700">{{ $mentionCount }}</span>
                                    </div>
                                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                                        <div class="h-full rounded-full bg-orange-500" style="width: {{ $barWidth }}%"></div>
                                    </div>
                                    @if($platforms->isNotEmpty())
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            @foreach($platforms as $platform)
                                                <span class="rounded-md bg-slate-50 px-2 py-1 text-xs text-slate-600">
                                                    {{ data_get($platform, 'platform', '-') }} {{ (float) data_get($platform, 'rate', 0) }}%
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @empty
                                <p class="rounded-md bg-slate-50 px-4 py-3 text-sm text-slate-500">暂无竞品提及数据。</p>
                            @endforelse
                        </div>
                    </section>

                    <div class="space-y-6">
                        <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                            <p class="text-sm font-semibold uppercase tracking-wide text-orange-600">Brand Profile</p>
                            <h3 class="mt-2 text-xl font-semibold text-slate-950">品牌画像</h3>
                            <div class="mt-5 space-y-4 text-sm">
                                <div>
                                    <div class="text-xs text-slate-500">品牌名称</div>
                                    <div class="mt-1 font-medium text-slate-950">{{ data_get($brandProfile, 'company_name', $case->company_name ?: $case->title) }}</div>
                                </div>
                                @if(!empty(data_get($brandProfile, 'core_services', [])))
                                    <div>
                                        <div class="text-xs text-slate-500">核心服务</div>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            @foreach((array) data_get($brandProfile, 'core_services', []) as $service)
                                                <span class="rounded-md bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700">{{ $service }}</span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                                @if(trim((string) data_get($brandProfile, 'description', '')) !== '')
                                    <p class="leading-6 text-slate-600">{{ data_get($brandProfile, 'description') }}</p>
                                @endif
                            </div>
                        </section>

                        <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                            <p class="text-sm font-semibold uppercase tracking-wide text-orange-600">Ranking</p>
                            <h3 class="mt-2 text-xl font-semibold text-slate-950">排名表现</h3>
                            <div class="mt-5 grid grid-cols-2 gap-3">
                                <div class="rounded-md bg-slate-50 p-4">
                                    <div class="text-2xl font-semibold text-slate-950">{{ (float) data_get($overall, 'top5_rate', 0) }}%</div>
                                    <div class="mt-1 text-xs text-slate-500">TOP5 曝光率</div>
                                </div>
                                <div class="rounded-md bg-slate-50 p-4">
                                    <div class="text-2xl font-semibold text-slate-950">{{ (int) data_get($overall, 'top5_count', 0) }}</div>
                                    <div class="mt-1 text-xs text-slate-500">TOP5 命中次数</div>
                                </div>
                            </div>
                            <div class="mt-4 grid grid-cols-5 gap-2 text-center text-xs">
                                @for($rank = 1; $rank <= 5; $rank++)
                                    <div class="rounded-md border border-slate-200 px-2 py-2">
                                        <div class="font-semibold text-slate-950">{{ (float) data_get($overall, 'top_rank_rates.top'.$rank, 0) }}%</div>
                                        <div class="mt-1 text-slate-500">TOP{{ $rank }}</div>
                                    </div>
                                @endfor
                            </div>
                        </section>

                        <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                            <p class="text-sm font-semibold uppercase tracking-wide text-orange-600">Sentiment</p>
                            <h3 class="mt-2 text-xl font-semibold text-slate-950">情感倾向</h3>
                            <div class="mt-5 space-y-3">
                                @foreach([
                                    ['label' => '正向', 'rate' => (float) data_get($sentimentOverall, 'positive_rate', 0), 'color' => 'bg-emerald-500'],
                                    ['label' => '中性', 'rate' => (float) data_get($sentimentOverall, 'neutral_rate', 0), 'color' => 'bg-sky-500'],
                                    ['label' => '负向', 'rate' => (float) data_get($sentimentOverall, 'negative_rate', 0), 'color' => 'bg-rose-500'],
                                ] as $sentiment)
                                    <div>
                                        <div class="flex items-center justify-between text-sm">
                                            <span class="text-slate-600">{{ $sentiment['label'] }}</span>
                                            <span class="font-medium text-slate-950">{{ $sentiment['rate'] }}%</span>
                                        </div>
                                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                                            <div class="h-full rounded-full {{ $sentiment['color'] }}" style="width: {{ min(100, max(0, $sentiment['rate'])) }}%"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    </div>
                </div>
            </section>
        @endif
    </main>

    <footer class="border-t border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl flex-col gap-2 px-4 py-6 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
            <span>{{ config('geoflow.site_name', config('app.name')) }}</span>
            <a href="{{ route('product-cases.index') }}" class="font-medium text-slate-600 hover:text-slate-950">查看更多案例</a>
        </div>
    </footer>

    <script>
        if (window.lucide) {
            window.lucide.createIcons();
        }
    </script>
</body>
</html>
