<footer class="tx-footer">
    <div class="tx-shell tx-footer-grid">
        <div>
            <div class="tx-footer-brand">{{ $siteName }}</div>
            @if(!empty($siteDescription))
                <p>{{ $siteDescription }}</p>
            @endif
        </div>
        <div class="tx-footer-meta">
            @if(!empty($companyAddress))
                <p>{{ $companyAddress }}</p>
            @endif
            @if(!empty($contactInfo))
                <p>{{ $contactInfo }}</p>
            @endif
            <p>{{ $footerCopyright !== '' ? $footerCopyright : '© '.date('Y').' '.$siteName }}</p>
        </div>
    </div>
</footer>
