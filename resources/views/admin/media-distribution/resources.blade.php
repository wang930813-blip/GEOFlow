@extends('admin.layouts.app')

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">分发媒体</h1>
                <p class="mt-1 text-sm text-gray-600">同步网站媒体和第三方自媒体资源，按销售价给站点投稿消耗积分。</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.media-distribution.submissions.index') }}" class="inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    <i data-lucide="send" class="h-4 w-4"></i>
                    投稿订单
                </a>
                <a href="{{ route('admin.media-distribution.credits.index') }}" class="inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    <i data-lucide="coins" class="h-4 w-4"></i>
                    积分
                </a>
                @if ($isSuperAdmin)
                    <a href="{{ route('admin.media-distribution.settings.index') }}" class="inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50">
                        <i data-lucide="settings" class="h-4 w-4"></i>
                        接口配置
                    </a>
                    <form method="POST" action="{{ route('admin.media-distribution.resources.sync') }}">
                        @csrf
                        <button type="submit" class="inline-flex h-10 items-center gap-2 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white hover:bg-indigo-700">
                            <i data-lucide="refresh-cw" class="h-4 w-4"></i>
                            同步资源
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <form method="GET" action="{{ route('admin.media-distribution.resources.index') }}" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-6">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">媒体类型</label>
                    <select name="source_type" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                        <option value="">全部</option>
                        <option value="website_media" @selected($sourceType === 'website_media')>网站媒体</option>
                        <option value="zi_media" @selected($sourceType === 'zi_media')>第三方自媒体</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">分类</label>
                    <select name="category" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                        <option value="">全部</option>
                        @foreach ($categories as $item)
                            <option value="{{ $item }}" @selected($category === $item)>{{ $item }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">状态</label>
                    <select name="status" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                        <option value="">全部</option>
                        <option value="active" @selected($status === 'active')>可投稿</option>
                        <option value="inactive" @selected($status === 'inactive')>不可用</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-gray-700">搜索媒体</label>
                    <input name="search" value="{{ $search }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="输入媒体名称">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">积分价</label>
                    <div class="grid grid-cols-2 gap-2">
                        <input name="min_price" value="{{ $minPrice }}" class="h-10 rounded-md border border-slate-200 px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="最低">
                        <input name="max_price" value="{{ $maxPrice }}" class="h-10 rounded-md border border-slate-200 px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="最高">
                    </div>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="inline-flex h-10 w-full items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50">
                        <i data-lucide="search" class="h-4 w-4"></i>
                        筛选
                    </button>
                </div>
            </div>
        </form>

        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">媒体</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">备注</th>
                            @if ($isSuperAdmin)
                                <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">成本价</th>
                            @endif
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">积分价</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">状态</th>
                            <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-500">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @forelse ($resources as $resource)
                            <tr>
                                <td class="px-5 py-4 align-top">
                                    <div class="font-medium text-gray-900">{{ $resource->title }}</div>
                                    <div class="mt-1 text-xs text-gray-500">{{ $resource->sourceLabel() }} · {{ $resource->external_resource_id }}</div>
                                    @if($resource->case_link !== '')
                                        <a href="{{ $resource->case_link }}" target="_blank" rel="noopener noreferrer" class="mt-1 inline-flex text-xs text-indigo-600 hover:text-indigo-800">案例链接</a>
                                    @endif
                                </td>
                                <td class="max-w-md px-5 py-4 align-top text-sm text-gray-600">{{ $resource->remarks }}</td>
                                @if ($isSuperAdmin)
                                    <td class="px-5 py-4 align-top text-sm text-gray-700">{{ $resource->cost_price }}</td>
                                @endif
                                <td class="px-5 py-4 align-top">
                                    @if ($isSuperAdmin)
                                        <form method="POST" action="{{ route('admin.media-distribution.resources.price', ['resource' => $resource->id]) }}" class="flex items-center gap-2">
                                            @csrf
                                            <input name="sale_price" value="{{ $resource->sale_price }}" class="h-9 w-24 rounded-md border border-slate-200 px-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                                            <button class="h-9 rounded-md bg-slate-900 px-3 text-xs font-medium text-white hover:bg-slate-700">保存</button>
                                        </form>
                                    @else
                                        <span class="font-medium text-gray-900">{{ $resource->sale_price }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $resource->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-600' }}">
                                        {{ $resource->status === 'active' ? '可投稿' : '不可用' }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 align-top text-right">
                                    <a href="{{ route('admin.media-distribution.submissions.index', ['media_resource_id' => $resource->id]) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">去投稿</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $isSuperAdmin ? 6 : 5 }}" class="px-5 py-10 text-center text-sm text-gray-500">暂无媒体资源，请先同步。</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-5 py-4">
                {{ $resources->links() }}
            </div>
        </div>
    </div>
@endsection
