@php
    $isDefaultHome = $search === '' && ! $category && ! $categoryMissing && (int) request('page', 1) === 1;
@endphp

<div id="mainContent" class="cg-shell cg-page">
    @if($isDefaultHome)
        <section class="cg-page-hero">
            <span class="cg-eyebrow">{{ __('site.home_latest') }}</span>
            <h1>{{ $siteTitle }}</h1>
            @if($siteDescription !== '')
                <p>{{ $siteDescription }}</p>
            @endif
        </section>
    @else
        <section class="cg-page-hero">
            <span class="cg-eyebrow">{{ $search !== '' ? __('site.search_button') : ($categoryMissing ? __('site.category_not_found') : __('site.home_latest')) }}</span>
            <h1>{{ $viewTitle }}</h1>
            @if($pageDescription !== '')
                <p>{{ $pageDescription }}</p>
            @endif
        </section>
    @endif

    <section class="cg-section">
        <div class="cg-list-stack">
            @forelse($articles as $article)
                @include('theme.corporate-growth-20260914.partials.article-card', ['article' => $article])
            @empty
                <div class="cg-empty">{{ $search !== '' ? __('site.search_empty_title') : __('site.home_empty_title') }}</div>
            @endforelse
        </div>
        <div class="cg-pagination">{{ $articles->links() }}</div>
    </section>
</div>
