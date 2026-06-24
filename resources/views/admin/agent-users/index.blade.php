@extends('admin.layouts.app')

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">代理用户管理</h1>
                <p class="mt-1 text-sm text-gray-600">代理可以创建客户账号；每个客户账号都会生成独立前台站点并继承代理当前规格。</p>
            </div>
            <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
                子账号：{{ $quota['used'] }} / {{ $quota['quota'] === null ? '不限' : $quota['quota'] }}
            </div>
        </div>

        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-5 flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-md bg-indigo-50 text-indigo-600">
                    <i data-lucide="user-plus" class="h-5 w-5"></i>
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">新增普通用户</h2>
                    <p class="text-sm text-gray-500">创建后系统会自动生成 8 位随机前台站点名，并继承代理当前规格。</p>
                </div>
            </div>

            <form method="POST" action="{{ route('admin.agent-users.store') }}" autocomplete="off" class="grid grid-cols-1 gap-4 xl:grid-cols-12">
                @csrf
                <div class="xl:col-span-3">
                    <label class="mb-1 block text-sm font-medium text-gray-700">账号</label>
                    <input name="username" required autocomplete="new-password" value="" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                </div>
                <div class="xl:col-span-3">
                    <label class="mb-1 block text-sm font-medium text-gray-700">密码</label>
                    <input name="password" type="password" required autocomplete="new-password" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                </div>
                <div class="xl:col-span-3">
                    <label class="mb-1 block text-sm font-medium text-gray-700">确认密码</label>
                    <input name="confirm_password" type="password" required autocomplete="new-password" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                </div>
                <div class="flex items-end sm:justify-end xl:col-span-3">
                    <button type="submit" class="inline-flex h-10 w-full items-center justify-center gap-2 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white transition hover:bg-indigo-700 sm:w-auto sm:min-w-28">
                        <i data-lucide="save" class="h-4 w-4"></i>
                        创建
                    </button>
                </div>
            </form>
        </section>

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-lg font-semibold text-gray-900">普通用户列表</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">账号</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">前台站点</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">套餐</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">状态</th>
                            <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-500">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @forelse ($members as $member)
                            @php
                                $memberSite = $member->sites->first();
                                $memberSubscription = $member->accountPlanSubscriptions->first();
                                $planName = $memberSubscription?->plan?->name ?? '未开通';
                            @endphp
                            <tr>
                                <td class="px-5 py-4 align-top">
                                    <div class="text-sm font-semibold text-gray-900">{{ $member->display_name ?: $member->username }}</div>
                                    <div class="mt-1 text-xs text-gray-400">{{ $member->username }}</div>
                                </td>
                                <td class="px-5 py-4 align-top text-sm text-gray-600">
                                    {{ $memberSite?->name ?? '-' }}
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <div class="text-sm font-medium text-gray-900">{{ $planName }}</div>
                                    @if ($memberSubscription?->ends_at)
                                        <div class="mt-1 text-xs text-gray-400">到期：{{ $memberSubscription->ends_at->format('Y-m-d') }}</div>
                                    @endif
                                </td>
                                <td class="px-5 py-4 align-top">
                                    @if ($member->status === 'active')
                                        <span class="inline-flex rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">启用</span>
                                    @else
                                        <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">停用</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 align-top text-right">
                                    <div class="inline-flex flex-wrap items-center justify-end gap-3">
                                        <a href="{{ route('admin.plan-usages.index', ['admin_id' => (int) $member->id]) }}" class="text-sm font-medium text-slate-600 hover:text-slate-900">
                                            规格用量
                                        </a>
                                        <button
                                            type="button"
                                            data-agent-user-edit
                                            data-member-id="{{ (int) $member->id }}"
                                            data-member-username="{{ $member->username }}"
                                            class="text-sm font-medium text-blue-600 hover:text-blue-800"
                                        >
                                            编辑
                                        </button>
                                        <form method="POST" action="{{ route('admin.agent-users.toggle-status', ['adminId' => $member->id]) }}" class="inline">
                                            @csrf
                                            <input type="hidden" name="next_status" value="{{ $member->status === 'active' ? 'inactive' : 'active' }}">
                                            <button type="submit" class="text-sm font-medium {{ $member->status === 'active' ? 'text-amber-600 hover:text-amber-800' : 'text-green-600 hover:text-green-800' }}">
                                                {{ $member->status === 'active' ? '停用' : '启用' }}
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-10 text-center text-sm text-gray-500">暂无普通用户</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div id="agent-user-edit-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/45 px-4 py-6">
            <div class="w-full max-w-lg overflow-hidden rounded-lg bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">编辑普通用户</h3>
                        <p class="mt-1 text-xs text-gray-500">账号和归属关系不可修改，密码留空则保持不变。</p>
                    </div>
                    <button type="button" data-agent-user-edit-close class="inline-flex h-8 w-8 items-center justify-center rounded-md text-gray-400 hover:bg-slate-100 hover:text-gray-600" aria-label="关闭">
                        <i data-lucide="x" class="h-4 w-4"></i>
                    </button>
                </div>
                <form id="agent-user-edit-form" method="POST" action="#" autocomplete="off" class="space-y-4 px-5 py-5">
                    @csrf
                    <div>
                        <label for="agent_user_edit_username" class="mb-1 block text-sm font-medium text-gray-700">账号</label>
                        <input id="agent_user_edit_username" type="text" disabled class="block h-10 w-full rounded-md border border-slate-200 bg-slate-50 px-3 text-sm text-slate-500">
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="agent_user_edit_password" class="mb-1 block text-sm font-medium text-gray-700">新密码</label>
                            <input id="agent_user_edit_password" name="password" type="password" autocomplete="new-password" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                        </div>
                        <div>
                            <label for="agent_user_edit_confirm_password" class="mb-1 block text-sm font-medium text-gray-700">确认新密码</label>
                            <input id="agent_user_edit_confirm_password" name="confirm_password" type="password" autocomplete="new-password" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-slate-200 pt-4">
                        <button type="button" data-agent-user-edit-close class="inline-flex h-10 items-center justify-center rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50">取消</button>
                        <button type="submit" class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white hover:bg-indigo-700">
                            <i data-lucide="save" class="h-4 w-4"></i>
                            保存
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const updateRouteTemplate = @json(route('admin.agent-users.update', ['adminId' => '__ADMIN_ID__']));
            const modal = document.getElementById('agent-user-edit-modal');
            const form = document.getElementById('agent-user-edit-form');
            const usernameInput = document.getElementById('agent_user_edit_username');
            const passwordInput = document.getElementById('agent_user_edit_password');
            const confirmPasswordInput = document.getElementById('agent_user_edit_confirm_password');

            function closeModal() {
                modal?.classList.add('hidden');
                modal?.classList.remove('flex');
            }

            document.querySelectorAll('[data-agent-user-edit]').forEach((button) => {
                button.addEventListener('click', () => {
                    form.action = updateRouteTemplate.replace('__ADMIN_ID__', button.dataset.memberId || '');
                    usernameInput.value = button.dataset.memberUsername || '';
                    passwordInput.value = '';
                    confirmPasswordInput.value = '';
                    modal?.classList.remove('hidden');
                    modal?.classList.add('flex');
                });
            });

            document.querySelectorAll('[data-agent-user-edit-close]').forEach((button) => {
                button.addEventListener('click', closeModal);
            });

            modal?.addEventListener('click', (event) => {
                if (event.target === modal) {
                    closeModal();
                }
            });
        })();
    </script>
@endpush
