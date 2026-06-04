@extends('admin.layouts.app')

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">媒体投稿订单</h1>
                <p class="mt-1 text-sm text-gray-600">选择当前站点文章投稿到媒体资源，投稿会消耗站点积分。</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.media-distribution.submissions.export') }}" class="inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    <i data-lucide="download" class="h-4 w-4"></i>
                    导出订单
                </a>
                <a href="{{ route('admin.media-distribution.resources.index') }}" class="inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    <i data-lucide="newspaper" class="h-4 w-4"></i>
                    媒体资源
                </a>
            </div>
        </div>

        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-4">
                <h2 class="text-lg font-semibold text-gray-900">选择文章投稿</h2>
                <p class="text-sm text-gray-500">{{ $hasSelectedResources ? '已预选上一步的媒体，请勾选要投稿的文章。' : '选择文章和媒体，系统会按每篇文章 × 每个媒体分别创建投稿订单并扣减积分。' }}</p>
            </div>
            <form method="POST" action="{{ route('admin.media-distribution.submissions.bulk-store') }}" class="grid grid-cols-1 gap-4 lg:grid-cols-12">
                @csrf
                <div class="lg:col-span-5">
                    <label class="mb-1 block text-sm font-medium text-gray-700">文章</label>
                    <select name="article_ids[]" multiple required size="5" class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                        @foreach ($articles as $article)
                            <option value="{{ $article->id }}">{{ $article->title }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="lg:col-span-4">
                    <label class="mb-1 block text-sm font-medium text-gray-700">媒体</label>
                    <select name="media_resource_ids[]" multiple required size="5" class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                        @foreach ($resources as $resource)
                            <option value="{{ $resource->id }}" @selected(in_array((int) $resource->id, $selectedResourceIds ?? [], true))>{{ $resource->platformLabel() }} · {{ $resource->title }} · {{ $resource->sale_price }} 积分</option>
                        @endforeach
                    </select>
                </div>
                <div class="lg:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-gray-700">备注</label>
                    <input name="remark" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="选填">
                </div>
                <div class="flex items-end lg:col-span-1">
                    <button type="submit" class="inline-flex h-10 w-full items-center justify-center rounded-md bg-slate-900 px-4 text-sm font-medium text-white hover:bg-slate-700">提交</button>
                </div>
            </form>
        </section>

        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">订单</th>
                            @if ($isSuperAdmin)
                                <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">站点</th>
                            @endif
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">文章/媒体</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">积分</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">状态</th>
                            <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-500">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @forelse ($submissions as $submission)
                            <tr>
                                <td class="px-5 py-4 text-sm text-gray-600">
                                    <div>#{{ $submission->id }}</div>
                                    <div class="text-xs text-gray-400">{{ $submission->external_order_nid ?: '未返回订单号' }}</div>
                                    @if($submission->agent_order_sn)
                                        <div class="text-xs text-gray-400">{{ $submission->agent_order_sn }}</div>
                                    @endif
                                </td>
                                @if ($isSuperAdmin)
                                    <td class="px-5 py-4 text-sm text-gray-600">{{ $submission->site?->name }}</td>
                                @endif
                                <td class="px-5 py-4">
                                    <div class="text-sm font-medium text-gray-900">{{ $submission->title_snapshot }}</div>
                                    <div class="mt-1 text-xs text-gray-500">{{ $submission->platformLabel() }} · {{ $submission->resource?->title }}</div>
                                </td>
                                <td class="px-5 py-4 text-sm text-gray-700">{{ $submission->points_amount }}</td>
                                <td class="px-5 py-4 text-sm text-gray-700">{{ $submission->statusLabel() }}</td>
                                <td class="px-5 py-4 text-right">
                                    <a href="{{ route('admin.media-distribution.submissions.show', ['submission' => $submission->id]) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">详情</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $isSuperAdmin ? 6 : 5 }}" class="px-5 py-10 text-center text-sm text-gray-500">暂无投稿订单</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-5 py-4">{{ $submissions->links() }}</div>
        </div>
    </div>
@endsection
