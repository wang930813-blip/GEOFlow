<a class="skip-link" href="#main-content">跳到主要内容</a>
<header class="site-header"><div class="shell header-inner">
    <a class="brand" href="{{ \App\Support\Site\SitePageUrl::to('home') }}" aria-label="IONIQ 首页"><img src="{{ asset('themes/qcx-ioniq-crm-024/assets/reference/logo.svg') }}" alt="IONIQ" width="132" height="30"><span class="sr-only">{{ $themeData['site']['brand_name'] ?? 'IONIQ' }}</span></a>
    <button class="menu-toggle" type="button" aria-label="打开导航菜单" aria-expanded="false" aria-controls="site-nav" data-menu-toggle>☰</button>
    <nav class="site-nav" id="site-nav" aria-label="主导航" data-site-nav>
        <a href="{{ \App\Support\Site\SitePageUrl::to('home') }}" data-nav="home">首页</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('about') }}" data-nav="about">关于我们</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('products') }}" data-nav="products">产品服务</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('news') }}" data-nav="news">资讯中心</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('contact') }}" data-nav="contact">联系我们</a>
    </nav>
</div></header>
