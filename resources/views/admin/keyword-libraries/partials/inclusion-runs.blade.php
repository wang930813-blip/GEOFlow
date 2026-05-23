<div id="inclusion-runs-panel">
    <h4 class="text-sm font-semibold text-gray-900 mb-3">最近批次</h4>
    @if (($inclusionRuns ?? collect())->isEmpty())
        <div class="text-sm text-gray-500">暂无检测批次</div>
    @else
        <div class="space-y-2">
            @foreach ($inclusionRuns as $run)
                <div class="rounded border border-gray-200 px-3 py-2 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="font-medium text-gray-900">#{{ (int) $run->id }} {{ $run->status }}</span>
                        <span class="text-gray-500">{{ optional($run->created_at)->format('Y-m-d H:i') }}</span>
                    </div>
                    <div class="mt-1 text-xs text-gray-500">
                        {{ (int) $run->completed_checks }}/{{ (int) $run->total_checks }} 已完成，失败 {{ (int) $run->failed_checks }}
                    </div>
                    @if (in_array((string) $run->status, ['pending', 'running'], true))
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-100">
                            <div class="h-full rounded-full bg-indigo-500 transition-all" style="width: {{ (int) $run->total_checks > 0 ? min(100, round(((int) $run->completed_checks / (int) $run->total_checks) * 100)) : 0 }}%"></div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
