@extends('theme.qcx-midnight-it-015.layout')
@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $about = $themeData['about'];
    $activeNav = 'about';
    $pageTitle = $themeData['seo']['about']['title'];
    $pageDescription = $themeData['seo']['about']['description'];
    $canonicalUrl = \App\Support\Site\SitePageUrl::to('about');
@endphp
@section('content')
<section class="page-banner"><p class="eyebrow">关于我们</p><h1>{{ $about['title'] }}</h1><p class="lead">{{ $about['summary'] }}</p></section>
<article class="page-content about-page-media">
    @if(!empty($about['image']['src']))
        <img src="{{ $about['image']['src'] }}" alt="{{ $about['image']['alt'] ?? '' }}" @if(!empty($about['image']['width'])) width="{{ $about['image']['width'] }}" @endif @if(!empty($about['image']['height'])) height="{{ $about['image']['height'] }}" @endif loading="lazy">
    @else
        <img src="{{ asset('themes/qcx-midnight-it-015/assets/about1.jpg') }}" alt="协作场景示意图" width="490" height="540" loading="lazy">
    @endif
    <div class="prose">{!! $about['content'] !!}</div>
</article>
@endsection

