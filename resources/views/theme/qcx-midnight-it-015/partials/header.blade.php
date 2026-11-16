<a class="skip-link" href="#main-content">跳到主要内容</a>
<header class="site-header">
    <a class="brand" href="{{ \App\Support\Site\SitePageUrl::to('home') }}">{{ $themeData['site']['brand_name'] }}</a>
    <button class="menu-toggle" type="button" aria-label="打开导航" aria-expanded="false" aria-controls="site-nav" data-menu-toggle>☰</button>
    <nav id="site-nav" aria-label="主导航" data-site-nav>
        @foreach(data_get($themeData, 'navigation.items', []) as $navigationItem)
            <a href="{{ $navigationItem['url'] }}" data-site-nav-item="{{ $navigationItem['id'] }}" @if($navigationItem['active']) aria-current="page" @endif><span data-site-nav-label>{{ $navigationItem['label'] }}</span></a>
        @endforeach
        @foreach($navPages ?? [] as $pageItem)
            <a href="{{ route('site.page', $pageItem->slug) }}" data-site-nav-item="page-{{ $pageItem->slug }}"><span data-site-nav-label>{{ $pageItem->title }}</span></a>
        @endforeach
    </nav>
</header>

