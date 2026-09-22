@extends('admin.layouts.app')

@section('content')
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-950">管理工具</h1>
            <p class="mt-1 text-sm text-slate-600">案例库导入与已导入案例管理。</p>
        </div>

        <section class="grid gap-4 md:grid-cols-2">
            <a href="{{ route('admin.management-tools.product-case-import.create') }}" class="group rounded-lg border border-slate-200 bg-white p-5 shadow-sm transition hover:border-indigo-300 hover:shadow-md">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <i data-lucide="file-up" class="h-5 w-5 text-indigo-600"></i>
                            <h2 class="text-lg font-semibold text-slate-950">案例导入</h2>
                        </div>
                        <p class="mt-2 text-sm leading-6 text-slate-600">上传 XLSX 案例表格，异步处理案例、封面和诊断演示数据。</p>
                    </div>
                    <i data-lucide="arrow-up-right" class="h-5 w-5 text-slate-400 transition group-hover:text-indigo-600"></i>
                </div>
                <div class="mt-5 text-sm font-medium text-indigo-600">进入工具</div>
            </a>

            <a href="{{ route('admin.management-tools.product-cases.index') }}" class="group rounded-lg border border-slate-200 bg-white p-5 shadow-sm transition hover:border-indigo-300 hover:shadow-md">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <i data-lucide="briefcase-business" class="h-5 w-5 text-indigo-600"></i>
                            <h2 class="text-lg font-semibold text-slate-950">案例管理</h2>
                        </div>
                        <p class="mt-2 text-sm leading-6 text-slate-600">编辑、删除、发布或下架当前数据范围内的产品案例。</p>
                    </div>
                    <i data-lucide="arrow-up-right" class="h-5 w-5 text-slate-400 transition group-hover:text-indigo-600"></i>
                </div>
                <div class="mt-5 text-sm font-medium text-indigo-600">管理案例（{{ $caseCount }}）</div>
            </a>
        </section>

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                <div>
                    <h2 class="text-base font-semibold text-slate-950">最近导入任务</h2>
                    <p class="mt-1 text-sm text-slate-500">查看当前数据范围内最近创建的任务。</p>
                </div>
                <a href="{{ route('admin.management-tools.product-case-import.create') }}" class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-slate-900 px-3 text-sm font-medium text-white transition hover:bg-slate-800">
                    <i data-lucide="plus" class="h-4 w-4"></i>
                    新建任务
                </a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">文件</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">站点</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">进度</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">状态</th>
                        <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-500">操作</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                    @forelse($tasks as $task)
                        @php
                            $totalRows = (int) $task->total_rows;
                            $processedRows = (int) $task->processed_rows;
                            $progress = $totalRows > 0 ? min(100, (int) floor(($processedRows / $totalRows) * 100)) : 0;
                            $status = match ((string) $task->status) {
                                'queued' => ['排队中', 'bg-amber-50 text-amber-700'],
                                'running' => ['执行中', 'bg-blue-50 text-blue-700'],
                                'completed' => ['已完成', 'bg-emerald-50 text-emerald-700'],
                                'failed' => ['执行失败', 'bg-red-50 text-red-700'],
                                default => [(string) $task->status, 'bg-slate-100 text-slate-700'],
                            };
                        @endphp
                        <tr>
                            <td class="px-5 py-4 align-top">
                                <div class="max-w-[300px] truncate font-medium text-slate-950" title="{{ $task->original_filename }}">{{ $task->original_filename }}</div>
                                <div class="mt-1 text-xs text-slate-500">{{ optional($task->created_at)->format('Y-m-d H:i:s') }}</div>
                            </td>
                            <td class="px-5 py-4 align-top text-sm text-slate-600">{{ $task->site?->name ?: '未关联站点' }}</td>
                            <td class="px-5 py-4 align-top text-sm text-slate-600">
                                <div>{{ $processedRows }} / {{ $totalRows }} 行</div>
                                <div class="mt-2 h-1.5 w-32 overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-full rounded-full bg-indigo-600" style="width: {{ $progress }}%"></div>
                                </div>
                            </td>
                            <td class="px-5 py-4 align-top">
                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $status[1] }}">{{ $status[0] }}</span>
                            </td>
                            <td class="px-5 py-4 text-right align-top">
                                <a href="{{ route('admin.management-tools.product-case-import.show', ['importId' => (int) $task->id]) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">查看结果</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-12 text-center text-sm text-slate-500">暂无导入任务</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
