@extends('admin.layouts.app')

@section('content')
    @php
        $metricKeys = collect($metricTypes);
        $selectedMetricType = old('metric_type', $metricKeys->first());
        $selectedMetricLabel = $metricLabels[$selectedMetricType] ?? '发布数据';
        $chartRows = collect($dailyChartRows);
        $pieSegmentsCollection = collect($pieSegments);
        $pieGradientStops = [];
        $pieOffset = 0;
        foreach ($pieSegmentsCollection as $segment) {
            $percent = (float) ($segment['percent'] ?? 0);
            if ($percent <= 0) {
                continue;
            }
            $pieGradientStops[] = sprintf('%s %.2f%% %.2f%%', $segment['color'], $pieOffset, $pieOffset + $percent);
            $pieOffset += $percent;
        }
        $pieGradient = $pieGradientStops !== []
            ? 'conic-gradient('.implode(', ', $pieGradientStops).')'
            : 'conic-gradient(#e2e8f0 0 100%)';
        $chartMaxValue = max(1, (int) $chartMaxValue);
    @endphp

    <div class="space-y-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <h1 class="text-2xl font-bold text-slate-900">发布数据台账</h1>
                <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600">
                    只用于运营手动录入当前站点的发布条数，不回写监测中心，也不影响真实发布任务。
                </p>
                <div class="mt-3 flex flex-wrap gap-2 text-xs text-slate-600">
                    <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1">当前站点：{{ $site?->name ?? '-' }}</span>
                    <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1">总计：{{ $summary['total'] }} 条</span>
                </div>
            </div>
        </div>

        <section class="rounded-md border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap gap-3">
                @foreach ($metricKeys as $type)
                    <button
                        type="button"
                        class="inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 transition hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-700"
                        onclick="openManualPublishStatModal('{{ $type }}', '{{ $metricLabels[$type] ?? $type }}')"
                    >
                        <i data-lucide="plus" class="h-4 w-4"></i>
                        新增 {{ $metricLabels[$type] ?? $type }}
                    </button>
                @endforeach
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                @foreach ($metricKeys as $type)
                    <span class="inline-flex items-center gap-2 rounded-full bg-slate-50 px-3 py-1 text-xs text-slate-600 ring-1 ring-slate-200">
                        <span class="h-2.5 w-2.5 rounded-full" style="background: {{ \App\Models\ManualPublishStatEntry::colorFor($type) }}"></span>
                        {{ $metricLabels[$type] ?? $type }} {{ $summary['by_type'][$type]['quantity'] ?? 0 }} 条
                    </span>
                @endforeach
            </div>
        </section>

        <section class="grid gap-6 xl:grid-cols-2">
            <div class="rounded-md border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">最近 30 天趋势</h2>
                        <p class="mt-1 text-sm text-slate-500">按当前站点汇总的五类发布增量</p>
                    </div>
                    <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-600">横向可滚动</span>
                </div>

                <div class="mt-5 overflow-x-auto">
                    <div class="min-w-[980px]">
                        <div class="rounded-md border border-dashed border-slate-200 bg-slate-50 p-4">
                            <div class="flex h-[360px] items-end gap-2">
                                @foreach ($chartRows as $row)
                                    @php
                                        $dayTotal = array_sum($row['values'] ?? []);
                                    @endphp
                                    <div class="flex h-full w-[28px] flex-col items-center justify-end gap-1">
                                        <div class="text-[10px] leading-none text-slate-500">{{ $dayTotal }}</div>
                                        <div class="flex h-[300px] w-full flex-col justify-end overflow-hidden rounded-md border border-slate-200 bg-white">
                                            @foreach ($metricKeys as $type)
                                                @php
                                                    $value = (int) ($row['values'][$type] ?? 0);
                                                    $height = $value <= 0 ? 0 : max(2, (int) round($value / $chartMaxValue * 100));
                                                @endphp
                                                @if ($height > 0)
                                                    <div
                                                        class="w-full"
                                                        style="height: {{ $height }}%; background: {{ \App\Models\ManualPublishStatEntry::colorFor($type) }};"
                                                        title="{{ $metricLabels[$type] ?? $type }}：{{ $value }} 条"
                                                    ></div>
                                                @endif
                                            @endforeach
                                        </div>
                                        <div class="text-[10px] leading-none text-slate-500">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('m-d') }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap gap-3 text-xs text-slate-500">
                    @foreach ($metricKeys as $type)
                        <span class="inline-flex items-center gap-2">
                            <span class="h-2.5 w-2.5 rounded-full" style="background: {{ \App\Models\ManualPublishStatEntry::colorFor($type) }}"></span>
                            {{ $metricLabels[$type] ?? $type }}
                        </span>
                    @endforeach
                </div>
            </div>

            <div class="rounded-md border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">类型占比</h2>
                        <p class="mt-1 text-sm text-slate-500">当前站点累计发布条数分布</p>
                    </div>
                    <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-600">饼图</span>
                </div>

                <div class="mt-6 flex items-center justify-center">
                    <div class="relative h-64 w-64">
                        <div class="h-full w-full rounded-full" style="background: {{ $pieGradient }}"></div>
                        <div class="absolute inset-10 rounded-full bg-white shadow-inner"></div>
                        <div class="absolute inset-0 flex items-center justify-center">
                            <div class="text-center">
                                <div class="text-sm text-slate-500">总计</div>
                                <div class="text-3xl font-semibold text-slate-900">{{ $summary['total'] }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-6 grid gap-3 sm:grid-cols-2">
                    @foreach ($pieSegmentsCollection as $segment)
                        <div class="flex items-center gap-3 rounded-md border border-slate-200 px-3 py-2">
                            <span class="h-3.5 w-3.5 rounded-full" style="background: {{ $segment['color'] }}"></span>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-medium text-slate-900">{{ $segment['label'] }}</div>
                                <div class="text-xs text-slate-500">{{ $segment['quantity'] }} 条 / {{ number_format((float) $segment['percent'], 2) }}%</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">增加记录</h2>
                    <p class="mt-1 text-sm text-slate-500">按日期倒序展示当前站点的手动录入记录</p>
                </div>
                <div class="text-sm text-slate-500">共 {{ $entries->total() }} 条</div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <th class="px-5 py-3">日期</th>
                            <th class="px-5 py-3">类型</th>
                            <th class="px-5 py-3">增加数量</th>
                            <th class="px-5 py-3">备注</th>
                            <th class="px-5 py-3">操作人</th>
                            <th class="px-5 py-3">创建时间</th>
                            <th class="px-5 py-3 text-right">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @forelse ($entries as $entry)
                            <tr class="align-top text-sm text-slate-700">
                                <td class="whitespace-nowrap px-5 py-4">{{ $entry->stat_date?->format('Y-m-d') ?? '-' }}</td>
                                <td class="px-5 py-4">{{ $metricLabels[$entry->metric_type] ?? $entry->metric_type }}</td>
                                <td class="px-5 py-4 font-medium text-slate-900">{{ $entry->quantity }}</td>
                                <td class="px-5 py-4 text-slate-500">{{ $entry->remark ?: '-' }}</td>
                                <td class="px-5 py-4">{{ $entry->createdBy?->display_name ?: ($entry->createdBy?->username ?: '-') }}</td>
                                <td class="whitespace-nowrap px-5 py-4 text-slate-500">{{ $entry->created_at?->format('Y-m-d H:i') ?? '-' }}</td>
                                <td class="px-5 py-4 text-right">
                                    <form method="POST" action="{{ route('admin.manual-publish-stats.destroy', ['manual_publish_stat' => $entry->id]) }}" onsubmit="return confirm('确认删除这条台账记录吗？');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="inline-flex items-center gap-1 rounded-md border border-rose-200 px-3 py-1.5 text-xs font-medium text-rose-600 transition hover:bg-rose-50">
                                            <i data-lucide="trash-2" class="h-3.5 w-3.5"></i>
                                            删除
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-12 text-center text-sm text-slate-500">暂无台账记录</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($entries->hasPages())
                <div class="border-t border-slate-200 px-5 py-4">
                    {{ $entries->links() }}
                </div>
            @endif
        </section>
    </div>

    <div id="manual-publish-stat-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/50 px-4 py-6">
        <div class="w-full max-w-xl rounded-md bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                <div>
                    <h3 id="manual-publish-stat-modal-title" class="text-lg font-semibold text-slate-900">新增记录</h3>
                    <p class="mt-1 text-sm text-slate-500">录入当前站点的手动发布条数</p>
                </div>
                <button type="button" class="rounded-md p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-700" onclick="closeManualPublishStatModal()" aria-label="关闭">
                    <i data-lucide="x" class="h-5 w-5"></i>
                </button>
            </div>

            <form method="POST" action="{{ route('admin.manual-publish-stats.store') }}" class="space-y-4 px-6 py-5">
                @csrf
                <input type="hidden" name="metric_type" id="manual-publish-stat-metric-type" value="{{ old('metric_type', $selectedMetricType) }}">

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">类型</label>
                    <div id="manual-publish-stat-metric-label" class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                        {{ $selectedMetricLabel }}
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">日期</label>
                        <input
                            type="date"
                            name="stat_date"
                            value="{{ old('stat_date', now()->toDateString()) }}"
                            class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
                            required
                        >
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">增加数量</label>
                        <input
                            type="number"
                            name="quantity"
                            min="1"
                            step="1"
                            value="{{ old('quantity', 1) }}"
                            class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
                            required
                        >
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">备注</label>
                    <textarea
                        name="remark"
                        rows="3"
                        class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
                        placeholder="可填写补录说明"
                    >{{ old('remark') }}</textarea>
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-slate-200 pt-4">
                    <button type="button" class="inline-flex h-10 items-center justify-center rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 transition hover:bg-slate-50" onclick="closeManualPublishStatModal()">
                        取消
                    </button>
                    <button type="submit" class="inline-flex h-10 items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-medium text-white transition hover:bg-indigo-700">
                        保存记录
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    function openManualPublishStatModal(type, label) {
        const modal = document.getElementById('manual-publish-stat-modal');
        const typeInput = document.getElementById('manual-publish-stat-metric-type');
        const labelBox = document.getElementById('manual-publish-stat-metric-label');
        const title = document.getElementById('manual-publish-stat-modal-title');

        if (! modal || ! typeInput || ! labelBox || ! title) {
            return;
        }

        typeInput.value = type;
        labelBox.textContent = label;
        title.textContent = '新增 ' + label;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeManualPublishStatModal() {
        const modal = document.getElementById('manual-publish-stat-modal');
        if (! modal) {
            return;
        }

        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    document.addEventListener('click', function (event) {
        const modal = document.getElementById('manual-publish-stat-modal');
        if (! modal || modal.classList.contains('hidden')) {
            return;
        }

        if (event.target === modal) {
            closeManualPublishStatModal();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeManualPublishStatModal();
        }
    });

    @if ($errors->any())
        openManualPublishStatModal(@json($selectedMetricType), @json($selectedMetricLabel));
    @endif
</script>
@endpush
