<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle ?? $siteName }}</title>
    <meta name="description" content="{{ $pageDescription ?? '' }}">
    @isset($siteKeywords)
        @if($siteKeywords !== '')
            <meta name="keywords" content="{{ $siteKeywords }}">
        @endif
    @endisset
    @if(!empty($siteFavicon))
        <link rel="icon" href="{{ $siteFavicon }}">
    @endif
    <link rel="canonical" href="{{ $canonicalUrl ?? url()->current() }}">
    @stack('head')
    <script src="/js/tailwindcss.play-cdn.js"></script>
    <link rel="stylesheet" href="/assets/css/style.css?v={{ filemtime(public_path('assets/css/style.css')) }}">
    <link rel="stylesheet" href="/themes/apple-support-inspired-20260914/theme.css?v={{ filemtime(public_path('themes/apple-support-inspired-20260914/theme.css')) }}">
    <link rel="stylesheet" href="/assets/css/custom.css?v={{ filemtime(public_path('assets/css/custom.css')) }}">
    <script src="/js/lucide.min.js"></script>
    @if(!empty($headAnalyticsCode))
        {!! $headAnalyticsCode !!}
    @endif
</head>
<body class="site-ui official-theme apple-support-theme">
    @include('theme.corporate-growth-20260914.partials.header')
    <main class="cg-main">
        @yield('content')
    </main>
    @include('theme.corporate-growth-20260914.partials.footer')
    @stack('scripts')
    <script src="/assets/js/main.js"></script>
    <script src="/themes/apple-support-inspired-20260914/theme.js" defer></script>
</body>
</html>
