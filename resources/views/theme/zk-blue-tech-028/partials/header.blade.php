@php
    $site = data_get($themeData, 'site', []);
    $logo = data_get($site, 'logo');
    $logoSource = data_get($logo, 'src');
@endphp
<a class="skip-link" href="#main-content">跳到主要内容</a>
<header class="site-header {{ $activeNav === 'home' ? 'site-header-overlay' : 'site-header-solid' }}" data-site-header>
    <a class="brand" href="{{ \App\Support\Site\SitePageUrl::to('home') }}" aria-label="{{ data_get($site, 'brand_name') }}">
        @if($logoSource)
            <img class="brand-image" src="{{ $logoSource }}" alt="{{ data_get($logo, 'alt', data_get($site, 'brand_name')) }}">
        @else
            <span class="brand-mark" aria-hidden="true"><i></i><i></i></span>
        @endif
        <span>{{ data_get($site, 'brand_name') }}</span>
    </a>
    <button class="menu-toggle" type="button" aria-label="打开导航" aria-expanded="false" aria-controls="site-navigation" data-menu-toggle>
        <span></span><span></span>
    </button>
    <nav id="site-navigation" class="site-nav" aria-label="主导航" data-site-nav>
        @foreach(data_get($themeData, 'navigation.items', []) as $navigationItem)
            <a href="{{ $navigationItem['url'] }}" data-site-nav-item="{{ $navigationItem['id'] }}" @if($navigationItem['active']) aria-current="page" @endif><span data-site-nav-label>{{ $navigationItem['label'] }}</span></a>
        @endforeach
        @foreach($navPages ?? [] as $pageItem)
            <a href="{{ route('site.page', $pageItem->slug) }}" data-site-nav-item="page-{{ $pageItem->slug }}"><span data-site-nav-label>{{ $pageItem->title }}</span></a>
        @endforeach
    </nav>
</header>
