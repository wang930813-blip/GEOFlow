@extends('theme.zk-blue-tech-028.layout')

@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $services = collect(data_get($themeData, 'products_services.items', []));
@endphp

@section('seo_title', data_get($themeData, 'seo.products_services.title', data_get($themeData, 'products_services.title')))
@section('seo_description', data_get($themeData, 'seo.products_services.description', data_get($themeData, 'products_services.summary')))

@section('body')
    @include('theme.zk-blue-tech-028.partials.header', ['themeData' => $themeData, 'activeNav' => 'products'])
    <main id="main-content">
        <section class="page-hero"><div class="section-shell page-hero-inner"><p class="eyebrow eyebrow-light">产品与服务</p><h1>{{ data_get($themeData, 'products_services.title') }}</h1><p class="page-lead">{{ data_get($themeData, 'products_services.summary') }}</p><span class="page-hero-code" aria-hidden="true">SERVICES</span></div><div class="page-hero-grid" aria-hidden="true"></div></section>
        <section class="products-section section-shell"><div class="section-heading"><div><p class="eyebrow">服务内容</p><h2>按已确认的服务信息逐项了解</h2></div><p>具体服务范围以公开资料为准。</p></div><div class="service-grid service-grid-page">
            @forelse($services as $index => $service)
                <article class="service-card reveal"><span class="service-marker">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span><h2>{{ data_get($service, 'name') }}</h2><p>{{ data_get($service, 'summary') }}</p>@if(data_get($service, 'details'))<div class="service-details prose">{!! data_get($service, 'details') !!}</div>@endif</article>
            @empty
                <p class="empty-state">暂无产品或服务内容。</p>
            @endforelse
        </div></section>
        <section class="contact-band"><div class="section-shell contact-band-inner"><div><p class="eyebrow eyebrow-light">需要进一步了解</p><h2>通过公开联系方式与我们沟通</h2><p>没有已确认的联系方式时，请稍后查看更新。</p></div><div class="band-links"><a class="band-link band-link-primary" href="{{ \App\Support\Site\SitePageUrl::to('contact') }}">查看联系方式</a></div></div></section>
    </main>
    @include('theme.zk-blue-tech-028.partials.footer', ['themeData' => $themeData])
@endsection
