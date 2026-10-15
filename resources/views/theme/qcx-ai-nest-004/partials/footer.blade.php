@php($site = $themeData['site'] ?? [])
<footer class="site-footer">
    <p><strong>{{ $site['brand_name'] ?? '' }}</strong><br>{{ $site['footer_text'] ?? '' }}</p>
    <nav class="footer-nav" aria-label="页脚导航">
        <a href="{{ \App\Support\Site\SitePageUrl::to('about') }}">关于我们</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('products') }}">产品服务</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('news') }}">资讯中心</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('contact') }}">联系我们</a>
    </nav>
    <p>© {{ date('Y') }} {{ $site['copyright'] ?: ($site['brand_name'] ?? '') }}</p>
</footer>
