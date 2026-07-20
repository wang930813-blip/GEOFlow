<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>注册账号 - {{ $adminSiteName }}</title>
    <script src="{{ asset('js/tailwindcss.play-cdn.js') }}"></script>
    <script src="{{ asset('js/lucide.min.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('assets/css/admin.css') }}?v={{ filemtime(public_path('assets/css/admin.css')) }}">
</head>
<body class="admin-ui admin-login-page min-h-dvh overflow-y-auto">
<div class="mx-auto flex min-h-dvh w-full max-w-lg items-center px-4 py-8">
    <div class="w-full rounded-lg p-8 login-form">
        <div class="mb-8 text-center">
            <div class="login-badge mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-md">
                <i data-lucide="user-plus" class="h-8 w-8 text-white"></i>
            </div>
            <h1 class="mb-2 text-2xl font-bold text-gray-900">注册账号</h1>
            <p class="text-gray-600">注册后将自动开通平台直客体验规格</p>
        </div>

        @if ($errors->any())
            <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700" role="alert">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.register.store') }}" class="space-y-5">
            @csrf
            <div>
                <label for="display_name" class="mb-2 block text-sm font-medium text-gray-700">显示名称</label>
                <input type="text" id="display_name" name="display_name" required value="{{ old('display_name') }}"
                       class="block w-full rounded-lg border border-gray-300 px-3 py-3 focus:border-blue-500 focus:ring-2 focus:ring-blue-500"
                       placeholder="请输入联系人或品牌名称" autocomplete="name">
                @error('display_name')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="mobile" class="mb-2 block text-sm font-medium text-gray-700">手机号</label>
                <input type="tel" id="mobile" name="mobile" required value="{{ old('mobile') }}"
                       class="block w-full rounded-lg border border-gray-300 px-3 py-3 focus:border-blue-500 focus:ring-2 focus:ring-blue-500"
                       placeholder="请输入手机号，后续作为登录账号" autocomplete="username">
                @error('mobile')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="password" class="mb-2 block text-sm font-medium text-gray-700">密码</label>
                    <input type="password" id="password" name="password" required
                           class="block w-full rounded-lg border border-gray-300 px-3 py-3 focus:border-blue-500 focus:ring-2 focus:ring-blue-500"
                           placeholder="至少 8 位" autocomplete="new-password">
                    @error('password')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="password_confirmation" class="mb-2 block text-sm font-medium text-gray-700">确认密码</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" required
                           class="block w-full rounded-lg border border-gray-300 px-3 py-3 focus:border-blue-500 focus:ring-2 focus:ring-blue-500"
                           placeholder="再次输入密码" autocomplete="new-password">
                </div>
            </div>

            <div>
                <label for="captcha" class="mb-2 block text-sm font-medium text-gray-700">图形验证码</label>
                <div class="flex gap-3">
                    <input type="text" id="captcha" name="captcha" required
                           class="min-w-0 flex-1 rounded-lg border border-gray-300 px-3 py-3 uppercase focus:border-blue-500 focus:ring-2 focus:ring-blue-500"
                           placeholder="请输入验证码" autocomplete="off">
                    <button type="button"
                            class="inline-flex h-12 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-gray-200 bg-white px-2 hover:border-blue-200 hover:bg-blue-50"
                            data-refresh-captcha
                            aria-label="刷新验证码">
                        <img src="{{ route('admin.register.captcha') }}?v={{ uniqid() }}" alt="图形验证码" class="h-12 w-32" data-captcha-image>
                    </button>
                </div>
                @error('captcha')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @else
                    <p class="mt-1 text-xs text-gray-500">看不清可以点击验证码刷新</p>
                @enderror
            </div>

            <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-md bg-blue-600 px-4 py-3 font-medium text-white hover:bg-blue-700">
                <i data-lucide="user-plus" class="h-5 w-5"></i>
                注册并进入后台
            </button>
        </form>

        <div class="mt-6 text-center text-sm text-gray-600">
            <span>已有账号？</span>
            <a href="{{ route('admin.login') }}" class="font-medium text-blue-600 hover:text-blue-700">返回登录</a>
        </div>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof lucide !== 'undefined') lucide.createIcons();

        const button = document.querySelector('[data-refresh-captcha]');
        const image = document.querySelector('[data-captcha-image]');
        if (button && image) {
            button.addEventListener('click', function () {
                const url = new URL(image.src, window.location.origin);
                url.searchParams.set('v', Date.now().toString());
                image.src = url.toString();
            });
        }
    });
</script>
</body>
</html>
