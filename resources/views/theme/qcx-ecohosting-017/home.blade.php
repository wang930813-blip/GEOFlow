@extends('theme.qcx-ecohosting-017.layout')
@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $latestArticles = collect($featuredArticles ?? [])->merge(collect($articles ?? []))->unique('id')->take(3)->values();
    $activeNav = 'home';
@endphp
@section('content')
<section class="hero"><img class="corner-shape" src="{{ asset('themes/qcx-ecohosting-017/assets/top-left-shape.png') }}" alt=""><div class="hero-copy"><p class="eyebrow">稳定的域名与托管服务</p><h1>强大的网络托管</h1><p class="lead">把站点托管、访问安全、性能监控和数据备份放在同一个清晰体系里，让业务在线服务保持稳定。</p><div class="hero-badges"><span>云端部署</span><span>安全备份</span><span>性能监测</span></div></div><div class="hero-media"><img src="{{ asset('themes/qcx-ecohosting-017/assets/hero_right.png') }}" alt="云服务器托管插画" width="720" height="620"></div></section>
<section class="section"><div class="section-heading"><p class="eyebrow">托管能力</p><h2>让网站运行更稳、更快、更容易管理</h2><p>不加入域名搜索条，直接呈现托管服务能力与使用价值。</p></div><div class="service-grid">@foreach(['云主机托管','安全防护','性能优化'] as $index => $service)<article class="feature-card"><img src="{{ asset('themes/qcx-ecohosting-017/assets/services' . ($index + 1) . '.svg') }}" alt=""><h3>{{ $service }}</h3><p>围绕网站运行状态、安全策略和服务稳定性提供清晰支持。</p></article>@endforeach</div></section>
<section class="stats-band"><div class="section stats-grid"><div class="stat"><strong>99.9%</strong><span>稳定性目标</span></div><div class="stat"><strong>24h</strong><span>运行监控</span></div><div class="stat"><strong>3 层</strong><span>安全策略</span></div><div class="stat"><strong>1 处</strong><span>统一管理</span></div></div></section>
<section class="detail-section"><div class="detail-media"><img src="{{ asset('themes/qcx-ecohosting-017/assets/about1.png') }}" alt="网站托管和运维协作插画" width="630" height="568"></div><div class="detail-copy"><p class="eyebrow">托管管理</p><h2>把网站运行中的关键事项集中起来</h2><p>从部署、证书、备份到监控，团队可以在同一套结构中理解服务状态。</p><div class="detail-list"><span>站点运行状态可追踪</span><span>安全与备份策略清晰</span><span>服务变化便于复盘</span></div></div></section>
<section class="section"><div class="section-heading"><p class="eyebrow">博客</p><h2>近期内容</h2></div><div class="article-grid">@forelse($latestArticles as $article)@include('theme.qcx-ecohosting-017.partials.article-card', ['article' => $article])@empty<p class="article-status">暂无资讯内容。</p>@endforelse</div></section>
@endsection
