@extends('theme.qcx-midnight-it-015.layout')
@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $services = $themeData['products_services'];
    $activeNav = 'products';
    $pageTitle = $themeData['seo']['products_services']['title'];
    $pageDescription = $themeData['seo']['products_services']['description'];
    $canonicalUrl = \App\Support\Site\SitePageUrl::to('products');
@endphp
@section('content')
<section class="page-banner"><p class="eyebrow">产品服务</p><h1>{{ $services['title'] }}</h1><p class="lead">{{ $services['summary'] }}</p></section>
<section class="page-content" aria-label="产品与服务列表"><div class="item-grid">
    @forelse($services['items'] as $service)
        @php($icon = ['services1.svg', 'services2.svg', 'services3.svg'][($loop->iteration - 1) % 3])
        <article class="item-card">
            @if(!empty($service['image']['src']))
                <img src="{{ $service['image']['src'] }}" alt="{{ $service['image']['alt'] ?? '' }}" @if(!empty($service['image']['width'])) width="{{ $service['image']['width'] }}" @endif @if(!empty($service['image']['height'])) height="{{ $service['image']['height'] }}" @endif loading="lazy">
            @else
                <img src="{{ asset('themes/qcx-midnight-it-015/assets/' . $icon) }}" alt="" width="61" height="63" loading="lazy">
            @endif
            <h2>{{ $service['name'] }}</h2><p>{{ $service['summary'] }}</p><div class="prose">{!! $service['details'] !!}</div>
        </article>
    @empty
        <p>暂无产品或服务内容。</p>
    @endforelse
</div></section>
@endsection

