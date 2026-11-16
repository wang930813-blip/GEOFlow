@extends('website-admin.layout')

@section('content')
<div class='wa-page-heading'><div><span class='wa-eyebrow'>系统管理</span><h1>{{ $showUsers ? '管理员' : '权限角色' }}</h1>@if ($showUsers)<p>主账号不可在此修改或移除。</p>@endif</div></div>
@if (! $showUsers)
<section class='wa-panel wa-team-section'>
    <div class='wa-panel-heading'><div><h2>角色列表</h2></div></div>
    @if ($context->allows('roles.create'))<details class='wa-team-create'><summary class='wa-button wa-button-secondary'>新建角色</summary><form method='POST' action='{{ route('website-admin.team.roles.create') }}'>@csrf
        <label class='wa-field'><span>角色名称</span><input name='name' maxlength='80' required></label>
        @include('website-admin.partials.permission-tree', ['selectedPermissions' => []])
        <button class='wa-button wa-button-primary' type='submit'>创建角色</button>
    </form></details>@endif
    @if ($roles->isEmpty())<div class='wa-empty'><strong>还没有角色</strong><p>先创建一个角色，再添加网站后台管理员。</p></div>@else
        <div class='wa-role-list'>@foreach ($roles as $role)
            @php($canManageRole = collect(\App\Support\WebsitePermissionCatalog::expand($role->permissions ?? []))->every(fn (string $key): bool => $context->allows($key)))
            <details class='wa-role-item'><summary><strong>{{ $role->name }}</strong><span>{{ $role->users_count }} 位管理员</span><b>查看权限</b></summary>
                @if ($canManageRole && $context->allows('roles.edit'))
                <div class='wa-role-body'><form method='POST' action='{{ route('website-admin.team.roles.update', $role->id) }}'>@csrf @method('PUT')
                    <label class='wa-field'><span>角色名称</span><input name='name' value='{{ $role->name }}' maxlength='80' required></label>
                    @include('website-admin.partials.permission-tree', ['selectedPermissions' => \App\Support\WebsitePermissionCatalog::expand($role->permissions ?? [])])
                    <button class='wa-button wa-button-primary' type='submit'>保存角色</button>
                </form></div>@endif
                @if (! ($canManageRole && $context->allows('roles.edit')))<div class='wa-role-body'>@include('website-admin.partials.permission-tree', ['selectedPermissions' => \App\Support\WebsitePermissionCatalog::expand($role->permissions ?? []), 'readOnly' => true])</div>@endif
                @if ($canManageRole && $context->allows('roles.delete'))<div class='wa-role-body'><form method='POST' action='{{ route('website-admin.team.roles.delete', $role->id) }}' data-wa-confirm='确定删除此角色吗？'>@csrf @method('DELETE')<button class='wa-button wa-button-quiet wa-danger-text' type='submit'>删除角色</button></form></div>@endif
            </details>
        @endforeach</div>
    @endif
