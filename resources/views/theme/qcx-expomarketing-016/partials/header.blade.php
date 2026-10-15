<a class="skip-link" href="#main-content">跳到主要内容</a>
<header class="site-header">
    <div class="header-inner">
        <a class="brand" href="{{ \App\Support\Site\SitePageUrl::to('home') }}" aria-label="返回首页"><img src="{{ asset('themes/qcx-expomarketing-016/assets/logo.png') }}" alt="{{ $themeData['site']['brand_name'] }}" width="154" height="49"></a>
        <button class="menu-toggle" type="button" aria-expanded="false" aria-controls="site-nav" data-menu-toggle>菜单</button>
        <nav id="site-nav" class="site-nav" aria-label="主导航" data-site-nav>
            <a href="{{ \App\Support\Site\SitePageUrl::to('home') }}" @if(($activeNav ?? '') === 'home') aria-current="page" @endif>首页</a>
            <a href="{{ \App\Support\Site\SitePageUrl::to('about') }}" @if(($activeNav ?? '') === 'about') aria-current="page" @endif>关于</a>
            <a href="{{ \App\Support\Site\SitePageUrl::to('products') }}" @if(($activeNav ?? '') === 'products') aria-current="page" @endif>服务</a>
            <a href="{{ \App\Support\Site\SitePageUrl::to('news') }}" @if(($activeNav ?? '') === 'news') aria-current="page" @endif>博客</a>
            <a href="{{ \App\Support\Site\SitePageUrl::to('contact') }}" @if(($activeNav ?? '') === 'contact') aria-current="page" @endif>联系信息</a>
        </nav>
    </div>
</header>
