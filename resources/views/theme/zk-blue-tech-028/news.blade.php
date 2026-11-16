@extends('theme.zk-blue-tech-028.layout')

@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $sourceLabels = ['latest' => '最新资讯', 'featured' => '精选资讯', 'hot' => '热门资讯'];
    $sourceLabel = $sourceLabels[$newsSource ?? 'latest'] ?? '资讯中心';
@endphp

@section('seo_title', data_get($themeData, 'site.brand_name') . ' | 资讯中心')
@section('seo_description', data_get($themeData, 'site.brand_tagline'))

@section('body')
    @include('theme.zk-blue-tech-028.partials.header', ['themeData' => $themeData, 'activeNav' => 'news'])
    <main id="main-content">
        <section class="page-hero"><div class="section-shell page-hero-inner"><p class="eyebrow eyebrow-light">资讯中心</p><h1>近期资讯与动态</h1><p class="page-lead">{{ $sourceLabel }}</p><span class="page-hero-code" aria-hidden="true">NEWS</span></div><div class="page-hero-grid" aria-hidden="true"></div></section>
        <section class="news-page section-shell" aria-labelledby="news-list-title">
            <h2 id="news-list-title" class="visually-hidden">{{ $sourceLabel }}</h2>
            <div class="news-grid">
                @forelse(($articles ?? []) as $article)
                    @include('theme.zk-blue-tech-028.partials.article-card', ['article' => $article])
                @empty
                    <p class="empty-state">暂无资讯内容。</p>
                @endforelse
            </div>
            @if(is_object($articles ?? null) && method_exists($articles, 'links'))
                <nav class="article-pagination" aria-label="资讯分页">{{ $articles->links() }}</nav>
            @endif
        </section>
    </main>
    @include('theme.zk-blue-tech-028.partials.footer', ['themeData' => $themeData])
@endsection
