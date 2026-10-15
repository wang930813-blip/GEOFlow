@extends('admin.layouts.app')

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <div class="flex items-center gap-3">
                    <a href="{{ route('admin.management-tools.index') }}" class="text-slate-400 transition hover:text-slate-700" aria-label="返回管理工具">
                        <i data-lucide="arrow-left" class="h-5 w-5"></i>
                    </a>
                    <h1 class="text-2xl font-bold text-slate-950">案例导入</h1>
                </div>
                <p class="mt-2 text-sm text-slate-600">当前站点：{{ $currentSite?->name ?: '未选择站点' }}</p>
            </div>
            <a href="{{ route('admin.management-tools.product-cases.index') }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50">
                <i data-lucide="briefcase-business" class="h-4 w-4"></i>
                案例管理
            </a>
        </div>

        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <form method="POST" action="{{ route('admin.management-tools.product-case-import.store') }}" enctype="multipart/form-data" class="space-y-5">
                @csrf
                <div>
                    <label for="source" class="block text-sm font-medium text-slate-700">案例表格</label>
                    <input id="source" name="source" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required class="mt-2 block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 file:mr-4 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100">
                    <p class="mt-2 text-xs text-slate-500">仅支持 XLSX，文件大小不超过 20MB。服务端会先校验表格，再进入异步导入队列。</p>
                </div>

                <label class="flex items-start gap-3 rounded-md border border-slate-200 bg-slate-50 px-4 py-3">
                    <input type="checkbox" name="refresh_images" value="1" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                    <span>
                        <span class="block text-sm font-medium text-slate-800">重新上传已有案例封面</span>
                        <span class="mt-1 block text-xs text-slate-500">默认复用已存在的案例封面，只有表格封面发生变化时才勾选。</span>
                    </span>
                </label>

                <div class="flex justify-end">
                    <button type="submit" class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white transition hover:bg-indigo-700">
                        <i data-lucide="upload" class="h-4 w-4"></i>
                        上传并创建任务
                    </button>
                </div>
            </form>
        </section>

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-base font-semibold text-slate-950">导入任务</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">文件</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">行数</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">状态</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">创建时间</th>
                        <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-500">操作</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                    @forelse($tasks as $task)
                        @php
                            $status = match ((string) $task->status) {
                                'queued' => ['排队中', 'bg-amber-50 text-amber-700'],
                                'running' => ['执行中', 'bg-blue-50 text-blue-700'],
                                'completed' => ['已完成', 'bg-emerald-50 text-emerald-700'],
                                'failed' => ['执行失败', 'bg-red-50 text-red-700'],
                                default => [(string) $task->status, 'bg-slate-100 text-slate-700'],
                            };
                        @endphp
                        <tr>
                            <td class="px-5 py-4 text-sm font-medium text-slate-950">{{ $task->original_filename }}</td>
                            <td class="px-5 py-4 text-sm text-slate-600">{{ (int) $task->processed_rows }} / {{ (int) $task->total_rows }}</td>
                            <td class="px-5 py-4"><span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $status[1] }}">{{ $status[0] }}</span></td>
                            <td class="px-5 py-4 text-sm text-slate-600">{{ optional($task->created_at)->format('Y-m-d H:i:s') }}</td>
                            <td class="px-5 py-4 text-right">
                                <a href="{{ route('admin.management-tools.product-case-import.show', ['importId' => (int) $task->id]) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">查看</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-12 text-center text-sm text-slate-500">暂无导入任务</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($tasks->hasPages())
                <div class="border-t border-slate-200 px-5 py-4">{{ $tasks->onEachSide(1)->links() }}</div>
            @endif
        </section>
    </div>
@endsection
