@extends('theme.'.($activeThemeId ?? \App\Support\Site\SiteThemeViewResolver::activeThemeId()).'.layout')

@push('head')
    <link rel="stylesheet" href="{{ asset('themes/geoflow-template-shared/official-home.css') }}">
    @php
        $schemaAtContext = chr(64).'context';
        $schemaAtType = chr(64).'type';
        $schemaItems = [];
        foreach ((is_object($articles ?? null) && method_exists($articles, 'getCollection') ? $articles->getCollection() : collect($articles ?? []))->take(10) as $schemaArticle) {
            $schemaItems[] = [
                $schemaAtType => 'ListItem',
                'position' => count($schemaItems) + 1,
                'url' => route('site.article', $schemaArticle->slug),
                'name' => $schemaArticle->title,
            ];
        }
        $collectionSchema = [
            $schemaAtContext => 'https://schema.org',
            $schemaAtType => 'CollectionPage',
            'name' => $pageTitle,
            'description' => $pageDescription,
            'url' => $canonicalUrl ?? route('site.home'),
            'mainEntity' => [
                $schemaAtType => 'ItemList',
                'itemListElement' => $schemaItems,
            ],
        ];
    @endphp
    <x-json-ld :data="$collectionSchema" />
@endpush

@section('content')
    @php
        $homeArticles = is_object($articles ?? null) && method_exists($articles, 'getCollection')
            ? $articles->getCollection()
            : collect($articles ?? []);
        $featuredCollection = collect($featuredArticles ?? [])->filter();
        $hotCollection = collect($hotArticles ?? [])->filter();
        $isDefaultHome = (bool) ($isDefaultHome ?? false);
        $themeIdForVariant = (string) ($activeThemeId ?? \App\Support\Site\SiteThemeViewResolver::activeThemeId());
        $officialVariant = app(\App\Support\Site\OfficialHomeVariantCatalog::class)->forTheme($themeIdForVariant);
        $variantClass = 'gf-home--'.$officialVariant['key'];
        $layoutClass = 'gf-layout--'.$officialVariant['layout'];
        $heroTitle = trim((string) ($siteTitle ?? '')) !== '' ? trim((string) $siteTitle) : trim((string) ($siteName ?? config('app.name')));
        $heroSubtitle = trim((string) ($siteSubtitle ?? ''));
        $heroDescription = trim((string) ($siteDescription ?? ''));
        $cardSummaries = $cardSummaries ?? [];
        $carouselSlides = collect($homepageCarouselSlides ?? [])->filter(fn ($slide) => is_array($slide) && trim((string) ($slide['image_url'] ?? '')) !== '');
        $insightArticles = $featuredCollection
            ->concat($hotCollection)
            ->concat($homeArticles)
            ->filter()
            ->unique(fn ($article) => $article->id ?? spl_object_id($article))
            ->take(6);
        $primaryArticle = $insightArticles->first();
        $summaryFor = static function ($article) use ($cardSummaries): string {
            if (! $article) {
                return '';
            }

            $summary = trim((string) ($cardSummaries[$article->id] ?? $article->excerpt ?? ''));

            return $summary !== '' ? $summary : trim(strip_tags((string) ($article->content ?? '')));
        };
    @endphp

    @if($isDefaultHome)
        <div id="mainContent" class="gf-official-home {{ $variantClass }} {{ $layoutClass }}" data-gf-template="{{ $themeIdForVariant }}">
            <section class="gf-hero">
                <div class="gf-shell gf-hero__grid">
                    <div class="gf-hero__content">
                        <p class="gf-eyebrow">{{ $heroSubtitle !== '' ? $heroSubtitle : $officialVariant['kicker'] }}</p>
                        <div class="gf-template-mark">
                            <span>{{ $officialVariant['number'] }}</span>
                            <strong>{{ $officialVariant['name'] }}</strong>
                            <em>{{ $officialVariant['reference'] }}</em>
                        </div>
                        <h1>{{ $heroTitle }}</h1>
                        <p class="gf-hero__lead">
                            {{ $heroDescription !== '' ? $heroDescription : 'Build a credible official website for AI search visibility, brand content, and global growth.' }}
                        </p>
                        <div class="gf-hero__actions">
                            <a href="{{ route('site.contact') }}" class="gf-button gf-button--primary">
                                Contact us
                                <i data-lucide="arrow-right" aria-hidden="true"></i>
                            </a>
                            <a href="{{ route('site.news') }}" class="gf-button gf-button--ghost">
                                View insights
                            </a>
                        </div>
                        <div class="gf-hero__proof" aria-label="Official website signals">
                            @foreach($officialVariant['proof'] as $proofLabel)
                                <span>{{ $proofLabel }}</span>
                            @endforeach
                        </div>
                    </div>

                    <div class="gf-hero__visual" aria-label="GEOFlow official website preview">
                        @if($carouselSlides->isNotEmpty())
                            <div class="gf-carousel">
                                @foreach($carouselSlides->take(3) as $slide)
                                    <figure class="gf-carousel__slide {{ $loop->first ? 'is-active' : '' }}">
                                        @if(trim((string) ($slide['link_url'] ?? '')) !== '')
                                            <a href="{{ $slide['link_url'] }}">
                                                <img src="{{ $slide['image_url'] }}" alt="{{ $slide['title'] ?? $heroTitle }}" loading="{{ $loop->first ? 'eager' : 'lazy' }}">
                                            </a>
                                        @else
                                            <img src="{{ $slide['image_url'] }}" alt="{{ $slide['title'] ?? $heroTitle }}" loading="{{ $loop->first ? 'eager' : 'lazy' }}">
                                        @endif
                                        @if(trim((string) ($slide['title'] ?? '')) !== '')
                                            <figcaption>{{ $slide['title'] }}</figcaption>
                                        @endif
                                    </figure>
                                @endforeach
                            </div>
                        @else
                            <div class="gf-dashboard-card">
                                <div class="gf-dashboard-card__top">
                                    <span></span><span></span><span></span>
                                    <strong>{{ $officialVariant['name'] }} Console</strong>
                                </div>
                                <div class="gf-score-panel">
                                    <small>{{ $officialVariant['metrics_title'] }}</small>
                                    <strong>86.4</strong>
                                    <span>+18.7% this month</span>
                                </div>
                                <div class="gf-chart" aria-hidden="true">
                                    <i style="height: 42%"></i>
                                    <i style="height: 58%"></i>
                                    <i style="height: 51%"></i>
                                    <i style="height: 69%"></i>
                                    <i style="height: 74%"></i>
                                    <i style="height: 88%"></i>
                                </div>
                                <div class="gf-source-list">
                                    <div><span>01</span><p>Brand sources<strong>1,248</strong></p></div>
                                    <div><span>02</span><p>AI questions<strong>326</strong></p></div>
                                    <div><span>03</span><p>Content assets<strong>2,680</strong></p></div>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </section>

            <section class="gf-section">
                <div class="gf-shell">
                    <div class="gf-section__head">
                        <p class="gf-eyebrow">{{ $officialVariant['name'] }} framework</p>
                        <h2>{{ $officialVariant['section_title'] }}</h2>
                        <p>{{ $officialVariant['section_body'] }}</p>
                    </div>
                    <div class="gf-capability-grid">
                        @foreach($officialVariant['capabilities'] as $capability)
                            <article>
                                <span>{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                                <h3>{{ $capability['title'] }}</h3>
                                <p>{{ $capability['body'] }}</p>
                            </article>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="gf-section gf-metrics">
                <div class="gf-shell gf-metrics__grid">
                    <div>
                        <p class="gf-eyebrow">GEO operating signals</p>
                        <h2>{{ $officialVariant['metrics_title'] }}</h2>
                    </div>
                    @foreach($officialVariant['metrics'] as $metric)
                        <div class="gf-metric-card"><strong>{{ $metric['value'] }}</strong><span>{{ $metric['label'] }}</span></div>
                    @endforeach
                </div>
            </section>

            <section class="gf-section gf-insights">
                <div class="gf-shell">
                    <div class="gf-section__head gf-section__head--split">
                        <div>
                            <p class="gf-eyebrow">Insights and updates</p>
                            <h2>{{ __('site.home_latest') }}</h2>
                        </div>
                        <a href="{{ route('site.news') }}" class="gf-link">View all <i data-lucide="arrow-right" aria-hidden="true"></i></a>
                    </div>

                    @if($primaryArticle)
                        <div class="gf-featured-resource">
                            <div>
                                <p class="gf-eyebrow">{{ $primaryArticle->category?->name ?? __('front.nav.all_articles') }}</p>
                                <h3><a href="{{ route('site.article', $primaryArticle->slug) }}">{{ $primaryArticle->title }}</a></h3>
                                <p>{{ mb_substr($summaryFor($primaryArticle), 0, 180) }}</p>
                            </div>
                            <a href="{{ route('site.article', $primaryArticle->slug) }}" class="gf-button gf-button--dark">{{ __('site.home_read_more') }}</a>
                        </div>
                    @endif

                    <div class="gf-resource-grid">
                        @forelse($insightArticles->reject(fn ($article) => $primaryArticle && $article->id === $primaryArticle->id)->take(5) as $article)
                            <article class="gf-resource-card">
                                <small>{{ $article->category?->name ?? __('front.nav.all_articles') }}</small>
                                <h3><a href="{{ route('site.article', $article->slug) }}">{{ $article->title }}</a></h3>
                                <p>{{ mb_substr($summaryFor($article), 0, 110) }}</p>
                                <time datetime="{{ ($article->published_at ?? $article->created_at)?->toAtomString() }}">{{ ($article->published_at ?? $article->created_at)?->format('Y.m.d') }}</time>
                            </article>
                        @empty
                            <article class="gf-empty-card">
                                <span>Content runway</span>
                                <h3>Publish articles in the admin panel and they will appear here as official website resources.</h3>
                                <p>The homepage shell is ready; content can be filled by normal article publishing.</p>
                            </article>
                        @endforelse
                    </div>
                </div>
            </section>

            <section class="gf-section gf-cta">
                <div class="gf-shell gf-cta__card">
                    <div>
                        <p class="gf-eyebrow">{{ $officialVariant['name'] }} conversion path</p>
                        <h2>Turn this official-site variant into a measurable brand growth hub.</h2>
                    </div>
                    <a href="{{ route('site.contact') }}" class="gf-button gf-button--primary">Contact us</a>
                </div>
            </section>
        </div>
    @else
        <section class="gf-official-home gf-results">
            <div class="gf-shell">
                <div class="gf-results__head">
                    <p class="gf-eyebrow">
                        @if($search !== '')
                            Search results
                        @elseif($categoryMissing)
                            Category status
                        @elseif($category)
                            Category
                        @else
                            Resource library
                        @endif
                    </p>
                    <h1>{{ $viewTitle }}</h1>
                    <p>{{ $pageDescription }}</p>
                    <form method="get" action="{{ route('site.home') }}" class="gf-results__search" role="search">
                        <label class="sr-only" for="gf-result-search">{{ __('site.search_placeholder') }}</label>
                        <input id="gf-result-search" type="search" name="search" value="{{ $search }}" placeholder="{{ __('site.search_placeholder') }}">
                        <button type="submit">{{ __('site.search_button') }}</button>
                    </form>
                </div>

                <div class="gf-result-list">
                    @forelse($articles as $article)
                        <article class="gf-result-card">
                            <div>
                                <small>{{ $article->category?->name ?? __('front.nav.all_articles') }}</small>
                                <h2><a href="{{ route('site.article', $article->slug) }}">{{ $article->title }}</a></h2>
                                <p>{{ mb_substr($summaryFor($article), 0, 150) }}</p>
                            </div>
                            <time datetime="{{ ($article->published_at ?? $article->created_at)?->toAtomString() }}">{{ ($article->published_at ?? $article->created_at)?->format('Y.m.d') }}</time>
                        </article>
                    @empty
                        <div class="gf-empty-card gf-empty-card--wide">
                            <span>0 results</span>
                            <h2>{{ __('site.home_empty_title') }}</h2>
                            <p>Try a shorter keyword or return to the homepage.</p>
                        </div>
                    @endforelse
                </div>

                <div class="gf-pagination">{{ $articles->links() }}</div>
            </div>
        </section>
    @endif
@endsection
