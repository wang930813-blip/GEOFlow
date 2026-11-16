@php
    $seoHeadTitle = (string) ($pageTitle ?? $siteTitle ?? $siteName ?? config('app.name'));
    $seoHeadDescription = (string) ($pageDescription ?? $siteDescription ?? '');
    $seoHeadUrl = (string) ($canonicalUrl ?? url()->current());
    $seoHeadType = isset($article) ? 'article' : 'website';
@endphp
<meta property="og:type" content="{{ $seoHeadType }}">
<meta property="og:title" content="{{ $seoHeadTitle }}">
<meta property="og:description" content="{{ $seoHeadDescription }}">
<meta property="og:url" content="{{ $seoHeadUrl }}">
<meta property="og:site_name" content="{{ $siteTitle ?? $siteName ?? config('app.name') }}">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="{{ $seoHeadTitle }}">
<meta name="twitter:description" content="{{ $seoHeadDescription }}">
@if (! empty($siteLogo))
    <meta property="og:image" content="{{ $siteLogo }}">
    <meta name="twitter:image" content="{{ $siteLogo }}">
@endif
