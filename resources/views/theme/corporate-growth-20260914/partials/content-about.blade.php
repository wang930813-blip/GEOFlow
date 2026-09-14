<div id="mainContent" class="cg-shell cg-page">
    <section class="cg-page-hero cg-page-hero--wide">
        <span class="cg-eyebrow">ABOUT</span>
        <h1>{{ app()->getLocale() === 'zh_CN' ? '关于我们' : 'About us' }}</h1>
        <p>{{ $siteSubtitle !== '' ? $siteSubtitle : ($siteDescription !== '' ? $siteDescription : __('site.home_hero_fallback')) }}</p>
    </section>

    <section class="cg-about-grid">
        <div class="cg-about-copy">
            <span class="cg-eyebrow">{{ $siteTitle }}</span>
            <h2>{{ app()->getLocale() === 'zh_CN' ? '让官网承载品牌、内容与 AI 搜索增长' : 'An official website built for brand, content, and AI search growth' }}</h2>
            @if($siteRemark !== '')
                <p>{!! nl2br(e($siteRemark)) !!}</p>
            @else
                <p>{{ $siteDescription !== '' ? $siteDescription : __('site.home_hero_fallback') }}</p>
            @endif
        </div>
        <div class="cg-about-panel">
            <div><strong>01</strong><span>{{ app()->getLocale() === 'zh_CN' ? '品牌表达' : 'Brand narrative' }}</span></div>
            <div><strong>02</strong><span>{{ app()->getLocale() === 'zh_CN' ? '内容资产' : 'Content assets' }}</span></div>
            <div><strong>03</strong><span>{{ app()->getLocale() === 'zh_CN' ? '增长信号' : 'Growth signals' }}</span></div>
        </div>
    </section>
</div>
