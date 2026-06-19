@extends('admin.layouts.app')

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">代理用户管理</h1>
                <p class="mt-1 text-sm text-gray-600">代理可以为自己的分站点创建普通用户，直客模式不开放该能力。</p>
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
                    <p class="text-sm text-gray-500">创建后用户只能使用当前站点业务功能，不能管理规格和用户。</p>
                </div>
            </div>

            <form method="POST" action="{{ route('admin.agent-users.store') }}" autocomplete="off" class="grid grid-cols-1 gap-4 xl:grid-cols-12">
                @csrf
                <div class="xl:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-gray-700">用户名</label>
                    <input name="username" required autocomplete="new-password" value="" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                </div>
                <div class="xl:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-gray-700">显示名称</label>
                    <input name="display_name" autocomplete="off" value="" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                </div>
                <div class="xl:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-gray-700">邮箱</label>
                    <input name="email" type="email" autocomplete="new-password" value="" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                </div>
                <div class="xl:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-gray-700">密码</label>
                    <input name="password" type="password" required autocomplete="new-password" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                </div>
                <div class="xl:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-gray-700">确认密码</label>
                    <input name="confirm_password" type="password" required autocomplete="new-password" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                </div>
                <div class="flex items-end sm:justify-end xl:col-span-2">
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
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">邮箱</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">状态</th>
                            <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-500">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @forelse ($members as $member)
                            <tr>
                                <td class="px-5 py-4 align-top">
                                    <div class="text-sm font-semibold text-gray-900">{{ $member->display_name ?: $member->username }}</div>
                                    <div class="mt-1 text-xs text-gray-400">{{ $member->username }}</div>
                                </td>
                                <td class="px-5 py-4 align-top text-sm text-gray-600">{{ $member->email ?: '-' }}</td>
                                <td class="px-5 py-4 align-top">
                                    @if ($member->status === 'active')
                                        <span class="inline-flex rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">启用</span>
                                    @else
                                        <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">停用</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 align-top text-right">
                                    <form method="POST" action="{{ route('admin.agent-users.toggle-status', ['adminId' => $member->id]) }}" class="inline">
                                        @csrf
                                        <input type="hidden" name="next_status" value="{{ $member->status === 'active' ? 'inactive' : 'active' }}">
                                        <button type="submit" class="text-sm font-medium {{ $member->status === 'active' ? 'text-amber-600 hover:text-amber-800' : 'text-green-600 hover:text-green-800' }}">
                                            {{ $member->status === 'active' ? '停用' : '启用' }}
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-5 py-10 text-center text-sm text-gray-500">暂无普通用户</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
