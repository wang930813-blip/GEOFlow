@php($themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars()))
<!doctype html>
<html lang="{{ str_replace('_','-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle ?? $themeData['seo']['home']['title'] }}</title>
    <meta name="description" content="{{ $pageDescription ?? $themeData['seo']['home']['description'] }}">
    <link rel="canonical" href="{{ $canonicalUrl ?? \App\Support\Site\SitePageUrl::to('home') }}">
    @include('site.partials.seo-head')
    @stack('head')
    <link rel="stylesheet" href="{{ asset('themes/qcx-quantum-ai-005/theme.css') }}">
    @if(!empty($headAnalyticsCode)){!! $headAnalyticsCode !!}@endif
    <x-json-ld :data="['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => $themeData['site']['brand_name']]" />
</head>
<body class="site-ui quantum-ai-theme">
    @include('theme.qcx-quantum-ai-005.partials.header')
    <main id="main-content">@yield('content')</main>
    @include('theme.qcx-quantum-ai-005.partials.footer')
    @stack('scripts')
    <script src="{{ asset('themes/qcx-quantum-ai-005/theme.js') }}" defer></script>
</body>
</html>
