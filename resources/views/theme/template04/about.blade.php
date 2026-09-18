@extends('theme.template04.layout')

@php($template04 = \App\Support\Site\Template01Data::fromView(get_defined_vars()))

@section('content')
<main class="page-shell">
    <header class="page-heading">
        <p class="eyebrow">关于我们</p>
        <h1>{{ $template04['about']['title'] }}</h1>
        <p class="lead">{{ $template04['about']['summary'] }}</p>
    </header>

    <section class="section feature-band reveal">
        <div class="prose">{!! $template04['about']['content'] !!}</div>
    </section>

    <section class="section reveal" aria-labelledby="about-principles-title">
        <div class="section-heading">
            <p class="eyebrow">工作方式</p>
            <h2 id="about-principles-title">让信息更清晰，让行动更直接</h2>
        </div>
        <div class="grid">
            <article class="panel"><span class="icon-box">01</span><h3>理解上下文</h3><p>围绕真实目标组织信息，减少不必要的沟通成本。</p></article>
            <article class="panel"><span class="icon-box">02</span><h3>连接团队</h3><p>让知识、流程和协作集中在自然易用的工作界面里。</p></article>
            <article class="panel"><span class="icon-box">03</span><h3>持续优化</h3><p>根据反馈不断调整体验，让每一次交付更贴近实际需要。</p></article>
        </div>
    </section>
</main>
@endsection
