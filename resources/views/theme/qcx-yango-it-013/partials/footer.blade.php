<footer class="site-footer">
    <div>
        <img src="{{ asset('themes/qcx-yango-it-013/assets/logo.png') }}" alt="" width="150" height="55">
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
