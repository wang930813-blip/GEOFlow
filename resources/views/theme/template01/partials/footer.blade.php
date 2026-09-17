<footer class="site-footer">
    <div class="container footer-main">
        <div class="footer-brand">
            @php
                $brandLogo = trim((string) ($template01['site']['logo'] ?? ''));
            @endphp
            <a class="brand" href="{{ \App\Support\Site\SitePageUrl::to('home') }}">
                @if($brandLogo !== '')
                    <img src="{{ $brandLogo }}" alt="{{ $template01['site']['brand_name'] }}" class="brand-logo">
                @else
                    <span class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></span>
                @endif
                <span data-content="site.brand_name">{{ $template01['site']['brand_name'] }}</span>
            </a>
            <p data-content="site.brand_tagline">{{ $template01['site']['brand_tagline'] }}</p>
        </div>
        <div class="footer-links">
            <div>
                <span class="footer-label">站点导航</span>
                <a href="{{ \App\Support\Site\SitePageUrl::to('home') }}">首页</a>
                <a href="{{ \App\Support\Site\SitePageUrl::to('about') }}">关于我们</a>
            </div>
            <div>
                <span class="footer-label">业务与动态</span>
                <a href="{{ \App\Support\Site\SitePageUrl::to('products') }}">产品服务</a>
                <a href="{{ \App\Support\Site\SitePageUrl::to('news') }}">资讯中心</a>
                <a href="{{ \App\Support\Site\SitePageUrl::to('contact') }}">联系我们</a>
            </div>
            <div>
                <span class="footer-label">品牌说明</span>
                <span data-content="site.footer_text">{{ $template01['site']['footer_text'] }}</span>
            </div>
        </div>
    </div>
    <div class="container footer-bottom">
        <span>© <span data-current-year>{{ now()->year }}</span> {{ $template01['site']['brand_name'] }}</span>
        <a href="#top">返回顶部 ↑</a>
    </div>
</footer>
<button class="back-to-top" type="button" data-back-to-top aria-label="返回顶部" title="返回顶部"><span aria-hidden="true">↑</span></button>
