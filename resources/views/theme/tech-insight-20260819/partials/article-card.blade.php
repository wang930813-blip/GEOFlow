@php
    /** @var \App\Models\Article $article */
    $summaryRaw = (string) ($cardSummaries[$article->id] ?? '');
    $summary = trim(preg_replace([
        '/!\[[^\]]*]\([^)]+\)/u',
        '/\[[^\]]+]\([^)]+\)/u',
        '/[`*_>#|~-]+/u',
        '/\s+/u',
    ], [' ', ' ', ' ', ' '], strip_tags($summaryRaw)) ?? '');
    $pub = $article->published_at ?? $article->created_at;
    $categoryName = $article->category?->name ?? __('front.nav.all_articles');
    $initial = mb_substr($categoryName, 0, 1);
    $coverImage = \App\Support\GeoFlow\ImageUrlNormalizer::toPublicUrl((string) ($article->cover_image ?? ''));
@endphp
<article class="tx-article-card">
    <a href="{{ route('site.article', $article->slug) }}" class="tx-card-visual{{ $coverImage !== '' ? ' has-image' : '' }}" aria-hidden="true">
        @if($coverImage !== '')
            <img src="{{ $coverImage }}" alt="" loading="lazy" referrerpolicy="no-referrer">
        @else
            <span>{{ $initial }}</span>
        @endif
    </a>
    <div class="tx-card-body">
        <div class="tx-card-meta">
            @if(!empty($showFeaturedBadge))
                <span class="tx-chip">{{ __('site.home_featured_badge') }}</span>
            @endif
            @if($article->category)
                <a href="{{ route('site.category', $article->category->slug) }}" class="tx-chip">{{ $article->category->name }}</a>
            @endif
            <time datetime="{{ $pub?->toAtomString() }}">{{ $pub?->format('Y-m-d') }}</time>
        </div>
        <h2 class="tx-card-title">
            <a href="{{ route('site.article', $article->slug) }}">{{ $article->title }}</a>
        </h2>
        @if($summary !== '')
            <p class="tx-card-summary">{{ $summary }}</p>
        @endif
        <a href="{{ route('site.article', $article->slug) }}" class="tx-link">
            {{ __('site.home_read_more') }}
            <i data-lucide="arrow-right" aria-hidden="true"></i>
        </a>
    </div>
</article>
