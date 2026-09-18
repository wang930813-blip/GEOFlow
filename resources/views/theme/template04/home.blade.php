@extends('theme.template04.layout')

@php
    $template04 = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $serviceItems = collect($template04['products_services']['items']);
    $latestArticles = $template04['featured_articles']
        ->merge($template04['articles'])
        ->unique('id')
        ->take(3)
        ->values();
    $heroSlide = collect($homepageCarouselSlides ?? [])->first();
    $heroImage = trim((string) data_get($heroSlide, 'image_url', ''));
    $heroImage = $heroImage !== ''
        ? (preg_match('/^(https?:)?\/\//', $heroImage) === 1 || str_starts_with($heroImage, '/') ? $heroImage : asset($heroImage))
        : '';
@endphp

@push('head')
    @php
        $homeSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $pageTitle ?? $template04['seo']['home']['title'],
            'description' => $pageDescription ?? $template04['seo']['home']['description'],
            'url' => $canonicalUrl ?? \App\Support\Site\SitePageUrl::to('home'),
        ];
    @endphp
    <x-json-ld :data="$homeSchema" />
@endpush

@section('content')
<section class="hero" aria-labelledby="hero-title">
    <div>
        <p class="eyebrow">{{ $template04['home']['hero']['eyebrow'] }}</p>
        <h1 id="hero-title">{{ $template04['home']['hero']['title'] }}</h1>
        <p class="lead">{{ $template04['home']['hero']['description'] }}</p>
        <div class="actions">
            <a class="button button-primary" href="{{ $template04['home']['hero']['primary_action']['url'] }}">{{ $template04['home']['hero']['primary_action']['label'] }} <span aria-hidden="true">→</span></a>
            <a class="button button-secondary" href="{{ $template04['home']['hero']['secondary_action']['url'] }}">{{ $template04['home']['hero']['secondary_action']['label'] }}</a>
        </div>
    </div>
    <div class="hero-art" aria-hidden="true">
        <div class="orb"></div>
        @if($heroImage !== '')
            <img class="hero-photo" src="{{ $heroImage }}" alt="" width="800" height="600" fetchpriority="high">
        @endif
    </div>
</section>

<section class="section" aria-labelledby="about-preview-title">
    <div class="section-heading">
        <p class="eyebrow">品牌概览</p>
        <h2 id="about-preview-title">{{ $template04['home']['introduction']['title'] }}</h2>
    </div>
    <div class="prose">{!! $template04['home']['introduction']['content'] !!}</div>
</section>

@if($serviceItems->isNotEmpty())
    <section class="section" aria-labelledby="services-title">
        <div class="section-heading">
            <p class="eyebrow">核心能力</p>
            <h2 id="services-title">{{ $template04['products_services']['title'] }}</h2>
        </div>
        <div class="grid">
            @foreach($serviceItems->take(3) as $item)
                <article class="panel">
                    <span class="icon-box">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                    <h3>{{ $item['name'] }}</h3>
                    <p>{{ $item['summary'] }}</p>
                </article>
            @endforeach
        </div>
    </section>
@endif

<section class="section" aria-labelledby="news-preview-title">
    <div class="section-heading">
        <p class="eyebrow">最新资讯</p>
        <h2 id="news-preview-title">保持对变化的敏锐</h2>
    </div>
    <div class="grid">
        @forelse($latestArticles as $article)
            @include('theme.template04.partials.article-card', ['article' => $article, 'cardSummaries' => $cardSummaries ?? []])
        @empty
            <p role="status">暂无资讯内容。</p>
        @endforelse
    </div>
</section>
@endsection
