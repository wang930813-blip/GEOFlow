@extends('admin.layouts.app')

@section('content')
    <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">媒体积分管理</h1>
                <p class="mt-1 text-sm text-gray-600">每个站点独立积分，媒体投稿按积分价 1:1 消耗。</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.media-distribution.credits.export') }}" class="inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    <i data-lucide="download" class="h-4 w-4"></i>
                    导出流水
                </a>
                <a href="{{ route('admin.media-distribution.resources.index') }}" class="inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    <i data-lucide="newspaper" class="h-4 w-4"></i>
                    媒体资源
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            @foreach ($accounts as $account)
                <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="text-sm text-gray-500">{{ $account->site?->name }}</div>
                            <div class="mt-2 text-3xl font-semibold text-gray-900">{{ $account->balance }}</div>
                            <div class="mt-1 text-sm text-gray-500">冻结：{{ $account->frozen_balance }} · 累计消耗：{{ $account->total_consumed }}</div>
                        </div>
                        <i data-lucide="coins" class="h-8 w-8 text-indigo-500"></i>
                    </div>
                    @if ($isSuperAdmin)
                        <form method="POST" action="{{ route('admin.media-distribution.credits.recharge', ['site' => $account->site_id]) }}" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-5">
                            @csrf
                            <input name="amount" type="number" min="0.01" step="0.01" required class="h-10 rounded-md border border-slate-200 px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 sm:col-span-2" placeholder="充值积分">
                            <input name="remark" class="h-10 rounded-md border border-slate-200 px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 sm:col-span-2" placeholder="备注">
                            <button class="h-10 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white hover:bg-indigo-700">充值</button>
                        </form>
                        <form method="POST" action="{{ route('admin.media-distribution.credits.adjust', ['site' => $account->site_id]) }}" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-5">
                            @csrf
                            <input name="amount" type="number" step="0.01" required class="h-10 rounded-md border border-slate-200 px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 sm:col-span-2" placeholder="调整积分 +/-">
                            <input name="remark" class="h-10 rounded-md border border-slate-200 px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 sm:col-span-2" placeholder="扣减或调整备注">
                            <button class="h-10 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50">调整</button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-lg font-semibold text-gray-900">积分流水</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">时间</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">站点</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">类型</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">变动</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">余额</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">备注</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @forelse ($ledger as $row)
                            <tr>
                                <td class="px-5 py-3 text-sm text-gray-500">{{ $row->created_at?->format('Y-m-d H:i') }}</td>
                                <td class="px-5 py-3 text-sm text-gray-700">{{ $row->site?->name }}</td>
                                <td class="px-5 py-3 text-sm text-gray-700">{{ $row->type }}</td>
                                <td class="px-5 py-3 text-sm font-medium {{ (float) $row->amount >= 0 ? 'text-green-700' : 'text-red-600' }}">{{ $row->amount }}</td>
                                <td class="px-5 py-3 text-sm text-gray-700">{{ $row->balance_after }}</td>
                                <td class="px-5 py-3 text-sm text-gray-500">{{ $row->remark }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-8 text-center text-sm text-gray-500">暂无积分流水</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
