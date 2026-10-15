@extends('theme.qcx-yango-it-013.layout')

@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $activeNav = 'news';
    $pageTitle = '资讯 | ' . $themeData['site']['brand_name'];
    $pageDescription = '平台动态资讯列表。';
    $canonicalUrl = \App\Support\Site\SitePageUrl::to('news');
@endphp

@section('content')
<section class="page-content">
    <div class="page-header">
        <p class="eyebrow">资讯</p>
        <h1>近期内容</h1>
        <p class="lead">这里只展示平台提供的真实文章标题、摘要、分类和发布时间。</p>
    </div>
    <div class="article-grid">
        @forelse($articles ?? [] as $article)
            @include('theme.qcx-yango-it-013.partials.article-card', ['article' => $article])
        @empty
            <p class="article-status">暂无资讯内容。</p>
        @endforelse
    </div>
    @if(isset($articles) && method_exists($articles, 'hasPages') && $articles->hasPages())
        <nav class="article-pagination" aria-label="资讯分页">{{ $articles->links() }}</nav>
    @endif
</section>
@endsection
