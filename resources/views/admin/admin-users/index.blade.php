@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="flex items-center justify-between mb-8">
            <div class="flex items-center space-x-4">
                <a href="{{ route('admin.site-settings.index') }}" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.admin_users.page_title') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ __('admin.admin_users.page_subtitle') }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('admin.admin-activity-logs') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    <i data-lucide="clipboard-list" class="w-4 h-4 mr-2"></i>
                    {{ __('admin.admin_users.view_logs') }}
                </a>
                <button type="button" onclick="showCreateAdminModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
                    <i data-lucide="user-plus" class="w-4 h-4 mr-2"></i>
                    {{ __('admin.admin_users.add_admin') }}
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="users" class="h-6 w-6 text-indigo-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.admin_users.total_admins') }}</dt>
                            <dd class="text-lg font-medium text-gray-900">{{ $stats['total_admins'] }}</dd>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="badge-check" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.admin_users.active_admins') }}</dt>
                            <dd class="text-lg font-medium text-gray-900">{{ $stats['active_admins'] }}</dd>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="shield-check" class="h-6 w-6 text-amber-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.admin_users.super_admins') }}</dt>
                            <dd class="text-lg font-medium text-gray-900">{{ $stats['super_admins'] }}</dd>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
            <div class="flex items-start gap-3">
                <i data-lucide="info" class="w-5 h-5 text-blue-600 mt-0.5"></i>
                <div class="text-sm text-blue-900">
                    {{ __('admin.admin_users.permission_notice') }}
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.admin_users.list_title') }}</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.admin_users.column_account') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.admin_users.column_role') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.admin_users.column_status') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.admin_users.column_last_login') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.admin_users.column_created') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.admin_users.column_activity') }}</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @foreach ($admins as $admin)
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">{{ $admin['display_name'] !== '' ? $admin['display_name'] : $admin['username'] }}</div>
                                    <div class="text-sm text-gray-500">{{ $admin['username'] }}</div>
                                    @if ($admin['email'] !== '')
                                        <div class="text-xs text-gray-400">{{ $admin['email'] }}</div>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if ($admin['is_super_admin'])
                                        <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">{{ __('admin.admin_users.role_super_admin') }}</span>
                                    @elseif (($admin['role'] ?? '') === 'agent_admin')
                                        <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">代理管理员</span>
                                    @elseif (($admin['role'] ?? '') === 'direct_admin')
                                        <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">直客管理员</span>
                                    @elseif (($admin['role'] ?? '') === 'site_user')
                                        <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-700">站点普通用户</span>
                                    @else
                                        <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">{{ __('admin.admin_users.role_admin') }}</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if ($admin['status'] === 'active')
                                        <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">{{ __('admin.admin_users.status_active') }}</span>
                                    @else
                                        <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">{{ __('admin.admin_users.status_inactive') }}</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    {{ $admin['last_login'] !== '' ? $admin['last_login'] : __('admin.admin_users.none_last_login') }}
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">
                                    <div>{{ $admin['created_at'] }}</div>
                                    <div class="text-xs text-gray-400">
                                        {{ __('admin.admin_users.created_by', ['value' => $admin['creator_username'] !== '' ? $admin['creator_username'] : __('admin.admin_users.system_init')]) }}
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    {{ __('admin.admin_users.activity_count', ['count' => $admin['activity_count']]) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    @if ($admin['id'] === $currentAdminId)
                                        <button
                                            type="button"
                                            onclick="showEditAdminModal({{ \Illuminate\Support\Js::from($admin) }})"
                                            class="text-blue-600 hover:text-blue-800"
                                        >
                                            {{ __('admin.button.edit') }}
                                        </button>
                                    @elseif (! $admin['is_super_admin'])
                                        <div class="inline-flex items-center justify-end gap-3">
                                            <button
                                                type="button"
                                                onclick="showEditAdminModal({{ \Illuminate\Support\Js::from($admin) }})"
                                                class="text-blue-600 hover:text-blue-800"
                                            >
                                                {{ __('admin.button.edit') }}
                                            </button>
                                            <form method="POST" action="{{ route('admin.admin-users.toggle-status', ['adminId' => $admin['id']]) }}" class="inline">
                                                @csrf
                                                <input type="hidden" name="next_status" value="{{ $admin['status'] === 'active' ? 'inactive' : 'active' }}">
                                                <button type="submit" class="{{ $admin['status'] === 'active' ? 'text-amber-600 hover:text-amber-800' : 'text-green-600 hover:text-green-800' }}">
                                                    {{ $admin['status'] === 'active' ? __('admin.admin_users.action_disable') : __('admin.admin_users.action_enable') }}
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('admin.admin-users.delete', ['adminId' => $admin['id']]) }}" class="inline" onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('admin.admin_users.confirm_delete', ['username' => $admin['username']])) }})">
                                                @csrf
                                                <button type="submit" class="text-red-600 hover:text-red-800">
                                                    {{ __('admin.button.delete') }}
                                                </button>
                                            </form>
                                        </div>
                                    @else
                                        <span class="text-gray-300">-</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="create-admin-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="flex max-h-[calc(100vh-2rem)] w-full max-w-3xl flex-col overflow-hidden rounded-lg bg-white shadow-xl">
                <div class="shrink-0 px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h3 class="text-lg font-medium text-gray-900">{{ __('admin.admin_users.modal_create') }}</h3>
                    <button type="button" onclick="hideCreateAdminModal()" class="text-gray-400 hover:text-gray-600">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <form method="POST" action="{{ route('admin.admin-users.store') }}" class="flex min-h-0 flex-1 flex-col">
                    @csrf
                    <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 py-5">
                    <div>
                        <label for="username" class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.admin_users.field_username') }}</label>
                        <input type="text" name="username" id="username" required class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="{{ __('admin.admin_users.placeholder_username') }}" value="{{ old('username') }}">
                    </div>

                    <div>
                        <label for="role" class="block text-sm font-medium text-gray-700 mb-1">角色</label>
                        <select name="role" id="role" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="admin">普通管理员</option>
                            <option value="agent_admin">代理管理员</option>
                            <option value="direct_admin">直客管理员</option>
                        </select>
                    </div>

                    <div>
                        <label for="display_name" class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.admin_users.field_display_name') }}</label>
                        <input type="text" name="display_name" id="display_name" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="{{ __('admin.admin_users.placeholder_display_name') }}" value="{{ old('display_name') }}">
                    </div>

                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.admin_users.field_email') }}</label>
                        <input type="email" name="email" id="email" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="{{ __('admin.admin_users.placeholder_email') }}" value="{{ old('email') }}">
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="password" class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.admin_users.field_password') }}</label>
                            <input type="password" name="password" id="password" required class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                        </div>
                        <div>
                            <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.admin_users.field_confirm_password') }}</label>
                            <input type="password" name="confirm_password" id="confirm_password" required class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                        </div>
                    </div>

                    <div class="bg-gray-50 border border-gray-200 rounded-md p-3 text-sm text-gray-600">
                        {{ __('admin.admin_users.create_help') }}
                    </div>

                    <div id="customer-onboarding-panel" class="hidden rounded-lg border border-emerald-200 bg-emerald-50/60 p-4">
                        <label class="flex items-start gap-3">
                            <input type="checkbox" name="open_customer_subscription" value="1" id="open_customer_subscription" class="mt-1 h-4 w-4 rounded border-emerald-300 text-emerald-600 focus:ring-emerald-500">
                            <span>
                                <span class="block text-sm font-semibold text-emerald-950">同步创建分站点并开通规格</span>
                                <span class="mt-1 block text-xs leading-5 text-emerald-800">适用于代理/直客开户。独立“客户开通”页面仍用于后续续费、换规格和手动调整有效期。</span>
                            </span>
                        </label>

                        <div id="customer-onboarding-fields" class="mt-4 hidden space-y-4">
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div>
                                    <label for="site_name" class="mb-1 block text-sm font-medium text-gray-700">站点名称</label>
                                    <input type="text" name="site_name" id="site_name" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" value="{{ old('site_name') }}" placeholder="客户品牌或公司名称">
                                </div>
                                <div>
                                    <label for="site_domain" class="mb-1 block text-sm font-medium text-gray-700">站点域名</label>
                                    <input type="text" name="site_domain" id="site_domain" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" value="{{ old('site_domain') }}" placeholder="example.com">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div>
                                    <label for="plan_id" class="mb-1 block text-sm font-medium text-gray-700">开通规格</label>
                                    <select name="plan_id" id="plan_id" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        <option value="">请选择规格</option>
                                        @foreach ($plans as $plan)
                                            <option value="{{ $plan->id }}" data-audience="{{ $plan->audience }}" @selected((string) old('plan_id') === (string) $plan->id)>
                                                {{ $plan->name }} / {{ $plan->duration_days }} 天 / {{ ['agent' => '代理', 'direct' => '直客', 'both' => '通用'][$plan->audience] ?? $plan->audience }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">发放积分</label>
                                    <label class="flex h-10 items-center gap-2 rounded-md border border-emerald-200 bg-white px-3 text-sm text-gray-700">
                                        <input type="checkbox" name="grant_credits" value="1" checked class="h-4 w-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                                        <span>开通时发放规格积分</span>
                                    </label>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div>
                                    <label for="starts_at" class="mb-1 block text-sm font-medium text-gray-700">开始时间</label>
                                    <input type="datetime-local" name="starts_at" id="starts_at" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" value="{{ old('starts_at') }}">
                                </div>
                                <div>
                                    <label for="ends_at" class="mb-1 block text-sm font-medium text-gray-700">到期时间</label>
                                    <input type="datetime-local" name="ends_at" id="ends_at" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" value="{{ old('ends_at') }}">
                                    <p class="mt-1 text-xs text-emerald-800">不填则按规格服务天数自动计算；填写后覆盖规格默认天数。</p>
                                </div>
                            </div>

                            <div>
                                <label for="subscription_remark" class="mb-1 block text-sm font-medium text-gray-700">开户备注</label>
                                <input type="text" name="subscription_remark" id="subscription_remark" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" value="{{ old('subscription_remark') }}" placeholder="线下转账后同步开户">
                            </div>
                        </div>
                    </div>
                    </div>

                    <div class="shrink-0 flex justify-end gap-3 border-t border-gray-200 bg-white px-6 py-4">
                        <button type="button" onclick="hideCreateAdminModal()" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 bg-white hover:bg-gray-50">{{ __('admin.button.cancel') }}</button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md text-white bg-indigo-600 hover:bg-indigo-700">{{ __('admin.admin_users.create_admin_submit') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="edit-admin-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-lg w-full">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h3 class="text-lg font-medium text-gray-900">{{ __('admin.admin_users.modal_edit') }}</h3>
                    <button type="button" onclick="hideEditAdminModal()" class="text-gray-400 hover:text-gray-600">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <form id="edit-admin-form" method="POST" action="#" class="px-6 py-5 space-y-4">
                    @csrf
                    <div>
                        <label for="edit_username" class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.admin_users.field_username') }}</label>
                        <input type="text" name="username" id="edit_username" required class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                    </div>

                    <div>
                        <label for="edit_display_name" class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.admin_users.field_display_name') }}</label>
                        <input type="text" name="display_name" id="edit_display_name" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                    </div>

                    <div>
                        <label for="edit_email" class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.admin_users.field_email') }}</label>
                        <input type="email" name="email" id="edit_email" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                    </div>

                    <div>
                        <label for="edit_status" class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.admin_users.column_status') }}</label>
                        <input type="hidden" name="status" id="edit_status_hidden" disabled>
                        <select name="status" id="edit_status" required class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="active">{{ __('admin.admin_users.status_active') }}</option>
                            <option value="inactive">{{ __('admin.admin_users.status_inactive') }}</option>
                        </select>
                    </div>

                    <div>
                        <label for="edit_role" class="block text-sm font-medium text-gray-700 mb-1">角色</label>
                        <select name="role" id="edit_role" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="admin">普通管理员</option>
                            <option value="agent_admin">代理管理员</option>
                            <option value="direct_admin">直客管理员</option>
                            <option value="site_user">站点普通用户</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="edit_password" class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.admin_users.field_new_password') }}</label>
                            <input type="password" name="password" id="edit_password" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                        </div>
                        <div>
                            <label for="edit_confirm_password" class="block text-sm font-medium text-gray-700 mb-1">{{ __('admin.admin_users.field_confirm_new_password') }}</label>
                            <input type="password" name="confirm_password" id="edit_confirm_password" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                        </div>
                    </div>

                    <div class="bg-gray-50 border border-gray-200 rounded-md p-3 text-sm text-gray-600">
                        {{ __('admin.admin_users.edit_help') }}
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" onclick="hideEditAdminModal()" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 bg-white hover:bg-gray-50">{{ __('admin.button.cancel') }}</button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md text-white bg-indigo-600 hover:bg-indigo-700">{{ __('admin.admin_users.update_admin_submit') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const updateAdminRouteTemplate = @json(route('admin.admin-users.update', ['adminId' => '__ADMIN_ID__']));
        const currentAdminId = @json($currentAdminId);

        function syncCustomerOnboardingPanel() {
            const role = document.getElementById('role')?.value || 'admin';
            const panel = document.getElementById('customer-onboarding-panel');
            const checkbox = document.getElementById('open_customer_subscription');
            const fields = document.getElementById('customer-onboarding-fields');
            const planSelect = document.getElementById('plan_id');
            const canOpen = role === 'agent_admin' || role === 'direct_admin';
            const mode = role === 'agent_admin' ? 'agent' : 'direct';

            if (!panel || !checkbox || !fields || !planSelect) {
                return;
            }

            panel.classList.toggle('hidden', !canOpen);
            if (!canOpen) {
                checkbox.checked = false;
            }
            fields.classList.toggle('hidden', !canOpen || !checkbox.checked);

            Array.from(planSelect.options).forEach((option) => {
                const audience = option.dataset.audience || '';
                const visible = option.value === '' || audience === 'both' || audience === mode;
                option.hidden = !visible;
                option.disabled = !visible;
            });

            const selected = planSelect.options[planSelect.selectedIndex];
            if (selected && selected.disabled) {
                planSelect.value = '';
            }
        }

        function showCreateAdminModal() {
            document.getElementById('create-admin-modal').classList.remove('hidden');
            syncCustomerOnboardingPanel();
        }

        function hideCreateAdminModal() {
            document.getElementById('create-admin-modal').classList.add('hidden');
        }

        function showEditAdminModal(admin) {
            const form = document.getElementById('edit-admin-form');
            const statusSelect = document.getElementById('edit_status');
            const statusHidden = document.getElementById('edit_status_hidden');
            const isSelf = Number(admin.id) === Number(currentAdminId);
            form.action = updateAdminRouteTemplate.replace('__ADMIN_ID__', admin.id);
            document.getElementById('edit_username').value = admin.username || '';
            document.getElementById('edit_display_name').value = admin.display_name || '';
            document.getElementById('edit_email').value = admin.email || '';
            document.getElementById('edit_role').value = admin.role || 'admin';
            statusSelect.value = admin.status || 'active';
            statusSelect.disabled = isSelf;
            statusHidden.disabled = !isSelf;
            statusHidden.value = admin.status || 'active';
            document.getElementById('edit_password').value = '';
            document.getElementById('edit_confirm_password').value = '';
            document.getElementById('edit-admin-modal').classList.remove('hidden');
        }

        function hideEditAdminModal() {
            document.getElementById('edit-admin-modal').classList.add('hidden');
        }

        document.addEventListener('DOMContentLoaded', () => {
            document.getElementById('role')?.addEventListener('change', syncCustomerOnboardingPanel);
            document.getElementById('open_customer_subscription')?.addEventListener('change', syncCustomerOnboardingPanel);
            syncCustomerOnboardingPanel();
        });
    </script>
@endpush
