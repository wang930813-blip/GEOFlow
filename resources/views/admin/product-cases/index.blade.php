@extends('admin.layouts.app')

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">产品案例管理</h1>
                <p class="mt-1 text-sm text-gray-600">维护公开产品案例，案例详情以手工内容为主，可关联一个站点/品牌展示监测数据摘要。</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.product-case-library.index') }}" target="_blank" rel="noopener noreferrer" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50">
                    <i data-lucide="external-link" class="h-4 w-4"></i>
                    公开列表
                </a>
                <a href="{{ route('admin.product-cases.create') }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700">
                    <i data-lucide="plus" class="h-4 w-4"></i>
                    新增案例
                </a>
            </div>
        </div>

        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <form method="GET" action="{{ route('admin.product-cases.index') }}" class="grid gap-4 md:grid-cols-[1fr_220px_auto]">
                <label class="block">
                    <span class="mb-1 block text-sm font-medium text-gray-700">搜索</span>
                    <input name="keyword" value="{{ $filters['keyword'] ?? '' }}" placeholder="标题 / 品牌 / 摘要" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                </label>
                <label class="block">
                    <span class="mb-1 block text-sm font-medium text-gray-700">状态</span>
                    <select name="status" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                        <option value="">全部状态</option>
                        @foreach($statusLabels as $statusKey => $statusLabel)
                            <option value="{{ $statusKey }}" @selected(($filters['status'] ?? '') === $statusKey)>{{ $statusLabel }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="flex items-end gap-2">
                    <button type="submit" class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-slate-900 px-4 text-sm font-medium text-white transition hover:bg-slate-800">
                        <i data-lucide="search" class="h-4 w-4"></i>
                        筛选
                    </button>
                    <a href="{{ route('admin.product-cases.index') }}" class="inline-flex h-10 items-center justify-center rounded-md border border-slate-200 bg-white px-3 text-sm font-medium text-slate-600 transition hover:bg-slate-50">重置</a>
                </div>
            </form>
        </section>

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-[1120px] divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">案例</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">关联站点</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">行业/地区</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">状态</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">发布时间</th>
                            <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-500">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @forelse($cases as $case)
                            <tr>
                                <td class="px-5 py-4 align-top">
                                    <div class="max-w-md font-semibold text-slate-950">{{ $case->title }}</div>
                                    <div class="mt-1 text-sm text-slate-500">{{ $case->company_name ?: '未设置品牌' }}</div>
                                    <div class="mt-1 text-xs text-slate-400">/{{ $case->slug }}</div>
                                </td>
                                <td class="px-5 py-4 align-top text-sm text-slate-600">
                                    @if($case->site)
                                        <div class="font-medium text-slate-900">{{ $case->site->name }}</div>
                                        <div class="mt-1 text-xs text-slate-400">{{ $case->site->domain ?: '未绑定域名' }}</div>
                                    @else
                                        <span class="text-slate-400">未关联</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 align-top text-sm text-slate-600">
                                    <div>{{ $case->industry ?: '未设置行业' }}</div>
                                    <div class="mt-1 text-xs text-slate-400">{{ $case->region ?: '未设置地区' }}</div>
                                </td>
                                <td class="px-5 py-4 align-top">
                                    @php
                                        $statusClass = match($case->status) {
                                            \App\Models\ProductCase::STATUS_PUBLISHED => 'bg-green-100 text-green-800',
                                            \App\Models\ProductCase::STATUS_HIDDEN => 'bg-slate-100 text-slate-700',
                                            default => 'bg-amber-100 text-amber-800',
                                        };
                                    @endphp
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $statusClass }}">{{ $statusLabels[$case->status] ?? $case->status }}</span>
                                </td>
                                <td class="px-5 py-4 align-top text-sm text-slate-600">
                                    {{ $case->published_at?->format('Y-m-d H:i') ?: '-' }}
                                </td>
                                <td class="px-5 py-4 align-top text-right">
                                    <div class="inline-flex flex-wrap items-center justify-end gap-2">
                                        @if($case->status === \App\Models\ProductCase::STATUS_PUBLISHED)
                                            <a href="{{ route('admin.product-case-library.show', ['slug' => $case->slug]) }}" target="_blank" rel="noopener noreferrer" class="text-sm font-medium text-slate-600 hover:text-slate-950">查看</a>
                                        @endif
                                        <a href="{{ route('admin.product-cases.edit', ['product_case' => $case->id]) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">编辑</a>
                                        <form method="POST" action="{{ route('admin.product-cases.toggle-status', ['product_case' => $case->id]) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-sm font-medium {{ $case->status === \App\Models\ProductCase::STATUS_PUBLISHED ? 'text-amber-600 hover:text-amber-800' : 'text-green-600 hover:text-green-800' }}">
                                                {{ $case->status === \App\Models\ProductCase::STATUS_PUBLISHED ? '隐藏' : '发布' }}
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.product-cases.destroy', ['product_case' => $case->id]) }}" class="inline" onsubmit="return confirm('确定删除这个产品案例吗？删除后公开页面不再展示。');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-sm font-medium text-red-600 hover:text-red-800">删除</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-12 text-center text-sm text-slate-500">暂无产品案例</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($cases->hasPages())
                <div class="border-t border-slate-200 px-5 py-4">
                    {{ $cases->onEachSide(1)->links() }}
                </div>
            @endif
        </section>
    </div>
@endsection
