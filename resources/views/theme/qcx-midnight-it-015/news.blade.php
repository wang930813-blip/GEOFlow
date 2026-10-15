@extends('theme.qcx-midnight-it-015.layout')
@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $activeNav = 'news';
    $pageTitle = '资讯中心 | ' . $themeData['site']['brand_name'];
    $pageDescription = '阅读近期发布的资讯内容。';
    $canonicalUrl = \App\Support\Site\SitePageUrl::to('news');
@endphp
@section('content')
<section class="page-banner"><p class="eyebrow">资讯中心</p><h1>资讯与动态</h1><p class="lead">阅读近期发布的内容。</p></section>
<section class="page-content" aria-label="资讯列表">
    <div class="item-grid">@forelse($articles ?? [] as $article)@include('theme.qcx-midnight-it-015.partials.article-card', ['article' => $article])@empty<p class="article-status">暂无资讯内容。</p>@endforelse</div>
    @if(isset($articles) && method_exists($articles, 'hasPages') && $articles->hasPages())<nav class="article-pagination" aria-label="资讯分页">{{ $articles->links() }}</nav>@endif
</section>
@endsection

