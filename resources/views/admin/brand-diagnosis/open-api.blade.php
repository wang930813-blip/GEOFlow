@extends('admin.layouts.app')

@section('content')
    @php
        $filters = $recordFilters ?? ['brand' => '', 'start_date' => '', 'end_date' => ''];
        $paginator = $recordPaginator ?? null;
    @endphp

    <div class="space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-slate-950">OpenAPI 诊断记录</h1>
                <p class="mt-1 text-sm text-slate-500">仅展示通过开放 API 创建的品牌诊断任务，不混入后台手动诊断记录。</p>
            </div>
            <a href="{{ route('admin.brand-diagnosis.index') }}" class="inline-flex h-10 items-center justify-center rounded-md border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                返回品牌诊断
            </a>
        </div>

        <form method="GET" action="{{ route('admin.brand-diagnosis.open-api.index') }}" class="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-[1fr_160px_160px_auto_auto]">
            <input type="search" name="brand" value="{{ $filters['brand'] ?? '' }}" placeholder="搜索品牌词" class="h-10 rounded-md border border-slate-200 px-3 text-sm outline-none focus:border-orange-400 focus:ring-2 focus:ring-orange-100">
            <input type="date" name="start_date" value="{{ $filters['start_date'] ?? '' }}" class="h-10 rounded-md border border-slate-200 px-3 text-sm outline-none focus:border-orange-400 focus:ring-2 focus:ring-orange-100">
            <input type="date" name="end_date" value="{{ $filters['end_date'] ?? '' }}" class="h-10 rounded-md border border-slate-200 px-3 text-sm outline-none focus:border-orange-400 focus:ring-2 focus:ring-orange-100">
            <button type="submit" class="inline-flex h-10 items-center justify-center rounded-md bg-orange-600 px-4 text-sm font-semibold text-white hover:bg-orange-700">筛选</button>
            <a href="{{ route('admin.brand-diagnosis.open-api.index') }}" class="inline-flex h-10 items-center justify-center rounded-md border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-600 hover:bg-slate-50">重置</a>
        </form>

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">品牌词</th>
                            <th class="px-4 py-3">任务 ID</th>
                            <th class="px-4 py-3">模型</th>
                            <th class="px-4 py-3">状态</th>
                            <th class="px-4 py-3">品牌表现</th>
                            <th class="px-4 py-3">创建时间</th>
                            <th class="px-4 py-3 text-right">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($records as $record)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3 font-semibold text-slate-900">{{ $record['brand'] }}</td>
                                <td class="px-4 py-3 font-mono text-xs text-slate-500">{{ $record['api_task_key'] }}</td>
                                <td class="px-4 py-3 text-slate-600">{{ collect($record['platform_options'] ?? [])->pluck('name')->filter()->implode('、') }}</td>
                                <td class="px-4 py-3 text-slate-600">{{ $record['status'] }}</td>
                                <td class="px-4 py-3 text-slate-600">评分 {{ $record['metrics']['score'] ?? 0 }} / 提及率 {{ $record['metrics']['mention_rate'] ?? 0 }}%</td>
                                <td class="px-4 py-3 text-slate-500">{{ $record['created_at'] }}</td>
                                <td class="px-4 py-3 text-right">
                                    @if (! empty($record['has_report']))
                                        <a href="{{ route('admin.brand-diagnosis.report', ['run' => $record['id']]) }}" class="font-semibold text-orange-700 hover:text-orange-800">查看报告</a>
                                    @else
                                        <span class="text-slate-400">诊断中</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-10 text-center text-sm text-slate-500">暂无 OpenAPI 诊断记录</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($paginator && $paginator->hasPages())
                <div class="border-t border-slate-200 px-4 py-3">
                    {{ $paginator->links() }}
                </div>
            @endif
        </section>
    </div>
@endsection
