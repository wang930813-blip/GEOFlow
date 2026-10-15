@extends('theme.qcx-seo-navy-022.layout')
@php($themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars()))
@php($activeNav = 'news')
@php($pageTitle = '资讯中心 | ' . $themeData['site']['brand_name'])
@php($pageDescription = '浏览近期发布的资讯内容。')
@php($canonicalUrl = \App\Support\Site\SitePageUrl::to('news'))
@section('content')
<section class="page-hero"><div class="shell"><p class="eyebrow">最新资讯</p><h1>资讯与动态</h1><p class="lead">查看平台提供的真实文章、行业内容和品牌动态。</p></div></section>
<section class="page-content"><div class="shell"><div class="article-grid">@forelse($articles ?? [] as $article)@include('theme.qcx-seo-navy-022.partials.article-card', ['article' => $article])@empty<p class="article-status">暂无资讯内容。</p>@endforelse</div>@if(isset($articles) && method_exists($articles, 'links'))<div class="article-pagination">{{ $articles->links() }}</div>@endif</div></section>
@endsection
