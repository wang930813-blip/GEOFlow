@php
    $navItems = [
        ['key' => 'home', 'label' => '首页', 'url' => \App\Support\Site\SitePageUrl::to('home')],
        ['key' => 'about', 'label' => '关于我们', 'url' => \App\Support\Site\SitePageUrl::to('about')],
        ['key' => 'products', 'label' => '产品服务', 'url' => \App\Support\Site\SitePageUrl::to('products')],
        ['key' => 'news', 'label' => '资讯中心', 'url' => \App\Support\Site\SitePageUrl::to('news')],
        ['key' => 'contact', 'label' => '联系我们', 'url' => \App\Support\Site\SitePageUrl::to('contact')],
    ];
    $brandName = $template01['site']['brand_name'];
    $brandLogo = trim((string) ($template01['site']['logo'] ?? ''));
@endphp

<div class="preloader" data-preloader aria-hidden="true">
    <div class="preloader-mark">
        @if($brandLogo !== '')
            <img src="{{ $brandLogo }}" alt="" class="brand-logo preloader-logo">
        @else
            <span class="brand-mark"><span></span><span></span><span></span></span>
        @endif
        <span>{{ $brandName }}</span>
    </div>
    <span class="preloader-line"></span>
</div>
<div class="site-cursor" data-cursor aria-hidden="true"><span data-cursor-text></span></div>
<a class="skip-link" href="#main-content">跳到主要内容</a>

<header class="site-header" data-header>
    <div class="container header-inner">
        <a class="brand" href="{{ \App\Support\Site\SitePageUrl::to('home') }}" aria-label="{{ $brandName }}">
            @if($brandLogo !== '')
                <img src="{{ $brandLogo }}" alt="{{ $brandName }}" class="brand-logo">
            @else
                <span class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></span>
            @endif
            <span data-content="site.brand_name">{{ $brandName }}</span>
        </a>
        <nav class="desktop-nav" aria-label="主导航">
            @foreach($navItems as $item)
                <a href="{{ $item['url'] }}" data-nav="{{ $item['key'] }}" @if(($activeNav ?? '') === $item['key'] || (($activeNav ?? '') === 'news' && $item['key'] === 'news')) aria-current="page" @endif>{{ $item['label'] }}</a>
            @endforeach
        </nav>
        <div class="header-actions">
            <button class="icon-button theme-toggle" type="button" data-theme-toggle aria-label="切换主题" title="切换主题">
                <svg class="icon icon-sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.65 17.65l1.42 1.42M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.65 6.35l1.42-1.42"></path></svg>
                <svg class="icon icon-moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 15.2A8.6 8.6 0 0 1 8.8 3.5 8.7 8.7 0 1 0 20.5 15.2Z"></path></svg>
            </button>
            <span class="magnetic-wrap"><a class="button button-small button-outline header-cta" data-magnetic href="{{ \App\Support\Site\SitePageUrl::to('contact') }}">联系我们</a></span>
            <button class="icon-button menu-toggle" type="button" data-menu-toggle aria-expanded="false" aria-controls="mobile-menu" aria-label="打开菜单" title="打开菜单">
                <svg class="icon menu-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"></path></svg>
                <svg class="icon menu-close" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
            </button>
        </div>
    </div>
</header>

<div class="menu-backdrop" data-menu-backdrop aria-hidden="true"></div>
<nav class="mobile-nav" id="mobile-menu" data-mobile-menu aria-hidden="true" aria-label="移动端主导航" tabindex="-1">
    <div class="mobile-nav-head">
        <span>站点导航</span>
        <button class="icon-button mobile-close" type="button" data-menu-close aria-label="关闭菜单" title="关闭菜单">
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
        </button>
    </div>
    @foreach($navItems as $item)
        <a href="{{ $item['url'] }}" data-nav="{{ $item['key'] }}">{{ $item['label'] }}</a>
    @endforeach
</nav>
