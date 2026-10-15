<footer class="site-footer">
    <div class="footer-inner">
        <div><a class="brand" href="{{ \App\Support\Site\SitePageUrl::to('home') }}"><img src="{{ asset('themes/qcx-expomarketing-016/assets/logo.png') }}" alt="{{ $themeData['site']['brand_name'] }}" width="154" height="49"></a><p>{{ $themeData['site']['footer_text'] }}</p></div>
        <div class="footer-links"><a href="{{ \App\Support\Site\SitePageUrl::to('about') }}">关于</a><a href="{{ \App\Support\Site\SitePageUrl::to('products') }}">服务</a><a href="{{ \App\Support\Site\SitePageUrl::to('news') }}">博客</a><a href="{{ \App\Support\Site\SitePageUrl::to('contact') }}">联系信息</a></div>
        <p class="copyright">&copy; {{ date('Y') }} {{ $themeData['site']['brand_name'] }}</p>
    </div>
</footer>
