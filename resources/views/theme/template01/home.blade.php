@extends('theme.template01.layout')

@php
    $template01 = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $themeAsset = static fn (string $path): string => asset('themes/template01/'.$path);
    $publicAsset = static fn (string $path): string => preg_match('/^(https?:)?\/\//', $path) === 1 || str_starts_with($path, '/') ? $path : asset($path);
    $heroSlide = collect($homepageCarouselSlides ?? [])->first();
    $heroImage = trim((string) data_get($heroSlide, 'image_url', ''));
    $heroImage = $heroImage !== '' ? $publicAsset($heroImage) : $themeAsset('assets/reference/hero-network.png');
    $serviceItems = collect($template01['products_services']['items']);
    $latestArticles = $template01['featured_articles']->merge($template01['articles'])->unique('id')->take(3)->values();
@endphp

@push('head')
    @php
        $schemaAtContext = chr(64).'context';
        $schemaAtType = chr(64).'type';
        $homeSchema = [
            $schemaAtContext => 'https://schema.org',
            $schemaAtType => 'WebPage',
            'name' => $pageTitle ?? $template01['seo']['home']['title'],
            'description' => $pageDescription ?? $template01['seo']['home']['description'],
            'url' => $canonicalUrl ?? route('site.home'),
        ];
    @endphp
    <x-json-ld :data="$homeSchema" />
@endpush

@section('body')
    @include('theme.template01.partials.header', ['template01' => $template01, 'activeNav' => 'home'])

    <main id="main-content">
        <section class="hero section-dark" id="top" aria-labelledby="hero-title" data-hero>
            <div class="hero-grid container">
                <div class="hero-copy reveal">
                    <p class="eyebrow"><span class="eyebrow-dot"></span><span data-content="home.hero.eyebrow">{{ $template01['home']['hero']['eyebrow'] }}</span></p>
                    <h1 id="hero-title">
                        <span class="headline-line" data-content="home.hero.title" data-text-animate>{{ $template01['home']['hero']['title'] }}</span>
                    </h1>
                    <p class="hero-lead" data-content="home.hero.description">{{ $template01['home']['hero']['description'] }}</p>
                    <div class="hero-actions">
                        <span class="magnetic-wrap"><a class="button button-primary" data-magnetic href="{{ $template01['home']['hero']['primary_action']['url'] }}">{{ $template01['home']['hero']['primary_action']['label'] }} <span aria-hidden="true">↗</span></a></span>
                        <a class="text-link" href="{{ $template01['home']['hero']['secondary_action']['url'] }}">{{ $template01['home']['hero']['secondary_action']['label'] }}</a>
                    </div>
                </div>

                <div class="hero-visual reveal reveal-delay-1" data-hero-visual>
                    <div class="hero-orbit" aria-hidden="true" data-parallax="0.12"></div>
                    <figure class="hero-image-frame" data-parallax="0.05">
                        <img src="{{ $heroImage }}" width="800" height="1200" alt="{{ $template01['site']['brand_name'] }} 官方网站视觉" fetchpriority="high" data-content-attribute="home.hero.visual:src">
                    </figure>
                </div>
            </div>
            <div class="hero-footer container">
                <p data-content="site.brand_tagline">{{ $template01['site']['brand_tagline'] }}</p>
                <a href="#introduction" class="round-link" aria-label="查看品牌介绍"><span aria-hidden="true">↓</span></a>
            </div>
        </section>

        <section class="section section-light home-introduction" id="introduction" aria-labelledby="introduction-title">
            <div class="container intro-grid">
                <div class="section-heading reveal">
                    <p class="eyebrow eyebrow-dark"><span class="eyebrow-dot"></span>Brand profile</p>
                    <h2 id="introduction-title" data-content="home.introduction.title"><span data-text-animate>{{ $template01['home']['introduction']['title'] }}</span></h2>
                </div>
                <div class="prose intro-prose reveal reveal-delay-1" data-content="home.introduction.content">{!! $template01['home']['introduction']['content'] !!}</div>
            </div>
        </section>

        @if($serviceItems->isNotEmpty())
            <section class="section section-ink home-services" id="services" aria-labelledby="services-title">
                <div class="container">
                    <div class="section-heading split-heading split-heading-ink reveal">
                        <div>
                            <p class="eyebrow"><span class="eyebrow-dot"></span>Services</p>
                            <h2 id="services-title" data-content="products_services.title"><span data-text-animate>{{ $template01['products_services']['title'] }}</span></h2>
                        </div>
                        @if($template01['products_services']['summary'] !== '')
                            <p data-content="products_services.summary">{{ $template01['products_services']['summary'] }}</p>
                        @endif
                    </div>
                    <div class="service-preview-grid" data-content-list="products_services.items">
                        @foreach($serviceItems->take(4) as $item)
                            <article class="service-preview-card reveal @if(!$loop->first) reveal-delay-{{ min($loop->index, 2) }} @endif" data-pointer-card>
                                <span class="service-preview-index">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                                <h3>{{ $item['name'] }}</h3>
                                <p>{{ $item['summary'] }}</p>
                                @if($item['url'] !== '')
                                    <a class="card-link" href="{{ $item['url'] }}">查看内容 <span aria-hidden="true">↗</span></a>
                                @endif
                            </article>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        <section class="section section-ink home-news" aria-labelledby="home-news-title">
            <div class="container">
                <div class="section-heading split-heading split-heading-ink reveal">
                    <div><p class="eyebrow"><span class="eyebrow-dot"></span>News</p><h2 id="home-news-title"><span data-text-animate>了解近期动态</span></h2></div>
                    <a class="text-link" href="{{ route('site.news') }}">查看全部资讯 <span aria-hidden="true">↗</span></a>
                </div>
                <div class="article-grid" data-state="{{ $latestArticles->isEmpty() ? 'empty' : 'success' }}">
                    @forelse($latestArticles as $article)
                        @include('theme.template01.partials.article-card', ['article' => $article])
                    @empty
                        <div class="news-state" data-state="empty"><strong>暂无资讯内容。</strong></div>
                    @endforelse
                </div>
            </div>
        </section>

        <section class="section section-cta home-contact" aria-labelledby="home-contact-title">
            <div class="container cta-inner reveal">
                <div><p class="eyebrow"><span class="eyebrow-dot"></span>Contact</p><h2 id="home-contact-title"><span data-text-animate>让下一步沟通</span><br><em>更直接</em></h2></div>
                <div class="cta-side">
                    <p data-content="contact.summary">{{ $template01['contact']['summary'] }}</p>
                    @foreach(array_filter([$template01['contact']['email'], $template01['contact']['phone'], $template01['contact']['address']], static fn ($item) => ! str_starts_with((string) $item, '暂无')) as $contactLine)
                        <span class="empty-state"><span class="status-dot"></span>{{ $contactLine }}</span>
                    @endforeach
                    <span class="magnetic-wrap"><a class="button button-light" data-magnetic href="{{ route('site.contact') }}">查看联系信息 <span aria-hidden="true">↗</span></a></span>
                </div>
            </div>
        </section>
    </main>

    @include('theme.template01.partials.footer', ['template01' => $template01])
@endsection
