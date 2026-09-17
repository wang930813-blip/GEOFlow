@php
    $summary = \App\Support\Site\ArticleHtmlPresenter::cardSummary($article, 160);
    $coverImage = trim((string) ($article->cover_image ?? ''));
@endphp

<article class="article-card">
    <a class="article-card-link" href="{{ \App\Support\Site\SitePageUrl::to('article', ['slug' => $article->slug]) }}" aria-label="阅读资讯：{{ $article->title }}">
        <div class="article-image {{ $coverImage === '' ? 'article-image-placeholder' : '' }}">
            @if($coverImage !== '')
                <img src="{{ $coverImage }}" alt="{{ $article->title }}" loading="lazy">
            @else
                <span>资讯</span>
            @endif
            <span class="article-tag">{{ $article->category?->name ?? '资讯' }}</span>
        </div>
        <div class="article-body">
            @if($article->published_at)
                <time datetime="{{ $article->published_at->toIso8601String() }}">{{ $article->published_at->format('Y-m-d') }}</time>
            @endif
            <h3>{{ $article->title }}</h3>
            <p>{{ $summary !== '' ? $summary : '文章摘要暂未提供。' }}</p>
            <span class="text-link article-action">阅读全文 ↗</span>
        </div>
    </a>
</article>
