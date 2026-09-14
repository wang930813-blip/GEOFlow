<div id="mainContent" class="cg-shell cg-page">
    <section class="cg-page-hero cg-page-hero--wide">
        <span class="cg-eyebrow">CONTACT</span>
        <h1>{{ app()->getLocale() === 'zh_CN' ? '联系我们' : 'Contact us' }}</h1>
        <p>{{ app()->getLocale() === 'zh_CN' ? '获取品牌增长、官网模板与内容发布相关信息。' : 'Get details about brand growth, official website templates, and content publishing.' }}</p>
    </section>

    <section class="cg-contact-grid">
        <div class="cg-contact-card">
            <i data-lucide="phone-call" aria-hidden="true"></i>
            <h2>{{ app()->getLocale() === 'zh_CN' ? '联系方式' : 'Contact details' }}</h2>
            @if(($contactInfoLines ?? []) !== [])
                @foreach($contactInfoLines as $line)
                    <p>{{ $line }}</p>
                @endforeach
            @elseif(!empty($contactInfo))
                <p>{!! nl2br(e($contactInfo)) !!}</p>
            @else
                <p>{{ app()->getLocale() === 'zh_CN' ? '暂未配置联系方式。' : 'Contact details have not been configured yet.' }}</p>
            @endif
        </div>
        <div class="cg-contact-card">
            <i data-lucide="map-pin" aria-hidden="true"></i>
            <h2>{{ app()->getLocale() === 'zh_CN' ? '公司地址' : 'Address' }}</h2>
            <p>{{ $companyAddress !== '' ? $companyAddress : (app()->getLocale() === 'zh_CN' ? '暂未配置公司地址。' : 'Address has not been configured yet.') }}</p>
        </div>
        @if($siteRemark !== '')
            <div class="cg-contact-card">
                <i data-lucide="notebook-text" aria-hidden="true"></i>
                <h2>{{ app()->getLocale() === 'zh_CN' ? '备注说明' : 'Notes' }}</h2>
                <p>{!! nl2br(e($siteRemark)) !!}</p>
            </div>
        @endif
    </section>

    @if(($contactPayments ?? []) !== [])
        <section class="cg-section">
            <div class="cg-section__head">
                <span class="cg-eyebrow">{{ app()->getLocale() === 'zh_CN' ? '二维码' : 'QR codes' }}</span>
                <h2>{{ app()->getLocale() === 'zh_CN' ? '联系二维码' : 'Contact QR codes' }}</h2>
            </div>
            <div class="cg-payment-grid">
                @foreach($contactPayments as $payment)
                    <div class="cg-payment-card">
                        @if($payment['qr_url'] !== '')
                            <img src="{{ $payment['qr_url'] }}" alt="{{ $payment['name'] !== '' ? $payment['name'] : 'QR code' }}" loading="lazy">
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
