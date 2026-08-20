@extends('theme.tech-insight-20260819.layout')

@push('head')
    @php
        $schemaAtContext = chr(64).'context';
        $schemaAtType = chr(64).'type';
        $pageSchema = [
            $schemaAtContext => 'https://schema.org',
            $schemaAtType => 'AboutPage',
            'name' => $pageTitle,
            'description' => $pageDescription,
            'url' => $canonicalUrl ?? route('site.about'),
        ];
    @endphp
    <script type="application/ld+json">
        {!! json_encode($pageSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
    </script>
@endpush

@section('content')
    <div id="mainContent" class="tx-shell tx-page-shell">
        <section class="tx-page-hero tx-page-hero--about">
            <img src="{{ asset('themes/tech-insight-20260819/assets/tech-banner-service.png') }}" alt="关于我们" loading="eager">
            <div class="tx-page-hero__content">
                <span class="tx-eyebrow">ABOUT</span>
                <h1>关于我们</h1>
                <p>{{ $siteSubtitle !== '' ? $siteSubtitle : ($siteDescription !== '' ? $siteDescription : __('site.home_hero_fallback')) }}</p>
            </div>
        </section>

        <section class="tx-about-grid">
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
    </div>
@endsection
