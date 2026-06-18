@extends('admin.layouts.app')

@section('content')
    @php
        $platformName = fn (string $platform): string => $platformLabels[$platform] ?? $platform;
        $statusClass = fn (string $status): string => match ($status) {
            'bound' => 'bg-emerald-100 text-emerald-800',
            'available' => 'bg-blue-100 text-blue-800',
            default => 'bg-slate-100 text-slate-700',
        };
        $statusName = fn (string $status): string => match ($status) {
            'bound' => '已绑定',
            'available' => '待绑定',
            default => $status,
        };
        $requestStatusClass = fn (string $status): string => match ($status) {
            'pending' => 'bg-amber-100 text-amber-800',
            'processing' => 'bg-blue-100 text-blue-800',
            'confirmed' => 'bg-emerald-100 text-emerald-800',
            'failed' => 'bg-red-100 text-red-800',
            'expired' => 'bg-slate-100 text-slate-600',
            default => 'bg-slate-100 text-slate-700',
        };
        $requestStatusName = fn (string $status): string => match ($status) {
            'pending' => '待处理',
            'processing' => '处理中',
            'confirmed' => '已绑定',
            'failed' => '失败',
            'expired' => '已过期',
            default => $status,
        };
        $platformStatusName = fn (string $status): string => match ($status) {
            'available' => '未绑定',
            'bound' => '已绑定',
            'pending' => '待处理',
            'processing' => '处理中',
            'confirmed' => '已绑定',
            'failed' => '失败',
            'expired' => '已过期',
            default => $status,
        };
        $imageUrl = function (?string $url): string {
            $url = trim((string) $url);
            if (str_starts_with($url, '//')) {
                return 'https:'.$url;
            }
            if (str_starts_with(strtolower($url), 'http://')) {
                return 'https://'.substr($url, 7);
            }
            return $url;
        };
    @endphp

    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">自媒体账号绑定</h1>
                <p class="mt-1 text-sm text-gray-600">当前站点：{{ $site->name }}。本页管理本地自媒体客户端同步回来的平台账号归属。</p>
            </div>
            @if ($canManage)
                <div class="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm text-slate-700 shadow-sm">
                    待绑定 {{ method_exists($availableAccounts, 'total') ? $availableAccounts->total() : $availableAccounts->count() }} 个
                </div>
            @endif
        </div>

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-lg font-semibold text-gray-900">选择要绑定的平台</h2>
                <p class="mt-1 text-sm text-slate-500">点击平台提交绑定申请。运营发送对应平台二维码后，扫码登录成功会自动绑定到你的账号。</p>
                @error('platform')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div class="grid grid-cols-1 gap-3 p-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($platforms as $platformKey => $platform)
                    @php
                        $state = $platformStates[$platformKey] ?? ['status' => 'available', 'can_request' => true, 'account' => null, 'request' => null];
                        $stateStatus = (string) ($state['status'] ?? 'available');
                        $canRequestPlatform = (bool) ($state['can_request'] ?? false);
                        $account = $state['account'] ?? null;
                        $latestRequest = $state['request'] ?? null;
                        $logoPath = 'assets/self-media-platforms/'.(string) ($platform['logo'] ?? ($platformKey.'.png'));
                        $hasPlatformLogo = file_exists(public_path($logoPath));
                        $statusBadgeClass = match ($stateStatus) {
                            'bound', 'confirmed' => 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100',
                            'pending' => 'bg-amber-50 text-amber-700 ring-1 ring-amber-100',
                            'processing' => 'bg-blue-50 text-blue-700 ring-1 ring-blue-100',
                            'failed' => 'bg-red-50 text-red-700 ring-1 ring-red-100',
                            default => 'bg-slate-100 text-slate-600',
                        };
                    @endphp
                    <div class="flex min-h-44 flex-col justify-between rounded-lg border border-slate-200 bg-white p-4 shadow-sm transition hover:border-slate-300 hover:shadow-md">
                        <div>
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <div class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50" data-platform-logo="{{ $logoPath }}">
                                        @if ($hasPlatformLogo)
                                            <img src="{{ asset($logoPath) }}" alt="{{ $platform['label'] }}" class="h-full w-full object-contain p-1.5">
                                        @else
                                            <span class="text-base font-semibold text-slate-500">{{ mb_substr((string) $platform['label'], 0, 1) }}</span>
                                        @endif
                                    </div>
                                    <div class="min-w-0">
                                        <h3 class="truncate text-base font-semibold text-slate-950">{{ $platform['label'] }}</h3>
                                        <p class="mt-1 truncate text-xs text-slate-500">{{ $platform['desc'] }}</p>
                                    </div>
                                </div>
                                <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium {{ $statusBadgeClass }}">
                                    {{ $platformStatusName($stateStatus) }}
                                </span>
                            </div>
                            <div class="mt-4 min-h-14 text-xs leading-5 text-slate-500">
                                @if ($account instanceof \App\Models\CrebeeAccount)
                                    <div class="flex items-center gap-3 rounded-md border border-emerald-100 bg-emerald-50/60 px-3 py-2">
                                        @if ($imageUrl($account->avatar) !== '')
                                            <img src="{{ $imageUrl($account->avatar) }}" alt="" class="h-9 w-9 shrink-0 rounded-full border border-white object-cover shadow-sm" referrerpolicy="no-referrer">
                                        @else
                                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white text-sm font-semibold text-emerald-700 shadow-sm">
                                                {{ mb_substr($account->account_name ?: $account->crebee_account_id, 0, 1) }}
                                            </div>
                                        @endif
                                        <div class="min-w-0">
                                            <div class="truncate font-medium text-slate-900">{{ $account->account_name ?: $account->crebee_account_id }}</div>
                                            <div class="mt-0.5 text-slate-500">{{ $account->bound_at?->format('Y-m-d H:i') ?? '-' }}</div>
                                        </div>
                                    </div>
                                @elseif ($latestRequest instanceof \App\Models\CrebeeBindRequest)
                                    <div class="rounded-md bg-slate-50 px-3 py-2">
                                        <div>{{ $latestRequest->requested_at?->format('Y-m-d H:i') ?? '-' }}</div>
                                    @if ((string) $latestRequest->failure_reason !== '')
                                        <div class="truncate text-red-600">{{ $latestRequest->failure_reason }}</div>
                                    @else
                                        <div>等待运营处理</div>
                                    @endif
                                    </div>
                                @else
                                    <div class="rounded-md bg-slate-50 px-3 py-2 text-slate-600">可提交绑定申请</div>
                                @endif
                            </div>
                        </div>
                        <form method="POST" action="{{ route('admin.crebee-accounts.requests.store') }}" class="mt-4">
                            @csrf
                            <input type="hidden" name="platform" value="{{ $platformKey }}">
                            <button type="submit" @disabled(! $canRequestPlatform) class="inline-flex h-9 w-full items-center justify-center gap-2 rounded-md px-3 text-sm font-medium transition {{ $canRequestPlatform ? 'bg-orange-600 text-white hover:bg-orange-700' : 'cursor-not-allowed bg-slate-100 text-slate-500' }}">
                                <i data-lucide="{{ $canRequestPlatform ? 'plus-circle' : 'check-circle-2' }}" class="h-4 w-4"></i>
                                {{ $canRequestPlatform ? '申请绑定' : $platformStatusName($stateStatus) }}
                            </button>
                        </form>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-lg font-semibold text-gray-900">{{ $canManage ? '绑定申请' : '我的绑定申请' }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ $canManage ? '运营处理用户提交的平台扫码申请。单个平台只有一个有效申请时，新同步账号会自动绑定。' : '这里展示你提交过的平台绑定进度。' }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">平台</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">申请用户</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">状态</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">时间</th>
                            @if ($canManage)
                                <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-500">操作</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @forelse ($bindRequests as $bindRequest)
                            <tr>
                                <td class="px-5 py-4 align-top">
                                    <div class="font-medium text-slate-900">{{ $platformName((string) $bindRequest->platform) }}</div>
                                    <div class="mt-1 text-xs text-slate-400">{{ $bindRequest->platform }}</div>
                                </td>
                                <td class="px-5 py-4 align-top text-sm text-slate-600">
                                    <div class="font-medium text-slate-800">{{ $bindRequest->owner?->display_name ?: $bindRequest->owner?->username ?: '-' }}</div>
                                    <div class="mt-1 text-xs text-slate-400">{{ $bindRequest->owner?->username ?? '' }}</div>
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $requestStatusClass((string) $bindRequest->status) }}">{{ $requestStatusName((string) $bindRequest->status) }}</span>
                                    @if ((string) $bindRequest->failure_reason !== '')
                                        <div class="mt-1 max-w-xs truncate text-xs text-red-600">{{ $bindRequest->failure_reason }}</div>
                                    @endif
                                </td>
                                <td class="px-5 py-4 align-top text-sm text-slate-600">
                                    <div>{{ $bindRequest->requested_at?->format('Y-m-d H:i:s') ?? '-' }}</div>
                                    @if ($bindRequest->confirmed_at)
                                        <div class="mt-1 text-xs text-slate-400">完成：{{ $bindRequest->confirmed_at->format('Y-m-d H:i:s') }}</div>
                                    @endif
                                </td>
                                @if ($canManage)
                                    <td class="px-5 py-4 align-top text-right">
                                        @if (in_array((string) $bindRequest->status, ['pending', 'processing'], true))
                                            <div class="flex justify-end gap-2">
                                                <form method="POST" action="{{ route('admin.crebee-accounts.requests.processing', $bindRequest) }}">
                                                    @csrf
                                                    <button type="submit" class="inline-flex h-9 items-center justify-center rounded-md bg-blue-600 px-3 text-sm font-medium text-white hover:bg-blue-700">
                                                        二维码已发送
                                                    </button>
                                                </form>
                                                <form method="POST" action="{{ route('admin.crebee-accounts.requests.fail', $bindRequest) }}" onsubmit="return confirm(@js('确定将该绑定申请标记为失败吗？'));">
                                                    @csrf
                                                    <input type="hidden" name="failure_reason" value="运营标记失败">
                                                    <button type="submit" class="inline-flex h-9 items-center justify-center rounded-md border border-red-200 px-3 text-sm font-medium text-red-600 hover:bg-red-50">
                                                        标记失败
                                                    </button>
                                                </form>
                                            </div>
                                        @else
                                            <span class="text-sm text-slate-400">-</span>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $canManage ? 5 : 4 }}" class="px-5 py-10 text-center text-sm text-slate-500">暂无绑定申请。</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($bindRequests->hasPages())
                <div class="border-t border-slate-200 px-5 py-4">
                    {{ $bindRequests->links() }}
                </div>
            @endif
        </section>

        @if ($canManage)
            <section class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                @forelse ($agents as $agent)
                    <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="truncate text-sm font-semibold text-gray-900">{{ str_replace('CreBee', '自媒体', $agent->name) }}</h2>
                                <p class="mt-1 truncate text-xs text-slate-500">{{ $agent->agent_uid }}</p>
                            </div>
                            <span class="shrink-0 rounded-full {{ $agent->last_seen_at && $agent->last_seen_at->gt(now()->subMinutes(2)) ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }} px-2 py-0.5 text-xs">
                                {{ $agent->last_seen_at && $agent->last_seen_at->gt(now()->subMinutes(2)) ? '在线' : '离线' }}
                            </span>
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-3 text-xs text-slate-500">
                            <div>
                                <div class="text-slate-400">客户端</div>
                                <div class="mt-1 font-medium text-slate-700">{{ $agent->crebee_status ?: 'unknown' }}</div>
                            </div>
                            <div>
                                <div class="text-slate-400">账号数</div>
                                <div class="mt-1 font-medium text-slate-700">{{ $agent->accounts_count }}</div>
                            </div>
                            <div class="col-span-2">
                                <div class="text-slate-400">最后心跳</div>
                                <div class="mt-1 font-medium text-slate-700">{{ $agent->last_seen_at?->format('Y-m-d H:i:s') ?? '-' }}</div>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="rounded-lg border border-dashed border-slate-200 bg-white p-6 text-sm text-slate-500 lg:col-span-3">
                        暂无自媒体同步服务。请先启动本机同步服务，成功心跳后这里会显示客户端状态。
                    </div>
                @endforelse
            </section>

            <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 class="text-lg font-semibold text-gray-900">待绑定账号</h2>
                    <p class="mt-1 text-sm text-slate-500">用户扫码登录成功后，agent 会把本地账号同步到这里；选择系统用户后即可完成绑定。</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">平台账号</th>
                                <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">Agent</th>
                                <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">同步时间</th>
                                <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-500">绑定给用户</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 bg-white">
                            @forelse ($availableAccounts as $account)
                                <tr>
                                    <td class="px-5 py-4 align-top">
                                        <div class="flex items-center gap-3">
                                            @if ($imageUrl($account->avatar) !== '')
                                                <img src="{{ $imageUrl($account->avatar) }}" alt="" class="h-10 w-10 rounded-full border border-slate-200 object-cover" referrerpolicy="no-referrer">
                                            @else
                                                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-sm font-semibold text-slate-500">{{ mb_substr($platformName($account->platform), 0, 1) }}</div>
                                            @endif
                                            <div class="min-w-0">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span class="font-semibold text-gray-900">{{ $account->account_name ?: $account->crebee_account_id }}</span>
                                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ $platformName($account->platform) }}</span>
                                                </div>
                                                <div class="mt-1 text-xs text-slate-400">{{ $account->crebee_account_id }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 align-top text-sm text-slate-600">
                                        <div>{{ $account->agent?->name ?? '-' }}</div>
                                        <div class="mt-1 text-xs text-slate-400">{{ $account->agent?->agent_uid ?? '' }}</div>
                                    </td>
                                    <td class="px-5 py-4 align-top text-sm text-slate-600">{{ $account->last_synced_at?->format('Y-m-d H:i:s') ?? '-' }}</td>
                                    <td class="px-5 py-4 align-top">
                                        <form method="POST" action="{{ route('admin.crebee-accounts.bind', $account) }}" class="ml-auto flex max-w-md items-center justify-end gap-2">
                                            @csrf
                                            <select name="owner_admin_id" required class="h-10 min-w-0 flex-1 rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                                                <option value="">选择用户</option>
                                                @foreach ($members as $member)
                                                    <option value="{{ $member->id }}">{{ $member->display_name ?: $member->username }}（{{ $member->username }}）</option>
                                                @endforeach
                                            </select>
                                            <button type="submit" class="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white transition hover:bg-indigo-700">
                                                <i data-lucide="link" class="h-4 w-4"></i>
                                                绑定
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-5 py-10 text-center text-sm text-slate-500">暂无待绑定账号。请确认自媒体客户端已登录平台账号，并且本机同步服务正在运行。</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if (method_exists($availableAccounts, 'hasPages') && $availableAccounts->hasPages())
                    <div class="border-t border-slate-200 px-5 py-4">
                        {{ $availableAccounts->links() }}
                    </div>
                @endif
            </section>
        @endif

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-lg font-semibold text-gray-900">已绑定账号</h2>
                <p class="mt-1 text-sm text-slate-500">{{ $canManage ? '当前站点下所有已绑定自媒体账号。' : '这里展示已绑定到你当前账号的平台账号。' }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">平台账号</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">绑定用户</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">状态</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">绑定时间</th>
                            @if ($canManage)
                                <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-500">操作</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @forelse ($boundAccounts as $account)
                            <tr>
                                <td class="px-5 py-4 align-top">
                                    <div class="flex items-center gap-3">
                                        @if ($imageUrl($account->avatar) !== '')
                                            <img src="{{ $imageUrl($account->avatar) }}" alt="" class="h-10 w-10 rounded-full border border-slate-200 object-cover" referrerpolicy="no-referrer">
                                        @else
                                            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-sm font-semibold text-slate-500">{{ mb_substr($platformName($account->platform), 0, 1) }}</div>
                                        @endif
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="font-semibold text-gray-900">{{ $account->account_name ?: $account->crebee_account_id }}</span>
                                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ $platformName($account->platform) }}</span>
                                            </div>
                                            <div class="mt-1 text-xs text-slate-400">{{ $account->crebee_account_id }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4 align-top text-sm text-slate-600">
                                    <div class="font-medium text-slate-800">{{ $account->owner?->display_name ?: $account->owner?->username ?: '-' }}</div>
                                    <div class="mt-1 text-xs text-slate-400">{{ $account->owner?->username ?? '' }}</div>
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $statusClass((string) $account->status) }}">{{ $statusName((string) $account->status) }}</span>
                                </td>
                                <td class="px-5 py-4 align-top text-sm text-slate-600">{{ $account->bound_at?->format('Y-m-d H:i:s') ?? '-' }}</td>
                                @if ($canManage)
                                    <td class="px-5 py-4 align-top text-right">
                                        <form method="POST" action="{{ route('admin.crebee-accounts.unbind', $account) }}" class="inline" onsubmit="return confirm(@js('确定解绑该自媒体账号吗？解绑后会回到待绑定池。'));">
                                            @csrf
                                            <button type="submit" class="text-sm font-medium text-amber-600 hover:text-amber-800">解绑</button>
                                        </form>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $canManage ? 5 : 4 }}" class="px-5 py-10 text-center text-sm text-slate-500">暂无已绑定自媒体账号。</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($boundAccounts->hasPages())
                <div class="border-t border-slate-200 px-5 py-4">
                    {{ $boundAccounts->links() }}
                </div>
            @endif
        </section>
    </div>
@endsection
