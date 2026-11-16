@extends('theme.zk-blue-tech-028.layout')

@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $hero = data_get($themeData, 'home.hero', []);
    $introduction = data_get($themeData, 'home.introduction', []);
    $services = collect(data_get($themeData, 'products_services.items', []));
    $homeArticles = collect($featuredArticles ?? [])->concat(collect($articles ?? []))
        ->filter()
        ->unique(fn ($item) => data_get($item, 'id') ?? data_get($item, 'slug'))
        ->take(3);
@endphp

@section('seo_title', data_get($themeData, 'seo.home.title', data_get($themeData, 'site.brand_name')))
@section('seo_description', data_get($themeData, 'seo.home.description', data_get($themeData, 'site.brand_tagline')))

@section('body')
    @include('theme.zk-blue-tech-028.partials.header', ['themeData' => $themeData, 'activeNav' => 'home'])
    <main id="main-content">
        <section class="hero" aria-labelledby="hero-title" data-site-section="hero">
            <div class="hero-inner">
                <div class="hero-copy reveal is-visible">
                    @if(data_get($hero, 'eyebrow'))
                        <p class="eyebrow eyebrow-light">{{ data_get($hero, 'eyebrow') }}</p>
                    @endif
                    <h1 id="hero-title"><span class="hero-title-lead">让技术承接实际目标</span><span class="hero-title-accent" data-site-editable-field="hero_title">{{ data_get($hero, 'title') }}</span></h1>
                    <p class="hero-description" data-site-editable-field="hero_description">{{ data_get($hero, 'description') }}</p>
                </div>
                <div class="hero-visual" aria-hidden="true">
                    <div class="visual-frame"><div class="visual-grid"></div><div class="visual-horizon"></div><div class="visual-core"><span></span><span></span><span></span></div><i class="visual-node visual-node-one"></i><i class="visual-node visual-node-two"></i><i class="visual-node visual-node-three"></i><div class="visual-caption"><span>数字连接</span><span>● ● ●</span></div></div>
                </div>
            </div>
            <div class="hero-edge" aria-hidden="true"><span></span></div>
        </section>

        <section class="process-section section-shell" aria-labelledby="process-title" data-site-section="workflow">
            <div class="section-intro reveal"><p class="eyebrow">工作方式</p><h2 id="process-title" data-site-editable-field="workflow_title">{{ data_get($themeData, 'home.workflow.title') }}</h2><p data-site-editable-field="workflow_summary">{{ data_get($themeData, 'home.workflow.summary') }}</p></div>
            <ol class="process-grid">
                <li class="process-item reveal"><span class="process-symbol">01</span><h3>需求梳理</h3><p>明确目标、现状与需要解决的问题。</p></li>
                <li class="process-item reveal"><span class="process-symbol">02</span><h3>方案规划</h3><p>整理实施范围、关键步骤和交付内容。</p></li>
                <li class="process-item reveal"><span class="process-symbol">03</span><h3>落地实施</h3><p>围绕确认后的方案推进建设与配置。</p></li>
                <li class="process-item reveal"><span class="process-symbol">04</span><h3>持续维护</h3><p>根据实际情况安排后续维护与更新。</p></li>
            </ol>
        </section>

        <section class="about-band" aria-labelledby="home-about-title" data-site-section="introduction">
            <div class="section-shell about-layout">
                <div class="about-visual reveal" aria-hidden="true"><div class="signal-board"><span class="signal-line signal-line-a"></span><span class="signal-line signal-line-b"></span><span class="signal-point signal-point-a"></span><span class="signal-point signal-point-b"></span><span class="signal-core"></span><span class="signal-label">SYSTEM / 01</span></div></div>
                <div class="about-copy reveal"><p class="eyebrow">关于我们</p><h2 id="home-about-title" data-site-editable-field="introduction_title">{{ data_get($introduction, 'title') }}</h2><div class="prose" data-site-editable-field="introduction_content">{!! data_get($introduction, 'content') !!}</div><a class="text-link" href="{{ \App\Support\Site\SitePageUrl::to('about') }}">了解更多 <span aria-hidden="true">↗</span></a></div>
            </div>
        </section>

        <section class="services-section section-shell" aria-labelledby="services-title" data-site-section="services">
            <div class="section-heading reveal"><div><p class="eyebrow">产品与服务</p><h2 id="services-title" data-site-editable-field="services_title">{{ data_get($themeData, 'products_services.title') }}</h2></div><p data-site-editable-field="services_summary">{{ data_get($themeData, 'products_services.summary') }}</p></div>
            <div class="service-grid">
                @forelse($services as $index => $service)
                    <article class="service-card reveal"><span class="service-marker">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span><h3>{{ data_get($service, 'name') }}</h3><p>{{ data_get($service, 'summary') }}</p>@if(data_get($service, 'details'))<div class="service-details prose">{!! data_get($service, 'details') !!}</div>@endif</article>
                @empty
                    <p class="empty-state">暂无产品或服务内容。</p>
                @endforelse
            </div>
            <p class="section-link"><a class="text-link" href="{{ \App\Support\Site\SitePageUrl::to('products') }}">查看全部服务 <span aria-hidden="true">↗</span></a></p>
        </section>

        <section class="contact-band" aria-labelledby="contact-band-title" data-site-section="contact">
            <div class="section-shell contact-band-inner"><div><p class="eyebrow eyebrow-light">下一步</p><h2 id="contact-band-title" data-site-editable-field="contact_heading">{{ data_get($themeData, 'home.contact_band.title') }}</h2><p data-site-editable-field="contact_description">{{ data_get($themeData, 'home.contact_band.summary') }}</p></div><div class="band-links">
                @foreach(['primary_action', 'secondary_action'] as $actionKey)
                    @php $action = data_get($hero, $actionKey); @endphp
                    @if(data_get($action, 'label') && data_get($action, 'target_page'))
                        <a class="band-link {{ $loop->first ? 'band-link-primary' : '' }}" href="{{ \App\Support\Site\SitePageUrl::to(data_get($action, 'target_page')) }}">{{ data_get($action, 'label') }}</a>
                    @endif
                @endforeach
            </div></div>
        </section>

        <section class="news-section section-shell" aria-labelledby="latest-news-title" data-site-section="news">
            <div class="section-heading reveal"><div><p class="eyebrow">资讯中心</p><h2 id="latest-news-title" data-site-editable-field="news_title">{{ data_get($themeData, 'home.news.title') }}</h2></div><a class="text-link" href="{{ \App\Support\Site\SitePageUrl::to('news') }}">查看全部 <span aria-hidden="true">↗</span></a></div>
            <div class="news-grid">
                @forelse($homeArticles as $article)
                    @include('theme.zk-blue-tech-028.partials.article-card', ['article' => $article])
                @empty
                    <p class="empty-state">暂无资讯内容。</p>
                @endforelse
            </div>
        </section>
    </main>
    @include('theme.zk-blue-tech-028.partials.footer', ['themeData' => $themeData])
@endsection
