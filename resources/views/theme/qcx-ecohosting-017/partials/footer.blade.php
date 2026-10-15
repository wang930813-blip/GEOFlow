<footer class="site-footer">
    <div class="footer-inner">
        <div><a class="brand" href="{{ \App\Support\Site\SitePageUrl::to('home') }}"><img src="{{ asset('themes/qcx-ecohosting-017/assets/logo.png') }}" alt="{{ $themeData['site']['brand_name'] }}" width="204" height="42"></a><p>{{ $themeData['site']['footer_text'] }}</p></div>
        <div class="footer-links"><a href="{{ \App\Support\Site\SitePageUrl::to('products') }}">套餐</a><a href="{{ \App\Support\Site\SitePageUrl::to('about') }}">帮助</a><a href="{{ \App\Support\Site\SitePageUrl::to('news') }}">博客</a><a href="{{ \App\Support\Site\SitePageUrl::to('contact') }}">联系信息</a></div>
        <p class="copyright">&copy; {{ date('Y') }} {{ $themeData['site']['brand_name'] }}</p>
    </div>
</footer>
