{{-- Created by Codex. @Date: 2026-11-11 @Time: 10:46:30 @Author: cdkay --}}
@php
    $publicSettings = \App\Support\Site\SiteSettingsBag::all();
    $schemaName = trim((string) ($publicSettings['site_name'] ?? $siteName ?? ''));
    $schemaUrl = route('site.home');
    $activeTheme = trim((string) ($publicSettings['active_theme'] ?? ''));
    $hasThemeWebsiteSchema = $activeTheme !== '' && is_file(resource_path('views/theme/'.$activeTheme.'/layout.blade.php'));
    $schemaGraph = $hasThemeWebsiteSchema ? [] : [[
        '@type' => 'WebSite',
        '@id' => $schemaUrl.'#website',
        'name' => $schemaName,
        'url' => $schemaUrl,
        'description' => (string) ($publicSettings['site_description'] ?? ''),
    ]];
    $organizationName = trim((string) ($publicSettings['organization_name'] ?? ''));
    if ($organizationName !== '') {
        $organization = [
            '@type' => 'Organization',
            '@id' => $schemaUrl.'#organization',
            'name' => $organizationName,
            'url' => trim((string) ($publicSettings['organization_url'] ?? '')) ?: $schemaUrl,
        ];
        foreach ([
            'legalName' => 'organization_legal_name',
            'description' => 'organization_description',
            'logo' => 'organization_logo',
            'email' => 'organization_email',
            'telephone' => 'organization_telephone',
        ] as $schemaField => $settingKey) {
            $value = trim((string) ($publicSettings[$settingKey] ?? ''));
            if ($value !== '') {
                $organization[$schemaField] = $schemaField === 'logo' ? ['@type' => 'ImageObject', 'url' => $value] : $value;
            }
        }
        $address = trim((string) ($publicSettings['organization_address'] ?? ''));
        if ($address !== '') {
            $organization['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $address];
        }
        $sameAsValues = preg_split('/[\r\n,]+/', (string) ($publicSettings['organization_same_as'] ?? '')) ?: [];
        $sameAs = array_values(array_filter(array_map('trim', $sameAsValues), static function (string $url): bool {
            return filter_var($url, FILTER_VALIDATE_URL) !== false && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
        }));
        if ($sameAs !== []) {
            $organization['sameAs'] = $sameAs;
        }
        $schemaGraph[] = $organization;
    }
    $websiteSchema = ['@context' => 'https://schema.org', '@graph' => $schemaGraph];
@endphp
<meta property='og:site_name' content='{{ $schemaName }}'>
<meta property='og:type' content='website'>
<meta property='og:title' content='{{ $pageTitle ?? $schemaName }}'>
<meta property='og:description' content='{{ $pageDescription ?? ($publicSettings['site_description'] ?? '') }}'>
<meta property='og:url' content='{{ $canonicalUrl ?? url()->current() }}'>
@if ($schemaGraph !== [])
    <script type='application/ld+json'>{!! json_encode($websiteSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endif
