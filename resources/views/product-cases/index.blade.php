<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $pageTitle }} - {{ config('geoflow.site_name', config('app.name')) }}</title>
    <meta name="description" content="{{ $pageDescription }}">
    <script src="{{ asset('js/tailwindcss.play-cdn.js') }}"></script>
    <script src="{{ asset('js/lucide.min.js') }}"></script>
    @php
        $schemaAtContext = chr(64).'context';
        $schemaAtType = chr(64).'type';
        $schemaItems = [];
        foreach ($cases->getCollection()->take(10) as $schemaCase) {
            $schemaItems[] = [
                $schemaAtType => 'ListItem',
                'position' => count($schemaItems) + 1,
                'url' => route('product-cases.show', ['slug' => $schemaCase->slug]),
                'name' => $schemaCase->title,
            ];
        }
        $schemaJsonOptions = JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRETTY_PRINT
            | JSON_HEX_TAG
            | JSON_HEX_APOS
            | JSON_HEX_AMP
            | JSON_HEX_QUOT;
    @endphp
    <script type="application/ld+json">
{!! json_encode([
    $schemaAtContext => 'https://schema.org',
    $schemaAtType => 'CollectionPage',
    'name' => '产品案例',
    'description' => $pageDescription,
    'mainEntity' => [
        $schemaAtType => 'ItemList',
        'itemListElement' => $schemaItems,
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
            <a href="{{ route('site.home') }}" class="inline-flex items-center gap-1 text-sm font-medium text-slate-600 hover:text-slate-950">
                <i data-lucide="home" class="h-4 w-4"></i>
                返回首页
            </a>
        </div>
    </header>

    <main>
        <section class="border-b border-slate-200 bg-white">
            <div class="mx-auto grid max-w-7xl gap-8 px-4 py-12 sm:px-6 lg:grid-cols-[1fr_0.72fr] lg:px-8 lg:py-16">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-wide text-orange-600">GEO Case Library</p>
                    <h1 class="mt-3 max-w-3xl text-3xl font-semibold tracking-normal text-slate-950 sm:text-5xl">产品案例</h1>
                    <p class="mt-5 max-w-2xl text-base leading-8 text-slate-600">
                        汇总平台真实维护的品牌案例，展示企业在 GEO 内容建设、AI 搜索收录、品牌诊断和内容增长中的实践路径。
                    </p>
                </div>
                <div class="grid gap-3 sm:grid-cols-3 lg:content-end">
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <div class="text-2xl font-semibold text-slate-950">{{ $cases->total() }}</div>
                        <div class="mt-1 text-sm text-slate-500">公开案例</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <div class="text-2xl font-semibold text-slate-950">{{ count($filterOptions['industries']) }}</div>
                        <div class="mt-1 text-sm text-slate-500">行业类型</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <div class="text-2xl font-semibold text-slate-950">{{ count($filterOptions['tags']) }}</div>
                        <div class="mt-1 text-sm text-slate-500">能力标签</div>
                    </div>
                </div>
            </div>
        </section>

        <section class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <form method="GET" action="{{ route('product-cases.index') }}" class="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm lg:grid-cols-[1.4fr_1fr_1fr_1fr_1fr_auto]">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-slate-500">搜索</span>
                    <input name="keyword" value="{{ $filters['keyword'] }}" placeholder="案例标题 / 品牌名称" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-orange-500 focus:ring-2 focus:ring-orange-100">
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-slate-500">行业</span>
                    <select name="industry" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-orange-500 focus:ring-2 focus:ring-orange-100">
                        <option value="">全部行业</option>
                        @foreach($filterOptions['industries'] as $industry)
                            <option value="{{ $industry }}" @selected($filters['industry'] === $industry)>{{ $industry }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-slate-500">地区</span>
                    <select name="region" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-orange-500 focus:ring-2 focus:ring-orange-100">
                        <option value="">全部地区</option>
                        @foreach($filterOptions['regions'] as $region)
                            <option value="{{ $region }}" @selected($filters['region'] === $region)>{{ $region }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-slate-500">模式</span>
                    <select name="business_mode" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-orange-500 focus:ring-2 focus:ring-orange-100">
                        <option value="">全部模式</option>
                        @foreach($filterOptions['business_modes'] as $businessMode)
                            <option value="{{ $businessMode }}" @selected($filters['businessMode'] === $businessMode)>{{ $businessMode }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-slate-500">标签</span>
                    <select name="tag" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-orange-500 focus:ring-2 focus:ring-orange-100">
                        <option value="">全部标签</option>
                        @foreach($filterOptions['tags'] as $tag)
                            <option value="{{ $tag }}" @selected($filters['tag'] === $tag)>{{ $tag }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="flex items-end gap-2">
                    <button type="submit" class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-slate-950 px-4 text-sm font-medium text-white transition hover:bg-slate-800">
                        <i data-lucide="search" class="h-4 w-4"></i>
                        筛选
                    </button>
                    <a href="{{ route('product-cases.index') }}" class="inline-flex h-10 items-center justify-center rounded-md border border-slate-200 bg-white px-3 text-sm font-medium text-slate-600 transition hover:bg-slate-50">重置</a>
                </div>
            </form>

            <div class="mt-8 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                @forelse($cases as $case)
                    @php
                        $metrics = $caseMetrics[(int) $case->id] ?? [];
                    @endphp
                    <article class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
                        <a href="{{ route('product-cases.show', ['slug' => $case->slug]) }}" class="block">
                            <div class="relative aspect-[16/9] overflow-hidden bg-slate-900">
                                @if(trim((string) $case->cover_url) !== '')
                                    <img src="{{ $case->cover_url }}" alt="{{ $case->title }}" class="h-full w-full object-cover">
                                @else
                                    <div class="flex h-full w-full items-center justify-center bg-[linear-gradient(135deg,#111827,#334155_45%,#d97706)] text-white">
                                        <i data-lucide="line-chart" class="h-10 w-10"></i>
                                    </div>
                                @endif
                                @if($case->industry !== '')
                                    <span class="absolute left-4 top-4 rounded-md bg-white/90 px-2.5 py-1 text-xs font-medium text-slate-800">{{ $case->industry }}</span>
                                @endif
                            </div>
                        </a>
                        <div class="p-5">
                            <div class="flex items-center gap-3">
                                <div class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-md border border-slate-200 bg-slate-50 text-sm font-semibold text-slate-700">
                                    @if(trim((string) $case->logo_url) !== '')
                                        <img src="{{ $case->logo_url }}" alt="{{ $case->company_name ?: $case->title }}" class="h-full w-full object-cover">
                                    @else
                                        {{ mb_substr($case->company_name ?: $case->title, 0, 2, 'UTF-8') }}
                                    @endif
                                </div>
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-medium text-slate-900">{{ $case->company_name ?: $case->title }}</div>
                                    <div class="mt-0.5 truncate text-xs text-slate-500">{{ collect([$case->region, $case->business_mode])->filter()->implode(' / ') ?: '案例品牌' }}</div>
                                </div>
                            </div>

                            <h2 class="mt-4 line-clamp-2 min-h-14 text-lg font-semibold leading-7 text-slate-950">
                                <a href="{{ route('product-cases.show', ['slug' => $case->slug]) }}" class="hover:text-orange-700">{{ $case->title }}</a>
                            </h2>
                            <p class="mt-3 line-clamp-3 min-h-[4.5rem] text-sm leading-6 text-slate-600">{{ $case->summary }}</p>

                            @if(!empty($metrics))
                                <div class="mt-4 grid grid-cols-3 gap-2">
                                    @foreach($metrics as $metric)
                                        <div class="rounded-md bg-slate-50 px-3 py-2">
                                            <div class="text-base font-semibold text-slate-950">{{ $metric['value'] }}</div>
                                            <div class="mt-0.5 truncate text-xs text-slate-500">{{ $metric['label'] }}</div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            @if(!empty($case->module_tags))
                                <div class="mt-4 flex flex-wrap gap-2">
                                    @foreach(array_slice((array) $case->module_tags, 0, 3) as $tag)
                                        <span class="rounded-md bg-orange-50 px-2 py-1 text-xs font-medium text-orange-700">{{ $tag }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </article>
                @empty
                    <div class="md:col-span-2 xl:col-span-3 rounded-lg border border-dashed border-slate-300 bg-white px-6 py-14 text-center">
                        <i data-lucide="folder-open" class="mx-auto h-10 w-10 text-slate-300"></i>
                        <h2 class="mt-4 text-base font-semibold text-slate-900">暂无产品案例</h2>
                        <p class="mt-2 text-sm text-slate-500">请调整筛选条件，或等待超管发布案例。</p>
                    </div>
                @endforelse
            </div>

            @if($cases->hasPages())
                <div class="mt-8">
                    {{ $cases->onEachSide(1)->links() }}
                </div>
            @endif
        </section>
    </main>

    <footer class="border-t border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl flex-col gap-2 px-4 py-6 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
            <span>{{ config('geoflow.site_name', config('app.name')) }}</span>
            <span>产品案例库</span>
        </div>
    </footer>

    <script>
        if (window.lucide) {
            window.lucide.createIcons();
        }
    </script>
</body>
</html>
