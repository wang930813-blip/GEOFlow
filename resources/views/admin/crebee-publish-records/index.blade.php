@extends('admin.layouts.app')

@section('content')
    @php
        $platformName = fn (string $platform): string => $platformLabels[$platform] ?? $platform;
        $platformLogo = fn (string $platform): string => \App\Support\Crebee\SelfMediaPlatformCatalog::logoPath($platform);
        $statusClass = fn (string $status): string => match ($status) {
            'success' => 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100',
            'partial_success' => 'bg-sky-50 text-sky-700 ring-1 ring-sky-100',
            'failed' => 'bg-red-50 text-red-700 ring-1 ring-red-100',
            'publishing', 'submitted', 'dispatching', 'queued' => 'bg-amber-50 text-amber-700 ring-1 ring-amber-100',
            default => 'bg-slate-100 text-slate-600',
        };
        $statusName = fn (string $status): string => match ($status) {
            'success' => '发布成功',
            'partial_success' => '部分成功',
            'failed' => '发布失败',
            'publishing' => '发布中',
            'submitted' => '待结果',
            'dispatching' => '派发中',
            'queued' => '排队中',
            default => $status,
        };
        $siteDisplayName = $site instanceof \App\Models\Site ? $site->name : '代理下属用户';
    @endphp

    <div class="space-y-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">自媒体发布记录</h1>
                <p class="mt-1 text-sm text-gray-600">当前站点：{{ $siteDisplayName }}。查看文章发布到自媒体平台的任务状态和发布链接。</p>
            </div>
            <a href="{{ route('admin.crebee-accounts.index') }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50">
                <i data-lucide="share-2" class="h-4 w-4"></i>
                账号绑定
            </a>
        </div>

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">发布内容</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">平台账号</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">状态</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">时间</th>
                            <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-500">结果</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @forelse($records as $record)
                            @php
                                $job = $record->job;
                                $account = $record->account;
                                $platform = (string) $record->platform;
                                $logoPath = $platformLogo($platform);
                                $accountName = trim((string) ($account?->account_name ?? '')) ?: (string) ($account?->crebee_account_id ?? '-');
                                $avatar = trim((string) ($account?->avatar ?? ''));
                                $ownerName = $job?->owner?->display_name ?: $job?->owner?->username ?: '-';
                                $title = (string) ($job?->title ?? '-');
                            @endphp
                            <tr>
                                <td class="px-5 py-4 align-top">
                                    <div class="max-w-md truncate font-medium text-slate-900" title="{{ $title }}">{{ $title }}</div>
                                    <div class="mt-1 text-xs text-slate-400">发布人：{{ $ownerName }}</div>
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <div class="flex items-center gap-3">
                                        <div class="relative flex h-11 w-11 shrink-0 items-center justify-center">
                                            @if($avatar !== '')
                                                <img src="{{ $avatar }}" alt="" class="h-10 w-10 rounded-full border border-slate-200 object-cover" referrerpolicy="no-referrer">
                                            @else
                                                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-sm font-semibold text-slate-500">{{ mb_substr($accountName, 0, 1, 'UTF-8') }}</div>
                                            @endif
                                            <span class="absolute -bottom-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full border border-white bg-white shadow-sm">
                                                <img src="{{ asset($logoPath) }}" alt="{{ $platformName($platform) }}" class="h-4 w-4 object-contain">
                                            </span>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="max-w-48 truncate text-sm font-medium text-slate-900" title="{{ $accountName }}">{{ $accountName }}</div>
                                            <div class="mt-0.5 text-xs text-slate-500">{{ $platformName($platform) }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $statusClass((string) $record->status) }}">{{ $statusName((string) $record->status) }}</span>
                                    @if((string) $record->message !== '')
                                        <div class="mt-1 max-w-56 truncate text-xs text-slate-500" title="{{ $record->message }}">{{ $record->message }}</div>
                                    @endif
                                </td>
                                <td class="px-5 py-4 align-top text-sm text-slate-600">
                                    <div>提交：{{ $job?->submitted_at?->format('Y-m-d H:i') ?? $job?->created_at?->format('Y-m-d H:i') ?? '-' }}</div>
                                    @if($record->published_at)
                                        <div class="mt-1 text-xs text-emerald-600">完成：{{ $record->published_at->format('Y-m-d H:i') }}</div>
                                    @endif
                                </td>
                                <td class="px-5 py-4 align-top text-right">
                                    @if((string) $record->published_url !== '')
                                        <a href="{{ $record->published_url }}" target="_blank" rel="noopener noreferrer" class="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-indigo-100 bg-indigo-50 px-3 text-sm font-medium text-indigo-700 hover:bg-indigo-100">
                                            <i data-lucide="external-link" class="h-4 w-4"></i>
                                            查看链接
                                        </a>
                                    @else
                                        <span class="text-sm text-slate-400">暂无链接</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-12 text-center text-sm text-slate-500">暂无自媒体发布记录。</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($records->hasPages())
                <div class="border-t border-slate-200 px-5 py-4">
                    {{ $records->links() }}
                </div>
            @endif
        </section>
    </div>
@endsection
