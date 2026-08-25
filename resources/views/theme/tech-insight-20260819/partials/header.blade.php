@php
    $activeNav = $activeNav ?? (request()->routeIs('site.news', 'site.category', 'site.article') ? 'news' : (request()->routeIs('site.about') ? 'about' : (request()->routeIs('site.contact') ? 'contact' : 'home')));
    $navItems = [
        ['key' => 'home', 'label' => __('front.nav.home'), 'url' => route('site.home')],
        ['key' => 'news', 'label' => '资讯', 'url' => route('site.news')],
        ['key' => 'about', 'label' => '关于我们', 'url' => route('site.about')],
        ['key' => 'contact', 'label' => '联系我们', 'url' => route('site.contact')],
    ];
@endphp
<header class="tx-header">
    <a href="#mainContent" class="tx-skip">跳到正文</a>
    <div class="tx-shell tx-header-row">
        <a href="{{ route('site.home') }}" class="tx-brand" aria-label="{{ $siteName }}">
            @if(!empty($siteLogo))
                <img src="{{ $siteLogo }}" alt="{{ $siteName }}">
            @else
                <span class="tx-brand-text">{{ $siteName }}</span>
            @endif
        </a>

        <nav class="tx-nav" aria-label="Primary">
            @foreach($navItems as $item)
                <a href="{{ $item['url'] }}" class="{{ $activeNav === $item['key'] ? 'is-active' : '' }}">{{ $item['label'] }}</a>
            @endforeach
        </nav>

        <form method="get" action="{{ route('site.home') }}" class="tx-search" role="search">
            <i data-lucide="search" aria-hidden="true"></i>
            <input type="search" name="search" value="{{ request('search') }}" placeholder="{{ __('site.search_placeholder') }}">
            <button type="submit">{{ __('site.search_button') }}</button>
        </form>

        <button type="button" class="tx-menu-button" data-tech-menu-toggle aria-controls="txMobileNav" aria-expanded="false" aria-label="{{ __('front.nav.categories') }}">
            <i data-lucide="menu" aria-hidden="true"></i>
        </button>
    </div>
    <div id="txMobileNav" class="tx-mobile-nav" hidden>
        <div class="tx-shell tx-mobile-panel">
            @foreach($navItems as $item)
                <a href="{{ $item['url'] }}" class="{{ $activeNav === $item['key'] ? 'is-active' : '' }}">{{ $item['label'] }}</a>
            @endforeach
        </div>
    </div>
</header>
