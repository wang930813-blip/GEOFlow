@extends('theme.qcx-blueprint-it-020.layout')
@php($themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars()))
@php($about = $themeData['about'])
@php($activeNav = 'about')
@php($pageTitle = $themeData['seo']['about']['title'])
@php($pageDescription = $themeData['seo']['about']['description'])
@php($canonicalUrl = \App\Support\Site\SitePageUrl::to('about'))
@section('content')
<section class="page-hero"><div class="shell"><p class="eyebrow">关于我们</p><h1>{{ $about['title'] }}</h1><p class="lead">{{ $about['summary'] }}</p></div></section>
<article class="page-content"><div class="shell split-section"><div class="split-media about-media"><img src="{{ !empty($about['image']['src']) ? $about['image']['src'] : asset('themes/qcx-blueprint-it-020/assets/reference/about.jpg') }}" alt="{{ $about['image']['alt'] ?? '团队使用数字工具开展工作的插画' }}" width="600" height="500" loading="lazy"></div><div class="prose">{!! $about['content'] !!}</div></div></article>
@endsection
