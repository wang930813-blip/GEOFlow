@extends('admin.layouts.app')

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">规格使用情况</h1>
                <p class="mt-1 text-sm text-gray-600">查看账号当前规格内各资源额度、已用量和剩余额度。</p>
            </div>
            @if ($isSuperAdmin)
                <a href="{{ route('admin.platform-plans.index') }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50">
                    <i data-lucide="package" class="h-4 w-4"></i>
                    平台规格
                </a>
            @endif
        </div>

        @if ($isSuperAdmin)
            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <form method="GET" action="{{ route('admin.plan-usages.index') }}" class="grid grid-cols-1 gap-4 lg:grid-cols-12">
                    <div class="lg:col-span-3">
                        <label class="mb-1 block text-sm font-medium text-gray-700">站点</label>
                        <select name="site_id" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                            <option value="">全部站点</option>
                            @foreach ($sites as $site)
                                <option value="{{ $site->id }}" @selected((string) request('site_id') === (string) $site->id)>{{ $site->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="lg:col-span-3">
                        <label class="mb-1 block text-sm font-medium text-gray-700">规格</label>
                        <select name="plan_id" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                            <option value="">全部规格</option>
                            @foreach ($plans as $plan)
                                <option value="{{ $plan->id }}" @selected((string) request('plan_id') === (string) $plan->id)>{{ $plan->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="lg:col-span-4">
                        <label class="mb-1 block text-sm font-medium text-gray-700">账号</label>
                        <input name="keyword" value="{{ request('keyword') }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="用户名或显示名称">
                    </div>
                    <div class="flex items-end gap-2 lg:col-span-2">
                        <button type="submit" class="inline-flex h-10 flex-1 items-center justify-center gap-2 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white transition hover:bg-indigo-700">
                            <i data-lucide="search" class="h-4 w-4"></i>
                            检索
                        </button>
                        <a href="{{ route('admin.plan-usages.index') }}" class="inline-flex h-10 items-center justify-center rounded-md border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50">重置</a>
                    </div>
                </form>
            </section>
        @endif

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-lg font-semibold text-gray-900">套餐用量</h2>
            </div>

            <div class="border-b border-slate-100 px-5 py-3 text-sm text-slate-500">
                共 {{ $subscriptions->total() }} 条，当前第 {{ $subscriptions->currentPage() }} / {{ $subscriptions->lastPage() }} 页
            </div>

            <div class="divide-y divide-slate-200">
                @forelse ($subscriptions as $row)
                    @php
                        $subscription = $row['subscription'];
                        $admin = $subscription->admin;
                        $site = $subscription->site;
                        $creditAccount = $row['creditAccount'];
                        $hasUnlimitedCredits = (bool) ($row['hasUnlimitedCredits'] ?? false);
                    @endphp
                    <div class="p-5" data-plan-usage-row>
                        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-base font-semibold text-gray-900">{{ $admin?->display_name ?: $admin?->username }}</h3>
                                    <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs text-slate-600">{{ $admin?->username }}</span>
                                    @if ($subscription->status === 'active' && (!$subscription->ends_at || $subscription->ends_at->isFuture()))
                                        <span class="rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">有效</span>
                                    @elseif ($subscription->status === 'cancelled')
                                        <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">已停用</span>
                                    @else
                                        <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800">已到期</span>
                                    @endif
                                </div>
                                <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-sm text-slate-500">
                                    <span>站点：{{ $site?->name ?? '-' }}</span>
                                    <span>规格：{{ $subscription->plan?->name ?? '规格已删除' }}</span>
                                    <span>有效期：{{ $subscription->starts_at?->format('Y-m-d') ?? '-' }} 至 {{ $subscription->ends_at?->format('Y-m-d') ?? '-' }}</span>
                                </div>
                            </div>

                            <div class="rounded-md bg-slate-50 px-4 py-3 text-sm text-slate-700">
                                <div class="font-medium text-slate-900">积分</div>
                                @if ($hasUnlimitedCredits)
                                    <div class="mt-1">额度不限</div>
                                @elseif ($creditAccount)
                                    <div class="mt-1">余额 {{ $creditAccount->balance }}，已用 {{ $creditAccount->total_consumed }}</div>
                                @else
                                    <div class="mt-1 text-slate-400">暂无积分账户</div>
                                @endif
                            </div>
                        </div>

                        <div class="mt-5 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                            @forelse ($row['resources'] as $resource)
                                @php($isUnlimited = (bool) ($resource['is_unlimited'] ?? false))
                                <div class="rounded-md border p-4 {{ $isUnlimited ? 'border-emerald-100 bg-emerald-50/30' : 'border-slate-200 bg-white' }}" data-resource-key="{{ $resource['key'] }}">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="text-sm font-medium text-gray-900">{{ $resource['label'] }}</div>
                                        <div class="shrink-0 text-xs {{ $isUnlimited ? 'font-medium text-emerald-700' : 'text-slate-500' }}">剩余 {{ $isUnlimited ? '不限' : $resource['remaining'] }}</div>
                                    </div>
                                    <div class="mt-2 text-sm text-slate-600">已用 {{ $resource['used'] }} / {{ $isUnlimited ? '不限' : $resource['quota'] }}</div>
                                    <div class="mt-3 h-2 overflow-hidden rounded-full {{ $isUnlimited ? 'bg-emerald-100' : 'bg-slate-100' }}">
                                        <div class="h-full rounded-full {{ $isUnlimited ? 'bg-emerald-400' : 'bg-indigo-500' }}" style="width: {{ $resource['percent'] }}%"></div>
                                    </div>
                                </div>
                            @empty
                                <div class="rounded-md border border-dashed border-slate-200 px-4 py-6 text-center text-sm text-slate-500 md:col-span-2 xl:col-span-3">暂无启用资源</div>
                            @endforelse
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-12 text-center text-sm text-slate-500">暂无规格使用记录</div>
                @endforelse
            </div>

            @if ($subscriptions->hasPages())
                <div class="border-t border-slate-200 px-5 py-4">
                    {{ $subscriptions->links() }}
                </div>
            @endif
        </section>
    </div>
@endsection
