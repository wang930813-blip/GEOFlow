@extends('admin.layouts.app')

@section('content')
    @php
        $badgeClass = match ($roleTone) {
            'indigo' => 'bg-indigo-50 text-indigo-700 ring-indigo-100',
            'amber' => 'bg-amber-50 text-amber-700 ring-amber-100',
            default => 'bg-emerald-50 text-emerald-700 ring-emerald-100',
        };
    @endphp

    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-bold text-gray-900">个人中心</h1>
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 {{ $badgeClass }}">{{ $roleLabel }}</span>
                </div>
                <p class="mt-1 text-sm text-gray-600">{{ $ownerLabel }}</p>
            </div>
            <a href="{{ route('admin.plan-usages.index') }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50">
                <i data-lucide="bar-chart-3" class="h-4 w-4"></i>
                规格使用情况
            </a>
        </div>

        <section class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($summaryCards as $card)
                <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="text-sm text-slate-500">{{ $card['label'] }}</div>
                    <div class="mt-2 text-2xl font-semibold text-slate-950">{{ $card['value'] }}</div>
                    <div class="mt-1 text-xs text-slate-500">{{ $card['desc'] }}</div>
                </div>
            @endforeach
        </section>

        @if ($admin->isSuperAdmin())
            <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
                <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-200 px-5 py-4">
                        <h2 class="text-lg font-semibold text-gray-900">平台数据融合</h2>
                    </div>
                    <div class="divide-y divide-slate-100">
                        @forelse ($agentUserRows as $row)
                            <div class="flex items-center justify-between gap-4 px-5 py-4">
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-medium text-gray-900">{{ $row['agent']->name }}</div>
                                    <div class="mt-1 text-xs text-slate-500">{{ $row['agent']->username }}</div>
                                </div>
                                <div class="text-sm font-semibold text-slate-900">{{ $row['user_count'] }} 个用户</div>
                            </div>
                        @empty
                            <div class="px-5 py-10 text-center text-sm text-slate-500">暂无代理账号。</div>
                        @endforelse
                    </div>
                </section>

                <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-200 px-5 py-4">
                        <h2 class="text-lg font-semibold text-gray-900">平台套餐激活</h2>
                    </div>
                    <div class="divide-y divide-slate-100">
                        @forelse ($planActivationRows as $row)
                            <div class="flex items-center justify-between gap-4 px-5 py-4">
                                <div class="truncate text-sm font-medium text-gray-900">{{ $row['plan_name'] }}</div>
                                <div class="text-sm text-slate-600">
                                    有效 <span class="font-semibold text-slate-950">{{ $row['active_count'] }}</span>
                                    / 总计 {{ $row['total_count'] }}
                                </div>
                            </div>
                        @empty
                            <div class="px-5 py-10 text-center text-sm text-slate-500">暂无平台规格。</div>
                        @endforelse
                    </div>
                </section>
            </div>
        @elseif ($admin->isAgentAdmin())
            <section class="rounded-lg border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-800">
                代理账号用于开通和管理下级会员账号，不作为文章、视频、自媒体发布等业务功能的使用账号。
            </section>
        @endif

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-lg font-semibold text-gray-900">{{ $admin->isSuperAdmin() ? '近期套餐使用' : '权益使用情况' }}</h2>
            </div>
            <div class="divide-y divide-slate-200">
                @forelse ($subscriptionRows as $row)
                    @php
                        $subscription = $row['subscription'];
                        $rowAdmin = $subscription->admin;
                        $creditAccount = $row['creditAccount'];
                    @endphp
                    <div class="p-5">
                        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-base font-semibold text-gray-900">{{ $rowAdmin?->name ?? '-' }}</h3>
                                    <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs text-slate-600">{{ $rowAdmin?->username ?? '-' }}</span>
                                    <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs text-slate-600">{{ $subscription->plan?->name ?? '规格已删除' }}</span>
                                </div>
                                <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-sm text-slate-500">
                                    <span>站点：{{ $subscription->site?->name ?? '-' }}</span>
                                    <span>有效期：{{ $subscription->starts_at?->format('Y-m-d') ?? '-' }} 至 {{ $subscription->ends_at?->format('Y-m-d') ?? '-' }}</span>
                                </div>
                            </div>
                            <div class="rounded-md bg-slate-50 px-4 py-3 text-sm text-slate-700">
                                <div class="font-medium text-slate-900">积分</div>
                                @if ($creditAccount)
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
                    <div class="px-5 py-12 text-center text-sm text-slate-500">暂无套餐使用记录。</div>
                @endforelse
            </div>
        </section>
    </div>
@endsection
