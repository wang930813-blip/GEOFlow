@extends('admin.layouts.app')

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <div class="flex items-center gap-3">
                    <a href="{{ route('admin.management-tools.index') }}" class="text-slate-400 transition hover:text-slate-700" aria-label="返回管理工具">
                        <i data-lucide="arrow-left" class="h-5 w-5"></i>
                    </a>
                    <h1 class="text-2xl font-bold text-slate-950">案例管理</h1>
                </div>
                <p class="mt-2 text-sm text-slate-600">管理当前账号数据范围内已经导入的产品案例。</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.management-tools.product-case-import.create') }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white transition hover:bg-indigo-700">
                    <i data-lucide="file-up" class="h-4 w-4"></i>
                    导入案例
                </a>
                <a href="{{ route('admin.product-case-library.index') }}" target="_blank" rel="noopener noreferrer" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
                    <i data-lucide="external-link" class="h-4 w-4"></i>
                    公开展示
                </a>
            </div>
        </div>

        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <form method="GET" action="{{ route('admin.management-tools.product-cases.index') }}" class="grid gap-4 md:grid-cols-[1fr_220px_auto]">
                <label class="block">
                    <span class="mb-1 block text-sm font-medium text-slate-700">搜索</span>
                    <input name="keyword" value="{{ $filters['keyword'] ?? '' }}" placeholder="标题 / 品牌 / 摘要" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                </label>
                <label class="block">
                    <span class="mb-1 block text-sm font-medium text-slate-700">状态</span>
                    <select name="status" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
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
                    <a href="{{ route('admin.management-tools.product-cases.index') }}" class="inline-flex h-10 items-center justify-center rounded-md border border-slate-200 bg-white px-3 text-sm font-medium text-slate-600 transition hover:bg-slate-50">重置</a>
                </div>
            </form>
        </section>

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[980px] table-fixed divide-y divide-slate-200">
                    <colgroup>
                        <col class="w-[30%]">
                        <col class="w-[18%]">
                        <col class="w-[16%]">
                        <col class="w-[12%]">
                        <col class="w-[24%]">
                    </colgroup>
                    <thead class="bg-slate-50">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">案例</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">关联站点</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">行业 / 地区</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">状态</th>
                        <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-500">操作</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                    @forelse($cases as $case)
                        @php
                            $statusClass = match($case->status) {
                                \App\Models\ProductCase::STATUS_PUBLISHED => 'bg-emerald-50 text-emerald-700',
                                \App\Models\ProductCase::STATUS_HIDDEN => 'bg-slate-100 text-slate-700',
                                default => 'bg-amber-50 text-amber-700',
                            };
                        @endphp
                        <tr>
                            <td class="px-5 py-4 align-top">
                                <div class="truncate font-semibold text-slate-950" title="{{ $case->title }}">{{ $case->title }}</div>
                                <div class="mt-1 truncate text-sm text-slate-500" title="{{ $case->company_name ?: '未设置品牌' }}">{{ $case->company_name ?: '未设置品牌' }}</div>
                                <div class="mt-1 truncate text-xs text-slate-400" title="/{{ $case->slug }}">/{{ $case->slug }}</div>
                            </td>
                            <td class="px-5 py-4 align-top text-sm text-slate-600">{{ $case->site?->name ?: '未关联' }}</td>
                            <td class="px-5 py-4 align-top text-sm text-slate-600">
                                <div>{{ $case->industry ?: '未设置行业' }}</div>
                                <div class="mt-1 text-xs text-slate-400">{{ $case->region ?: '未设置地区' }}</div>
                            </td>
                            <td class="px-5 py-4 align-top">
                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $statusClass }}">{{ $statusLabels[$case->status] ?? $case->status }}</span>
                            </td>
                            <td class="whitespace-nowrap px-5 py-4 text-right align-top">
                                <div class="inline-flex items-center justify-end gap-3">
                                    @if($case->status === \App\Models\ProductCase::STATUS_PUBLISHED)
                                        <a href="{{ route('admin.product-case-library.show', ['slug' => $case->slug]) }}" target="_blank" rel="noopener noreferrer" class="text-sm font-medium text-slate-600 hover:text-slate-950">查看</a>
                                    @endif
                                    <a href="{{ route('admin.management-tools.product-cases.edit', ['productCase' => (int) $case->id]) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">编辑</a>
                                    <form method="POST" action="{{ route('admin.management-tools.product-cases.toggle-status', ['productCase' => (int) $case->id]) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-sm font-medium {{ $case->status === \App\Models\ProductCase::STATUS_PUBLISHED ? 'text-amber-600 hover:text-amber-800' : 'text-emerald-600 hover:text-emerald-800' }}">
                                            {{ $case->status === \App\Models\ProductCase::STATUS_PUBLISHED ? '下架' : '发布' }}
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.management-tools.product-cases.destroy', ['productCase' => (int) $case->id]) }}" class="inline" onsubmit="return confirm('确定删除这个产品案例吗？');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-sm font-medium text-red-600 hover:text-red-800">删除</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-12 text-center text-sm text-slate-500">暂无产品案例</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($cases->hasPages())
                <div class="border-t border-slate-200 px-5 py-4">{{ $cases->onEachSide(1)->links() }}</div>
            @endif
        </section>
    </div>
@endsection
