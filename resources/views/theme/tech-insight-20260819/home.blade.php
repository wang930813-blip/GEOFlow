@extends('theme.tech-insight-20260819.layout')

@push('head')
    @php
        $schemaAtContext = chr(64).'context';
        $schemaAtType = chr(64).'type';
        $schemaItems = [];
        foreach ((method_exists($articles, 'getCollection') ? $articles->getCollection() : collect($articles))->take(10) as $schemaArticle) {
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
    <script type="application/ld+json">
        {!! json_encode($collectionSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
    </script>
@endpush

@section('content')
    @php
        $homeArticles = method_exists($articles, 'getCollection') ? $articles->getCollection() : collect($articles);
        $isDefaultHome = $search === '' && !$category && !$categoryMissing;
        $bannerSlides = collect([
            [
                'image_url' => asset('themes/tech-insight-20260819/assets/tech-banner-service.png'),
                'title' => $siteTitle,
            ],
            [
                'image_url' => asset('themes/tech-insight-20260819/assets/tech-banner-future.png'),
                'title' => $siteTitle,
            ],
        ]);
        $spotlightArticle = $isDefaultHome ? ($featuredArticles->first() ?: $homeArticles->first()) : null;
        $spotlightImage = $spotlightArticle
            ? \App\Support\GeoFlow\ImageUrlNormalizer::toPublicUrl((string) ($spotlightArticle->cover_image ?? ''))
            : '';
        $spotlightSummary = $spotlightArticle ? trim((string) ($cardSummaries[$spotlightArticle->id] ?? '')) : '';
        $feedArticles = $isDefaultHome && $spotlightArticle
            ? $homeArticles->reject(fn ($item) => $item->id === $spotlightArticle->id)
            : $homeArticles;
    @endphp

    <div id="mainContent" class="tx-shell tx-home-shell">
        @if($isDefaultHome)
            <section class="tx-banner-carousel" data-home-poster-carousel>
                @foreach($bannerSlides as $slide)
                    @php
                        $slideTitle = trim((string) ($slide['title'] ?? ''));
                        $slideLink = trim((string) ($slide['link_url'] ?? ''));
                        $slideImage = trim((string) ($slide['image_url'] ?? ''));
                        $slideAlt = $slideTitle !== '' ? $slideTitle : $siteTitle;
                    @endphp
                    @if($slideLink !== '')
                        <a href="{{ $slideLink }}" class="tx-banner-slide {{ $loop->first ? 'is-active' : '' }}" data-home-poster-slide>
                    @else
                        <div class="tx-banner-slide {{ $loop->first ? 'is-active' : '' }}" data-home-poster-slide>
                    @endif
                        <img src="{{ $slideImage }}" alt="{{ $slideAlt }}" loading="lazy" referrerpolicy="no-referrer">
                    @if($slideLink !== '')
                        </a>
                    @else
                        </div>
                    @endif
                @endforeach

                @if($bannerSlides->count() > 1)
                    <div class="tx-banner-dots" aria-hidden="true">
                        @foreach($bannerSlides as $slide)
                            <button type="button" class="{{ $loop->first ? 'is-active' : '' }}" data-home-poster-dot></button>
                        @endforeach
                    </div>
                @endif
            </section>

            <div id="txContentGrid" class="tx-content-grid">
                <section class="tx-content-main">
                    <div class="tx-home-section-head">
                        <div>
                            <span class="tx-eyebrow">资讯</span>
                            <h3>最新资讯</h3>
                        </div>
                    </div>

                    @if($spotlightArticle)
                        @php
                            $spotlightPub = $spotlightArticle->published_at ?? $spotlightArticle->created_at;
                        @endphp
                        <article class="tx-spotlight-card">
                            <a href="{{ route('site.article', $spotlightArticle->slug) }}" class="tx-spotlight-media{{ $spotlightImage !== '' ? ' has-image' : '' }}">
                                @if($spotlightImage !== '')
                                    <img src="{{ $spotlightImage }}" alt="{{ $spotlightArticle->title }}" loading="lazy" referrerpolicy="no-referrer">
                                @else
                                    <span>{{ mb_substr($spotlightArticle->title, 0, 1) }}</span>
                                @endif
                            </a>
                            <div class="tx-spotlight-body">
                                <div class="tx-card-meta tx-spotlight-meta">
                                    <span class="tx-chip">{{ __('site.home_featured_badge') }}</span>
                                    @if($spotlightArticle->category)
                                        <a href="{{ route('site.category', $spotlightArticle->category->slug) }}" class="tx-chip">
                                            {{ $spotlightArticle->category->name }}
                                        </a>
                                    @endif
                                    <time datetime="{{ $spotlightPub?->toAtomString() }}">{{ $spotlightPub?->format('Y-m-d') }}</time>
                                </div>
                                <h3>
                                    <a href="{{ route('site.article', $spotlightArticle->slug) }}">{{ $spotlightArticle->title }}</a>
                                </h3>
                                @if($spotlightSummary !== '')
                                    <p class="tx-spotlight-summary">{{ $spotlightSummary }}</p>
                                @endif
                                <div class="tx-card-actions">
                                    <a href="{{ route('site.article', $spotlightArticle->slug) }}" class="tx-link">
                                        {{ __('site.home_read_more') }}
                                        <i data-lucide="arrow-right" aria-hidden="true"></i>
                                    </a>
                                </div>
                            </div>
                        </article>
                    @endif

                    @if($feedArticles->isNotEmpty())
                        <div id="txArticleGrid" class="tx-article-grid">
                            @foreach($feedArticles as $article)
                                @include('theme.tech-insight-20260819.partials.article-card', ['article' => $article])
                            @endforeach
                        </div>
                    @endif

                    <div class="tx-pagination">
                        {{ $articles->links() }}
                    </div>
                </section>

                @include('theme.tech-insight-20260819.partials.sidebar', ['showFeedPanel' => $isDefaultHome, 'showCategoryCloud' => false])
            </div>
        @else
            <div class="tx-page-head">
                @if($search !== '')
                    <span class="tx-eyebrow">{{ __('site.search_button') }}</span>
                    <h1>{{ __('site.search_breadcrumb', ['term' => $search]) }}</h1>
                    <p>{{ $pageDescription }}</p>
                @elseif($categoryMissing)
                    <span class="tx-eyebrow">{{ __('site.category_not_found') }}</span>
                    <h1>{{ __('site.category_not_found') }}</h1>
                    <p>{{ $pageDescription }}</p>
                @else
                    <span class="tx-eyebrow">资讯</span>
                    <h1>{{ $viewTitle }}</h1>
                    <p>{{ $pageDescription }}</p>
                @endif
            </div>

            <div class="tx-content-grid tx-content-grid--compact">
                <section class="tx-content-main">
                    <section class="tx-section">
                        <div class="tx-section-heading">
                            <span>{{ $viewTitle }}</span>
                        </div>
                        <div class="tx-article-grid">
                            @forelse($articles as $article)
                                @include('theme.tech-insight-20260819.partials.article-card', ['article' => $article])
                            @empty
                                <div class="tx-empty">{{ __('site.home_empty_title') }}</div>
                            @endforelse
                        </div>
                    </section>

                    <div class="tx-pagination">
                        {{ $articles->links() }}
                    </div>
                </section>

                @include('theme.tech-insight-20260819.partials.sidebar', ['showFeedPanel' => false])
            </div>
        @endif
    </div>
@endsection
