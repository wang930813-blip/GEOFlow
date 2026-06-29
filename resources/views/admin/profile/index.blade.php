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
                            <div class="rounded-md border border-amber-200 bg-gradient-to-br from-amber-50 via-white to-indigo-50 px-4 py-3 text-sm text-slate-700 shadow-sm">
                                <div class="flex items-center gap-2 font-medium text-slate-900">
                                    <span class="inline-flex h-7 w-7 items-center justify-center rounded-md bg-amber-100 text-amber-700">
                                        <i data-lucide="sparkles" class="h-4 w-4"></i>
                                    </span>
                                    <span>积分权益包</span>
                                </div>
                                @if (($creditDescription ?? '') !== '')
                                    <div class="mt-2 flex max-w-full items-center gap-1 rounded-md border border-amber-200 bg-white/80 px-2.5 py-1 text-xs font-medium leading-5 text-amber-800">
                                        <i data-lucide="badge-check" class="h-3.5 w-3.5 shrink-0"></i>
                                        <span class="break-words">{{ $creditDescription }}</span>
                                    </div>
                                @endif
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
                                @php($isStatOnly = (bool) ($resource['is_stat_only'] ?? false))
                                @php($barStyle = 'width: '.$resource['percent'].'%'.(! $isUnlimited && (float) $resource['used'] > 0 && (float) $resource['percent'] < 1 ? '; min-width: 6px' : ''))
                                @if ($isStatOnly)
                                    <div class="relative overflow-hidden rounded-md border border-orange-200 bg-gradient-to-br from-orange-50 via-white to-emerald-50 p-4 shadow-sm" data-resource-key="{{ $resource['key'] }}">
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <div class="text-sm font-semibold text-gray-900">{{ $resource['label'] }}</div>
                                                @if (($resource['description'] ?? '') !== '')
                                                    <div class="mt-1 text-xs leading-5 text-orange-700">{{ $resource['description'] }}</div>
                                                @endif
                                            </div>
                                            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-orange-100 text-orange-700">
                                                <i data-lucide="{{ $resource['icon'] ?? 'megaphone' }}" class="h-4 w-4"></i>
                                            </span>
                                        </div>
                                        <div class="mt-4 text-3xl font-bold tracking-normal text-slate-950">{{ $resource['used'] }} 条</div>
                                        <div class="mt-2 text-xs text-slate-500">累计投放成果，随媒体发布自动增长</div>
                                    </div>
                                @else
                                    <div class="rounded-md border p-4 shadow-sm {{ $isUnlimited ? 'border-emerald-200 bg-gradient-to-br from-emerald-50 via-white to-sky-50' : 'border-slate-200 bg-gradient-to-br from-white via-white to-slate-50' }}" data-resource-key="{{ $resource['key'] }}">
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <div class="text-sm font-semibold text-gray-900">{{ $resource['label'] }}</div>
                                                @if (($resource['description'] ?? '') !== '')
                                                    <div class="mt-1 text-xs leading-5 text-slate-500">{{ $resource['description'] }}</div>
                                                @endif
                                            </div>
                                            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md {{ $isUnlimited ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                                <i data-lucide="activity" class="h-4 w-4"></i>
                                            </span>
                                        </div>
                                        <div class="mt-4 flex items-end justify-between gap-3">
                                            <div class="text-sm font-medium text-slate-700">已用 {{ $resource['used'] }} / {{ $isUnlimited ? '不限' : $resource['quota'] }}</div>
                                            <div class="shrink-0 text-xs {{ $isUnlimited ? 'font-medium text-emerald-700' : 'text-slate-500' }}">剩余 {{ $isUnlimited ? '不限' : $resource['remaining'] }}</div>
                                        </div>
                                        <div class="mt-3 h-2 overflow-hidden rounded-full {{ $isUnlimited ? 'bg-emerald-100' : 'bg-slate-100' }}">
                                            <div class="h-full rounded-full {{ $isUnlimited ? 'bg-emerald-400' : 'bg-indigo-500' }}" style="{{ $barStyle }}"></div>
                                        </div>
                                    </div>
                                @endif
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
