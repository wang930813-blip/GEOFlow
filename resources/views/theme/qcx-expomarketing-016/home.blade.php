@extends('theme.qcx-expomarketing-016.layout')
@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $latestArticles = collect($featuredArticles ?? [])->merge(collect($articles ?? []))->unique('id')->take(3)->values();
    $activeNav = 'home';
@endphp
@section('content')
<section class="hero"><img class="hero-shape" src="{{ asset('themes/qcx-expomarketing-016/assets/section_bg01.png') }}" alt=""><div class="hero-copy"><h1>建立受众，扩大你的品牌</h1><p class="lead">内容营销的核心是为用户提供价值。通过清晰的策略、持续的内容和可衡量的传播动作，让品牌被更多合适的人看见。</p><p class="hero-note">策略清楚，增长可见</p></div><div class="hero-media"><img src="{{ asset('themes/qcx-expomarketing-016/assets/hero-img.png') }}" alt="品牌增长数据与营销场景插画" width="850" height="725"></div></section>
<section class="trust-line"><h2>被更多团队信赖的品牌增长方法</h2></section>
<section class="section service-band"><div class="section-heading"><p class="eyebrow">服务能力</p><h2>我们可以怎样帮助你</h2><p>围绕品牌表达、内容生产、渠道传播和页面体验，建立可持续的增长基础。</p></div><div class="service-grid">@foreach(['数字营销','社交媒体运营','内容创作','网页设计'] as $service)<article class="feature-card"><img src="{{ asset('themes/qcx-expomarketing-016/assets/check.svg') }}" alt=""><div><h3>{{ $service }}</h3><p>围绕品牌增长目标，建立清晰、稳定、可复盘的服务动作。</p></div></article>@endforeach</div></section>
<section class="detail-section"><div class="detail-media"><img src="{{ asset('themes/qcx-expomarketing-016/assets/about.png') }}" alt="团队讨论品牌增长策略插画" width="655" height="645"></div><div class="detail-copy"><p class="eyebrow">品牌策略</p><h2>把复杂想法转化为清晰表达</h2><p>从目标人群、信息层级到内容节奏，我们帮助品牌建立更容易被理解和记住的传播框架。</p><div class="detail-list"><span>明确品牌定位</span><span>梳理内容主题</span><span>追踪传播效果</span></div></div></section>
<section class="detail-section alt"><div class="detail-media"><img src="{{ asset('themes/qcx-expomarketing-016/assets/about2.png') }}" alt="数字增长解决方案插画" width="654" height="595"></div><div class="detail-copy"><p class="eyebrow">增长方案</p><h2>用更好的内容连接更合适的人</h2><p>我们关注真实用户的需求，用持续输出和数据反馈优化每一次触达，让品牌增长更稳。</p></div></section>
<section class="section"><div class="section-heading"><p class="eyebrow">博客</p><h2>近期内容</h2></div><div class="article-grid">@forelse($latestArticles as $article)@include('theme.qcx-expomarketing-016.partials.article-card', ['article' => $article])@empty<p class="article-status">暂无资讯内容。</p>@endforelse</div></section>
@endsection
