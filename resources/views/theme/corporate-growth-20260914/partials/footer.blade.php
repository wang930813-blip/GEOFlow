<footer class="cg-footer">
    <div class="cg-shell cg-footer__grid">
        <div>
            <div class="cg-footer__brand">{{ $siteName }}</div>
            @if(!empty($siteRemark))
                <p>{!! nl2br(e($siteRemark)) !!}</p>
            @endif
        </div>
        <div class="cg-footer__links">
            <a href="{{ route('site.news') }}">{{ app()->getLocale() === 'zh_CN' ? '资讯' : 'Insights' }}</a>
            <a href="{{ route('site.about') }}">{{ app()->getLocale() === 'zh_CN' ? '关于我们' : 'About' }}</a>
            <a href="{{ route('site.contact') }}">{{ app()->getLocale() === 'zh_CN' ? '联系我们' : 'Contact' }}</a>
        </div>
        <div class="cg-footer__meta">
            @if(!empty($companyAddress))
                <p>{{ $companyAddress }}</p>
            @endif
            @if(!empty($contactInfo))
                <p>{!! nl2br(e($contactInfo)) !!}</p>
            @endif
            <p>{{ $footerCopyright !== '' ? $footerCopyright : '© '.date('Y').' '.$siteName }}</p>
        </div>
    </div>
</footer>
