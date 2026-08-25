<section class="tx-about-grid {{ $brandIntroClass ?? '' }}">
    <div class="tx-about-main">
        <span class="tx-eyebrow">品牌介绍</span>
        <h2>{{ $siteTitle }}</h2>
        <p>{{ $siteDescription !== '' ? $siteDescription : __('site.home_hero_fallback') }}</p>
    </div>

    <div class="tx-about-cards">
        <div class="tx-about-card">
            <i data-lucide="sparkles" aria-hidden="true"></i>
            <strong>内容建设</strong>
            <p>围绕品牌、行业与用户搜索意图组织内容，让信息更容易被阅读与引用。</p>
        </div>
        <div class="tx-about-card">
            <i data-lucide="radio-tower" aria-hidden="true"></i>
            <strong>信息发布</strong>
            <p>保持内容结构清晰、分类明确，便于持续发布与长期沉淀。</p>
        </div>
        <div class="tx-about-card">
            <i data-lucide="line-chart" aria-hidden="true"></i>
            <strong>数据观察</strong>
            <p>通过文章、分类与站点信息形成可持续更新的品牌内容窗口。</p>
        </div>
    </div>
</section>
