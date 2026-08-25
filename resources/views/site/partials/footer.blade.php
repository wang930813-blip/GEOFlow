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
<footer class="bg-white border-t border-gray-100 mt-16">
    <div class="site-container px-4 sm:px-6 lg:px-8 py-8">
        @if ($hasContactBlock)
            <div class="grid grid-cols-1 gap-8 md:grid-cols-2 lg:grid-cols-4 mb-8 text-sm text-gray-600">
                @if ($contactInfoLines !== [])
                    <div>
                        <h3 class="text-gray-900 font-semibold mb-3">联系方式</h3>
                        <ul class="space-y-1">
                            @foreach ($contactInfoLines as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($companyAddress !== '')
                    <div>
                        <h3 class="text-gray-900 font-semibold mb-3">公司地址</h3>
                        <p>{{ $companyAddress }}</p>
                    </div>
                @endif

                @if ($contactPayments !== [])
                    <div>
                        <h3 class="text-gray-900 font-semibold mb-3">微信 / 支付宝</h3>
                        <div class="flex flex-wrap gap-3">
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
                                <div class="text-center">
                                    @if ($qrUrl !== '')
                                        <img src="{{ $qrUrl }}" alt="{{ $displayLabel }}" class="w-24 h-24 object-cover rounded" loading="lazy">
                                    @endif
                                    <div class="text-xs mt-1 text-gray-700">{{ $displayLabel }}</div>
                                    @if ($account !== '')
                                        <div class="text-xs text-gray-500">{{ $account }}</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($siteRemark !== '')
                    <div>
                        <h3 class="text-gray-900 font-semibold mb-3">官网备注</h3>
                        <p>{!! nl2br(e($siteRemark)) !!}</p>
                    </div>
                @endif
            </div>
        @endif

        <div class="text-center pt-6 border-t border-gray-100">
            <p class="text-gray-500 text-sm">{{ $footerCopyright !== '' ? $footerCopyright : '© '.date('Y').' '.$siteName }}</p>
        </div>
    </div>
</footer>
