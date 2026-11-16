<a class="skip-link" href="#main-content">跳到主要内容</a>
<header class="site-header @if(in_array($activeNav ?? '', ['about', 'contact'], true)) header-light @endif"><div class="shell header-inner">
    <a class="brand" href="{{ \App\Support\Site\SitePageUrl::to('home') }}" aria-label="{{ $themeData['site']['brand_name'] ?? '首页' }}"><span class="brand-mark" aria-hidden="true"></span><span>{{ $themeData['site']['brand_name'] ?? '品牌名称' }}</span></a>
    <nav aria-label="主导航" data-site-nav>
        @foreach(data_get($themeData, 'navigation.items', []) as $navigationItem)
            <a href="{{ $navigationItem['url'] }}" data-nav="{{ $navigationItem['id'] }}" data-site-nav-item="{{ $navigationItem['id'] }}" @if($navigationItem['active']) aria-current="page" @endif><span data-site-nav-label>{{ $navigationItem['label'] }}</span></a>
        @endforeach
        @foreach($navPages ?? [] as $pageItem)
            <a href="{{ route('site.page', $pageItem->slug) }}" data-site-nav-item="page-{{ $pageItem->slug }}"><span data-site-nav-label>{{ $pageItem->title }}</span></a>
        @endforeach
    </nav>
</div></header>

