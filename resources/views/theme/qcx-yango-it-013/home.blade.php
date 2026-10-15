@extends('theme.qcx-yango-it-013.layout')

@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $home = $themeData['home'];
    $services = collect($themeData['products_services']['items']);
    $latestArticles = collect($featuredArticles ?? [])->merge(collect($articles ?? []))->unique('id')->take(3)->values();
    $activeNav = 'home';
@endphp

@section('content')
<section class="hero hero-home">
    <div class="hero-copy">
        <p class="eyebrow">{{ $home['hero']['eyebrow'] }}</p>
        <h1>{{ $home['hero']['title'] }}</h1>
        <p class="lead">{{ $home['hero']['description'] }}</p>
        <div class="hero-points" aria-label="服务重点"><span>系统建设</span><span>云端安全</span><span>数据治理</span></div>
    </div>
    <div class="hero-media"><img src="{{ asset('themes/qcx-yango-it-013/assets/banner1.png') }}" alt="办公场景中的数字化服务插画" width="865" height="650"></div>
</section>

<section class="split-section">
    <div class="split-media"><img src="{{ asset('themes/qcx-yango-it-013/assets/features.jpg') }}" alt="团队讨论数字化工作流程" width="646" height="521"></div>
    <div class="split-copy">
        <p class="eyebrow">核心能力</p>
        <h2>以更轻量的方式呈现完整 IT 服务能力</h2>
        <div class="feature-grid">
            <article class="mini-card"><span class="feature-icon feature-red">01</span><h3>IT 管理</h3><p>面向日常系统、权限、设备和流程提供可持续的技术管理支持。</p></article>
            <article class="mini-card"><span class="feature-icon feature-cyan">02</span><h3>云端安全</h3><p>围绕云资源、访问控制和备份策略，建立更稳妥的运行边界。</p></article>
            <article class="mini-card"><span class="feature-icon feature-purple">03</span><h3>数据安全</h3><p>帮助企业梳理数据权限、存储、同步和应急恢复的关键环节。</p></article>
            <article class="mini-card"><span class="feature-icon feature-yellow">04</span><h3>智能分析</h3><p>将业务数据转化为可追踪、可比较、可复盘的信息视图。</p></article>
        </div>
    </div>
</section>

<section class="soft-band">
    <div class="band-copy">
        <p class="eyebrow">关于服务</p>
        <h2>{{ $home['introduction']['title'] }}</h2>
        <div class="prose">{!! $home['introduction']['content'] !!}</div>
    </div>
    <div class="band-media"><img src="{{ asset('themes/qcx-yango-it-013/assets/about/about1.png') }}" alt="团队协作与技术服务插画" width="681" height="588"></div>
</section>

@if($services->isNotEmpty())
<section class="section">
    <div class="section-heading"><p class="eyebrow">服务范围</p><h2>{{ $themeData['products_services']['title'] }}</h2><p>{{ $themeData['products_services']['summary'] }}</p></div>
    <div class="service-grid">
        @foreach($services->take(6) as $service)
            <article class="service-card">
                <img src="{{ asset('themes/qcx-yango-it-013/assets/services/services-icon' . (($loop->iteration - 1) % 6 + 1) . '.png') }}" alt="" width="72" height="72">
                <h3>{{ $service['name'] }}</h3>
                <p>{{ $service['summary'] }}</p>
            </article>
        @endforeach
    </div>
</section>
@endif

<section class="section news-preview">
    <div class="section-heading"><p class="eyebrow">资讯</p><h2>近期内容</h2><p>资讯由平台文章服务提供，空列表时不展示演示文章。</p></div>
    <div class="article-grid">
        @forelse($latestArticles as $article)
            @include('theme.qcx-yango-it-013.partials.article-card', ['article' => $article])
        @empty
            <p class="article-status">暂无资讯内容。</p>
        @endforelse
    </div>
</section>
@endsection
