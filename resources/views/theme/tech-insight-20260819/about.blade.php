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

        @include('theme.tech-insight-20260819.partials.brand-intro')
    </div>
@endsection
