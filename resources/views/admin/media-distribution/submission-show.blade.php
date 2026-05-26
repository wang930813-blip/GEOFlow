@extends('admin.layouts.app')

@section('content')
    <div class="mx-auto max-w-5xl space-y-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">媒体投稿详情</h1>
                <p class="mt-1 text-sm text-gray-600">订单 #{{ $submission->id }} · {{ $submission->status }}</p>
            </div>
            <div class="flex gap-2">
                <form method="POST" action="{{ route('admin.media-distribution.submissions.sync', ['submission' => $submission->id]) }}">
                    @csrf
                    <button class="inline-flex h-10 items-center gap-2 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white hover:bg-indigo-700">
                        <i data-lucide="refresh-cw" class="h-4 w-4"></i>
                        同步状态
                    </button>
                </form>
                <a href="{{ route('admin.media-distribution.submissions.index') }}" class="inline-flex h-10 items-center rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50">返回</a>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">投稿信息</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">站点</dt><dd class="text-gray-900">{{ $submission->site?->name }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">文章</dt><dd class="text-gray-900">{{ $submission->title_snapshot }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">媒体</dt><dd class="text-gray-900">{{ $submission->resource?->title }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">订单号</dt><dd class="text-gray-900">{{ $submission->external_order_nid ?: '-' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">消耗积分</dt><dd class="text-gray-900">{{ $submission->points_amount }}</dd></div>
                    @if ($isSuperAdmin)
                        <div class="flex justify-between gap-4"><dt class="text-gray-500">成本价</dt><dd class="text-gray-900">{{ $submission->cost_price_snapshot }}</dd></div>
                    @endif
                </dl>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">状态</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">当前状态</dt><dd class="text-gray-900">{{ $submission->status }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">投稿时间</dt><dd class="text-gray-900">{{ $submission->submitted_at?->format('Y-m-d H:i:s') ?: '-' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">同步时间</dt><dd class="text-gray-900">{{ $submission->last_synced_at?->format('Y-m-d H:i:s') ?: '-' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">发布链接</dt><dd class="text-right text-gray-900">
                        @if ($submission->published_url !== '')
                            <a href="{{ $submission->published_url }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:text-indigo-800">打开链接</a>
                        @else
                            -
                        @endif
                    </dd></div>
                </dl>
                @if ($submission->last_error_message)
                    <div class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-700">{{ $submission->last_error_message }}</div>
                @endif
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-gray-900">投稿备注</h2>
            <p class="mt-3 text-sm text-gray-600">{{ $submission->remark ?: '无' }}</p>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <form method="POST" action="{{ route('admin.media-distribution.submissions.cancel', ['submission' => $submission->id]) }}" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                @csrf
                <h2 class="text-lg font-semibold text-gray-900">取消订单</h2>
                <textarea name="reason" required rows="3" class="mt-3 block w-full rounded-md border border-slate-200 px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="填写取消原因">{{ $submission->cancel_reason }}</textarea>
                <button class="mt-3 inline-flex h-10 items-center rounded-md border border-red-200 bg-red-50 px-4 text-sm font-medium text-red-700 hover:bg-red-100">提交取消</button>
            </form>

            <form method="POST" action="{{ route('admin.media-distribution.submissions.appeal', ['submission' => $submission->id]) }}" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                @csrf
                <h2 class="text-lg font-semibold text-gray-900">订单申诉</h2>
                <textarea name="content" required rows="3" class="mt-3 block w-full rounded-md border border-slate-200 px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="填写申诉内容">{{ $submission->appeal_content }}</textarea>
                <button class="mt-3 inline-flex h-10 items-center rounded-md bg-slate-900 px-4 text-sm font-medium text-white hover:bg-slate-700">提交申诉</button>
            </form>
        </div>
    </div>
@endsection
