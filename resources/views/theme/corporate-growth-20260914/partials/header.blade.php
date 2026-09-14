@php
    $activeNav = $activeNav ?? (request()->routeIs('site.news', 'site.category', 'site.article') ? 'news' : (request()->routeIs('site.about') ? 'about' : (request()->routeIs('site.contact') ? 'contact' : 'home')));
    $navItems = [
        ['key' => 'home', 'label' => __('front.nav.home'), 'url' => route('site.home')],
        ['key' => 'news', 'label' => app()->getLocale() === 'zh_CN' ? '资讯' : 'Insights', 'url' => route('site.news')],
        ['key' => 'about', 'label' => app()->getLocale() === 'zh_CN' ? '关于我们' : 'About', 'url' => route('site.about')],
        ['key' => 'contact', 'label' => app()->getLocale() === 'zh_CN' ? '联系我们' : 'Contact', 'url' => route('site.contact')],
    ];
@endphp
<header class="cg-header">
    <a href="#mainContent" class="cg-skip">{{ app()->getLocale() === 'zh_CN' ? '跳到正文' : 'Skip to content' }}</a>
    <div class="cg-shell cg-header__row">
        <a href="{{ route('site.home') }}" class="cg-brand" aria-label="{{ $siteName }}">
            @if(!empty($siteLogo))
                <img src="{{ $siteLogo }}" alt="{{ $siteName }}">
            @else
                <span class="cg-brand__mark">{{ mb_substr($siteName, 0, 1) }}</span>
                <span class="cg-brand__text">{{ $siteName }}</span>
            @endif
        </a>

        <nav class="cg-nav" aria-label="Primary">
            @foreach($navItems as $item)
                <a href="{{ $item['url'] }}" class="{{ $activeNav === $item['key'] ? 'is-active' : '' }}">{{ $item['label'] }}</a>
            @endforeach
        </nav>

        <form method="get" action="{{ route('site.home') }}" class="cg-search" role="search">
            <i data-lucide="search" aria-hidden="true"></i>
            <input type="search" name="search" value="{{ request('search') }}" placeholder="{{ __('site.search_placeholder') }}">
        </form>

        <a href="{{ route('site.contact') }}" class="cg-header__cta">{{ app()->getLocale() === 'zh_CN' ? '获取方案' : 'Get a plan' }}</a>
        <button type="button" class="cg-menu-button" data-official-menu-toggle aria-controls="cgMobileNav" aria-expanded="false" aria-label="Menu">
            <i data-lucide="menu" aria-hidden="true"></i>
        </button>
    </div>
    <div id="cgMobileNav" class="cg-mobile-nav" hidden>
        <div class="cg-shell cg-mobile-nav__panel">
            @foreach($navItems as $item)
                <a href="{{ $item['url'] }}" class="{{ $activeNav === $item['key'] ? 'is-active' : '' }}">{{ $item['label'] }}</a>
            @endforeach
        </div>
    </div>
</header>
