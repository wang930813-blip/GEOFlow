@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $site = data_get($themeData, 'site', []);
    $pageTitle = trim((string) $__env->yieldContent('seo_title'));
    $pageDescription = trim((string) $__env->yieldContent('seo_description'));
    $pageTitle = $pageTitle !== '' ? $pageTitle : (string) data_get($themeData, 'seo.home.title', data_get($site, 'brand_name', ''));
    $pageDescription = $pageDescription !== '' ? $pageDescription : (string) data_get($themeData, 'seo.home.description', data_get($site, 'brand_tagline', ''));
    $canonicalUrl = isset($canonicalUrl) ? $canonicalUrl : url()->current();
    $websiteJsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => (string) data_get($site, 'brand_name', ''),
        'url' => url('/'),
    ];
@endphp
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('site.partials.seo-head')
    @stack('head')
    <link rel="stylesheet" href="{{ asset('themes/zk-blue-tech-028/theme.css') }}">
    <x-json-ld :data="$websiteJsonLd" />
</head>
<body>
    @if(!empty($headAnalyticsCode))
        {!! $headAnalyticsCode !!}
    @endif
    @yield('body')
    <script defer src="{{ asset('themes/zk-blue-tech-028/theme.js') }}"></script>
    @stack('scripts')
    @include('site.partials.visual-editor-runtime', ['themeData' => $themeData])
</body>
</html>
