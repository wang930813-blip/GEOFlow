@extends('admin.layouts.app')

@section('content')
    @php
        $selfMediaAccountInputName = (string) ($selfMediaAccountInputName ?? 'crebee_account_ids');
    @endphp

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
        $videoUrl = $video->firstVideoUrl();
        $canOperateVideos = (bool) ($canOperateVideos ?? true);
    @endphp

    <div class="space-y-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-bold text-gray-900">{{ $video->title ?: $video->subject }}</h1>
                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $statusClass((string) $video->status) }}">{{ $statusName((string) $video->status) }}</span>
                </div>
                <p class="mt-1 text-sm text-gray-600">任务 ID：{{ $video->api_task_id ?: '-' }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.video-generations.index') }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50">
                    <i data-lucide="arrow-left" class="h-4 w-4"></i>
                    返回列表
                </a>
                @if($canOperateVideos && (string) $video->status === 'success' && $videoUrl !== '')
                    <button type="button" onclick="document.getElementById('self-media-publish-modal').classList.remove('hidden')" class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white shadow-sm hover:bg-indigo-700">
                        <i data-lucide="send" class="h-4 w-4"></i>
                        发布自媒体
                    </button>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
            <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm lg:col-span-2">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 class="text-lg font-semibold text-gray-900">视频预览</h2>
                </div>
                <div class="p-5">
                    @if($videoUrl !== '')
                        <video src="{{ $videoUrl }}" controls class="aspect-video w-full rounded-lg bg-black object-contain"></video>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <a href="{{ $videoUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50">
                                <i data-lucide="external-link" class="h-4 w-4"></i>
                                新窗口打开
                            </a>
                            <a href="{{ route('admin.video-generations.download', ['videoGeneration' => (int) $video->id]) }}" class="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50">
                                <i data-lucide="download" class="h-4 w-4"></i>
                                下载视频
                            </a>
                        </div>
                    @elseif($canOperateVideos)
                        <div class="flex h-72 items-center justify-center rounded-lg bg-slate-50 text-sm text-slate-500">
                            视频尚未生成完成
                        </div>
                    @endif
                </div>
            </section>

            <aside class="space-y-5">
                <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">生成信息</h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">比例</dt><dd class="font-medium text-slate-900">{{ $video->video_aspect }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">数量</dt><dd class="font-medium text-slate-900">{{ $video->video_count }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">进度</dt><dd class="font-medium text-slate-900">{{ $video->progress }}%</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">创建时间</dt><dd class="font-medium text-slate-900">{{ $video->created_at?->format('Y-m-d H:i') ?? '-' }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">完成时间</dt><dd class="font-medium text-slate-900">{{ $video->finished_at?->format('Y-m-d H:i') ?? '-' }}</dd></div>
                    </dl>
                    @if((string) $video->failure_reason !== '')
                        <div class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ $video->failure_reason }}</div>
                    @endif
                </section>

                <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">封面图</h2>
                    @if((string) $video->cover_image !== '')
                        <img src="{{ $video->cover_image }}" alt="" class="mt-4 aspect-video w-full rounded-md border border-slate-200 object-cover" referrerpolicy="no-referrer">
                    @else
                        <div class="mt-4 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-700">发布视频到自媒体前需要补充封面图。</div>
                    @endif
                    @if($canOperateVideos)
                    <form method="POST" action="{{ route('admin.video-generations.cover.update', $video) }}" class="mt-4 space-y-3">
                        @csrf
                        <input name="cover_image" value="{{ old('cover_image', $video->cover_image) }}" class="block h-10 w-full rounded-md border border-slate-200 px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="https://...">
                        <button type="submit" class="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50">
                            <i data-lucide="image" class="h-4 w-4"></i>
                            保存封面
                        </button>
                    </form>
                    @endif
                </section>
            </aside>
        </div>

        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-gray-900">视频脚本</h2>
            <div class="mt-4 whitespace-pre-wrap rounded-md bg-slate-50 p-3 text-sm leading-6 text-slate-700">{{ $video->script ?: '暂无' }}</div>
        </section>
    </div>

    @if($canOperateVideos)
    <div id="self-media-publish-modal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="self-media-publish-title" role="dialog" aria-modal="true">
        <div class="flex min-h-screen items-center justify-center px-4 py-8">
            <div class="fixed inset-0 bg-slate-900/40" onclick="document.getElementById('self-media-publish-modal').classList.add('hidden')"></div>
            <form method="POST" action="{{ route('admin.video-generations.self-media.publish', $video) }}" class="relative w-full max-w-3xl overflow-hidden rounded-lg bg-white shadow-xl">
                @csrf
                <div class="border-b border-slate-200 px-5 py-4">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 id="self-media-publish-title" class="text-lg font-semibold text-gray-900">发布自媒体</h3>
                            <p class="mt-1 text-sm text-slate-500">只显示已绑定且支持视频发布的平台。选择几个平台就扣几次自媒体发布额度。</p>
                        </div>
                        <button type="button" onclick="document.getElementById('self-media-publish-modal').classList.add('hidden')" class="rounded-md p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
                            <i data-lucide="x" class="h-5 w-5"></i>
                        </button>
                    </div>
                </div>
                <div class="max-h-[65vh] overflow-y-auto p-5">
                    @if((string) $video->cover_image === '')
                        <div class="mb-4 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-700">当前视频缺少封面图，请先保存封面图后再发布。</div>
                    @endif
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @forelse($selfMediaVideoAccounts as $account)
                            @php
                                $platform = (string) $account->platform;
                                $logoPath = $selfMediaPlatformLogos[$platform] ?? \App\Support\Crebee\SelfMediaPlatformCatalog::logoPath($platform);
                                $externalAccountId = (string) ($account->crebee_account_id ?? $account->external_account_id ?? '');
                                $accountName = trim((string) $account->account_name) ?: $externalAccountId;
                            @endphp
                            <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200 p-3 hover:border-indigo-200 hover:bg-indigo-50/40">
                                <input type="checkbox" name="{{ $selfMediaAccountInputName }}[]" value="{{ $account->id }}" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                <span class="relative flex h-11 w-11 shrink-0 items-center justify-center">
                                    @if((string) $account->avatar !== '')
                                        <img src="{{ $account->avatar }}" alt="" class="h-10 w-10 rounded-full border border-slate-200 object-cover" referrerpolicy="no-referrer">
                                    @else
                                        <span class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-sm font-semibold text-slate-500">{{ mb_substr($accountName, 0, 1) }}</span>
                                    @endif
                                    <span class="absolute -bottom-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full border border-white bg-white shadow-sm">
                                        <img src="{{ asset($logoPath) }}" alt="" class="h-4 w-4 object-contain">
                                    </span>
                                </span>
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium text-slate-900">{{ $accountName }}</span>
                                    <span class="mt-0.5 block text-xs text-slate-500">{{ $selfMediaPlatformLabels[$platform] ?? $platform }}</span>
                                </span>
                            </label>
                        @empty
                            <div class="rounded-md bg-slate-50 px-3 py-6 text-center text-sm text-slate-500 sm:col-span-2">
                                暂无可发布的视频自媒体账号，请先到自媒体账号绑定页面完成绑定。
                            </div>
                        @endforelse
                    </div>
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-4">
                    <button type="button" onclick="document.getElementById('self-media-publish-modal').classList.add('hidden')" class="inline-flex h-10 items-center justify-center rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50">取消</button>
                    <button type="submit" @disabled($selfMediaVideoAccounts->isEmpty() || (string) $video->cover_image === '') class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300">
                        <i data-lucide="send" class="h-4 w-4"></i>
                        提交发布
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif
@endsection
