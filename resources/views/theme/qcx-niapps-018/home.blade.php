@extends('theme.qcx-niapps-018.layout')
@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $latestArticles = collect($featuredArticles ?? [])->merge(collect($articles ?? []))->unique('id')->take(3)->values();
    $activeNav = 'home';
@endphp
@section('content')
<section class="hero"><div class="hero-copy"><p class="eyebrow">欢迎来到 NIAPPS</p><h1>我们帮助你<span class="highlight">增长与扩展</span></h1><p class="lead">用清晰的数字产品策略、项目执行和增长分析，帮助团队把想法转化为可持续的业务进展。</p><div class="hero-points"><span>数字产品</span><span>增长策略</span><span>项目交付</span></div></div><div class="hero-media"><img class="orbit" src="{{ asset('themes/qcx-niapps-018/assets/hero-orbit.png') }}" alt=""><img src="{{ asset('themes/qcx-niapps-018/assets/hero-main.png') }}" alt="宇航员与数字产品界面插画" width="720" height="560"></div></section>
<section class="blue-band"><div class="section"><div class="section-heading"><p class="eyebrow">服务能力</p><h2>把增长目标拆成可以执行的工作</h2><p>去掉行动按钮，直接呈现服务能力、项目方法和关键价值。</p></div><div class="service-grid">@foreach(['产品规划','体验设计','增长分析'] as $service)<article class="service-card"><span class="service-index">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><h3>{{ $service }}</h3><p>围绕数字增长目标，建立清晰、可执行、可复盘的服务动作。</p></article>@endforeach</div></div></section>
<section class="detail-section"><div class="detail-media"><img src="{{ asset('themes/qcx-niapps-018/assets/hero-panel.png') }}" alt="数字项目协作界面插画" width="680" height="520"></div><div class="detail-copy"><p class="eyebrow">项目服务</p><h2>让数字项目更清晰地推进</h2><p>从需求理解、方案设计到上线复盘，模板用更清楚的分区展示项目价值。</p><div class="detail-list"><span>明确目标与范围</span><span>组织服务能力</span><span>保留动态项目内容</span></div></div></section>
<section class="section"><div class="section-heading centered"><p class="eyebrow">团队反馈</p><h2>清晰表达让服务更容易被理解</h2></div><div class="testimonial-strip"><div class="quote-card"><img src="{{ asset('themes/qcx-niapps-018/assets/user-1.png') }}" alt=""><p><strong>数字产品团队</strong>页面结构更利于呈现服务能力和项目案例。</p></div><div class="quote-card"><img src="{{ asset('themes/qcx-niapps-018/assets/user-3.png') }}" alt=""><p><strong>增长运营团队</strong>蓝色分区和黄色强调让重点内容更醒目。</p></div></div></section>
<section class="section"><div class="section-heading"><p class="eyebrow">项目</p><h2>近期内容</h2></div><div class="article-grid">@forelse($latestArticles as $article)@include('theme.qcx-niapps-018.partials.article-card', ['article' => $article])@empty<p class="article-status">暂无资讯内容。</p>@endforelse</div></section>
@endsection
