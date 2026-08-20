@php
    $path = request()->path();
    $isHome = $path === '' || $path === '/';
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
            <a href="{{ route('site.home') }}" class="{{ $isHome ? 'is-active' : '' }}">{{ __('front.nav.home') }}</a>
            @foreach($navCategories->take(5) as $categoryItem)
                <a href="{{ route('site.category', $categoryItem->slug) }}" class="{{ request()->is('category/'.$categoryItem->slug) ? 'is-active' : '' }}">{{ $categoryItem->name }}</a>
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
            <a href="{{ route('site.home') }}" class="{{ $isHome ? 'is-active' : '' }}">{{ __('front.nav.home') }}</a>
            @foreach($navCategories as $categoryItem)
                <a href="{{ route('site.category', $categoryItem->slug) }}" class="{{ request()->is('category/'.$categoryItem->slug) ? 'is-active' : '' }}">{{ $categoryItem->name }}</a>
            @endforeach
        </div>
    </div>
</header>
