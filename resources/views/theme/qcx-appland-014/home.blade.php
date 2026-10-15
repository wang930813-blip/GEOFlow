@extends('theme.qcx-appland-014.layout')

@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $home = $themeData['home'];
    $latestArticles = collect($featuredArticles ?? [])->merge(collect($articles ?? []))->unique('id')->take(3)->values();
    $activeNav = 'home';
@endphp

@section('content')
<section class="hero">
    <div class="hero-copy">
        <p class="eyebrow">数字应用产品</p>
        <h1>让日常工作变得更清晰</h1>
        <p class="lead">从信息整理到团队协作，阿普兰帮助你把重要的事情放在一起看、一起推进，并在需要的时候快速找到答案。</p>
        <p class="hero-note"><span></span>简单上手，持续使用</p>
    </div>
    <div class="hero-media"><img src="{{ asset('themes/qcx-appland-014/assets/hero-img.png') }}" alt="阿普兰应用界面与协作场景插画" width="712" height="600"></div>
</section>
<section class="section">
    <div class="section-heading centered"><p class="eyebrow">应用功能</p><h2>围绕实际工作设计的功能体验</h2><p>清晰的界面、连续的流程和可追踪的进展，让每一次协作都有明确的下一步。</p></div>
    <div class="feature-layout"><div class="feature-illustration"><img src="{{ asset('themes/qcx-appland-014/assets/features.svg') }}" alt="应用功能模块插画" width="480" height="390"></div><div class="feature-grid">
        @foreach(['信息整理','团队协作','安全存储','过程追踪','清晰提醒','灵活扩展'] as $feature)
            <article class="feature-card"><span class="feature-number">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><h3>{{ $feature }}</h3><p>让信息、任务和进展保持清晰可见，支持团队持续推进。</p></article>
        @endforeach
    </div></div>
</section>
<section class="detail-row detail-row-blue"><div class="detail-copy"><p class="eyebrow">从容开始</p><h2>把复杂流程，整理成容易理解的步骤</h2><p>用更直观的结构承载日常工作，让信息不再散落在不同的页面和工具之间。</p></div><div class="detail-media"><img src="{{ asset('themes/qcx-appland-014/assets/details-1.png') }}" alt="清晰整理工作流程的应用插画" width="634" height="634"></div></section>
<section class="detail-row detail-row-pink"><div class="detail-media"><img src="{{ asset('themes/qcx-appland-014/assets/details-2.png') }}" alt="团队共同推进工作的应用插画" width="634" height="634"></div><div class="detail-copy"><p class="eyebrow">一起推进</p><h2>让每一份想法，都能找到下一步</h2><p>从记录灵感到确认任务，从阶段反馈到最终交付，让协作更顺畅，也更容易复盘。</p></div></section>
<section class="section"><div class="section-heading centered"><p class="eyebrow">资讯</p><h2>了解产品与行业动态</h2></div><div class="article-grid">@forelse($latestArticles as $article)@include('theme.qcx-appland-014.partials.article-card', ['article' => $article])@empty<p class="article-status">暂无资讯内容。</p>@endforelse</div></section>
@endsection
