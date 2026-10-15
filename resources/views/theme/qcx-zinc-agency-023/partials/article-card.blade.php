@php($article = $article ?? null)
<article class="article-card"><p class="item-meta">{{ $article->category->name ?? '资讯' }} · {{ $article->published_at ?? '' }}</p><h3><a href="{{ \App\Support\Site\SitePageUrl::to('article', ['slug' => $article->slug ?? '']) }}">{{ $article->title ?? '资讯标题' }}</a></h3><p>{{ $article->excerpt ?? $article->summary ?? '暂无资讯摘要。' }}</p></article>
