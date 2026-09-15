<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#071321">
    @include('site.partials.seo-head')
    @stack('head')
    <link rel="stylesheet" href="{{ asset('themes/template01/theme.css') }}">
    @if(!empty($headAnalyticsCode))
        {!! $headAnalyticsCode !!}
    @endif
    @php
        $schemaAtContext = chr(64).'context';
        $schemaAtType = chr(64).'type';
        $websiteSchema = [
            $schemaAtContext => 'https://schema.org',
            $schemaAtType => 'WebSite',
            'name' => $siteName ?? $siteTitle ?? config('geoflow.site_name', config('app.name')),
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
<body>
    @yield('body')
    @stack('scripts')
    <script type="module" src="{{ asset('themes/template01/theme.js') }}"></script>
</body>
</html>
