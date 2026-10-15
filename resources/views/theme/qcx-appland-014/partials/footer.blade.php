<footer class="site-footer">
    <div>
        <a class="brand" href="{{ \App\Support\Site\SitePageUrl::to('home') }}">{{ $themeData['site']['brand_name'] }}</a>
        <p>{{ $themeData['site']['footer_text'] }}</p>
    </div>
    <nav aria-label="页脚导航">
        <a href="{{ \App\Support\Site\SitePageUrl::to('home') }}">首页</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('products') }}">服务</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('about') }}">关于</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('news') }}">资讯</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('contact') }}">联系信息</a>
    </nav>
    <p>&copy; {{ date('Y') }} {{ $themeData['site']['brand_name'] }}</p>
</footer>
