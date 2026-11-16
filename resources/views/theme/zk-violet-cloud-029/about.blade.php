<?php
/**
 * Created by Codex.
 * @Date: 2026-10-22
 * @Time: 17:05:42
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： about.blade.php
 * @Description: 呈现公共数据中的品牌背景与介绍正文。
 */
?>
@extends('theme.zk-violet-cloud-029.layout')
@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $activeNav = 'about';
    $pageTitle = $themeData['seo']['about']['title'];
    $pageDescription = $themeData['seo']['about']['description'];
    $canonicalUrl = \App\Support\Site\SitePageUrl::to('about');
@endphp
@section('body')
<section class="page-hero reveal">
    <p class="eyebrow">关于我们</p>
    <h1>{{ $themeData['about']['title'] }}</h1>
    <p class="page-lead">{{ $themeData['about']['summary'] }}</p>
</section>
<section class="page-body section-shell reveal">
    <div class="page-body-label"><span class="label-mark" aria-hidden="true"></span><p>品牌介绍</p></div>
    <div class="prose">{!! $themeData['about']['content'] !!}</div>
</section>
<section class="page-next section-shell reveal">
    <div><p class="eyebrow">产品与服务</p><h2>{{ $themeData['products_services']['title'] }}</h2><p>{{ $themeData['products_services']['summary'] }}</p></div>
    <a class="text-link" href="{{ \App\Support\Site\SitePageUrl::to('products') }}">查看产品服务 <span aria-hidden="true">↗</span></a>
</section>
@endsection
