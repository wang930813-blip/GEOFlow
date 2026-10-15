@extends('theme.qcx-yango-it-013.layout')

@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $about = $themeData['about'];
    $activeNav = 'about';
    $pageTitle = $themeData['seo']['about']['title'];
    $pageDescription = $themeData['seo']['about']['description'];
    $canonicalUrl = \App\Support\Site\SitePageUrl::to('about');
@endphp

@section('content')
<section class="hero subhero">
    <div class="hero-copy">
        <p class="eyebrow">关于</p>
        <h1>{{ $about['title'] }}</h1>
        <p class="lead">{{ $about['summary'] }}</p>
    </div>
    <div class="hero-media"><img src="{{ asset('themes/qcx-yango-it-013/assets/about/about1.png') }}" alt="技术服务团队协作插画" width="681" height="588"></div>
</section>
<section class="page-content">
    <div class="prose">{!! $about['content'] !!}</div>
    <div class="value-grid">
        <article class="value-card"><h3>内容真实</h3><p>公开页面只展示已确认信息，缺少资料时使用中性说明。</p></article>
        <article class="value-card"><h3>结构清楚</h3><p>导航、标题、服务说明和资讯入口保持稳定，便于替换真实数据。</p></article>
        <article class="value-card"><h3>交付可接入</h3><p>HTML 预览与 Blade 主题共享同一页面职责和数据语义。</p></article>
    </div>
</section>
@endsection
