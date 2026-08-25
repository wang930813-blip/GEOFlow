@extends('admin.layouts.app')

@section('content')
    @php
        $platformName = fn (string $platform): string => $platformLabels[$platform] ?? $platform;
        $statusClass = fn (string $status): string => match ($status) {
            'bound', 'authorized' => 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100',
            'pending' => 'bg-amber-50 text-amber-700 ring-1 ring-amber-100',
            'failed' => 'bg-red-50 text-red-700 ring-1 ring-red-100',
            'unavailable' => 'bg-red-50 text-red-700 ring-1 ring-red-100',
            'expired' => 'bg-slate-100 text-slate-600',
            default => 'bg-slate-100 text-slate-600',
        };
        $statusName = fn (string $status): string => match ($status) {
            'bound', 'authorized' => '已授权',
            'available' => '未授权',
            'pending' => '授权中',
            'failed' => '授权失败',
            'unavailable' => '账号异常',
            'expired' => '已过期',
            'unbound' => '已解绑',
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
        $isDataImage = fn (?string $url): bool => str_starts_with(strtolower(trim((string) $url)), 'data:image/');
    @endphp

    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">自媒体账号授权</h1>
                <p class="mt-1 text-sm text-gray-600">发布前请先完成对应平台授权。</p>
                @if(! $apiConfigured)
                    <p class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-700">接口密钥未配置，暂时只能查看默认平台列表。</p>
                @endif
                @error('platform')
                    <p class="mt-2 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if($canAuthorize)
                    <form method="POST" action="{{ route('admin.crebee-accounts.aitoearn.accounts.sync') }}">
                        @csrf
                        <button type="submit" @disabled(! $apiConfigured) class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400">
                            <i data-lucide="refresh-cw" class="h-4 w-4"></i>
                            同步账号
                        </button>
                    </form>
                @endif
                <a href="{{ route('admin.crebee-publish-records.index') }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50">
                    <i data-lucide="radio" class="h-4 w-4"></i>
                    发布记录
                </a>
            </div>
        </div>

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-lg font-semibold text-gray-900">选择授权平台</h2>
                <p class="mt-1 text-sm text-slate-500">授权完成后回到本页同步状态。</p>
            </div>
            <div class="grid grid-cols-1 gap-3 p-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach($platforms as $platformKey => $platform)
                    @php
                        $state = $platformStates[$platformKey] ?? ['status' => 'available', 'account' => null, 'session' => null];
                        $stateStatus = (string) ($state['status'] ?? 'available');
                        $account = $state['account'] ?? null;
                        $session = $state['session'] ?? null;
                        $logoPath = \App\Support\SelfMedia\SelfMediaPlatformCatalog::logoPath((string) $platformKey);
                        $remoteLogo = trim((string) ($platform['logo_url'] ?? ''));
                        $hasPlatformLogo = file_exists(public_path($logoPath));
                    @endphp
                    <div class="flex min-h-44 flex-col justify-between rounded-lg border border-slate-200 bg-white p-4 shadow-sm transition hover:border-slate-300">
                        <div>
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <div class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50">
                                        @if($hasPlatformLogo)
                                            <img src="{{ asset($logoPath) }}" alt="{{ $platform['label'] ?? $platformKey }}" class="h-full w-full object-contain p-1.5">
                                        @elseif($remoteLogo !== '')
                                            <img src="{{ $imageUrl($remoteLogo) }}" alt="{{ $platform['label'] ?? $platformKey }}" class="h-full w-full object-contain p-1.5" referrerpolicy="no-referrer">
                                        @else
                                            <span class="text-base font-semibold text-slate-500">{{ mb_substr((string) ($platform['label'] ?? $platformKey), 0, 1, 'UTF-8') }}</span>
                                        @endif
                                    </div>
                                    <div class="min-w-0">
                                        <h3 class="truncate text-base font-semibold text-slate-950">{{ $platform['label'] ?? $platformKey }}</h3>
                                        <p class="mt-1 truncate text-xs text-slate-500">{{ $platform['desc'] ?? '内容发布' }}</p>
                                    </div>
                                </div>
                                <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClass($stateStatus) }}">
                                    {{ $statusName($stateStatus) }}
                                </span>
                            </div>

                            <div class="mt-4 min-h-14 text-xs leading-5 text-slate-500">
                                @if($account instanceof \App\Models\SelfMediaAccount)
                                    <div class="flex items-center gap-3 rounded-md border border-emerald-100 bg-emerald-50/60 px-3 py-2">
                                        @if($imageUrl($account->avatar) !== '')
                                            <img src="{{ $imageUrl($account->avatar) }}" alt="" class="h-9 w-9 shrink-0 rounded-full border border-white object-cover shadow-sm" referrerpolicy="no-referrer">
                                        @else
                                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white text-sm font-semibold text-emerald-700 shadow-sm">
                                                {{ mb_substr($account->account_name ?: $account->external_account_id, 0, 1, 'UTF-8') }}
                                            </div>
                                        @endif
                                        <div class="min-w-0">
                                            <div class="truncate font-medium text-slate-900">{{ $account->account_name ?: $account->external_account_id }}</div>
                                            <div class="mt-0.5 text-slate-500">{{ $account->bound_at?->format('Y-m-d H:i') ?? '-' }}</div>
                                        </div>
                                    </div>
                                @elseif($session instanceof \App\Models\SelfMediaAuthSession)
                                    @php($authUrl = (string) $session->authorization_url)
                                    @if($isDataImage($authUrl))
                                        <div class="rounded-md border border-indigo-100 bg-indigo-50/50 px-3 py-3 text-center">
                                            <img src="{{ $authUrl }}" alt="authorization qr code" class="mx-auto h-24 w-24 rounded-md bg-white p-1 shadow-sm">
                                            <div class="mt-2 font-medium text-slate-700">扫码授权</div>
                                            <div class="mt-1 text-slate-500">{{ $session->expires_at?->format('Y-m-d H:i') ?? $session->created_at?->format('Y-m-d H:i') ?? '-' }}</div>
                                        </div>
                                    @else
                                    <div class="rounded-md bg-slate-50 px-3 py-2">
                                        <div>{{ $session->created_at?->format('Y-m-d H:i') ?? '-' }}</div>
                                        <div>{{ $statusName((string) $session->status) }}</div>
                                    </div>
                                    @endif
                                @else
                                    <div class="rounded-md bg-slate-50 px-3 py-2 text-slate-600">可发起授权</div>
                                @endif
                            </div>
                        </div>

                        <div class="mt-4 flex gap-2">
                            @if($account instanceof \App\Models\SelfMediaAccount)
                                <form method="POST" action="{{ route('admin.crebee-accounts.aitoearn.accounts.unbind', $account) }}" class="w-full" onsubmit="return confirm(@js('确定解绑该自媒体账号吗？'));">
                                    @csrf
                                    <button type="submit" class="inline-flex h-9 w-full items-center justify-center gap-2 rounded-md border border-amber-200 px-3 text-sm font-medium text-amber-700 hover:bg-amber-50">
                                        <i data-lucide="unlink" class="h-4 w-4"></i>
                                        解绑
                                    </button>
                                </form>
                            @elseif($session instanceof \App\Models\SelfMediaAuthSession && (string) $session->authorization_url !== '' && in_array((string) $session->status, ['pending', 'expired', 'failed'], true))
                                @php($authUrl = (string) $session->authorization_url)
                                @if(! $isDataImage($authUrl))
                                <a href="{{ $session->authorization_url }}" target="_blank" rel="noopener noreferrer" class="inline-flex h-9 flex-1 items-center justify-center gap-2 rounded-md bg-indigo-600 px-3 text-sm font-medium text-white hover:bg-indigo-700">
                                    <i data-lucide="external-link" class="h-4 w-4"></i>
                                    继续授权
                                </a>
                                @endif
                                <form method="POST" action="{{ route('admin.crebee-accounts.aitoearn.auth-sessions.sync', $session) }}" class="{{ $isDataImage($authUrl) ? 'w-full' : 'flex-1' }}">
                                    @csrf
                                    <button type="submit" class="inline-flex h-9 w-full items-center justify-center gap-2 rounded-md border border-slate-200 px-3 text-sm font-medium text-slate-700 hover:bg-slate-50">
                                        <i data-lucide="refresh-cw" class="h-4 w-4"></i>
                                        同步状态
                                    </button>
                                </form>
                            @else
                                @if($canAuthorize)
                                    <form method="POST" action="{{ route('admin.crebee-accounts.aitoearn.authorizations.start') }}" class="w-full">
                                        @csrf
                                        <input type="hidden" name="platform" value="{{ $platformKey }}">
                                        <button type="submit" @disabled(! $apiConfigured || (string) ($platform['status'] ?? 'available') !== 'available') class="inline-flex h-9 w-full items-center justify-center gap-2 rounded-md bg-indigo-600 px-3 text-sm font-medium text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-200 disabled:text-slate-500">
                                            <i data-lucide="shield-check" class="h-4 w-4"></i>
                                            去授权
                                        </button>
                                    </form>
                                @else
                                    <div class="inline-flex h-9 w-full items-center justify-center rounded-md bg-slate-100 px-3 text-sm font-medium text-slate-600">
                                        {{ $canManage ? '超管查看' : '无授权操作权限' }}
                                    </div>
                                @endif
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-lg font-semibold text-gray-900">已授权账号</h2>
                <p class="mt-1 text-sm text-slate-500">发布文章或视频时，只能选择这里已授权的账号。</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">平台账号</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">绑定用户</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">状态</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">同步时间</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @forelse($boundAccounts as $account)
                            <tr>
                                <td class="px-5 py-4 align-top">
                                    <div class="font-medium text-slate-900">{{ $account->account_name ?: $account->external_account_id }}</div>
                                    <div class="mt-1 text-xs text-slate-400">{{ $platformName((string) $account->platform) }} · {{ $account->external_account_id }}</div>
                                </td>
                                <td class="px-5 py-4 align-top text-sm text-slate-600">
                                    <div class="font-medium text-slate-800">{{ $account->owner?->display_name ?: $account->owner?->username ?: '-' }}</div>
                                    <div class="mt-1 text-xs text-slate-400">{{ $account->site?->name ?? '' }}</div>
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $statusClass((string) $account->auth_status) }}">{{ $statusName((string) $account->auth_status) }}</span>
                                </td>
                                <td class="px-5 py-4 align-top text-sm text-slate-600">{{ $account->last_synced_at?->format('Y-m-d H:i:s') ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-5 py-10 text-center text-sm text-slate-500">暂无已授权自媒体账号。</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($boundAccounts->hasPages())
                <div class="border-t border-slate-200 px-5 py-4">
                    {{ $boundAccounts->links() }}
                </div>
            @endif
        </section>

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-lg font-semibold text-gray-900">授权记录</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">平台</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">发起用户</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">状态</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">时间</th>
                            <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-500">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @forelse($authSessions as $session)
                            <tr>
                                <td class="px-5 py-4 align-top">
                                    <div class="font-medium text-slate-900">{{ $platformName((string) $session->platform) }}</div>
                                    <div class="mt-1 text-xs text-slate-400">{{ $session->session_id }}</div>
                                </td>
                                <td class="px-5 py-4 align-top text-sm text-slate-600">
                                    <div class="font-medium text-slate-800">{{ $session->owner?->display_name ?: $session->owner?->username ?: '-' }}</div>
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $statusClass((string) $session->status) }}">{{ $statusName((string) $session->status) }}</span>
                                </td>
                                <td class="px-5 py-4 align-top text-sm text-slate-600">
                                    <div>{{ $session->created_at?->format('Y-m-d H:i:s') ?? '-' }}</div>
                                    @if($session->expires_at)
                                        <div class="mt-1 text-xs text-slate-400">过期：{{ $session->expires_at->format('Y-m-d H:i:s') }}</div>
                                    @endif
                                </td>
                                <td class="px-5 py-4 align-top text-right">
                                    @if(in_array((string) $session->status, ['pending', 'failed', 'expired'], true))
                                        <form method="POST" action="{{ route('admin.crebee-accounts.aitoearn.auth-sessions.sync', $session) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">同步状态</button>
                                        </form>
                                    @else
                                        <span class="text-sm text-slate-400">-</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-10 text-center text-sm text-slate-500">暂无授权记录。</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($authSessions->hasPages())
                <div class="border-t border-slate-200 px-5 py-4">
                    {{ $authSessions->links() }}
                </div>
            @endif
        </section>
    </div>
@endsection
