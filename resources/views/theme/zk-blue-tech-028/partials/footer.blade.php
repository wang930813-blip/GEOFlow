@php
    $site = data_get($themeData, 'site', []);
@endphp
<footer class="site-footer">
    <div class="footer-main section-shell">
        <div class="footer-brand">
            <a class="brand" href="{{ \App\Support\Site\SitePageUrl::to('home') }}">
                <span class="brand-mark" aria-hidden="true"><i></i><i></i></span>
                <span>{{ data_get($site, 'brand_name') }}</span>
            </a>
            <p>{{ data_get($site, 'brand_tagline') }}</p>
        </div>
        <nav class="footer-nav" aria-label="页脚导航">
            @foreach(data_get($themeData, 'navigation.items', []) as $navigationItem)
                <a href="{{ $navigationItem['url'] }}" data-site-nav-item="{{ $navigationItem['id'] }}"><span data-site-nav-label>{{ $navigationItem['label'] }}</span></a>
            @endforeach
        </nav>
        <p class="footer-note">{{ data_get($site, 'footer_text') }}</p>
    </div>
    <div class="footer-bottom section-shell">
        <span>© {{ data_get($site, 'copyright', now()->year) }} {{ data_get($site, 'brand_name') }}</span>
        <span>科技服务 · 清晰呈现</span>
    </div>
</footer>
