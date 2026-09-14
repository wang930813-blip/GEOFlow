@php
    $filingInfo = trim((string) ($footerFilingInfo ?? ''));
    $filingUrl = trim((string) ($footerFilingUrl ?? ''));
@endphp

@if($filingInfo !== '')
    <div class="mt-2 break-words">
        @if($filingUrl !== '')
            <a href="{{ $filingUrl }}" target="_blank" rel="nofollow noopener noreferrer" class="underline decoration-current underline-offset-4">
                {{ $filingInfo }}
            </a>
        @else
            <span>{{ $filingInfo }}</span>
        @endif
    </div>
@endif
