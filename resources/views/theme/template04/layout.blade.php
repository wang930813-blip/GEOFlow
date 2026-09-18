@php
    $template04 = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $brandName = $template04['site']['brand_name'];
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#070912">
    <title>{{ $pageTitle ?? $template04['seo']['home']['title'] }}</title>
    <meta name="description" content="{{ $pageDescription ?? $template04['seo']['home']['description'] }}">
    <link rel="canonical" href="{{ $canonicalUrl ?? \App\Support\Site\SitePageUrl::to('home') }}">
    @include('site.partials.seo-head')
    @stack('head')
    <link rel="stylesheet" href="{{ asset('themes/template04/theme.css') }}">
    @if(!empty($headAnalyticsCode))
        {!! $headAnalyticsCode !!}
    @endif
    @php
        $websiteSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $brandName,
            'url' => \App\Support\Site\SitePageUrl::to('home'),
        ];
    @endphp
    <x-json-ld :data="$websiteSchema" />
</head>
<body class="site-ui template04-theme">
    @include('theme.template04.partials.header', ['template04' => $template04, 'activeNav' => $activeNav ?? ''])
    <main id="main-content">@yield('content')</main>
    @include('theme.template04.partials.footer', ['template04' => $template04])
    @stack('scripts')
    <script src="{{ asset('themes/template04/theme.js') }}" defer></script>
</body>
</html>
