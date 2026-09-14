@php
    $categoryCards = collect($categories ?? []);
@endphp

<div id="mainContent" class="cg-shell cg-page">
    <section class="cg-page-hero cg-page-hero--wide">
        <span class="cg-eyebrow">{{ app()->getLocale() === 'zh_CN' ? '资讯中心' : 'Insights Center' }}</span>
        <h1>{{ app()->getLocale() === 'zh_CN' ? '最新资讯与专题内容' : 'Latest insights and topic updates' }}</h1>
        <p>{{ $pageDescription }}</p>
    </section>

    @if($categoryCards->isNotEmpty())
        <section class="cg-section">
            <div class="cg-section__head">
                <span class="cg-eyebrow">{{ app()->getLocale() === 'zh_CN' ? '内容分类' : 'Topics' }}</span>
                <h2>{{ app()->getLocale() === 'zh_CN' ? '快速进入内容专题' : 'Explore by topic' }}</h2>
            </div>
            <div class="cg-topic-grid">
                @foreach($categoryCards as $categoryItem)
                    <a href="{{ route('site.category', $categoryItem->slug) }}" class="cg-topic-card">
                        <span>{{ (int) ($categoryItem->published_count ?? 0) }}</span>
                        <strong>{{ $categoryItem->name }}</strong>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <section class="cg-section">
        <div class="cg-section__head">
            <span class="cg-eyebrow">{{ __('site.home_latest') }}</span>
            <h2>{{ app()->getLocale() === 'zh_CN' ? '全部文章' : 'All articles' }}</h2>
        </div>
        <div class="cg-news-grid">
            @forelse($articles as $article)
                @include('theme.corporate-growth-20260914.partials.article-card', ['article' => $article])
            @empty
                <div class="cg-empty">{{ __('site.home_empty_title') }}</div>
            @endforelse
        </div>
        <div class="cg-pagination">{{ $articles->links() }}</div>
    </section>
</div>
