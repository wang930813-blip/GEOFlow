<?php
/**
 * Created by Codex.
 * @Date: 2026-10-22
 * @Time: 17:05:42
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： layout.blade.php
 * @Description: 定义紫色轻云主题的公共页面结构、SEO 和资源入口。
 */
?>
@php($themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars()))
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle ?? $themeData['seo']['home']['title'] }}</title>
    <meta name="description" content="{{ $pageDescription ?? $themeData['seo']['home']['description'] }}">
    <link rel="canonical" href="{{ $canonicalUrl ?? \App\Support\Site\SitePageUrl::to('home') }}">
    @include('site.partials.seo-head')
    @stack('head')
    <link rel="icon" type="image/svg+xml" href="{{ asset('themes/zk-violet-cloud-029/assets/favicon.svg') }}">
    <link rel="stylesheet" href="{{ asset('themes/zk-violet-cloud-029/theme.css') }}">
    @if(!empty($headAnalyticsCode)){!! $headAnalyticsCode !!}@endif
    <x-json-ld :data="['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => $themeData['site']['brand_name']]" />
</head>
<body>
    @include('theme.zk-violet-cloud-029.partials.header')
    <main id="main-content">@yield('body')</main>
    @include('theme.zk-violet-cloud-029.partials.footer')
    @stack('scripts')
    <script src="{{ asset('themes/zk-violet-cloud-029/theme.js') }}" defer></script>
    @include('site.partials.visual-editor-runtime', ['themeData' => $themeData])
</body>
</html>
