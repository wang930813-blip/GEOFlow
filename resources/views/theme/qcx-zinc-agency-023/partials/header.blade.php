<a class="skip-link" href="#main-content">跳到主要内容</a>
<header class="site-header"><div class="shell header-inner">
    <a class="brand" href="{{ \App\Support\Site\SitePageUrl::to('home') }}" aria-label="Zinc 首页"><img src="{{ asset('themes/qcx-zinc-agency-023/assets/reference/logo.svg') }}" alt="Zinc" width="115" height="26"></a>
    <button class="menu-toggle" type="button" aria-label="打开导航菜单" aria-expanded="false" aria-controls="site-nav" data-menu-toggle>菜单</button>
    <nav class="site-nav" id="site-nav" aria-label="主导航" data-site-nav>
        <a href="{{ \App\Support\Site\SitePageUrl::to('home') }}#header">首页</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('home') }}#services">服务</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('home') }}#projects">项目</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('home') }}#pricing">价格</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('about') }}">更多</a>
    </nav>
</div></header>