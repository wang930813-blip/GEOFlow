@extends('admin.layouts.app')

@section('content')
    @php
        $entitlementMap = $plan->entitlements->keyBy('resource_key');
    @endphp

    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">编辑规格</h1>
                <p class="mt-1 text-sm text-gray-600">修改规格模板只影响后续开通和续费，已开通客户仍使用开通时的额度快照。</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.platform-plans.show', $plan) }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50">
                    <i data-lucide="eye" class="h-4 w-4"></i>
                    详情
                </a>
                <a href="{{ route('admin.platform-plans.index') }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50">
                    <i data-lucide="arrow-left" class="h-4 w-4"></i>
                    返回列表
                </a>
            </div>
        </div>

        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <form method="POST" action="{{ route('admin.platform-plans.update', $plan) }}" class="space-y-5">
                @csrf
                <div class="grid grid-cols-1 gap-4 lg:grid-cols-12">
                    <div class="lg:col-span-3">
                        <label class="mb-1 block text-sm font-medium text-gray-700">规格名称</label>
                        <input name="name" required value="{{ old('name', $plan->name) }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                    </div>
                    <div class="lg:col-span-2">
                        <label class="mb-1 block text-sm font-medium text-gray-700">规格编码</label>
                        <input name="code" required value="{{ old('code', $plan->code) }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                    </div>
                    <div class="lg:col-span-2">
                        <label class="mb-1 block text-sm font-medium text-gray-700">适用对象</label>
                        <select name="audience" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                            <option value="both" @selected(old('audience', $plan->audience) === 'both')>代理/直客</option>
                            <option value="agent" @selected(old('audience', $plan->audience) === 'agent')>代理</option>
                            <option value="direct" @selected(old('audience', $plan->audience) === 'direct')>直客</option>
                        </select>
                    </div>
                    <div class="lg:col-span-2">
                        <label class="mb-1 block text-sm font-medium text-gray-700">服务天数</label>
                        <input name="duration_days" type="number" min="1" required value="{{ old('duration_days', $plan->duration_days) }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                    </div>
                    <div class="lg:col-span-1">
                        <label class="mb-1 block text-sm font-medium text-gray-700">排序</label>
                        <input name="sort_order" type="number" min="0" value="{{ old('sort_order', $plan->sort_order) }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                    </div>
                    <div class="lg:col-span-2">
                        <label class="mb-1 block text-sm font-medium text-gray-700">状态</label>
                        <select name="status" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                            <option value="active" @selected(old('status', $plan->status) === 'active')>启用</option>
                            <option value="inactive" @selected(old('status', $plan->status) === 'inactive')>停用</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($resourceCatalog as $key => $resource)
                        @php
                            $entitlement = $entitlementMap->get($key);
                            $enabled = old("resources.$key.enabled", $entitlement?->enabled ? '1' : null);
                            $quotaValue = old("resources.$key.quota_value", $entitlement?->quota_value ?? 0);
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
                    <textarea name="description" rows="3" class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">{{ old('description', $plan->description) }}</textarea>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-indigo-600 px-5 text-sm font-medium text-white transition hover:bg-indigo-700">
                        <i data-lucide="save" class="h-4 w-4"></i>
                        保存修改
                    </button>
                </div>
            </form>
        </section>
    </div>
@endsection
