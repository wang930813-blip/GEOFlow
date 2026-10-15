@php
    $site = $themeData['site'] ?? [];
@endphp
<header class="site-header quantum-inner-header">
    <a class="brand" href="{{ \App\Support\Site\SitePageUrl::to('home') }}"><span class="logo-text">Quantum</span><span class="logo-badge">AI</span></a>
    <button class="menu-toggle" aria-label="打开菜单" aria-expanded="false">☰</button>
    <nav aria-label="主导航">
        <a href="{{ \App\Support\Site\SitePageUrl::to('home') }}" @if(($activeNav ?? '') === 'home') aria-current="page" @endif>首页</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('about') }}" @if(($activeNav ?? '') === 'about') aria-current="page" @endif>关于我们</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('products') }}" @if(($activeNav ?? '') === 'products') aria-current="page" @endif>产品服务</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('news') }}" @if(($activeNav ?? '') === 'news') aria-current="page" @endif>资讯</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('contact') }}" @if(($activeNav ?? '') === 'contact') aria-current="page" @endif>联系我们</a>
    </nav>
</header>
