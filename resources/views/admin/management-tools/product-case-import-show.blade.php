@extends('admin.layouts.app')

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <div class="flex items-center gap-3">
                    <a href="{{ route('admin.management-tools.product-case-import.create') }}" class="text-slate-400 transition hover:text-slate-700" aria-label="返回案例导入">
                        <i data-lucide="arrow-left" class="h-5 w-5"></i>
                    </a>
                    <h1 class="text-2xl font-bold text-slate-950">案例导入任务</h1>
                </div>
                <p class="mt-2 text-sm text-slate-600">{{ $task->original_filename }} · {{ $task->site?->name ?: '未关联站点' }}</p>
            </div>
            <a href="{{ route('admin.management-tools.product-cases.index') }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50">
                <i data-lucide="briefcase-business" class="h-4 w-4"></i>
                案例管理
            </a>
        </div>

        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm" data-import-status data-status-url="{{ $statusUrl }}" data-status="{{ $task->status }}">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="text-sm text-slate-500">任务状态</div>
                    <div class="mt-1 text-lg font-semibold text-slate-950" data-status-label>{{ $statusLabel }}</div>
                </div>
                <div class="text-sm text-slate-600"><span data-processed-rows>{{ (int) $task->processed_rows }}</span> / <span data-total-rows>{{ (int) $task->total_rows }}</span> 行</div>
            </div>
            <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-100">
                <div data-progress-bar class="h-full rounded-full bg-indigo-600 transition-all" style="width: {{ $progressPercent }}%"></div>
            </div>
            <div class="mt-2 text-right text-xs text-slate-500"><span data-progress-percent>{{ $progressPercent }}</span>%</div>

            <div class="mt-5 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                @foreach([
                    ['label' => '新增案例', 'key' => 'created_count', 'value' => $task->created_count],
                    ['label' => '更新案例', 'key' => 'updated_count', 'value' => $task->updated_count],
                    ['label' => '失败行数', 'key' => 'failed_count', 'value' => $task->failed_count],
                    ['label' => '上传图片', 'key' => 'images_uploaded', 'value' => $task->images_uploaded],
                    ['label' => '复用图片', 'key' => 'images_reused', 'value' => $task->images_reused],
                    ['label' => '诊断数据', 'key' => 'diagnosis_runs', 'value' => $task->diagnosis_runs],
                ] as $metric)
                    <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-3">
                        <div class="text-xs text-slate-500">{{ $metric['label'] }}</div>
                        <div class="mt-1 text-xl font-semibold text-slate-950" data-metric="{{ $metric['key'] }}">{{ (int) $metric['value'] }}</div>
                    </div>
                @endforeach
            </div>

            <div data-error-message class="mt-4 whitespace-pre-line text-sm text-red-600">{{ $task->error_message }}</div>
        </section>

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-base font-semibold text-slate-950">逐行结果</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">行号</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">品牌</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">标题</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">结果</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">说明</th>
                        <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-500">案例</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                    @forelse($items as $item)
                        @php
                            $itemStatus = match ((string) $item->status) {
                                'succeeded' => ['成功', 'bg-emerald-50 text-emerald-700'],
                                'failed' => ['失败', 'bg-red-50 text-red-700'],
                                'pending' => ['等待中', 'bg-slate-100 text-slate-700'],
                                default => ['处理中', 'bg-blue-50 text-blue-700'],
                            };
                        @endphp
                        <tr>
                            <td class="px-5 py-4 text-sm text-slate-600">{{ (int) $item->row_number }}</td>
                            <td class="px-5 py-4 text-sm font-medium text-slate-950">{{ $item->brand_name }}</td>
                            <td class="px-5 py-4 text-sm text-slate-700">{{ $item->title }}</td>
                            <td class="px-5 py-4"><span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $itemStatus[1] }}">{{ $itemStatus[0] }}</span></td>
                            <td class="max-w-[360px] whitespace-pre-line px-5 py-4 text-sm text-slate-600">{{ $item->error_message ?: (($item->action === 'created' ? '新增' : ($item->action === 'updated' ? '更新' : '')) ?: '-') }}</td>
                            <td class="px-5 py-4 text-right">
                                @if($item->product_case_id)
                                    <a href="{{ route('admin.management-tools.product-cases.edit', ['productCase' => (int) $item->product_case_id]) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">编辑</a>
                                @else
                                    <span class="text-sm text-slate-400">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-12 text-center text-sm text-slate-500">暂无逐行结果</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($items->hasPages())
                <div class="border-t border-slate-200 px-5 py-4">{{ $items->onEachSide(1)->links() }}</div>
            @endif
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const root = document.querySelector('[data-import-status]');
            if (!root || !['queued', 'running'].includes(root.dataset.status)) {
                return;
            }

            const statusUrl = root.dataset.statusUrl;
            const terminalStates = ['completed', 'failed'];
            let consecutiveFailures = 0;
            const update = async () => {
                try {
                    const response = await fetch(statusUrl, {
                        headers: {Accept: 'application/json'},
                        credentials: 'same-origin',
                    });
                    if (!response.ok) {
                        consecutiveFailures++;
                        root.querySelector('[data-error-message]').textContent = consecutiveFailures >= 3
                            ? '任务状态暂时无法获取，系统仍在重试，请稍后刷新页面'
                            : '任务状态获取失败，正在重试';
                        window.setTimeout(update, 5000);
                        return;
                    }

                    const payload = await response.json();
                    consecutiveFailures = 0;
                    root.dataset.status = payload.status;
                    root.querySelector('[data-status-label]').textContent = payload.status_label;
                    root.querySelector('[data-processed-rows]').textContent = payload.processed_rows;
                    root.querySelector('[data-total-rows]').textContent = payload.total_rows;
                    root.querySelector('[data-progress-percent]').textContent = payload.progress_percent;
                    root.querySelector('[data-progress-bar]').style.width = `${payload.progress_percent}%`;
                    root.querySelector('[data-error-message]').textContent = payload.error_message || '';

                    Object.entries({
                        created_count: payload.created_count,
                        updated_count: payload.updated_count,
                        failed_count: payload.failed_count,
                        images_uploaded: payload.images_uploaded,
                        images_reused: payload.images_reused,
                        diagnosis_runs: payload.diagnosis_runs,
                    }).forEach(([key, value]) => {
                        const target = root.querySelector(`[data-metric="${key}"]`);
                        if (target) {
                            target.textContent = value;
                        }
                    });

                    if (terminalStates.includes(payload.status)) {
                        window.setTimeout(() => window.location.reload(), 500);
                        return;
                    }

                    window.setTimeout(update, 2500);
                } catch (error) {
                    consecutiveFailures++;
                    root.querySelector('[data-error-message]').textContent = consecutiveFailures >= 3
                        ? '任务状态暂时无法获取，系统仍在重试，请稍后刷新页面'
                        : '任务状态获取失败，正在重试';
                    window.setTimeout(update, 5000);
                }
            };

            window.setTimeout(update, 1500);
        })();
    </script>
@endpush
