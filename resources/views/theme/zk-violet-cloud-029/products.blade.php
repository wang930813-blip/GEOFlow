<?php
/**
 * Created by Codex.
 * @Date: 2026-10-22
 * @Time: 17:05:42
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： products.blade.php
 * @Description: 按公共产品服务字段完整呈现服务条目。
 */
?>
@extends('theme.zk-violet-cloud-029.layout')
@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $activeNav = 'products';
    $pageTitle = $themeData['seo']['products_services']['title'];
    $pageDescription = $themeData['seo']['products_services']['description'];
    $canonicalUrl = \App\Support\Site\SitePageUrl::to('products');
@endphp
@section('body')
<section class="page-hero reveal">
    <p class="eyebrow">产品与服务</p>
    <h1>{{ $themeData['products_services']['title'] }}</h1>
    <p class="page-lead">{{ $themeData['products_services']['summary'] }}</p>
</section>
<section class="page-body section-shell">
    <div class="service-grid service-grid-page">
        @forelse(($themeData['products_services']['items'] ?? []) as $item)
            <article class="service-card reveal">
                <span class="service-mark" aria-hidden="true"><i></i><i></i><i></i></span>
                <h2>{{ data_get($item, 'name') }}</h2>
                <p class="service-summary">{{ data_get($item, 'summary') }}</p>
                @if(data_get($item, 'details'))<div class="service-details prose">{!! data_get($item, 'details') !!}</div>@endif
            </article>
        @empty
            <p class="empty-state">暂无产品或服务内容。</p>
        @endforelse
    </div>
</section>
<section class="page-next section-shell reveal">
    <div><p class="eyebrow">联系我们</p><h2>{{ $themeData['contact']['title'] }}</h2><p>{{ $themeData['contact']['summary'] }}</p></div>
    <a class="text-link" href="{{ \App\Support\Site\SitePageUrl::to('contact') }}">查看联系信息 <span aria-hidden="true">↗</span></a>
</section>
@endsection
