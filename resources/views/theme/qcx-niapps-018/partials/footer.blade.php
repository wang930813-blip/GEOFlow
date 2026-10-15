<footer class="site-footer">
    <div class="footer-inner">
        <div><a class="brand" href="{{ \App\Support\Site\SitePageUrl::to('home') }}"><img src="{{ asset('themes/qcx-niapps-018/assets/footer-logo.png') }}" alt="{{ $themeData['site']['brand_name'] }}" width="120" height="28"></a><p>{{ $themeData['site']['footer_text'] }}</p></div>
        <div class="footer-links"><a href="{{ \App\Support\Site\SitePageUrl::to('about') }}">关于我们</a><a href="{{ \App\Support\Site\SitePageUrl::to('products') }}">服务</a><a href="{{ \App\Support\Site\SitePageUrl::to('news') }}">项目</a><a href="{{ \App\Support\Site\SitePageUrl::to('contact') }}">联系信息</a></div>
        <p class="copyright">&copy; {{ date('Y') }} {{ $themeData['site']['brand_name'] }}</p>
    </div>
</footer>
