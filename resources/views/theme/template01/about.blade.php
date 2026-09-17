@extends('theme.template01.layout')

@php
    $template01 = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $themeAsset = static fn (string $path): string => asset('themes/template01/'.$path);
@endphp

@section('body')
    @include('theme.template01.partials.header', ['template01' => $template01, 'activeNav' => 'about'])

    <main id="main-content">
        <header class="page-hero section-dark" id="top" data-hero>
            <div class="container page-hero-inner">
                <div class="page-hero-copy reveal">
                    <p class="eyebrow"><span class="eyebrow-dot"></span>Brand profile</p>
                    <h1 data-content="about.title" data-text-animate>{{ $template01['about']['title'] }}</h1>
                    <p class="page-lead" data-content="about.summary">{{ $template01['about']['summary'] }}</p>
                </div>
            </div>
        </header>

        <section class="section section-light about-story" aria-labelledby="about-story-title">
            <div class="container about-story-grid">
                <figure class="about-story-visual reveal">
                    <img src="{{ $themeAsset('assets/reference/studio.jpg') }}" width="1200" height="900" loading="lazy" alt="{{ $template01['site']['brand_name'] }} 品牌介绍">
                    <figcaption><span data-content="site.brand_name">{{ $template01['site']['brand_name'] }}</span><span>品牌介绍</span></figcaption>
                </figure>
                <div class="about-story-copy reveal reveal-delay-1">
                    <p class="eyebrow eyebrow-dark"><span class="eyebrow-dot"></span>About</p>
                    <h2 id="about-story-title">从真实资料开始<br><span>认识一个品牌</span></h2>
                    <div class="prose prose-large" data-content="about.content">{!! $template01['about']['content'] !!}</div>
                    <p class="about-tagline" data-content="site.brand_tagline">{{ $template01['site']['brand_tagline'] }}</p>
                </div>
            </div>
        </section>

        <section class="section section-ink page-next" aria-labelledby="about-next-title">
            <div class="container page-next-inner reveal">
                <div><p class="eyebrow"><span class="eyebrow-dot"></span>继续了解</p><h2 id="about-next-title">查看产品与服务内容</h2></div>
                <span class="magnetic-wrap"><a class="button button-light" data-magnetic href="{{ route('site.products') }}">产品服务 <span aria-hidden="true">↗</span></a></span>
            </div>
        </section>
    </main>

    @include('theme.template01.partials.footer', ['template01' => $template01])
@endsection
