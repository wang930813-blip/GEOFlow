<footer class="site-footer">
    <div><a class="brand" href="{{ \App\Support\Site\SitePageUrl::to('home') }}">{{ $themeData['site']['brand_name'] }}</a><p>{{ $themeData['site']['brand_tagline'] }}</p></div>
    <nav class="footer-nav" aria-label="页脚导航">
        <a href="{{ \App\Support\Site\SitePageUrl::to('home') }}">首页</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('about') }}">关于我们</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('products') }}">产品服务</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('news') }}">资讯中心</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('contact') }}">联系我们</a>
    </nav>
    <p>{{ $themeData['site']['footer_text'] }}<br>&copy; {{ date('Y') }} {{ $themeData['site']['brand_name'] }}<br><a href="https://colorlib.com/wp/template/itkol/" rel="noopener noreferrer">视觉素材：Colorlib</a></p>
</footer>

