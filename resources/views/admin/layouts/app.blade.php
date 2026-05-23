@php
    $adminBrandName = \App\Support\AdminWeb::siteName();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@isset($pageTitle){{ $pageTitle }} - @endisset{{ $adminBrandName }}</title>
    <script src="{{ asset('js/tailwindcss.play-cdn.js') }}"></script>
    <script src="{{ asset('js/lucide.min.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('assets/css/admin.css') }}?v={{ filemtime(public_path('assets/css/admin.css')) }}">
    @stack('styles')
</head>
<body class="admin-ui">
@include('admin.partials.header', [
    'adminBrandName' => $adminBrandName,
    'adminSiteName' => $adminSiteName ?? $adminBrandName,
    'pageTitle' => $pageTitle ?? '',
    'activeMenu' => $activeMenu ?? '',
    'currentSite' => $currentSite ?? null,
    'availableSites' => $availableSites ?? collect(),
])
    <main class="max-w-7xl mx-auto px-4 py-6 sm:px-6 lg:px-8">
        @if (session('message'))
            <div class="admin-flash-alert mb-4 border px-4 py-3 relative" style="background: rgba(76, 175, 80, 0.12); border-color: rgba(76, 175, 80, 0.28); color: #2f7d32;">
                <span class="block sm:inline">{{ session('message') }}</span>
            </div>
        @endif
        @if ($errors->any())
            <div class="admin-flash-alert mb-4 border px-4 py-3 relative" style="background: rgba(255, 87, 34, 0.12); border-color: rgba(255, 87, 34, 0.28); color: #d63b1f;">
                @foreach ($errors->all() as $err)
                    <div>{{ $err }}</div>
                @endforeach
            </div>
        @endif
        @yield('content')
    </main>
@include('admin.partials.footer')
@include('admin.partials.welcome-modal')
@stack('scripts')
</body>
</html>
