@extends('admin.layouts.app')

@php
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
                <p class="mt-1 text-sm text-gray-600">总管理员可以创建客户站点、绑定二级域名，并分配可维护该站点的管理员。</p>
            </div>
            <a href="{{ route('admin.site-settings.index') }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50">
                <i data-lucide="settings" class="h-4 w-4"></i>
                当前站点设置
            </a>
        </div>

        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-5 flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-md bg-indigo-50 text-indigo-600">
                    <i data-lucide="plus" class="h-5 w-5"></i>
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">创建站点</h2>
                    <p class="text-sm text-gray-500">域名只填写主机名，例如 a.geo.xinzhidi.cn。</p>
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
                <div class="lg:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-gray-700" for="create-customer-mode">客户模式</label>
                    <select id="create-customer-mode" name="customer_mode" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                        <option value="internal" @selected(old('customer_mode', 'internal') === 'internal')>内部站点</option>
                        <option value="agent" @selected(old('customer_mode') === 'agent')>代理</option>
                        <option value="direct" @selected(old('customer_mode') === 'direct')>直客</option>
                    </select>
                </div>
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
                <h2 class="text-lg font-semibold text-gray-900">全部站点</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
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
                                $memberIds = $site->members->pluck('id')->map(fn ($id) => (int) $id)->all();
                            @endphp
                            <tr>
                                <td class="px-5 py-4 align-top">
                                    <div class="text-sm font-semibold text-gray-900">{{ $site->name }}</div>
                                    <div class="mt-1 text-xs text-gray-400">ID: {{ $site->id }}</div>
                                </td>
                                <td class="px-5 py-4 align-top text-sm text-gray-600">
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
                                    @php($latestSubscription = $site->planSubscriptions->first())
                                    @if ($latestSubscription)
                                        <div>{{ $latestSubscription->plan?->name ?? '规格已删除' }}</div>
                                        <div class="mt-1 text-xs text-gray-400">{{ $latestSubscription->ends_at?->format('Y-m-d') ?? '-' }} 到期</div>
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
                                <td class="px-5 py-4 align-top text-right">
                                    <div class="inline-flex items-center justify-end gap-3">
                                        <button type="button" onclick="toggleSiteEdit({{ $site->id }})" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">编辑</button>
                                        <form method="POST" action="{{ route('admin.sites.manage.toggle-status', ['site' => $site->id]) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-sm font-medium {{ $site->status === 'active' ? 'text-amber-600 hover:text-amber-800' : 'text-green-600 hover:text-green-800' }}">
                                                {{ $site->status === 'active' ? '停用' : '启用' }}
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <tr id="site-edit-{{ $site->id }}" class="hidden bg-slate-50/70">
                                <td colspan="8" class="px-5 py-5">
                                    <form method="POST" action="{{ route('admin.sites.manage.update', ['site' => $site->id]) }}" class="grid grid-cols-1 gap-4 lg:grid-cols-12">
                                        @csrf
                                        <div class="lg:col-span-3">
                                            <label class="mb-1 block text-sm font-medium text-gray-700" for="site-name-{{ $site->id }}">站点名称</label>
                                            <input id="site-name-{{ $site->id }}" name="name" required value="{{ $site->name }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                                        </div>
                                        <div class="lg:col-span-3">
                                            <label class="mb-1 block text-sm font-medium text-gray-700" for="site-domain-{{ $site->id }}">前台域名</label>
                                            <input id="site-domain-{{ $site->id }}" name="domain" value="{{ $site->domain }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                                        </div>
                                        <div class="lg:col-span-2">
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
                                        <div class="lg:col-span-2">
                                            <label class="mb-1 block text-sm font-medium text-gray-700" for="site-status-{{ $site->id }}">状态</label>
                                            <select id="site-status-{{ $site->id }}" name="status" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                                                <option value="active" @selected($site->status === 'active')>启用</option>
                                                <option value="inactive" @selected($site->status === 'inactive')>停用</option>
                                            </select>
                                        </div>
                                        <div class="lg:col-span-2">
                                            <label class="mb-1 block text-sm font-medium text-gray-700" for="site-customer-mode-{{ $site->id }}">客户模式</label>
                                            <select id="site-customer-mode-{{ $site->id }}" name="customer_mode" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                                                <option value="internal" @selected(($site->customer_mode ?? 'internal') === 'internal')>内部站点</option>
                                                <option value="agent" @selected(($site->customer_mode ?? 'internal') === 'agent')>代理</option>
                                                <option value="direct" @selected(($site->customer_mode ?? 'internal') === 'direct')>直客</option>
                                            </select>
                                        </div>
                                        <div class="flex items-end lg:col-span-2">
                                            <button type="submit" class="inline-flex h-10 w-full items-center justify-center gap-2 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white transition hover:bg-indigo-700">
                                                <i data-lucide="save" class="h-4 w-4"></i>
                                                保存
                                            </button>
                                        </div>
                                        <div class="lg:col-span-12">
                                            <label class="mb-2 block text-sm font-medium text-gray-700">可管理此站点的管理员</label>
                                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                                @foreach ($adminOptions as $option)
                                                    <label class="flex min-h-10 items-center gap-2 rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-700">
                                                        <input type="checkbox" name="member_ids[]" value="{{ $option['id'] }}" @checked(in_array($option['id'], $memberIds, true)) class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                                        <span class="truncate">{{ $option['label'] }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        </div>
                                    </form>
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
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        function toggleSiteEdit(siteId) {
            const row = document.getElementById(`site-edit-${siteId}`);
            if (!row) {
                return;
            }
            row.classList.toggle('hidden');
        }
    </script>
@endpush
