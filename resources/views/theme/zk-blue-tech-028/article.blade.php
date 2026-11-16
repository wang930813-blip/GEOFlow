@extends('theme.zk-blue-tech-028.layout')

@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $articleTitle = (string) data_get($article, 'title', '资讯详情');
    $publishedAt = data_get($article, 'published_at');
    $canonicalUrl = \App\Support\Site\SitePageUrl::to('article', ['slug' => data_get($article, 'slug')]);
    $articleJsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $articleTitle,
        'datePublished' => data_get($article, 'published_at'),
        'mainEntityOfPage' => $canonicalUrl,
    ];
    if (data_get($article, 'updated_at')) {
        $articleJsonLd['dateModified'] = data_get($article, 'updated_at');
    }
@endphp

@section('seo_title', $articleTitle . ' | ' . data_get($themeData, 'site.brand_name'))
@section('seo_description', $excerptPlain ?? '')

@push('head')
    <x-json-ld :data="$articleJsonLd" />
@endpush

@section('body')
    @include('theme.zk-blue-tech-028.partials.header', ['themeData' => $themeData, 'activeNav' => 'article'])
    <main id="main-content" class="article-page section-shell">
        <p class="article-back"><a class="text-link" href="{{ \App\Support\Site\SitePageUrl::to('news') }}">← 返回资讯中心</a></p>
        <article class="article-detail">
            <header class="article-header"><p class="eyebrow">{{ data_get($article, 'category.name') }}</p><h1>{{ $articleTitle }}</h1>@if($publishedAt)<p class="article-meta"><time datetime="{{ \Illuminate\Support\Carbon::parse($publishedAt)->toAtomString() }}">{{ \Illuminate\Support\Carbon::parse($publishedAt)->format('Y-m-d') }}</time></p>@endif @if($excerptPlain ?? false)<p class="article-summary">{{ $excerptPlain }}</p>@endif</header>
            <div class="article-content">{!! $contentHtml !!}</div>
            @if(!empty($tags))<ul class="article-tags" aria-label="资讯标签">@foreach($tags as $tag)<li>{{ data_get($tag, 'name', $tag) }}</li>@endforeach</ul>@endif
        </article>
        @if(isset($relatedArticles) && $relatedArticles->isNotEmpty())
            <section class="related-news" aria-labelledby="related-news-title"><div class="section-heading"><div><p class="eyebrow">延伸阅读</p><h2 id="related-news-title">相关资讯</h2></div></div><div class="news-grid">@foreach($relatedArticles as $article)@include('theme.zk-blue-tech-028.partials.article-card', ['article' => $article])@endforeach</div></section>
        @endif
    </main>
    @include('theme.zk-blue-tech-028.partials.footer', ['themeData' => $themeData])
@endsection
