@extends('admin.layouts.app')

@section('content')
    <div class="mx-auto max-w-3xl space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">分发媒体接口配置</h1>
            <p class="mt-1 text-sm text-gray-600">配置小青蛙开放平台 API，用于同步媒体资源和提交投稿订单。</p>
        </div>

        <form method="POST" action="{{ route('admin.media-distribution.settings.update') }}" class="space-y-5 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            @csrf
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">API Base URL</label>
                <input name="api_base_url" required value="{{ old('api_base_url', $setting?->api_base_url ?? config('media_distribution.base_url')) }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">API Key</label>
                <input name="api_key" value="" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="{{ $maskedApiKey !== '' ? $maskedApiKey : '首次配置请输入 API Key' }}">
                <p class="mt-1 text-xs text-gray-500">留空表示保持原 API Key 不变。</p>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">状态</label>
                <select name="status" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                    <option value="active" @selected(old('status', $setting?->status ?? 'active') === 'active')>启用</option>
                    <option value="inactive" @selected(old('status', $setting?->status ?? 'active') === 'inactive')>停用</option>
                </select>
            </div>
            <div class="flex items-center justify-between">
                <a href="{{ route('admin.media-distribution.resources.index') }}" class="text-sm font-medium text-slate-600 hover:text-slate-900">返回媒体资源</a>
                <button type="submit" class="inline-flex h-10 items-center gap-2 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white hover:bg-indigo-700">
                    <i data-lucide="save" class="h-4 w-4"></i>
                    保存配置
                </button>
            </div>
        </form>
    </div>
@endsection
