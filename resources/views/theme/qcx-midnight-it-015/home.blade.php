@extends('theme.qcx-midnight-it-015.layout')
@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $home = $themeData['home'];
    $services = $themeData['products_services'];
    $latestArticles = collect($articles ?? [])->take(3);
    $activeNav = 'home';
    $pageTitle = $themeData['seo']['home']['title'];
    $pageDescription = $themeData['seo']['home']['description'];
    $canonicalUrl = \App\Support\Site\SitePageUrl::to('home');
@endphp
@section('content')
<section class="hero">
    <div class="hero-inner">
        <div class="hero-copy">
            @if(!empty($home['hero']['eyebrow']))<p class="eyebrow">{{ $home['hero']['eyebrow'] }}</p>@endif
            <h1>{{ $home['hero']['title'] }}</h1>
            <p class="lead">{{ $home['hero']['description'] }}</p>
        </div>
        @if(!empty($home['hero']['visual']['src']))
            <img class="hero-art" src="{{ $home['hero']['visual']['src'] }}" alt="{{ $home['hero']['visual']['alt'] ?? '' }}" @if(!empty($home['hero']['visual']['width'])) width="{{ $home['hero']['visual']['width'] }}" @endif @if(!empty($home['hero']['visual']['height'])) height="{{ $home['hero']['visual']['height'] }}" @endif>
        @else
            <img class="hero-art" src="{{ asset('themes/qcx-midnight-it-015/assets/hero-img.png') }}" alt="" width="1158" height="669">
        @endif
    </div>
</section>
<section class="services-band"><div class="section">
    <div class="section-heading centered"><p class="eyebrow">产品服务</p><h2>{{ $services['title'] }}</h2><p>{{ $services['summary'] }}</p></div>
    <div class="service-grid">
        @forelse($services['items'] as $service)
            @php($icon = ['services1.svg', 'services2.svg', 'services3.svg'][($loop->iteration - 1) % 3])
            <article class="service-card">
                <img src="{{ asset('themes/qcx-midnight-it-015/assets/' . $icon) }}" alt="" width="61" height="63" loading="lazy">
                <h3>{{ $service['name'] }}</h3><p>{{ $service['summary'] }}</p>
            </article>
        @empty
            <p>暂无产品或服务内容。</p>
        @endforelse
    </div>
    <p class="section-link"><a class="text-link" href="{{ \App\Support\Site\SitePageUrl::to($home['hero']['primary_action']['target_page'] === 'products_services' ? 'products' : $home['hero']['primary_action']['target_page']) }}">{{ $home['hero']['primary_action']['label'] }}</a></p>
</div></section>
<section class="path-band" aria-labelledby="path-title"><div class="path-inner">
    <div class="path-intro">
        <p class="eyebrow">服务路径</p>
        <h2 id="path-title">从问题出发，<br>让每一步都有方向</h2>
        <p>技术方案不必从复杂术语开始。先看清当下，再确定轻重缓急，让后续工作有清晰的衔接。</p>
    </div>
    <div class="path-steps">
        <article><span>01</span><div><h3>看清现状</h3><p>梳理系统、人员与流程，找到需要优先处理的问题。</p></div></article>
        <article><span>02</span><div><h3>确定路径</h3><p>把目标拆成可执行的步骤，明确范围、节奏与协作方式。</p></div></article>
        <article><span>03</span><div><h3>保持连续</h3><p>随着业务变化持续检查、调整和完善技术方案。</p></div></article>
    </div>
</div></section>
<section class="scenarios-band" aria-labelledby="scenarios-title"><div class="section">
    <div class="section-heading"><p class="eyebrow">关注的业务环节</p><h2 id="scenarios-title">让技术融入日常运转</h2><p>不同的工作场景，对稳定性、安全性和协作方式有不同期待。</p></div>
    <div class="scenario-grid">
        <article><span>01</span><h3>稳定运行</h3><p>系统和设备持续可用，是每个团队的基础需求。</p></article>
        <article><span>02</span><h3>数据保护</h3><p>让资料有备份、有恢复路径，也有清楚的管理边界。</p></article>
        <article><span>03</span><h3>协作效率</h3><p>让信息更容易被找到，让沟通围绕同一目标展开。</p></article>
        <article><span>04</span><h3>业务扩展</h3><p>当团队与工具发生变化，技术结构也需要保持弹性。</p></article>
    </div>
</div></section>
<section class="news-band"><div class="section">
    <div class="section-heading centered"><p class="eyebrow">最新资讯</p><h2>近期发布的内容</h2></div>
    <div class="item-grid">@forelse($latestArticles as $article)@include('theme.qcx-midnight-it-015.partials.article-card', ['article' => $article])@empty<p class="article-status">暂无资讯内容。</p>@endforelse</div>
    <p><a class="text-link" href="{{ \App\Support\Site\SitePageUrl::to('news') }}">查看全部资讯</a></p>
</div></section>
@endsection

