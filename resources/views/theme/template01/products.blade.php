@extends('theme.template01.layout')

@php
    $template01 = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $themeAsset = static fn (string $path): string => asset('themes/template01/'.$path);
    $publicAsset = static fn (string $path): string => preg_match('/^(https?:)?\/\//', $path) === 1 || str_starts_with($path, '/') ? $path : asset($path);
    $serviceItems = collect($template01['products_services']['items']);
@endphp

@section('body')
    @include('theme.template01.partials.header', ['template01' => $template01, 'activeNav' => 'products'])

    <main id="main-content">
        <header class="page-hero section-dark" id="top" data-hero>
            <div class="container page-hero-inner">
                <div class="page-hero-copy reveal">
                    <p class="eyebrow"><span class="eyebrow-dot"></span>Products & Services</p>
                    <h1 data-content="products_services.title" data-text-animate>{{ $template01['products_services']['title'] }}</h1>
                    @if($template01['products_services']['summary'] !== '')
                        <p class="page-lead" data-content="products_services.summary">{{ $template01['products_services']['summary'] }}</p>
                    @endif
                </div>
            </div>
        </header>

        <section class="section section-light products-catalog" aria-labelledby="catalog-title">
            <div class="container">
                @if($serviceItems->isNotEmpty())
                    <div class="catalog-heading reveal"><p class="eyebrow eyebrow-dark"><span class="eyebrow-dot"></span>内容列表</p><h2 id="catalog-title">查看具体内容</h2><p>每项内容根据后台配置的产品与服务资料展示。</p></div>
                    <div class="product-list" data-content-list="products_services.items">
                        @foreach($serviceItems as $item)
                            @php
                                $productImage = trim((string) ($item['image_url'] ?? ''));
                                $productImage = $productImage !== '' ? $publicAsset($productImage) : $themeAsset($loop->odd ? 'assets/reference/process-discover.png' : 'assets/reference/process-deliver.png');
                            @endphp
                            <article class="product-item reveal">
                                <figure class="product-item-visual"><img src="{{ $productImage }}" width="900" height="680" loading="lazy" alt="{{ $item['name'] }}"></figure>
                                <div class="product-item-copy">
                                    <span class="product-item-index">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                                    <h2>{{ $item['name'] }}</h2>
                                    <p class="product-summary">{{ $item['summary'] }}</p>
                                    <div class="prose product-details">{!! $item['details'] !!}</div>
                                    @if($item['url'] !== '')
                                        <a class="text-link" href="{{ $item['url'] }}">查看关联内容 <span aria-hidden="true">↗</span></a>
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>
                @else
                    <div class="catalog-empty reveal" role="status">
                        <strong>暂无产品</strong>
                    </div>
                @endif
            </div>
        </section>

        <section class="section section-cta products-contact" aria-labelledby="products-contact-title">
            <div class="container cta-inner reveal"><div><p class="eyebrow"><span class="eyebrow-dot"></span>下一步</p><h2 id="products-contact-title">需要进一步了解<br><em>具体内容</em></h2></div><div class="cta-side"><p data-content="contact.summary">{{ $template01['contact']['summary'] }}</p><span class="magnetic-wrap"><a class="button button-light" data-magnetic href="{{ \App\Support\Site\SitePageUrl::to('contact') }}">查看联系信息 <span aria-hidden="true">↗</span></a></span></div></div>
        </section>
    </main>

    @include('theme.template01.partials.footer', ['template01' => $template01])
@endsection
