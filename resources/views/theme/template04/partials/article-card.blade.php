@php($published = $article->published_at ?? $article->created_at)
<article class="panel article-card">
    <p class="item-meta">{{ $article->category?->name ?? '资讯' }} · {{ $published?->format('Y-m-d') }}</p>
    <h3><a href="{{ \App\Support\Site\SitePageUrl::to('article', ['slug' => $article->slug]) }}">{{ $article->title }}</a></h3>
    <p>{{ trim((string) (($cardSummaries[$article->id] ?? '') ?: $article->excerpt ?? '')) }}</p>
</article>
