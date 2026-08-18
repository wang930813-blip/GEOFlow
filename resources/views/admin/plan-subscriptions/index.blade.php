@extends('admin.layouts.app')

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">客户开通</h1>
                <p class="mt-1 text-sm text-gray-600">线下收款后，由平台后台手动给代理或直客开通规格。</p>
            </div>
            <a href="{{ route('admin.platform-plans.index') }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50">
                <i data-lucide="package" class="h-4 w-4"></i>
                平台规格
            </a>
        </div>

        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-5 flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-md bg-emerald-50 text-emerald-600">
                    <i data-lucide="badge-check" class="h-5 w-5"></i>
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">开通规格</h2>
                    <p class="text-sm text-gray-500">新开通会取消该站点原 active 订阅，业务可用性按新的起止时间判断。</p>
                </div>
            </div>

            <div class="mb-5 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-900">
                <div class="flex gap-2">
                    <i data-lucide="info" class="mt-0.5 h-4 w-4 shrink-0"></i>
                    <div>
                        <span class="font-medium">时间规则：</span>
                        手动填写到期时间时，以手动时间为准；未填写到期时间时，系统按所选规格的服务天数自动计算。续费未到期客户时，如需顺延，请手动填写“原到期时间 + 购买时长”后的新到期时间。
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('admin.plan-subscriptions.store') }}" class="grid grid-cols-1 gap-4 lg:grid-cols-12">
                @csrf
                <div class="lg:col-span-3">
                    <label class="mb-1 block text-sm font-medium text-gray-700">站点</label>
                    <select name="site_id" required class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                        <option value="">请选择站点</option>
                        @foreach ($sites as $site)
                            <option value="{{ $site->id }}" @selected((string) old('site_id') === (string) $site->id)>{{ $site->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="lg:col-span-3">
                    <label class="mb-1 block text-sm font-medium text-gray-700">规格</label>
                    <select name="plan_id" required class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                        <option value="">请选择规格</option>
                        @foreach ($plans as $plan)
                            <option value="{{ $plan->id }}" @selected((string) old('plan_id') === (string) $plan->id)>{{ $plan->name }} / {{ $plan->duration_days }} 天</option>
                        @endforeach
                    </select>
                </div>
                <div class="lg:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-gray-700">客户模式</label>
                    <select name="mode" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                        <option value="direct">直客</option>
                        <option value="agent">代理</option>
                        <option value="internal">内部站点</option>
                    </select>
                </div>
                <div class="lg:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-gray-700">负责人</label>
                    <select name="owner_admin_id" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                        <option value="">沿用站点负责人</option>
                        @foreach ($admins as $admin)
                            <option value="{{ $admin->id }}">{{ $admin->display_name ?: $admin->username }} ({{ $admin->username }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="lg:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-gray-700">发放积分</label>
                    <label class="flex h-10 items-center gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 text-sm text-gray-700">
                        <input type="checkbox" name="grant_credits" value="1" checked class="h-4 w-4 rounded border-slate-300 text-indigo-600">
                        <span>开通时发放</span>
                    </label>
                </div>
                <div class="lg:col-span-3">
                    <label class="mb-1 block text-sm font-medium text-gray-700">开始时间</label>
                    <input name="starts_at" type="datetime-local" value="{{ old('starts_at') }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                </div>
                <div class="lg:col-span-3">
                    <label class="mb-1 block text-sm font-medium text-gray-700">到期时间</label>
                    <input name="ends_at" type="datetime-local" value="{{ old('ends_at') }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                    <p class="mt-1 text-xs text-gray-400">不填则按规格服务天数自动计算；填写后会覆盖规格默认天数。</p>
                </div>
                <div class="lg:col-span-4">
                    <label class="mb-1 block text-sm font-medium text-gray-700">备注</label>
                    <input name="remark" value="{{ old('remark') }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="线下转账开通">
                </div>
                <div class="flex items-end lg:col-span-2">
                    <button type="submit" class="inline-flex h-10 w-full items-center justify-center gap-2 rounded-md bg-emerald-600 px-4 text-sm font-medium text-white transition hover:bg-emerald-700">
                        <i data-lucide="save" class="h-4 w-4"></i>
                        开通
                    </button>
                </div>
            </form>
        </section>

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-lg font-semibold text-gray-900">开通记录</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">站点</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">规格</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">模式</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">有效期</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">状态</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @forelse ($subscriptions as $subscription)
                            <tr>
                                <td class="px-5 py-4 align-top text-sm font-medium text-gray-900">{{ $subscription->site?->name ?? '站点已删除' }}</td>
                                <td class="px-5 py-4 align-top text-sm text-gray-600">{{ $subscription->plan?->name ?? '规格已删除' }}</td>
                                <td class="px-5 py-4 align-top text-sm text-gray-600">
                                    {{ ['agent' => '代理', 'direct' => '直客', 'internal' => '内部'][$subscription->mode] ?? $subscription->mode }}
                                </td>
                                <td class="px-5 py-4 align-top text-sm text-gray-600">
                                    {{ $subscription->starts_at?->format('Y-m-d H:i') ?? '-' }} 至 {{ $subscription->ends_at?->format('Y-m-d H:i') ?? '-' }}
                                </td>
                                <td class="px-5 py-4 align-top">
                                    @if ($subscription->status === 'active' && (!$subscription->ends_at || $subscription->ends_at->isFuture()))
                                        <span class="inline-flex rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">有效</span>
                                    @elseif ($subscription->status === 'cancelled')
                                        <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">已停用</span>
                                    @else
                                        <span class="inline-flex rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800">已到期</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-10 text-center text-sm text-gray-500">暂无开通记录</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($subscriptions->hasPages())
                <div class="border-t border-slate-200 px-5 py-4">
                    {{ $subscriptions->onEachSide(1)->links() }}
                </div>
            @endif
        </section>
    </div>
@endsection
