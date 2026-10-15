@php
    $slug = is_array($article) ? ($article['slug'] ?? '') : ($article->slug ?? '');
    $title = is_array($article) ? ($article['title'] ?? '') : ($article->title ?? '');
    $summary = is_array($article) ? ($article['summary'] ?? '') : ($article->summary ?? '');
    $category = is_array($article) ? ($article['category']['name'] ?? '') : ($article->category->name ?? '');
    $publishedAt = is_array($article) ? ($article['published_at'] ?? '') : ($article->published_at ?? '');
@endphp
<article class="article-card"><p class="item-meta">{{ $category }} · {{ $publishedAt }}</p><h3><a href="{{ \App\Support\Site\SitePageUrl::to('article', ['slug' => $slug]) }}">{{ $title }}</a></h3><p>{{ $summary }}</p></article>

