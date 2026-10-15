<a class="skip-link" href="#main-content">跳到主要内容</a>
<header class="site-header"><div class="shell header-inner">
    <a class="brand" href="{{ \App\Support\Site\SitePageUrl::to('home') }}"><span class="brand-mark" aria-hidden="true"></span>{{ $themeData['site']['brand_name'] }}</a>
    <button class="menu-toggle" type="button" aria-label="打开导航菜单" aria-expanded="false" aria-controls="site-nav" data-menu-toggle>☰</button>
    <nav class="site-nav" id="site-nav" aria-label="主导航" data-site-nav>
        <a href="{{ \App\Support\Site\SitePageUrl::to('home') }}" @if(($activeNav ?? '') === 'home') aria-current="page" @endif>首页</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('about') }}" @if(($activeNav ?? '') === 'about') aria-current="page" @endif>关于我们</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('products') }}" @if(($activeNav ?? '') === 'products') aria-current="page" @endif>产品服务</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('news') }}" @if(($activeNav ?? '') === 'news') aria-current="page" @endif>资讯中心</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('contact') }}" @if(($activeNav ?? '') === 'contact') aria-current="page" @endif>联系方式</a>
    </nav>
</div></header>


