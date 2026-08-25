<div id="inclusion-daily-reports-panel">
    <div class="mb-3 flex items-center justify-between gap-3">
        <h4 class="text-sm font-semibold text-gray-900">每日监测结果</h4>
        <a href="{{ route('admin.keyword-libraries.inclusion-results.export', ['libraryId' => (int) $library->id]) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
            <i data-lucide="download" class="w-3.5 h-3.5 mr-1"></i>
            下载表格
        </a>
    </div>
    @if (($inclusionDailyReports ?? collect())->isEmpty())
        <div class="text-sm text-gray-500">暂无检测结果</div>
    @else
        <div class="space-y-4">
            @foreach ($inclusionDailyReports as $dayReport)
                <div class="rounded-md border border-gray-200">
                    <div class="border-b border-gray-100 bg-gray-50 px-3 py-2">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="text-sm font-semibold text-gray-900">{{ $dayReport['date'] }}</div>
                                <div class="mt-2 grid grid-cols-1 gap-2 text-xs text-gray-600 sm:grid-cols-2">
                                    <div>
                                        <span class="font-medium text-gray-700">命中关键词：</span>
                                        {{ $dayReport['matched_keywords']->isNotEmpty() ? $dayReport['matched_keywords']->implode('、') : '无' }}
                                    </div>
                                    <div>
                                        <span class="font-medium text-gray-700">未命中关键词：</span>
                                        {{ $dayReport['missed_keywords']->isNotEmpty() ? $dayReport['missed_keywords']->implode('、') : '无' }}
                                    </div>
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center justify-end gap-2 text-xs">
                                <span class="rounded bg-white px-2 py-1 text-gray-600">批次 {{ $dayReport['runs']->count() }}</span>
                                <span class="rounded bg-white px-2 py-1 text-gray-600">检测 {{ (int) $dayReport['total'] }} 次</span>
                                <span class="rounded bg-green-50 px-2 py-1 text-green-700">关键词命中 {{ (int) $dayReport['keyword_hits'] }}</span>
                                <span class="rounded bg-blue-50 px-2 py-1 text-blue-700">品牌命中 {{ (int) $dayReport['brand_hits'] }}</span>
                            </div>
                        </div>
                        @if ($dayReport['platforms']->isNotEmpty())
                            <div class="mt-2 flex flex-wrap gap-2 text-xs text-gray-500">
                                @foreach ($dayReport['platforms'] as $platformReport)
                                    <span class="rounded-full bg-white px-2 py-1">
                                        {{ $platformReport['label'] }}：关键词 {{ (int) $platformReport['keyword_hits'] }}/{{ (int) $platformReport['total'] }}，品牌 {{ (int) $platformReport['brand_hits'] }}/{{ (int) $platformReport['total'] }}
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div class="divide-y divide-gray-100">
                        @foreach ($dayReport['runs'] as $runReport)
                            <details class="group">
                                <summary class="cursor-pointer list-none bg-white px-3 py-2 hover:bg-gray-50">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div class="flex flex-wrap items-center gap-2 text-sm">
                                            <span class="font-semibold text-gray-900">#{{ (int) $runReport['run_id'] }} {{ $runReport['status'] }}</span>
                                            <span class="text-xs text-gray-500">{{ optional($runReport['created_at'])->format('H:i') }}</span>
                                            <span class="rounded bg-gray-100 px-2 py-1 text-xs text-gray-600">{{ (int) $runReport['completed_checks'] }}/{{ (int) $runReport['total_checks'] }} 已完成，失败 {{ (int) $runReport['failed_checks'] }}</span>
                                            <span class="rounded bg-green-50 px-2 py-1 text-xs text-green-700">关键词 {{ (int) $runReport['keyword_hits'] }}/{{ (int) $runReport['total'] }}</span>
                                            <span class="rounded bg-blue-50 px-2 py-1 text-xs text-blue-700">品牌 {{ (int) $runReport['brand_hits'] }}/{{ (int) $runReport['total'] }}</span>
                                        </div>
                                        <span class="inline-flex items-center rounded border border-gray-200 bg-white px-2 py-1 text-xs font-medium text-gray-600">
                                            <i data-lucide="chevron-down" class="mr-1 h-3.5 w-3.5 transition-transform group-open:rotate-180"></i>
                                            <span class="group-open:hidden">展开</span>
                                            <span class="hidden group-open:inline">收起</span>
                                        </span>
                                    </div>
                                    @if ($runReport['platforms']->isNotEmpty())
                                        <div class="mt-2 flex flex-wrap gap-2 text-xs text-gray-500">
                                            @foreach ($runReport['platforms'] as $platformReport)
                                                <span class="rounded-full bg-gray-50 px-2 py-1">
                                                    {{ $platformReport['label'] }}：关键词 {{ (int) $platformReport['keyword_hits'] }}/{{ (int) $platformReport['total'] }}，品牌 {{ (int) $platformReport['brand_hits'] }}/{{ (int) $platformReport['total'] }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                </summary>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-100 text-xs">
                                        <thead class="bg-white text-left text-gray-500">
                                            <tr>
                                                <th class="px-3 py-2 font-medium">平台</th>
                                                <th class="px-3 py-2 font-medium">关键词</th>
                                                <th class="px-3 py-2 font-medium">问题</th>
                                                <th class="px-3 py-2 font-medium">关键词</th>
                                                <th class="px-3 py-2 font-medium">品牌</th>
                                                <th class="px-3 py-2 font-medium">状态</th>
                                                <th class="px-3 py-2 font-medium">时间</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 bg-white text-gray-700">
                                            @foreach ($runReport['results'] as $result)
                                                <tr>
                                                    <td class="whitespace-nowrap px-3 py-2 font-medium text-gray-900">{{ match (strtolower((string) $result->platform)) { 'doubao' => '豆包', 'qianwen' => '千问', 'deepseek' => 'DeepSeek', 'yuanbao' => '腾讯元宝', 'wenxin' => '文心一言', default => strtoupper((string) $result->platform) } }}</td>
                                                    <td class="whitespace-nowrap px-3 py-2">{{ $result->keyword?->keyword }}</td>
                                                    <td class="min-w-[12rem] px-3 py-2">{{ $result->question }}</td>
                                                    <td class="whitespace-nowrap px-3 py-2">
                                                        <span class="rounded px-2 py-1 {{ $result->keyword_hit ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-600' }}">{{ $result->keyword_hit ? '命中' : '未命中' }}</span>
                                                    </td>
                                                    <td class="whitespace-nowrap px-3 py-2">
                                                        <span class="rounded px-2 py-1 {{ $result->brand_hit ? 'bg-blue-50 text-blue-700' : 'bg-gray-100 text-gray-600' }}">{{ $result->brand_hit ? '命中' : '未命中' }}</span>
                                                    </td>
                                                    <td class="whitespace-nowrap px-3 py-2">
                                                        @if ((string) $result->status === 'failed')
                                                            <span class="rounded bg-red-50 px-2 py-1 text-red-700" title="{{ $result->error_message }}">失败</span>
                                                        @else
                                                            <span class="rounded bg-green-50 px-2 py-1 text-green-700">成功</span>
                                                        @endif
                                                    </td>
                                                    <td class="whitespace-nowrap px-3 py-2 text-gray-500">{{ optional($result->checked_at)->format('H:i') }}</td>
                                                </tr>
                                                @if ((string) $result->status === 'failed' && (string) ($result->error_message ?? '') !== '')
                                                    <tr>
                                                        <td class="px-3 py-2 text-xs text-red-700" colspan="7">
                                                            失败原因：{{ $result->error_message }}
                                                        </td>
                                                    </tr>
                                                @endif
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
