<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('site.partials.seo-head')
    @stack('head')
    <script src="/js/tailwindcss.play-cdn.js"></script>
    <link rel="stylesheet" href="/assets/css/style.css?v={{ filemtime(public_path('assets/css/style.css')) }}">
    <link rel="stylesheet" href="/themes/netease-news-20260507/theme.css?v={{ filemtime(public_path('themes/netease-news-20260507/theme.css')) }}">
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
    <x-json-ld :data="$websiteSchema" />
</head>
<body class="site-ui ne-body">
    @include('theme.netease-news-20260507.partials.header')
    <main class="ne-main">
        @yield('content')
    </main>
    @include('theme.netease-news-20260507.partials.footer')
    @stack('scripts')
    <script src="/assets/js/main.js"></script>
    <script src="/themes/netease-news-20260507/theme.js" defer></script>
</body>
</html>
