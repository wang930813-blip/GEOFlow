@extends('admin.layouts.app')

@section('content')
    <div class="mx-auto max-w-2xl space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">修改密码</h1>
            <p class="mt-1 text-sm text-gray-600">修改成功后当前会话会退出，需要使用新密码重新登录。</p>
        </div>

        <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            <form method="POST" action="{{ route('admin.security-settings.password.update') }}" class="space-y-5">
                @csrf
                <div>
                    <label for="current_password" class="mb-1 block text-sm font-medium text-gray-700">当前密码</label>
                    <input id="current_password" name="current_password" type="password" required autocomplete="current-password" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="new_password" class="mb-1 block text-sm font-medium text-gray-700">新密码</label>
                        <input id="new_password" name="new_password" type="password" required autocomplete="new-password" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                    </div>
                    <div>
                        <label for="confirm_password" class="mb-1 block text-sm font-medium text-gray-700">确认新密码</label>
                        <input id="confirm_password" name="confirm_password" type="password" required autocomplete="new-password" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                    </div>
                </div>
                <div class="flex justify-end border-t border-slate-200 pt-5">
                    <button type="submit" class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-indigo-600 px-5 text-sm font-medium text-white transition hover:bg-indigo-700">
                        <i data-lucide="key-round" class="h-4 w-4"></i>
                        保存新密码
                    </button>
                </div>
            </form>
        </section>
    </div>
@endsection
