@extends('theme.qcx-appland-014.layout')

@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $services = collect($themeData['products_services']['items']);
    $activeNav = 'products';
    $pageTitle = $themeData['seo']['products_services']['title'];
    $pageDescription = $themeData['seo']['products_services']['description'];
    $canonicalUrl = \App\Support\Site\SitePageUrl::to('products');
@endphp

@section('content')
<section class="page-content">
    <div class="page-header">
        <p class="eyebrow">服务</p>
        <h1>{{ $themeData['products_services']['title'] }}</h1>
        <p class="lead">{{ $themeData['products_services']['summary'] }}</p>
    </div>
    <div class="service-grid">
        @forelse($services as $service)
            <article class="service-card">
                <img src="{{ asset('themes/qcx-appland-014/assets/features.svg') }}" alt="" width="72" height="72">
                <h2>{{ $service['name'] }}</h2>
                <p>{{ $service['summary'] }}</p>
                @if(!empty($service['details']))<div class="prose">{!! $service['details'] !!}</div>@endif
            </article>
        @empty
            <p>暂无产品或服务内容。</p>
        @endforelse
    </div>
</section>
@endsection
