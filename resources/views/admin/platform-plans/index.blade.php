@extends('admin.layouts.app')

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">平台规格</h1>
                <p class="mt-1 text-sm text-gray-600">维护代理和直客可开通的规格、服务时长与资源额度。</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.plan-usages.index') }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50">
                    <i data-lucide="bar-chart-3" class="h-4 w-4"></i>
                    规格使用情况
                </a>
                <a href="{{ route('admin.plan-subscriptions.index') }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50">
                    <i data-lucide="badge-check" class="h-4 w-4"></i>
                    客户开通
                </a>
            </div>
        </div>

        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-5 flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-md bg-indigo-50 text-indigo-600">
                    <i data-lucide="package-plus" class="h-5 w-5"></i>
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">新增规格</h2>
                    <p class="text-sm text-gray-500">媒体发布不单独设置次数，统一按积分余额扣费。</p>
                </div>
            </div>

            <form method="POST" action="{{ route('admin.platform-plans.store') }}" class="space-y-5">
                @csrf
                <div class="grid grid-cols-1 gap-4 lg:grid-cols-12">
                    <div class="lg:col-span-3">
                        <label class="mb-1 block text-sm font-medium text-gray-700">规格名称</label>
                        <input name="name" required value="{{ old('name') }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="专业版季度版">
                    </div>
                    <div class="lg:col-span-2">
                        <label class="mb-1 block text-sm font-medium text-gray-700">规格编码</label>
                        <input name="code" required value="{{ old('code') }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="pro_quarter">
                    </div>
                    <div class="lg:col-span-2">
                        <label class="mb-1 block text-sm font-medium text-gray-700">适用对象</label>
                        <select name="audience" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                            <option value="both">代理/直客</option>
                            <option value="agent">代理</option>
                            <option value="direct">直客</option>
                        </select>
                    </div>
                    <div class="lg:col-span-2">
                        <label class="mb-1 block text-sm font-medium text-gray-700">服务天数</label>
                        <input name="duration_days" type="number" min="1" required value="{{ old('duration_days', 30) }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                    </div>
                    <div class="lg:col-span-1">
                        <label class="mb-1 block text-sm font-medium text-gray-700">排序</label>
                        <input name="sort_order" type="number" min="0" value="{{ old('sort_order', 0) }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                    </div>
                    <div class="lg:col-span-2">
                        <label class="mb-1 block text-sm font-medium text-gray-700">状态</label>
                        <select name="status" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                            <option value="active">启用</option>
                            <option value="inactive">停用</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($resourceCatalog as $key => $resource)
                        @php
                            $enabled = old("resources.$key.enabled");
                            $quotaValue = old("resources.$key.quota_value", 0);
                        @endphp
                        <div class="rounded-md border border-slate-200 bg-slate-50 p-3">
                            <label class="flex items-center gap-2 text-sm font-medium text-gray-800">
                                <input type="checkbox" name="resources[{{ $key }}][enabled]" value="1" @checked((bool) $enabled) class="h-4 w-4 rounded border-slate-300 text-indigo-600">
                                <span>{{ $resource['label'] }}</span>
                            </label>
                            <input name="resources[{{ $key }}][quota_value]" type="number" min="0" value="{{ $quotaValue }}" class="mt-3 block h-9 w-full rounded-md border border-slate-200 bg-white px-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="数量">
                            @error("resources.$key.quota_value")
                                <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach
                </div>
                @error('resources')
                    <p class="-mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">说明</label>
                    <textarea name="description" rows="3" class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">{{ old('description') }}</textarea>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-indigo-600 px-5 text-sm font-medium text-white transition hover:bg-indigo-700">
                        <i data-lucide="save" class="h-4 w-4"></i>
                        保存规格
                    </button>
                </div>
            </form>
        </section>

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-lg font-semibold text-gray-900">规格列表</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">规格</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">适用对象</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">服务时长</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">资源</th>
                            <th class="w-[5.5rem] px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">状态</th>
                            <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-500">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @forelse ($plans as $plan)
                            <tr>
                                <td class="px-5 py-4 align-top">
                                    <div class="text-sm font-semibold text-gray-900">{{ $plan->name }}</div>
                                    <div class="mt-1 text-xs text-gray-400">{{ $plan->code }}</div>
                                </td>
                                <td class="px-5 py-4 align-top text-sm text-gray-600">
                                    {{ ['agent' => '代理', 'direct' => '直客', 'both' => '代理/直客'][$plan->audience] ?? $plan->audience }}
                                </td>
                                <td class="px-5 py-4 align-top text-sm text-gray-600">{{ $plan->duration_days }} 天</td>
                                <td class="px-5 py-4 align-top">
                                    <div class="flex max-w-xl flex-wrap gap-2">
                                        @foreach ($plan->entitlements->where('enabled', true) as $entitlement)
                                            <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-700">
                                                {{ $resourceCatalog[$entitlement->resource_key]['label'] ?? $entitlement->resource_key }}：{{ $entitlement->quota_value }}
                                            </span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-5 py-4 align-top whitespace-nowrap">
                                    @if ($plan->status === 'active')
                                        <span class="inline-flex min-w-[3.5rem] items-center justify-center rounded-full bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-200 whitespace-nowrap">启用</span>
                                    @else
                                        <span class="inline-flex min-w-[3.5rem] items-center justify-center rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600 ring-1 ring-inset ring-slate-200 whitespace-nowrap">停用</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 align-top text-right">
                                    <div class="inline-flex items-center justify-end gap-3">
                                        <a href="{{ route('admin.platform-plans.show', $plan) }}" class="text-sm font-medium text-slate-600 hover:text-slate-900">详情</a>
                                        <a href="{{ route('admin.platform-plans.edit', $plan) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">编辑</a>
                                        <form method="POST" action="{{ route('admin.platform-plans.destroy', $plan) }}" class="inline" onsubmit="return confirm(@js('确定删除该规格吗？已被开通记录引用的规格不能删除。'));">
                                            @csrf
                                            <button type="submit" class="text-sm font-medium text-red-600 hover:text-red-800">删除</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-10 text-center text-sm text-gray-500">暂无规格</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
