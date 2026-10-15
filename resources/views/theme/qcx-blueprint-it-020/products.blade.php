@extends('theme.qcx-blueprint-it-020.layout')
@php($themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars()))
@php($services = $themeData['products_services'])
@php($activeNav = 'products')
@php($pageTitle = $themeData['seo']['products_services']['title'])
@php($pageDescription = $themeData['seo']['products_services']['description'])
@php($canonicalUrl = \App\Support\Site\SitePageUrl::to('products'))
@section('content')
<section class="page-hero"><div class="shell"><p class="eyebrow">产品服务</p><h1>{{ $services['title'] }}</h1><p class="lead">{{ $services['summary'] }}</p></div></section>
<section class="page-content"><div class="shell"><div class="service-grid">@forelse($services['items'] as $service)@php($icon = 'services-' . ((($loop->iteration - 1) % 6) + 1) . '.svg')<article class="service-card"><span class="icon-orb"><img src="{{ asset('themes/qcx-blueprint-it-020/assets/reference/icons/' . $icon) }}" alt="" width="38" height="38" loading="lazy"></span><h2>{{ $service['name'] }}</h2><p>{{ $service['summary'] }}</p><div class="prose">{!! $service['details'] !!}</div></article>@empty<p>暂无产品或服务内容。</p>@endforelse</div><div class="product-showcase" style="margin-top:6rem"><div><p class="eyebrow">服务结构</p><h2>从基础建设到持续维护，保持服务结构清晰</h2><p class="lead">产品和服务条目由公共数据契约提供，模板只负责按照统一语义呈现。</p></div><div><img class="product-art" src="{{ asset('themes/qcx-blueprint-it-020/assets/reference/overview.png') }}" alt="团队使用云端工具协作的插画" width="600" height="480" loading="lazy"></div></div></div></section>
@endsection
