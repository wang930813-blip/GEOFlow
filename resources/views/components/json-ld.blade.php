@props(['data'])

@php
    $jsonLd = json_encode(
        $data,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
@endphp
<script type="application/ld+json">{!! $jsonLd !!}</script>
