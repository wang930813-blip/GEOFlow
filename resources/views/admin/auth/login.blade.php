<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('admin.login.title') }} - {{ $adminSiteName }}</title>
    <script src="{{ asset('js/tailwindcss.play-cdn.js') }}"></script>
    <script src="{{ asset('js/lucide.min.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('assets/css/admin.css') }}?v={{ filemtime(public_path('assets/css/admin.css')) }}">
</head>
<body class="admin-ui admin-login-page overflow-hidden">
<div class="fixed right-4 top-4 z-50">
    <div class="relative" data-admin-locale-menu>
        <button onclick="toggleLocaleMenu()" type="button" class="flex h-8 items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 text-xs font-medium text-gray-600 shadow-sm hover:bg-gray-50">
            <i data-lucide="languages" class="h-3.5 w-3.5"></i>
            <span>{{ \App\Support\AdminWeb::supportedLocales()[app()->getLocale()] ?? app()->getLocale() }}</span>
            <i data-lucide="chevron-down" class="h-3 w-3"></i>
        </button>
        <div id="locale-menu" class="admin-menu-panel hidden absolute right-0 mt-2 w-40 overflow-hidden rounded-md border bg-white py-1 z-50">
            @foreach (\App\Support\AdminWeb::supportedLocales() as $localeCode => $localeLabel)
                <a href="{{ route('admin.locale.switch', ['locale' => $localeCode]) }}"
                   class="flex items-center justify-between gap-2 rounded-md px-3 py-2 text-sm text-gray-700 hover:bg-gray-100">
                    <span class="truncate">{{ $localeLabel }}</span>
                    @if(app()->getLocale() === $localeCode)
                        <i data-lucide="check" class="h-4 w-4 shrink-0"></i>
                    @endif
                </a>
            @endforeach
        </div>
    </div>
</div>
<div class="fixed top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-full max-w-md px-4">
    <div class="rounded-lg p-8 login-form">
        <div class="text-center mb-8">
            <div class="login-badge w-16 h-16 rounded-md flex items-center justify-center mx-auto mb-4">
                <i data-lucide="shield-check" class="w-8 h-8 text-white"></i>
            </div>
            <h1 class="text-2xl font-bold text-gray-900 mb-2">{{ __('admin.login.title') }}</h1>
            <p class="text-gray-600">{{ __('admin.login.subtitle', ['site_name' => $adminSiteName]) }}</p>
        </div>
        @if (session('message'))
            <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm">
                {{ session('message') }}
            </div>
        @endif
        @if ($errors->any())
            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
                {{ $errors->first() }}
            </div>
        @endif
        <form method="POST" action="{{ route('admin.login.attempt') }}" class="space-y-6">
            @csrf
            <div>
                <label for="username" class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.login.username') }}</label>
                <input type="text" id="username" name="username" required value="{{ old('username') }}"
                       class="block w-full px-3 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                       placeholder="{{ __('admin.login.username_placeholder') }}" autocomplete="username">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.login.password') }}</label>
                <input type="password" id="password" name="password" required
                       class="block w-full px-3 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                       placeholder="{{ __('admin.login.password_placeholder') }}" autocomplete="current-password">
            </div>
            <input type="hidden" name="remember" value="0">
            <label class="flex items-center justify-between rounded-lg border border-gray-200 bg-white/70 px-3 py-3 text-sm text-gray-600">
                <span class="flex items-center gap-2">
                    <input type="checkbox" name="remember" value="1" checked class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <span>{{ __('admin.login.remember_30_days') }}</span>
                </span>
                <span class="text-xs text-gray-400">{{ __('admin.login.remember_30_days_hint') }}</span>
            </label>
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-3 px-4 rounded-md">
                {{ __('admin.login.submit') }}
            </button>
        </form>
    </div>
    <div class="text-center mt-6">
        <a href="{{ url('/') }}" class="text-gray-600 hover:text-gray-900 text-sm">{{ __('admin.login.back_home') }}</a>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof lucide !== 'undefined') lucide.createIcons();
    });

    function toggleLocaleMenu() {
        const menu = document.getElementById('locale-menu');
        if (menu) {
            menu.classList.toggle('hidden');
        }
    }

    document.addEventListener('click', function (event) {
        const localeMenu = document.getElementById('locale-menu');
        if (localeMenu && ! event.target.closest('[onclick="toggleLocaleMenu()"]') && ! localeMenu.contains(event.target)) {
            localeMenu.classList.add('hidden');
        }
    });
</script>
</body>
</html>