</section>
@else
<section class='wa-panel wa-team-section'>
    <div class='wa-panel-heading'><div><h2>账号列表</h2></div></div>
    @php($assignableRoles = $roles->filter(fn ($role) => collect(\App\Support\WebsitePermissionCatalog::expand($role->permissions ?? []))->every(fn (string $key): bool => $context->allows($key))))
    @if ($assignableRoles->isNotEmpty() && $context->allows('users.create'))<form class='wa-add-user' method='POST' action='{{ route('website-admin.team.users.create') }}'>@csrf
        <label class='wa-field'><span>账号</span><input name='username' minlength='3' maxlength='50' required></label>
        <label class='wa-field'><span>显示名称</span><input name='display_name' maxlength='100'></label>
        <label class='wa-field'><span>初始密码</span><input type='password' name='password' minlength='12' autocomplete='new-password' required><small>至少 12 位</small></label>
        <label class='wa-field'><span>角色</span><select name='website_role_id' required><option value=''>选择角色</option>@foreach ($assignableRoles as $role)<option value='{{ $role->id }}'>{{ $role->name }}</option>@endforeach</select></label>
        <button class='wa-button wa-button-primary' type='submit'>添加管理员</button>
    </form>@endif
    @if ($users->isEmpty())<div class='wa-empty'><strong>尚未添加管理员</strong>@if ($roles->isEmpty())<p>请先创建角色，再添加管理员。</p><a class='wa-button wa-button-secondary' href='{{ route('website-admin.team.roles') }}'>前往权限角色</a>@endif</div>@else
        <div class='wa-table-wrap'><table class='wa-table'><thead><tr><th>账号</th><th>显示名称</th><th>角色</th><th>状态</th><th>最近登录</th><th>操作</th></tr></thead><tbody>
            @foreach ($users as $user)@php($canManageUser = $context->user?->id !== $user->id && collect(\App\Support\WebsitePermissionCatalog::expand($user->role?->permissions ?? []))->every(fn (string $key): bool => $context->allows($key)))<tr>
                <td><strong>{{ $user->username }}</strong></td><td>{{ $user->display_name ?: '—' }}</td>
                <td>
                    @if ($canManageUser && $context->allows('users.edit'))
                        <form id='wa-user-update-{{ $user->id }}' method='POST' action='{{ route('website-admin.team.users.update', $user->id) }}'>@csrf @method('PUT')<input type='hidden' name='display_name' value='{{ $user->display_name }}'></form>
                        <select class='wa-inline-edit' form='wa-user-update-{{ $user->id }}' name='website_role_id'>@foreach ($assignableRoles as $role)<option value='{{ $role->id }}' @selected((int) $user->website_role_id === (int) $role->id)>{{ $role->name }}</option>@endforeach</select>
                    @else {{ $user->role?->name ?? '未设置角色' }} @endif
                </td>
                <td>@if ($canManageUser && $context->allows('users.edit'))<select form='wa-user-update-{{ $user->id }}' name='status'><option value='active' @selected($user->status === 'active')>启用</option><option value='disabled' @selected($user->status === 'disabled')>停用</option></select>@else {{ $user->status === 'active' ? '启用' : '停用' }} @endif</td>
                <td>{{ $user->last_login?->format('Y-m-d H:i') ?: '从未登录' }}</td><td><div class='wa-row-actions'>@if ($canManageUser && $context->allows('users.edit'))<button class='wa-text-button' type='submit' form='wa-user-update-{{ $user->id }}'>保存</button>@endif @if ($canManageUser && $context->allows('users.delete'))<form method='POST' action='{{ route('website-admin.team.users.delete', $user->id) }}' data-wa-confirm='确定移除该管理员吗？'>@csrf @method('DELETE')<button class='wa-text-button wa-danger-text' type='submit'>移除</button></form>@endif</div></td>
            </tr>@endforeach
        </tbody></table></div>
    @endif
</section>
@endif
@endsection

@push('scripts')
<script>
document.querySelectorAll('[data-wa-permission-tree]').forEach(tree => {
    const nodes = Array.from(tree.querySelectorAll('[data-wa-permission-node]'));
    const refresh = node => {
        const parent = node.querySelector(':scope > .wa-permission-parent > [data-wa-permission-parent]');
        const leaves = Array.from(node.querySelectorAll('[data-wa-permission-leaf]'));
        const editableLeaves = leaves.filter(leaf => !leaf.disabled);
        if (!parent) return;
        const checked = leaves.filter(leaf => leaf.checked).length;
        parent.checked = leaves.length > 0 && checked === leaves.length;
        parent.indeterminate = checked > 0 && checked < leaves.length;
        parent.disabled = editableLeaves.length === 0;
    };
    tree.addEventListener('change', event => {
        if (event.target.matches('[data-wa-permission-parent]')) {
            event.target.closest('[data-wa-permission-node]').querySelectorAll('[data-wa-permission-leaf]:not(:disabled)').forEach(leaf => { leaf.checked = event.target.checked; });
        }
        nodes.slice().reverse().forEach(refresh);
    });
    nodes.slice().reverse().forEach(refresh);
});
</script>
@endpush
