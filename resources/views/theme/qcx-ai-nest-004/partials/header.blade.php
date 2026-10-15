@php
    $site = $themeData['site'] ?? [];
    $logo = trim((string) ($site['logo'] ?? ''));
    $logoSrc = $logo !== '' && (preg_match('/^(https?:)?\\/\\//', $logo) === 1 || str_starts_with($logo, '/'))
        ? $logo
        : ($logo !== '' ? asset($logo) : '');
@endphp
<header class="site-header">
    <a class="brand" href="{{ \App\Support\Site\SitePageUrl::to('home') }}">
        @if($logoSrc !== '')<img src="{{ $logoSrc }}" alt="{{ $site['brand_name'] ?? '' }}" width="34" height="34">@else<span class="brand-mark">N</span>@endif
        <span>{{ $site['brand_name'] ?? '' }}</span>
    </a>
    <button class="menu-toggle" aria-label="打开菜单" aria-expanded="false">☰</button>
    <nav aria-label="主导航">
        <a href="{{ \App\Support\Site\SitePageUrl::to('home') }}" @if(($activeNav ?? '') === 'home') aria-current="page" @endif>首页</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('about') }}" @if(($activeNav ?? '') === 'about') aria-current="page" @endif>关于我们</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('products') }}" @if(($activeNav ?? '') === 'products') aria-current="page" @endif>产品服务</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('news') }}" @if(($activeNav ?? '') === 'news') aria-current="page" @endif>资讯</a>
        <a class="header-cta" href="{{ \App\Support\Site\SitePageUrl::to('contact') }}">联系我们 →</a>
    </nav>
</header>
