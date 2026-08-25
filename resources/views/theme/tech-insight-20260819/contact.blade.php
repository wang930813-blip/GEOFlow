@extends('theme.tech-insight-20260819.layout')

@push('head')
    @php
        $schemaAtContext = chr(64).'context';
        $schemaAtType = chr(64).'type';
        $pageSchema = [
            $schemaAtContext => 'https://schema.org',
            $schemaAtType => 'ContactPage',
            'name' => $pageTitle,
            'description' => $pageDescription,
            'url' => $canonicalUrl ?? route('site.contact'),
        ];
    @endphp
    <script type="application/ld+json">
        {!! json_encode($pageSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
    </script>
@endpush

@section('content')
    <div id="mainContent" class="tx-shell tx-page-shell">
        <section class="tx-page-hero tx-page-hero--contact">
            <img src="{{ asset('themes/tech-insight-20260819/assets/tech-banner-service.png') }}" alt="联系我们" loading="eager">
            <div class="tx-page-hero__content">
                <span class="tx-eyebrow">CONTACT</span>
                <h1>联系我们</h1>
                <p>获取品牌、内容与合作相关信息，可通过以下方式联系。</p>
            </div>
        </section>

        <section class="tx-contact-grid">
            <div class="tx-contact-card">
                <i data-lucide="phone-call" aria-hidden="true"></i>
                <h2>联系方式</h2>
                @if($contactInfoLines !== [])
                    <div class="tx-contact-lines">
                        @foreach($contactInfoLines as $line)
                            <p>{{ $line }}</p>
                        @endforeach
                    </div>
                @else
                    <p class="tx-muted">暂未配置联系方式。</p>
                @endif
            </div>

            <div class="tx-contact-card">
                <i data-lucide="map-pin" aria-hidden="true"></i>
                <h2>公司地址</h2>
                <p>{{ $companyAddress !== '' ? $companyAddress : '暂未配置公司地址。' }}</p>
            </div>

            @if($siteRemark !== '')
                <div class="tx-contact-card">
                    <i data-lucide="notebook-text" aria-hidden="true"></i>
                    <h2>备注说明</h2>
                    <p>{!! nl2br(e($siteRemark)) !!}</p>
                </div>
            @endif
        </section>

        @if($contactPayments !== [])
            <section class="tx-payment-section">
                <div class="tx-page-section-head">
                    <span class="tx-eyebrow">二维码</span>
                    <h2>联系二维码</h2>
                </div>
                <div class="tx-payment-grid">
                    @foreach($contactPayments as $payment)
                        <div class="tx-payment-card">
                            @if($payment['qr_url'] !== '')
                                <img src="{{ $payment['qr_url'] }}" alt="{{ $payment['name'] !== '' ? $payment['name'] : '二维码' }}" loading="lazy">
                            @endif
                            <strong>{{ $payment['name'] !== '' ? $payment['name'] : $payment['type'] }}</strong>
                            @if($payment['account'] !== '')
                                <span>{{ $payment['account'] }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
@endsection
