@extends('admin.layouts.app')

@section('content')
    @php
        $statusClass = fn (string $status): string => match ($status) {
            'success' => 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100',
            'failed' => 'bg-red-50 text-red-700 ring-1 ring-red-100',
            'processing', 'queued' => 'bg-amber-50 text-amber-700 ring-1 ring-amber-100',
            default => 'bg-slate-100 text-slate-600',
        };
        $statusName = fn (string $status): string => match ($status) {
            'success' => '已完成',
            'failed' => '失败',
            'processing' => '生成中',
            'queued' => '排队中',
            default => $status,
        };
        $canOperateVideos = (bool) ($canOperateVideos ?? true);
    @endphp

    <div class="space-y-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">生成视频</h1>
                <p class="mt-1 text-sm text-gray-600">创建 AI 视频生成任务，完成后可预览、下载，并发布到已绑定的自媒体账号。</p>
            </div>
            @if($canOperateVideos)
            <a href="{{ route('admin.video-generations.create') }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white shadow-sm hover:bg-indigo-700">
                <i data-lucide="plus" class="h-4 w-4"></i>
                创建视频
            </a>
            @endif
        </div>

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">视频主题</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">状态</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">配置</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">时间</th>
                            <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-500">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @forelse($videos as $video)
                            @php
                                $title = (string) ($video->title ?: $video->subject);
                                $firstVideo = $video->firstVideoUrl();
                            @endphp
                            <tr>
                                <td class="px-5 py-4 align-top">
                                    <div class="max-w-md truncate font-medium text-slate-900" title="{{ $title }}">{{ $title }}</div>
                                    <div class="mt-1 text-xs text-slate-400">创建人：{{ $video->owner?->display_name ?: $video->owner?->username ?: '-' }}</div>
                                </td>
                                <td class="px-5 py-4 align-top">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $statusClass((string) $video->status) }}">{{ $statusName((string) $video->status) }}</span>
                                    @if(in_array((string) $video->status, ['queued', 'processing'], true))
                                        <div class="mt-2 h-1.5 w-32 overflow-hidden rounded-full bg-slate-100">
                                            <div class="h-full rounded-full bg-amber-500" style="width: {{ max(0, min(100, (int) $video->progress)) }}%"></div>
                                        </div>
                                    @endif
                                    @if((string) $video->failure_reason !== '')
                                        <div class="mt-1 max-w-56 truncate text-xs text-red-600" title="{{ $video->failure_reason }}">{{ $video->failure_reason }}</div>
                                    @endif
                                </td>
                                <td class="px-5 py-4 align-top text-sm text-slate-600">
                                    <div>{{ $video->video_aspect }} · {{ $video->video_count }} 个</div>
                                    <div class="mt-1 text-xs text-slate-400">{{ $video->video_source }}</div>
                                </td>
                                <td class="px-5 py-4 align-top text-sm text-slate-600">
                                    <div>{{ $video->created_at?->format('Y-m-d H:i') ?? '-' }}</div>
                                    @if($video->finished_at)
                                        <div class="mt-1 text-xs text-emerald-600">完成：{{ $video->finished_at->format('Y-m-d H:i') }}</div>
                                    @endif
                                </td>
                                <td class="px-5 py-4 align-top text-right">
                                    <div class="flex justify-end gap-2">
                                        @if($firstVideo !== '')
                                            <a href="{{ $firstVideo }}" target="_blank" rel="noopener noreferrer" class="inline-flex h-9 items-center justify-center gap-1 rounded-md border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50">
                                                <i data-lucide="play" class="h-4 w-4"></i>
                                                预览
                                            </a>
                                        @endif
                                        @if($canOperateVideos)
                                            <form method="POST" action="{{ route('admin.video-generations.destroy', $video) }}" onsubmit="return confirm('确认删除这个视频生成任务吗？');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="inline-flex h-9 items-center justify-center gap-1 rounded-md border border-red-100 bg-red-50 px-3 text-sm font-medium text-red-700 hover:bg-red-100">
                                                    <i data-lucide="trash-2" class="h-4 w-4"></i>
                                                    删除
                                                </button>
                                            </form>
                                        @endif
                                        <a href="{{ route('admin.video-generations.show', $video) }}" class="inline-flex h-9 items-center justify-center gap-1 rounded-md border border-indigo-100 bg-indigo-50 px-3 text-sm font-medium text-indigo-700 hover:bg-indigo-100">
                                            <i data-lucide="eye" class="h-4 w-4"></i>
                                            查看
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-12 text-center text-sm text-slate-500">暂无视频生成任务。</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($videos->hasPages())
                <div class="border-t border-slate-200 px-5 py-4">
                    {{ $videos->links() }}
                </div>
            @endif
        </section>
    </div>
@endsection
