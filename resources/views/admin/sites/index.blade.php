@extends('admin.layouts.app')

@php
    $isAgentSiteManager = (bool) ($isAgentSiteManager ?? false);
    $isSuperSiteManager = (bool) ($isSuperSiteManager ?? false);
    $monitoringReportLogosBySite = $monitoringReportLogosBySite ?? collect();
    $adminOptions = $admins->map(fn ($admin) => [
        'id' => (int) $admin->id,
        'label' => trim((string) $admin->display_name) !== '' ? $admin->display_name.' ('.$admin->username.')' : $admin->username,
        'status' => (string) $admin->status,
        'is_super' => $admin->isSuperAdmin(),
    ]);
@endphp

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">站点管理</h1>
                <p class="mt-1 text-sm text-gray-600">
                    @if($isAgentSiteManager)
                        为下级用户创建和维护前台站点，站点数据仅归属当前代理。
                    @else
                        超管可以创建客户站点、绑定二级域名，并分配可维护该站点的管理员。
                    @endif
                </p>
            </div>
            @if($isSuperSiteManager)
                <a href="{{ route('admin.site-settings.index') }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50">
                    <i data-lucide="settings" class="h-4 w-4"></i>
                    当前站点设置
                </a>
            @endif
        </div>

        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-5 flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-md bg-indigo-50 text-indigo-600">
                    <i data-lucide="plus" class="h-5 w-5"></i>
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">创建站点</h2>
                    <p class="text-sm text-gray-500">域名只在创建时填写，创建后不可修改。</p>
                </div>
            </div>

            <form method="POST" action="{{ route('admin.sites.manage.store') }}" class="grid grid-cols-1 gap-4 lg:grid-cols-12">
                @csrf
                <div class="lg:col-span-3">
                    <label class="mb-1 block text-sm font-medium text-gray-700" for="create-name">站点名称</label>
                    <input id="create-name" name="name" required value="{{ old('name') }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="客户A官网">
                </div>
                <div class="lg:col-span-3">
                    <label class="mb-1 block text-sm font-medium text-gray-700" for="create-domain">前台域名</label>
                    <input id="create-domain" name="domain" value="{{ old('domain') }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="a.geo.xinzhidi.cn">
                </div>
                @if($isSuperSiteManager)
                    <div class="lg:col-span-4">
                        <label class="mb-1 block text-sm font-medium text-gray-700" for="create-monitoring-report-logo">监测中心报表 Logo</label>
                        <input id="create-monitoring-report-logo" name="monitoring_report_logo" value="{{ old('monitoring_report_logo') }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="https://example.com/report-logo.png">
                        <p class="mt-1 text-xs text-slate-500">为空时使用平台默认 Logo。</p>
                    </div>
                @endif
                <div class="lg:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-gray-700" for="create-owner">站点负责人</label>
                    <select id="create-owner" name="owner_admin_id" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                        <option value="">不指定</option>
                        @foreach ($adminOptions as $option)
                            <option value="{{ $option['id'] }}" @selected((string) old('owner_admin_id') === (string) $option['id'])>
                                {{ $option['label'] }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="lg:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-gray-700" for="create-status">状态</label>
                    <select id="create-status" name="status" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                        <option value="active" @selected(old('status', 'active') === 'active')>启用</option>
                        <option value="inactive" @selected(old('status') === 'inactive')>停用</option>
                    </select>
                </div>
                @if($isAgentSiteManager)
                    <input type="hidden" name="customer_mode" value="agent">
                @else
                    <div class="lg:col-span-2">
                        <label class="mb-1 block text-sm font-medium text-gray-700" for="create-customer-mode">客户模式</label>
                        <select id="create-customer-mode" name="customer_mode" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                            <option value="internal" @selected(old('customer_mode', 'internal') === 'internal')>内部站点</option>
                            <option value="agent" @selected(old('customer_mode') === 'agent')>代理</option>
                            <option value="direct" @selected(old('customer_mode') === 'direct')>直客</option>
                        </select>
                    </div>
                @endif
                <div class="flex items-end lg:col-span-2">
                    <button type="submit" class="inline-flex h-10 w-full items-center justify-center gap-2 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white transition hover:bg-indigo-700">
                        <i data-lucide="save" class="h-4 w-4"></i>
                        创建
                    </button>
                </div>
                <div class="lg:col-span-12">
                    <label class="mb-2 block text-sm font-medium text-gray-700">可管理此站点的管理员</label>
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($adminOptions as $option)
                            <label class="flex min-h-10 items-center gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 text-sm text-gray-700">
                                <input type="checkbox" name="member_ids[]" value="{{ $option['id'] }}" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                <span class="truncate">{{ $option['label'] }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </form>
        </section>

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-lg font-semibold text-gray-900">{{ $isAgentSiteManager ? '下级用户站点' : '全部站点' }}</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-[1120px] table-fixed divide-y divide-slate-200">
                    <colgroup>
                        <col class="w-48">
                        <col class="w-56">
                        <col class="w-40">
                        <col class="w-28">
                        <col class="w-44">
                        <col class="w-24">
                        <col class="w-24">
                        <col class="w-36">
                    </colgroup>
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">站点</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">前台域名</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">负责人</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">客户模式</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">规格</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">成员</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">状态</th>
                            <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-500">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @forelse ($sites as $site)
                            @php
                                $latestSubscription = $site->planSubscriptions->first();
                                $accountSubscription = ($accountPlanSubscriptionsBySite ?? collect())->get((int) $site->owner_admin_id.':'.(int) $site->id);
                                $displaySubscription = $latestSubscription ?: $accountSubscription;
                            @endphp
                            <tr>
                                <td class="px-5 py-4 align-top">
                                    <div class="truncate text-sm font-semibold text-gray-900" title="{{ $site->name }}">{{ $site->name }}</div>
                                    <div class="mt-1 text-xs text-gray-400">ID: {{ $site->id }}</div>
                                </td>
                                <td class="break-all px-5 py-4 align-top text-sm text-gray-600">
                                    @if ((string) $site->domain !== '')
                                        {{ $site->domain }}
                                    @else
                                        <span class="text-gray-400">未绑定</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 align-top text-sm text-gray-600">
                                    {{ $site->owner?->name ?? '未指定' }}
                                </td>
                                <td class="px-5 py-4 align-top text-sm text-gray-600">
                                    {{ ['agent' => '代理', 'direct' => '直客', 'internal' => '内部'][$site->customer_mode ?? 'internal'] ?? $site->customer_mode }}
                                </td>
                                <td class="px-5 py-4 align-top text-sm text-gray-600">
                                    @if ($displaySubscription)
                                        <div>{{ $displaySubscription->plan?->name ?? '规格已删除' }}</div>
                                        <div class="mt-1 text-xs text-gray-400">{{ $displaySubscription->ends_at?->format('Y-m-d') ?? '-' }} 到期</div>
                                    @else
                                        <span class="text-gray-400">未开通</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 align-top text-sm text-gray-600">
                                    {{ $site->members_count }} 人
                                </td>
                                <td class="px-5 py-4 align-top">
                                    @if ($site->status === 'active')
                                        <span class="inline-flex rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">启用</span>
                                    @else
                                        <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">停用</span>
                                    @endif
                                </td>
                                <td class="w-36 px-4 py-4 align-top text-right">
                                    <form method="POST" action="{{ route('admin.sites.manage.destroy', ['site' => $site->id]) }}" class="mb-2 inline-block" onsubmit="return confirm('确定删除该站点吗？删除后会取消关联开通记录并移除站点成员。');">
                                        @csrf
                                        <button type="submit" class="text-sm font-medium text-red-600 hover:text-red-800">删除</button>
                                    </form>
                                    <div class="inline-flex items-center justify-end gap-2 whitespace-nowrap">
                                        <button type="button" data-open-site-edit-modal="{{ $site->id }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">编辑</button>
                                        <form method="POST" action="{{ route('admin.sites.manage.toggle-status', ['site' => $site->id]) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-sm font-medium {{ $site->status === 'active' ? 'text-amber-600 hover:text-amber-800' : 'text-green-600 hover:text-green-800' }}">
                                                {{ $site->status === 'active' ? '停用' : '启用' }}
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-5 py-10 text-center text-sm text-gray-500">暂无站点</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($sites->hasPages())
                <div class="border-t border-slate-200 px-5 py-4">
                    {{ $sites->onEachSide(1)->links() }}
                </div>
            @endif
        </section>

        @foreach ($sites as $site)
            @php
                $memberIds = $site->members->pluck('id')->map(fn ($id) => (int) $id)->all();
                $monitoringReportLogo = (string) ($monitoringReportLogosBySite->get((int) $site->id, '') ?? '');
            @endphp
            <div id="site-edit-modal-{{ $site->id }}" data-site-edit-modal class="fixed inset-0 z-50 hidden overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="site-edit-title-{{ $site->id }}">
                <div class="flex min-h-full items-center justify-center px-4 py-6">
                    <div class="fixed inset-0 bg-slate-900/50" data-close-site-edit-modal></div>
                    <form method="POST" action="{{ route('admin.sites.manage.update', ['site' => $site->id]) }}" class="relative w-full max-w-5xl overflow-hidden rounded-lg bg-white shadow-xl">
                        @csrf
                        <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-4">
                            <div>
                                <h2 id="site-edit-title-{{ $site->id }}" class="text-lg font-semibold text-slate-900">编辑站点</h2>
                                <p class="mt-1 text-sm text-slate-500">{{ $site->name }} / ID: {{ $site->id }}</p>
                            </div>
                            <button type="button" data-close-site-edit-modal class="rounded-md p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
                                <i data-lucide="x" class="h-5 w-5"></i>
                            </button>
                        </div>

                        <div class="max-h-[70vh] overflow-y-auto px-6 py-5">
                            <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-4">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700" for="site-name-{{ $site->id }}">站点名称</label>
                                    <input id="site-name-{{ $site->id }}" name="name" required value="{{ $site->name }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700" for="site-domain-{{ $site->id }}">前台域名</label>
                                    <input id="site-domain-{{ $site->id }}" value="{{ $site->domain }}" readonly class="block h-10 w-full cursor-not-allowed rounded-md border border-slate-200 bg-slate-100 px-3 text-sm text-slate-500 outline-none">
                                    <p class="mt-1 text-xs text-slate-500">创建后不可修改。</p>
                                </div>
                                @if($isSuperSiteManager)
                                    <div class="xl:col-span-2">
                                        <label class="mb-1 block text-sm font-medium text-gray-700" for="site-monitoring-report-logo-{{ $site->id }}">监测中心报表 Logo</label>
                                        <input id="site-monitoring-report-logo-{{ $site->id }}" name="monitoring_report_logo" value="{{ old('monitoring_report_logo', $monitoringReportLogo) }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="https://example.com/report-logo.png">
                                        <p class="mt-1 text-xs text-slate-500">为空时使用平台默认 Logo。</p>
                                    </div>
                                @endif
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700" for="site-owner-{{ $site->id }}">负责人</label>
                                    <select id="site-owner-{{ $site->id }}" name="owner_admin_id" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                                        <option value="">不指定</option>
                                        @foreach ($adminOptions as $option)
                                            <option value="{{ $option['id'] }}" @selected((int) $site->owner_admin_id === $option['id'])>
                                                {{ $option['label'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700" for="site-status-{{ $site->id }}">状态</label>
                                    <select id="site-status-{{ $site->id }}" name="status" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                                        <option value="active" @selected($site->status === 'active')>启用</option>
                                        <option value="inactive" @selected($site->status === 'inactive')>停用</option>
                                    </select>
                                </div>
                                @if($isAgentSiteManager)
                                    <input type="hidden" name="customer_mode" value="agent">
                                @else
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-gray-700" for="site-customer-mode-{{ $site->id }}">客户模式</label>
                                        <select id="site-customer-mode-{{ $site->id }}" name="customer_mode" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                                            <option value="internal" @selected(($site->customer_mode ?? 'internal') === 'internal')>内部站点</option>
                                            <option value="agent" @selected(($site->customer_mode ?? 'internal') === 'agent')>代理</option>
                                            <option value="direct" @selected(($site->customer_mode ?? 'internal') === 'direct')>直客</option>
                                        </select>
                                    </div>
                                @endif
                            </div>

                            <div class="mt-6">
                                <label class="mb-2 block text-sm font-medium text-gray-700">可管理此站点的管理员</label>
                                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3">
                                    @foreach ($adminOptions as $option)
                                        <label class="flex min-h-10 items-center gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 text-sm text-gray-700">
                                            <input type="checkbox" name="member_ids[]" value="{{ $option['id'] }}" @checked(in_array($option['id'], $memberIds, true)) class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                            <span class="truncate">{{ $option['label'] }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4">
                            <button type="button" data-close-site-edit-modal class="inline-flex h-10 items-center rounded-md border border-slate-300 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-100">取消</button>
                            <button type="submit" class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white transition hover:bg-indigo-700">
                                <i data-lucide="save" class="h-4 w-4"></i>
                                保存
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @endforeach
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const modals = document.querySelectorAll('[data-site-edit-modal]');

            function closeSiteEditModals() {
                modals.forEach((modal) => modal.classList.add('hidden'));
                document.body.classList.remove('overflow-hidden');
            }

            document.querySelectorAll('[data-open-site-edit-modal]').forEach((button) => {
                button.addEventListener('click', () => {
                    const modal = document.getElementById(`site-edit-modal-${button.dataset.openSiteEditModal}`);
                    if (!modal) {
                        return;
                    }

                    closeSiteEditModals();
                    modal.classList.remove('hidden');
                    document.body.classList.add('overflow-hidden');
                });
            });

            document.querySelectorAll('[data-close-site-edit-modal]').forEach((button) => {
                button.addEventListener('click', closeSiteEditModals);
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    closeSiteEditModals();
                }
            });
        });
    </script>
@endpush
