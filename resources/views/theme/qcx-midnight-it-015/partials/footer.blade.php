<footer class="site-footer">
    <div><a class="brand" href="{{ \App\Support\Site\SitePageUrl::to('home') }}">{{ $themeData['site']['brand_name'] }}</a><p>{{ $themeData['site']['brand_tagline'] }}</p></div>
    <nav class="footer-nav" aria-label="页脚导航">
        @foreach(data_get($themeData, 'navigation.items', []) as $navigationItem)
            <a href="{{ $navigationItem['url'] }}" data-site-nav-item="{{ $navigationItem['id'] }}"><span data-site-nav-label>{{ $navigationItem['label'] }}</span></a>
        @endforeach
    </nav>
    <p>{{ $themeData['site']['footer_text'] }}<br>&copy; {{ date('Y') }} {{ $themeData['site']['brand_name'] }}<br><a href="https://colorlib.com/wp/template/itkol/" rel="noopener noreferrer">视觉素材：Colorlib</a></p>
</footer>

