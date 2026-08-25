@php
    $sidebarHotArticles = collect($hotArticles ?? [])->take(6);
    $latestArticles = method_exists($articles ?? null, 'getCollection')
        ? $articles->getCollection()->take(6)
        : collect($articles ?? [])->take(6);
    $sidebarArticles = $sidebarHotArticles->isNotEmpty() ? $sidebarHotArticles : $latestArticles;
    $navCategoriesCollection = collect($navCategories ?? []);
    $feedTitle = trim((string) (($siteSubtitle ?? '') !== '' ? $siteSubtitle : ($siteTitle ?? 'GEOFlow')));
    $feedDescription = trim((string) ($siteDescription ?? ''));
@endphp
@php
    $showCategoryCloud = $showCategoryCloud ?? true;
@endphp
<aside class="tx-sidebar">
    @if(!empty($showFeedPanel))
        <section class="tx-panel tx-signal-panel">
            <span class="tx-eyebrow">{{ $siteTitle }}</span>
            <h2>{{ $feedTitle }}</h2>
            @if($feedDescription !== '')
                <p>{{ $feedDescription }}</p>
            @endif
        </section>
    @endif

    <section class="tx-panel">
        <div class="tx-section-heading">
            <span>{{ $sidebarHotArticles->isNotEmpty() ? '热门资讯' : '最新资讯' }}</span>
        </div>
        <div class="tx-rank-list">
            @forelse($sidebarArticles as $hotArticle)
                <a href="{{ route('site.article', $hotArticle->slug) }}">
                    <span>{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                    <strong>{{ $hotArticle->title }}</strong>
                </a>
            @empty
                <p class="tx-muted">{{ __('site.home_empty_title') }}</p>
            @endforelse
        </div>
    </section>

    @if($showCategoryCloud && $navCategoriesCollection->isNotEmpty())
        <section class="tx-panel" id="txSidebarCategories">
            <div class="tx-section-heading">
                <span>{{ __('front.nav.categories') }}</span>
            </div>
            <div class="tx-category-cloud">
                @foreach($navCategoriesCollection as $categoryItem)
                    <a href="{{ route('site.category', $categoryItem->slug) }}">{{ $categoryItem->name }}</a>
                @endforeach
            </div>
        </section>
    @endif
</aside>
