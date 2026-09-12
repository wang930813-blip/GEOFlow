<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $pageTitle }} - {{ config('geoflow.site_name', config('app.name')) }}</title>
    <meta name="description" content="{{ $pageDescription }}">
    <script src="{{ asset('js/tailwindcss.play-cdn.js') }}"></script>
    <script src="{{ asset('js/lucide.min.js') }}"></script>
    @php
        $caseRoutes = $caseRoutes ?? ['index' => 'product-cases.index', 'show' => 'product-cases.show', 'home' => 'site.home'];
        $schemaAtContext = chr(64).'context';
        $schemaAtType = chr(64).'type';
        $schemaItems = [];
        foreach ($cases->getCollection()->take(10) as $schemaCase) {
            $schemaItems[] = [
                $schemaAtType => 'ListItem',
                'position' => count($schemaItems) + 1,
                'url' => route($caseRoutes['show'], ['slug' => $schemaCase->slug]),
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
    'name' => 'Product Cases',
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
            <a href="{{ route($caseRoutes['index']) }}" class="inline-flex items-center gap-2 text-lg font-semibold">
                <span class="flex h-9 w-9 items-center justify-center rounded-md bg-slate-950 text-white">
                    <i data-lucide="briefcase-business" class="h-4 w-4"></i>
                </span>
                Product Cases
            </a>
            <a href="{{ route($caseRoutes['home']) }}" class="inline-flex items-center gap-1 text-sm font-medium text-slate-600 hover:text-slate-950">
                <i data-lucide="home" class="h-4 w-4"></i>
                Back to Home
            </a>
        </div>
    </header>

    <main>
        <section class="border-b border-slate-200 bg-white">
            <div class="mx-auto grid max-w-7xl gap-8 px-4 py-12 sm:px-6 lg:grid-cols-[1fr_0.72fr] lg:px-8 lg:py-16">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-wide text-orange-600">GEO Case Library</p>
                    <h1 class="mt-3 max-w-3xl text-3xl font-semibold tracking-normal text-slate-950 sm:text-5xl">Product Cases</h1>
                    <p class="mt-5 max-w-2xl text-base leading-8 text-slate-600">
                        Explore real brand case studies across GEO content operations, AI answer visibility, brand diagnostics, and measurable content growth.
                    </p>
                </div>
                <div class="grid gap-3 sm:grid-cols-3 lg:content-end">
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <div class="text-2xl font-semibold text-slate-950">{{ $cases->total() }}</div>
                        <div class="mt-1 text-sm text-slate-500">Published Cases</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <div class="text-2xl font-semibold text-slate-950">{{ count($filterOptions['industries']) }}</div>
                        <div class="mt-1 text-sm text-slate-500">Industries</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <div class="text-2xl font-semibold text-slate-950">{{ count($filterOptions['regions']) }}</div>
                        <div class="mt-1 text-sm text-slate-500">Global Regions</div>
                    </div>
                </div>
            </div>
        </section>

        <section class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <form method="GET" action="{{ route($caseRoutes['index']) }}" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_auto]">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-slate-500">Search</span>
                        <input name="keyword" value="{{ $filters['keyword'] }}" placeholder="Case title / brand name" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-orange-500 focus:ring-2 focus:ring-orange-100">
                    </label>
                    <div class="flex items-end gap-2">
                        <button type="submit" class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-slate-950 px-4 text-sm font-medium text-white transition hover:bg-slate-800">
                            <i data-lucide="search" class="h-4 w-4"></i>
                            Filter
                        </button>
                        <a href="{{ route($caseRoutes['index']) }}" class="inline-flex h-10 items-center justify-center rounded-md border border-slate-200 bg-white px-3 text-sm font-medium text-slate-600 transition hover:bg-slate-50">Reset</a>
                    </div>
                </div>

                <div class="mt-4 divide-y divide-slate-100 rounded-md border border-slate-100 bg-slate-50/80">
                    <div class="grid gap-3 px-3 py-3 md:grid-cols-[64px_1fr]">
                        <div class="pt-1 text-sm font-semibold text-slate-700">Industry</div>
                        <div class="flex flex-wrap gap-2">
                            @php $industryActive = $filters['industry'] === ''; @endphp
                            <label class="cursor-pointer">
                                <input type="radio" name="industry" value="" class="peer sr-only" @checked($industryActive)>
                                <span class="inline-flex h-8 items-center rounded-md border border-transparent bg-white px-3 text-sm text-slate-600 transition hover:border-slate-200 hover:text-slate-950 peer-checked:border-orange-500 peer-checked:bg-orange-50 peer-checked:font-medium peer-checked:text-orange-700">All</span>
                            </label>
                            @foreach($filterOptions['industries'] as $industry)
                                @php $industryActive = $filters['industry'] === $industry; @endphp
                                <label class="cursor-pointer">
                                    <input type="radio" name="industry" value="{{ $industry }}" class="peer sr-only" @checked($industryActive)>
                                    <span class="inline-flex h-8 items-center rounded-md border border-transparent bg-white px-3 text-sm text-slate-600 transition hover:border-slate-200 hover:text-slate-950 peer-checked:border-orange-500 peer-checked:bg-orange-50 peer-checked:font-medium peer-checked:text-orange-700">{{ $industry }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <div class="grid gap-3 px-3 py-3 md:grid-cols-[64px_1fr]">
                        <div class="pt-1 text-sm font-semibold text-slate-700">Region</div>
                        <div class="flex flex-wrap gap-2">
                            @php $regionActive = $filters['region'] === ''; @endphp
                            <label class="cursor-pointer">
                                <input type="radio" name="region" value="" class="peer sr-only" @checked($regionActive)>
                                <span class="inline-flex h-8 items-center rounded-md border border-transparent bg-white px-3 text-sm text-slate-600 transition hover:border-slate-200 hover:text-slate-950 peer-checked:border-orange-500 peer-checked:bg-orange-50 peer-checked:font-medium peer-checked:text-orange-700">All</span>
                            </label>
                            @foreach($filterOptions['regions'] as $region)
                                @php $regionActive = $filters['region'] === $region; @endphp
                                <label class="cursor-pointer">
                                    <input type="radio" name="region" value="{{ $region }}" class="peer sr-only" @checked($regionActive)>
                                    <span class="inline-flex h-8 items-center rounded-md border border-transparent bg-white px-3 text-sm text-slate-600 transition hover:border-slate-200 hover:text-slate-950 peer-checked:border-orange-500 peer-checked:bg-orange-50 peer-checked:font-medium peer-checked:text-orange-700">{{ $region }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>
            </form>

            <div class="mt-8 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                @forelse($cases as $case)
                    @php
                        $metrics = $caseMetrics[(int) $case->id] ?? [];
                        $industryLabel = $case->displayIndustry();
                        $regionLabel = $case->displayRegion();
                    @endphp
                    <article class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
                        <a href="{{ route($caseRoutes['show'], ['slug' => $case->slug]) }}" class="block">
                            <div class="relative aspect-[16/9] overflow-hidden bg-slate-900">
                                @if(trim((string) $case->cover_url) !== '')
                                    <img src="{{ $case->cover_url }}" alt="{{ $case->title }}" class="h-full w-full object-cover">
                                @else
                                    <div class="flex h-full w-full items-center justify-center bg-[linear-gradient(135deg,#111827,#334155_45%,#d97706)] text-white">
                                        <i data-lucide="line-chart" class="h-10 w-10"></i>
                                    </div>
                                @endif
                                <div class="absolute left-4 top-4 flex flex-wrap gap-2">
                                    @if($industryLabel !== '')
                                        <span class="rounded-md bg-white/90 px-2.5 py-1 text-xs font-medium text-slate-800">{{ $industryLabel }}</span>
                                    @endif
                                    @if($regionLabel !== '')
                                        <span class="rounded-md bg-white/90 px-2.5 py-1 text-xs font-medium text-slate-800">{{ $regionLabel }}</span>
                                    @endif
                                </div>
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
                                    <div class="mt-0.5 truncate text-xs text-slate-500">{{ collect([$industryLabel, $regionLabel])->filter()->implode(' / ') ?: 'Case Brand' }}</div>
                                </div>
                            </div>

                            <h2 class="mt-4 line-clamp-2 min-h-14 text-lg font-semibold leading-7 text-slate-950">
                                <a href="{{ route($caseRoutes['show'], ['slug' => $case->slug]) }}" class="hover:text-orange-700">{{ $case->title }}</a>
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

                        </div>
                    </article>
                @empty
                    <div class="md:col-span-2 xl:col-span-3 rounded-lg border border-dashed border-slate-300 bg-white px-6 py-14 text-center">
                        <i data-lucide="folder-open" class="mx-auto h-10 w-10 text-slate-300"></i>
                        <h2 class="mt-4 text-base font-semibold text-slate-900">No product cases yet</h2>
                        <p class="mt-2 text-sm text-slate-500">Adjust the filters or check back after new cases are published.</p>
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
            <span>Product Case Library</span>
        </div>
    </footer>

    <script>
        if (window.lucide) {
            window.lucide.createIcons();
        }
    </script>
</body>
</html>
