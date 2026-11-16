<?php
/**
 * Created by Codex.
 * @Date: 2026-10-22
 * @Time: 17:05:42
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： home.blade.php
 * @Description: 呈现公共品牌信息、产品服务和真实资讯预览。
 */
?>
@extends('theme.zk-violet-cloud-029.layout')
@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $activeNav = 'home';
    $latestArticles = collect($featuredArticles ?? [])
        ->merge(collect($hotArticles ?? []))
        ->merge(collect($articles ?? []))
        ->unique(fn ($item) => data_get($item, 'slug'))
        ->take(3)
        ->values();
@endphp
@section('body')
<section class="hero-shell" aria-labelledby="home-title" data-site-section="hero">
    <div class="hero-copy">
        @if(!empty($themeData['home']['hero']['eyebrow']))<p class="eyebrow eyebrow-light">{{ $themeData['home']['hero']['eyebrow'] }}</p>@endif
        <h1 id="home-title" data-site-editable-field="hero_title">{{ $themeData['home']['hero']['title'] }}</h1>
        <p class="hero-lead" data-site-editable-field="hero_description">{{ $themeData['home']['hero']['description'] }}</p>
    </div>
    <div class="hero-visual" aria-hidden="true">
        <div class="dashboard-frame">
            <div class="dashboard-topbar"><i></i><i></i><i></i><span></span><b></b></div>
            <div class="dashboard-content">
                <aside class="dashboard-rail"><i></i><i></i><i></i><i></i><i></i></aside>
                <div class="dashboard-main">
                    <div class="dashboard-title"><i></i><span></span></div>
                    <div class="dashboard-overview"><i></i><i></i><i></i></div>
                    <div class="dashboard-chart"><div class="chart-heading"><i></i><span></span><b></b></div><div class="chart-grid"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div></div>
                    <div class="dashboard-rows"><i></i><i></i><i></i></div>
                </div>
            </div>
        </div>
        <div class="float-tile float-tile-top"><i></i><span></span><b></b></div>
        <div class="float-tile float-tile-bottom"><i></i><span></span><b></b></div>
    </div>
</section>
<section class="intro-section reveal" data-site-section="introduction">
    <p class="eyebrow">品牌概览</p>
    <h2 data-site-editable-field="introduction_title">{{ $themeData['home']['introduction']['title'] }}</h2>
    <div class="intro-copy prose" data-site-editable-field="introduction_content">{!! $themeData['home']['introduction']['content'] !!}</div>
    <a class="text-link" href="{{ \App\Support\Site\SitePageUrl::to('about') }}">了解更多 <span aria-hidden="true">↗</span></a>
</section>
<section class="services-section" data-site-section="services">
    <div class="section-shell">
        <div class="section-heading centered reveal">
            <p class="eyebrow">产品与服务</p>
            <h2 data-site-editable-field="services_title">{{ $themeData['products_services']['title'] }}</h2>
            <p data-site-editable-field="services_summary">{{ $themeData['products_services']['summary'] }}</p>
        </div>
        <div class="service-grid">
            @forelse(($themeData['products_services']['items'] ?? []) as $item)
                <article class="service-card reveal">
                    <span class="service-mark" aria-hidden="true"><i></i><i></i><i></i></span>
                    <h3>{{ data_get($item, 'name') }}</h3>
                    <p class="service-summary">{{ data_get($item, 'summary') }}</p>
                    @if(data_get($item, 'details'))<div class="service-details prose">{!! data_get($item, 'details') !!}</div>@endif
                </article>
            @empty
                <p class="empty-state">暂无产品或服务内容。</p>
            @endforelse
        </div>
    </div>
</section>
<section class="about-band section-shell reveal" data-site-section="about">
    <div class="about-band-copy">
        <p class="eyebrow">关于我们</p>
        <h2 data-site-editable-field="about_band_title">{{ $themeData['home']['about_band']['title'] }}</h2>
        <p class="about-band-summary" data-site-editable-field="about_band_summary">{{ $themeData['home']['about_band']['summary'] }}</p>
        <a class="text-link" href="{{ \App\Support\Site\SitePageUrl::to('about') }}">阅读品牌介绍 <span aria-hidden="true">↗</span></a>
    </div>
    <div class="abstract-board" aria-hidden="true">
        <div class="board-window">
            <div class="board-window-head"><i></i><i></i><i></i><span></span></div>
            <div class="board-window-body"><div class="board-sidebar"><i></i><i></i><i></i><i></i></div><div class="board-content"><i></i><i></i><i></i><b></b><span></span><span></span></div></div>
        </div>
        <div class="board-orbit board-orbit-one"></div><div class="board-orbit board-orbit-two"></div>
    </div>
</section>
<section class="latest-section" data-site-section="news">
    <div class="section-shell">
        <div class="section-heading section-heading-row reveal">
            <div><p class="eyebrow">资讯中心</p><h2 data-site-editable-field="news_title">{{ $themeData['home']['news']['title'] }}</h2></div>
            <a class="text-link" href="{{ \App\Support\Site\SitePageUrl::to('news') }}">查看全部资讯 <span aria-hidden="true">↗</span></a>
        </div>
        <div class="article-grid">
            @forelse($latestArticles as $article)
                @include('theme.zk-violet-cloud-029.partials.article-card', ['article' => $article])
            @empty
                <p class="empty-state">暂无资讯内容。</p>
            @endforelse
        </div>
    </div>
</section>
@endsection
