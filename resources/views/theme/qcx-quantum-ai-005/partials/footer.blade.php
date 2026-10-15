@php($site = $themeData['site'] ?? [])
<footer class="site-footer">
    <p><strong>QUANTUM AI</strong><br>{{ $site['footer_text'] ?? '让数据成为增长的答案。' }}</p>
    <nav class="footer-nav" aria-label="页脚导航">
        <a href="{{ \App\Support\Site\SitePageUrl::to('home') }}">首页</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('about') }}">关于我们</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('products') }}">产品服务</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('news') }}">资讯</a>
        <a href="{{ \App\Support\Site\SitePageUrl::to('contact') }}">联系我们</a>
    </nav>
    <p>© {{ date('Y') }} {{ $site['copyright'] ?? 'Quantum AI' }}</p>
</footer>
