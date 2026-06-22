@extends('admin.layouts.app')

@section('content')
    @php
        $audienceLabels = ['agent' => '代理', 'direct' => '直客', 'both' => '代理/直客'];
        $entitlementMap = $plan->entitlements->keyBy('resource_key');
        $enabledEntitlements = $plan->entitlements->where('enabled', true);
    @endphp

    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">规格详情</h1>
                <p class="mt-1 text-sm text-gray-600">查看规格基础信息、资源额度和历史引用情况。</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.platform-plans.edit', $plan) }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700">
                    <i data-lucide="pencil" class="h-4 w-4"></i>
                    编辑
                </a>
                <a href="{{ route('admin.platform-plans.index') }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50">
                    <i data-lucide="arrow-left" class="h-4 w-4"></i>
                    返回列表
                </a>
            </div>
        </div>

        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div>
                    <div class="text-xs text-slate-500">规格名称</div>
                    <div class="mt-1 text-base font-semibold text-gray-900">{{ $plan->name }}</div>
                    <div class="mt-1 text-xs text-slate-400">{{ $plan->code }}</div>
                </div>
                <div>
                    <div class="text-xs text-slate-500">适用对象</div>
                    <div class="mt-1 text-sm font-medium text-gray-900">{{ $audienceLabels[$plan->audience] ?? $plan->audience }}</div>
                </div>
                <div>
                    <div class="text-xs text-slate-500">服务时长</div>
                    <div class="mt-1 text-sm font-medium text-gray-900">{{ $plan->duration_days }} 天</div>
                </div>
                <div>
                    <div class="text-xs text-slate-500">状态</div>
                    <div class="mt-1">
                        @if ($plan->status === 'active')
                            <span class="inline-flex rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">启用</span>
                        @else
                            <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">停用</span>
                        @endif
                    </div>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="rounded-md bg-slate-50 p-4">
                    <div class="text-xs text-slate-500">站点开通引用</div>
                    <div class="mt-1 text-2xl font-semibold text-gray-900">{{ $siteSubscriptionCount }}</div>
                </div>
                <div class="rounded-md bg-slate-50 p-4">
                    <div class="text-xs text-slate-500">账号规格引用</div>
                    <div class="mt-1 text-2xl font-semibold text-gray-900">{{ $adminSubscriptionCount }}</div>
                </div>
                <div class="rounded-md bg-slate-50 p-4">
                    <div class="text-xs text-slate-500">资源项</div>
                    <div class="mt-1 text-2xl font-semibold text-gray-900">{{ $enabledEntitlements->count() }}</div>
                </div>
            </div>

            @if ((string) $plan->description !== '')
                <div class="mt-5 rounded-md border border-slate-200 bg-white px-4 py-3 text-sm leading-6 text-slate-700">
                    {{ $plan->description }}
                </div>
            @endif
        </section>

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-lg font-semibold text-gray-900">资源额度</h2>
            </div>
            <div class="grid grid-cols-1 gap-3 p-5 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($resourceCatalog as $resourceKey => $resource)
                    @php
                        $entitlement = $entitlementMap->get($resourceKey);
                        $isEnabled = $entitlement?->enabled ?? false;
                    @endphp
                    <div class="rounded-md border border-slate-200 bg-slate-50 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="text-sm font-semibold text-gray-900">{{ $resource['label'] }}</div>
                            @if ($isEnabled)
                                <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">启用</span>
                            @else
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">未启用</span>
                            @endif
                        </div>
                        <div class="mt-2 text-2xl font-semibold text-slate-900">{{ $isEnabled && (int) ($entitlement?->quota_value ?? 0) <= 0 ? '不限' : ($entitlement?->quota_value ?? 0) }}</div>
                        <div class="mt-1 text-xs text-slate-500">{{ $isEnabled && (int) ($entitlement?->quota_value ?? 0) <= 0 ? '不限制使用数量' : '按开通周期总量计算' }}</div>
                    </div>
                @endforeach
            </div>
        </section>
    </div>
@endsection
