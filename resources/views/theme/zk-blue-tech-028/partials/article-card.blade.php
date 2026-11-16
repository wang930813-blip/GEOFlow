@php
    $publishedAt = data_get($article, 'published_at');
@endphp
<article class="article-card">
    <p class="item-meta">
        <span>{{ data_get($article, 'category.name') }}</span>
        @if($publishedAt)
            <time datetime="{{ \Illuminate\Support\Carbon::parse($publishedAt)->toAtomString() }}">{{ \Illuminate\Support\Carbon::parse($publishedAt)->format('Y-m-d') }}</time>
        @endif
    </p>
    <h2><a href="{{ \App\Support\Site\SitePageUrl::to('article', ['slug' => data_get($article, 'slug')]) }}">{{ data_get($article, 'title') }}</a></h2>
    <p>{{ data_get($article, 'excerpt') }}</p>
</article>
