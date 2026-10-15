@php
    $title = data_get($article, 'title', '资讯标题');
    $summary = data_get($article, 'excerpt', data_get($article, 'summary', ''));
    $slug = data_get($article, 'slug', '');
    $category = data_get($article, 'category.name', data_get($article, 'category_name', '资讯'));
    $published = data_get($article, 'published_at');
    $publishedLabel = is_object($published) && method_exists($published, 'format') ? $published->format('Y-m-d') : (is_string($published) ? $published : '');
    $href = $slug !== '' ? \App\Support\Site\SitePageUrl::to('article', ['slug' => $slug]) : \App\Support\Site\SitePageUrl::to('news');
@endphp
<article class="article-card">
    <p class="item-meta">{{ $category }}@if($publishedLabel !== '') · {{ $publishedLabel }}@endif</p>
    <h3><a href="{{ $href }}">{{ $title }}</a></h3>
    @if($summary)<p>{{ $summary }}</p>@endif
</article>
