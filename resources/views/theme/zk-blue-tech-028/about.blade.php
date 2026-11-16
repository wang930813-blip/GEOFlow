@extends('theme.zk-blue-tech-028.layout')

@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
@endphp

@section('seo_title', data_get($themeData, 'seo.about.title', data_get($themeData, 'about.title')))
@section('seo_description', data_get($themeData, 'seo.about.description', data_get($themeData, 'about.summary')))

@section('body')
    @include('theme.zk-blue-tech-028.partials.header', ['themeData' => $themeData, 'activeNav' => 'about'])
    <main id="main-content">
        <section class="page-hero"><div class="section-shell page-hero-inner"><p class="eyebrow eyebrow-light">关于我们</p><h1>{{ data_get($themeData, 'about.title') }}</h1><p class="page-lead">{{ data_get($themeData, 'about.summary') }}</p><span class="page-hero-code" aria-hidden="true">ABOUT</span></div><div class="page-hero-grid" aria-hidden="true"></div></section>
        <section class="about-detail section-shell">
            <div class="about-detail-side reveal"><p class="eyebrow">业务介绍</p><div class="about-mark" aria-hidden="true"><span></span><span></span><span></span></div><p class="side-caption">科技服务<br>持续更新</p></div>
            <article class="prose about-article reveal"><h2>品牌介绍</h2>{!! data_get($themeData, 'about.content') !!}</article>
        </section>
        <section class="process-band"><div class="section-shell process-band-inner"><p class="eyebrow eyebrow-light">工作方式</p><h2>从梳理到落地，让每一步都有清晰依据</h2><ol class="process-line"><li><span>01</span><strong>梳理目标</strong></li><li><span>02</span><strong>规划方案</strong></li><li><span>03</span><strong>推进实施</strong></li><li><span>04</span><strong>持续维护</strong></li></ol></div></section>
        <section class="page-next section-shell"><div><p class="eyebrow">继续了解</p><h2>查看可提供的产品与服务</h2></div><a class="text-link" href="{{ \App\Support\Site\SitePageUrl::to('products') }}">产品服务 <span aria-hidden="true">↗</span></a></section>
    </main>
    @include('theme.zk-blue-tech-028.partials.footer', ['themeData' => $themeData])
@endsection
