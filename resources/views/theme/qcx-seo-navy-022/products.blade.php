@extends('theme.qcx-seo-navy-022.layout')
@php($themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars()))
@php($services = $themeData['products_services'])
@php($activeNav = 'products')
@php($pageTitle = $themeData['seo']['products_services']['title'])
@php($pageDescription = $themeData['seo']['products_services']['description'])
@php($canonicalUrl = \App\Support\Site\SitePageUrl::to('products'))
@section('content')
<section class="page-hero"><div class="shell"><p class="eyebrow">产品服务</p><h1>{{ $services['title'] }}</h1><p class="lead">{{ $services['summary'] }}</p></div></section>
<section class="page-content"><div class="shell"><div class="service-grid">@forelse($services['items'] as $service)<article class="service-card"><figure aria-hidden="true">{{ str_pad((string)($loop->index + 1), 2, '0', STR_PAD_LEFT) }}</figure><h2>{{ $service['name'] }}</h2><p>{{ $service['summary'] }}</p><div class="prose">{!! $service['details'] !!}</div></article>@empty<p>暂无产品或服务内容。</p>@endforelse</div></div></section>
@endsection
