@php
    $contactInfo = trim((string) ($contactInfo ?? ''));
    $companyAddress = trim((string) ($companyAddress ?? ''));
    $siteRemark = trim((string) ($siteRemark ?? ''));
    $contactPayments = $contactPayments ?? [];
    $contactInfoLines = $contactInfo !== ''
        ? array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $contactInfo) ?: []), static fn ($line) => $line !== ''))
        : [];
    $hasContactBlock = $contactInfoLines !== [] || $companyAddress !== '' || $siteRemark !== '' || $contactPayments !== [];
@endphp
<footer class="ne-footer">
    <div class="ne-shell">
        @if ($hasContactBlock)
            <div class="ne-footer-contact tt-footer-contact" data-site-footer-contact>
                @if ($contactInfoLines !== [])
                    <div class="tt-footer-contact-block">
                        <div class="tt-footer-contact-title">联系方式</div>
                        <ul class="tt-footer-contact-list">
                            @foreach ($contactInfoLines as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($companyAddress !== '')
                    <div class="tt-footer-contact-block">
                        <div class="tt-footer-contact-title">公司地址</div>
                        <p class="tt-footer-contact-text">{{ $companyAddress }}</p>
                    </div>
                @endif

                @if ($contactPayments !== [])
                    <div class="tt-footer-contact-block">
                        <div class="tt-footer-contact-title">微信 / 支付宝</div>
                        <div class="tt-footer-contact-payments">
                            @foreach ($contactPayments as $payment)
                                @php
                                    $type = (string) ($payment['type'] ?? '');
                                    $name = trim((string) ($payment['name'] ?? ''));
                                    $qrUrl = trim((string) ($payment['qr_url'] ?? ''));
                                    $account = trim((string) ($payment['account'] ?? ''));
                                    $defaultLabel = match ($type) {
                                        'wechat' => '微信',
                                        'alipay' => '支付宝',
                                        'bank' => '银行 / 对公',
                                        default => '收款方式',
                                    };
                                    $displayLabel = $name !== '' ? $name : $defaultLabel;
                                @endphp
                                <div class="tt-footer-payment">
                                    @if ($qrUrl !== '')
                                        <img src="{{ $qrUrl }}" alt="{{ $displayLabel }}" loading="lazy">
                                    @else
                                        <div class="tt-footer-payment-placeholder">{{ $displayLabel }}</div>
                                    @endif
                                    <div class="tt-footer-payment-meta">
                                        <span class="tt-footer-payment-name">{{ $displayLabel }}</span>
                                        @if ($account !== '')
                                            <span class="tt-footer-payment-account">{{ $account }}</span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($siteRemark !== '')
                    <div class="tt-footer-contact-block tt-footer-contact-remark">
                        <div class="tt-footer-contact-title">官网备注</div>
                        <p class="tt-footer-contact-text">{!! nl2br(e($siteRemark)) !!}</p>
                    </div>
                @endif
            </div>
        @endif

        <div class="ne-footer-inner">
            {{ $footerCopyright !== '' ? $footerCopyright : '© '.date('Y').' '.$siteName.'. All rights reserved.' }}
        </div>
    </div>
</footer>
