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
    <link rel="stylesheet" href="/themes/tech-insight-20260819/theme.css?v={{ filemtime(public_path('themes/tech-insight-20260819/theme.css')) }}">
    <link rel="stylesheet" href="/assets/css/custom.css?v={{ filemtime(public_path('assets/css/custom.css')) }}">
    <script src="/js/lucide.min.js"></script>
    @if(!empty($headAnalyticsCode))
        {!! $headAnalyticsCode !!}
    @endif
    @php
        $schemaAtContext = chr(64).'context';
        $schemaAtType = chr(64).'type';
        $websiteSchema = [
            $schemaAtContext => 'https://schema.org',
            $schemaAtType => 'WebSite',
            'name' => $siteName,
            'url' => route('site.home'),
            'potentialAction' => [
                $schemaAtType => 'SearchAction',
                'target' => route('site.home').'?search={search_term_string}',
                'query-input' => 'required name=search_term_string',
            ],
        ];
    @endphp
    <script type="application/ld+json">
        {!! json_encode($websiteSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
    </script>
</head>
<body class="site-ui tech-insight-theme">
    @include('theme.tech-insight-20260819.partials.header')
    <main class="tx-main">
        @yield('content')
    </main>
    @include('theme.tech-insight-20260819.partials.footer')
    @stack('scripts')
    <script src="/assets/js/main.js"></script>
    <script src="/themes/tech-insight-20260819/theme.js" defer></script>
</body>
</html>
