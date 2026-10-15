@extends('theme.qcx-violet-it-021.layout')
@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $services = $themeData['products_services'];
    $activeNav = 'products';
    $pageTitle = $themeData['seo']['products_services']['title'];
    $pageDescription = $themeData['seo']['products_services']['description'];
    $canonicalUrl = \App\Support\Site\SitePageUrl::to('products');
@endphp
@section('content')
<section class="page-hero"><div class="shell"><p class="eyebrow">产品服务</p><h1>{{ $services['title'] }}</h1><p class="lead">{{ $services['summary'] }}</p></div></section>
<section class="page-content"><div class="shell"><div class="service-grid">@forelse($services['items'] as $service)@php($icon = ['service-it.png', 'service-web.png', 'service-network.png', 'service-seo.png', 'service-app.png', 'service-data.png'][$loop->index % 6])<article class="service-card"><figure><img src="{{ asset('themes/qcx-violet-it-021/assets/reference/icons/' . $icon) }}" alt="" width="50" height="50"></figure><h2>{{ $service['name'] }}</h2><p>{{ $service['summary'] }}</p><div class="prose">{!! $service['details'] !!}</div></article>@empty<p>暂无产品或服务内容。</p>@endforelse</div></div></section>
@endsection

