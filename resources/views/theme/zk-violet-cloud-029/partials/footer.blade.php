<?php
/**
 * Created by Codex.
 * @Date: 2026-10-22
 * @Time: 17:05:42
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： footer.blade.php
 * @Description: 输出公共品牌说明、ICP备案号与统一站内导航。
 * @UpdateTime: 2026-11-18 16:26:24
 */
?>
<footer class="site-footer">
    <div class="footer-inner">
        <div class="footer-brand-block">
            <a class="brand" href="{{ \App\Support\Site\SitePageUrl::to('home') }}">
                <span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i></span>
                <span class="brand-name">{{ $themeData['site']['brand_name'] }}</span>
            </a>
            <p>{{ $themeData['site']['footer_text'] }}</p>
        </div>
        <nav class="footer-nav" aria-label="页脚导航">
            @foreach(data_get($themeData, 'navigation.items', []) as $navigationItem)
                <a href="{{ $navigationItem['url'] }}" data-site-nav-item="{{ $navigationItem['id'] }}"><span data-site-nav-label>{{ $navigationItem['label'] }}</span></a>
            @endforeach
        </nav>
        <p class="copyright">&copy; {{ date('Y') }} {{ $themeData['site']['brand_name'] }}</p>
        @if (trim((string) ($icpNumber ?? '')) !== '')
            <small class="website-icp-number">ICP备案号：{{ $icpNumber }}</small>
        @endif
    </div>
</footer>
