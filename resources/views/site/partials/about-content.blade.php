@php
    $aboutTitle = trim((string) ($siteTitle ?? $siteName ?? config('app.name')));
    $aboutDescription = trim((string) ($siteRemark ?? ''));
    if ($aboutDescription === '') {
        $aboutDescription = trim((string) ($siteDescription ?? __('site.home_hero_fallback')));
    }
@endphp

<p class="about-lede">
    {{ $aboutDescription }}
</p>

<h2>{{ $aboutTitle }}</h2>
<p>
    {{ app()->getLocale() === 'zh_CN'
        ? '我们围绕品牌官网、内容资产与 AI 搜索可见度构建持续运营能力，让官网既能承载品牌表达，也能沉淀可被检索、引用和转化的可信内容。'
        : 'We build around official websites, content assets, and AI search visibility so the site can carry brand narrative and reusable, searchable, conversion-ready knowledge.' }}
</p>

<h2>{{ app()->getLocale() === 'zh_CN' ? '核心能力' : 'Core capabilities' }}</h2>
<dl class="about-capabilities">
    <div>
        <dt>{{ app()->getLocale() === 'zh_CN' ? '官网呈现' : 'Official website' }}</dt>
        <dd>{{ app()->getLocale() === 'zh_CN' ? '通过多套官网模板承载品牌、产品、案例、资讯与联系信息。' : 'Present brand, products, cases, insights, and contact information through multiple official-site templates.' }}</dd>
    </div>
    <div>
        <dt>{{ app()->getLocale() === 'zh_CN' ? '内容资产' : 'Content assets' }}</dt>
        <dd>{{ app()->getLocale() === 'zh_CN' ? '把文章、分类、SEO、信源与运营数据统一进入前台展示链路。' : 'Connect articles, categories, SEO, sources, and operating data into the public site experience.' }}</dd>
    </div>
    <div>
        <dt>{{ app()->getLocale() === 'zh_CN' ? '增长转化' : 'Growth conversion' }}</dt>
        <dd>{{ app()->getLocale() === 'zh_CN' ? '通过首屏、服务、指标、CTA 与资讯模块提升官网转化感。' : 'Use hero, service, metric, CTA, and insight sections to make the site feel conversion-oriented.' }}</dd>
    </div>
</dl>
