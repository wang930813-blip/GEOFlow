@extends('admin.layouts.app')

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">媒体投稿利润报表</h1>
                <p class="mt-1 text-sm text-gray-600">按站点汇总投稿销售额、成本和利润。</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.media-distribution.reports.profit-export') }}" class="inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    <i data-lucide="download" class="h-4 w-4"></i>
                    导出报表
                </a>
                <a href="{{ route('admin.media-distribution.submissions.index') }}" class="inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    <i data-lucide="send" class="h-4 w-4"></i>
                    投稿订单
                </a>
            </div>
        </div>

        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">站点</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">订单数</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">销售额</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">成本</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">利润</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @forelse ($rows as $row)
                            <tr>
                                <td class="px-5 py-4 text-sm text-gray-700">{{ $row->site_name }}</td>
                                <td class="px-5 py-4 text-sm text-gray-700">{{ $row->orders_count }}</td>
                                <td class="px-5 py-4 text-sm text-gray-700">{{ number_format((float) $row->sale_total, 2, '.', '') }}</td>
                                <td class="px-5 py-4 text-sm text-gray-700">{{ number_format((float) $row->cost_total, 2, '.', '') }}</td>
                                <td class="px-5 py-4 text-sm font-medium text-green-700">{{ number_format((float) $row->profit_total, 2, '.', '') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-10 text-center text-sm text-gray-500">暂无利润数据</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
