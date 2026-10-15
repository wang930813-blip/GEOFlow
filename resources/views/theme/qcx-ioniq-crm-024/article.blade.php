@extends('theme.qcx-ioniq-crm-024.layout')
@php($themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars()))
@php($activeNav = 'news')
@php($pageTitle = $article->title ?? ('资讯详情 | ' . $themeData['site']['brand_name']))
@php($pageDescription = $excerptPlain ?? ($article->summary ?? '资讯详情。'))
@php($canonicalUrl = \App\Support\Site\SitePageUrl::to('article', ['slug' => $article->slug ?? '']))
@section('content')
<section class="page-hero"><div class="shell"><p class="eyebrow">{{ $article->category->name ?? '资讯详情' }}</p><h1>{{ $article->title ?? '资讯详情' }}</h1><p class="lead">{{ $article->summary ?? '' }}</p></div></section>
<section class="page-content article-detail"><div class="shell"><a class="back-link" href="{{ \App\Support\Site\SitePageUrl::to('news') }}">← 返回资讯中心</a><p class="item-meta"><time datetime="{{ $article->published_at ?? '' }}">{{ $article->published_at ?? '' }}</time></p><div class="prose">{!! $contentHtml ?? '' !!}</div></div></section>
@endsection
