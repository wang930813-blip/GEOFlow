@extends('admin.layouts.app')

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">媒体投稿订单</h1>
                <p class="mt-1 text-sm text-gray-600">选择当前站点文章投稿到媒体资源，系统会按文章 × 媒体创建订单并扣减积分。</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.media-distribution.submissions.export') }}" class="inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    <i data-lucide="download" class="h-4 w-4"></i>
                    导出订单
                </a>
                <a href="{{ route('admin.media-distribution.resources.index') }}" class="inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    <i data-lucide="newspaper" class="h-4 w-4"></i>
                    媒体资源
                </a>
            </div>
        </div>

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm" data-media-submission-picker data-media-search-url="{{ route('admin.media-distribution.submissions.media-resources.search') }}">
            <div class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">创建投稿订单</h2>
                    <p class="mt-1 text-sm text-gray-500">{{ $hasSelectedResources ? '已预选上一步的媒体，请继续勾选要投稿的文章。' : '可同时选择多篇文章和多个媒体，提交后自动生成组合订单。' }}</p>
                </div>
                <div class="grid grid-cols-2 gap-2 sm:flex sm:items-center">
                    <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                        <span class="text-slate-500">可选文章</span>
                        <span class="ml-2 font-semibold text-slate-900">{{ $articles->count() }}</span>
                    </div>
                    <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                        <span class="text-slate-500">可投稿媒体</span>
                        <span class="ml-2 font-semibold text-slate-900">{{ $activeResourceCount ?? $resources->count() }}</span>
                    </div>
                    @if ($account)
                        <div class="col-span-2 rounded-md border border-indigo-100 bg-indigo-50 px-3 py-2 text-sm sm:col-span-1">
                            <span class="text-indigo-700">积分余额</span>
                            <span class="ml-2 font-semibold text-indigo-900">{{ $account->balance }}</span>
                        </div>
                    @endif
                </div>
            </div>

            <form method="POST" action="{{ route('admin.media-distribution.submissions.bulk-store') }}" class="space-y-5 p-5" data-bulk-submission-form>
                @csrf
                <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
                    <div class="rounded-lg border border-slate-200 bg-slate-50/60">
                        <div class="border-b border-slate-200 p-4">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <label for="media-submission-article-search" class="text-sm font-semibold text-gray-900">选择文章</label>
                                    <p class="mt-1 text-xs text-gray-500">已选 <span data-article-count>0</span> 篇</p>
                                </div>
                                <div class="flex shrink-0 gap-2">
                                    <button type="button" class="inline-flex h-8 items-center rounded-md border border-slate-200 bg-white px-3 text-xs font-medium text-slate-600 hover:bg-slate-50" data-select-visible="article">全选当前</button>
                                    <button type="button" class="inline-flex h-8 items-center rounded-md border border-slate-200 bg-white px-3 text-xs font-medium text-slate-600 hover:bg-slate-50" data-clear-selection="article">清空</button>
                                </div>
                            </div>
                            <div class="mt-3">
                                <input id="media-submission-article-search" type="search" data-article-search class="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="搜索文章标题或ID">
                            </div>
                        </div>
                        <div class="max-h-96 overflow-y-auto p-3" data-article-list>
                            @forelse ($articles as $article)
                                <label class="flex cursor-pointer gap-3 rounded-md border border-transparent px-3 py-3 hover:border-indigo-100 hover:bg-white" data-article-item data-search="{{ $article->title }} #{{ $article->id }} {{ $article->status }} {{ $article->review_status }}">
                                    <input type="checkbox" name="article_ids[]" value="{{ $article->id }}" class="mt-1 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium text-gray-900">{{ $article->title }}</span>
                                        <span class="mt-1 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                            <span>#{{ $article->id }}</span>
                                            <span>{{ $article->status === 'published' ? '已发布' : '未发布' }}</span>
                                            @if ($article->created_at)
                                                <span>{{ $article->created_at->format('Y-m-d') }}</span>
                                            @endif
                                        </span>
                                    </span>
                                </label>
                            @empty
                                <div class="rounded-md border border-dashed border-slate-200 bg-white px-4 py-10 text-center text-sm text-gray-500">暂无可投稿文章</div>
                            @endforelse
                        </div>
                    </div>

                    <div class="rounded-lg border border-slate-200 bg-slate-50/60">
                        <div class="border-b border-slate-200 p-4">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <label for="media-submission-media-search" class="text-sm font-semibold text-gray-900">选择媒体</label>
                                    <p class="mt-1 text-xs text-gray-500">已选 <span data-media-count>0</span> 个，搜索会从全部可投稿媒体中匹配</p>
                                </div>
                                <div class="flex shrink-0 gap-2">
                                    <button type="button" class="inline-flex h-8 items-center rounded-md border border-slate-200 bg-white px-3 text-xs font-medium text-slate-600 hover:bg-slate-50" data-select-visible="media">全选当前</button>
                                    <button type="button" class="inline-flex h-8 items-center rounded-md border border-slate-200 bg-white px-3 text-xs font-medium text-slate-600 hover:bg-slate-50" data-clear-selection="media">清空</button>
                                </div>
                            </div>
                            <div class="mt-3">
                                <input id="media-submission-media-search" type="search" data-media-search class="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="搜索媒体、平台或价格">
                            </div>
                            <div class="mt-2 text-xs text-gray-500" data-media-search-status>默认显示最近同步的 50 个媒体，输入关键词可搜索全部可投稿媒体。</div>
                            <div class="mt-3 rounded-md border border-indigo-100 bg-white p-3">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="text-xs font-medium text-indigo-800">已选媒体</div>
                                    <div class="text-xs text-indigo-600">切换搜索词不会丢失已选项</div>
                                </div>
                                <div class="mt-2 flex flex-wrap gap-2" data-selected-media-list>
                                    <span class="text-xs text-gray-400" data-selected-media-empty>暂未选择媒体</span>
                                </div>
                                <div data-media-hidden-inputs>
                                    @foreach ($resources as $resource)
                                        @if (in_array((int) $resource->id, $selectedResourceIds ?? [], true))
                                            <input type="hidden" name="media_resource_ids[]" value="{{ $resource->id }}">
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        </div>
                        <div class="max-h-96 overflow-y-auto p-3" data-media-list>
                            @forelse ($resources as $resource)
                                <label class="flex cursor-pointer gap-3 rounded-md border border-transparent px-3 py-3 hover:border-indigo-100 hover:bg-white" data-media-item data-search="{{ $resource->title }} {{ $resource->platformLabel() }} {{ $resource->sourceLabel() }} {{ $resource->sale_price }} #{{ $resource->id }}">
                                    <input type="checkbox" value="{{ $resource->id }}" @checked(in_array((int) $resource->id, $selectedResourceIds ?? [], true)) data-media-option data-media-title="{{ $resource->title }}" data-media-platform="{{ $resource->platformLabel() }}" data-media-source="{{ $resource->sourceLabel() }}" data-media-price="{{ $resource->sale_price }}" class="mt-1 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                    <span class="min-w-0 flex-1">
                                        <span class="flex min-w-0 items-center justify-between gap-3">
                                            <span class="truncate text-sm font-medium text-gray-900">{{ $resource->title }}</span>
                                            <span class="shrink-0 rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">{{ $resource->sale_price }} 积分</span>
                                        </span>
                                        <span class="mt-1 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                            <span>{{ $resource->platformLabel() }}</span>
                                            <span>{{ $resource->sourceLabel() }}</span>
                                            <span>#{{ $resource->id }}</span>
                                        </span>
                                    </span>
                                </label>
                            @empty
                                <div class="rounded-md border border-dashed border-slate-200 bg-white px-4 py-10 text-center text-sm text-gray-500">暂无可投稿媒体，请先同步媒体资源</div>
                            @endforelse
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 rounded-lg border border-slate-200 bg-white p-4 lg:grid-cols-12 lg:items-end">
                    <div class="lg:col-span-7">
                        <label class="mb-1 block text-sm font-medium text-gray-700">备注</label>
                        <input name="remark" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="选填，会同步到本次创建的投稿订单">
                    </div>
                    <div class="lg:col-span-3">
                        <div class="rounded-md bg-slate-50 px-3 py-2">
                            <div class="text-xs text-slate-500">订单预估</div>
                            <div class="mt-1 text-sm font-semibold text-slate-900" data-order-count>预计生成订单：0 × 0 = 0</div>
                        </div>
                    </div>
                    <div class="lg:col-span-2">
                        <button type="submit" data-submit-button class="inline-flex h-10 w-full items-center justify-center gap-2 rounded-md bg-slate-900 px-4 text-sm font-medium text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:bg-slate-300" disabled>
                            <i data-lucide="send" class="h-4 w-4"></i>
                            提交投稿
                        </button>
                    </div>
                </div>
            </form>
        </section>

        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col gap-2 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">投稿订单</h2>
                    <p class="mt-1 text-sm text-gray-500">查看投稿进度、同步状态和发布链接。</p>
                </div>
                <div class="text-sm text-gray-500">共 {{ $submissions->total() }} 条</div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="whitespace-nowrap px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">订单</th>
                            @if ($isSuperAdmin)
                                <th class="whitespace-nowrap px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">站点</th>
                            @endif
                            <th class="min-w-80 px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">文章 / 媒体</th>
                            <th class="whitespace-nowrap px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">积分</th>
                            <th class="whitespace-nowrap px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">状态</th>
                            <th class="whitespace-nowrap px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">时间</th>
                            <th class="whitespace-nowrap px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-500">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @forelse ($submissions as $submission)
                            @php
                                $statusClass = match ((string) $submission->status) {
                                    'published' => 'bg-emerald-50 text-emerald-700 ring-emerald-100',
                                    'failed', 'rejected', 'cancelled' => 'bg-red-50 text-red-700 ring-red-100',
                                    'submitting', 'publishing', 'appealing' => 'bg-amber-50 text-amber-700 ring-amber-100',
                                    default => 'bg-slate-100 text-slate-700 ring-slate-200',
                                };
                            @endphp
                            <tr class="hover:bg-slate-50/70">
                                <td class="whitespace-nowrap px-5 py-4 align-top text-sm text-gray-600">
                                    <div class="font-medium text-gray-900">#{{ $submission->id }}</div>
                                    <div class="mt-1 text-xs text-gray-400">{{ $submission->external_order_nid ?: '未返回订单号' }}</div>
                                    @if($submission->agent_order_sn)
                                        <div class="mt-1 text-xs text-gray-400">{{ $submission->agent_order_sn }}</div>
                                    @endif
                                </td>
                                @if ($isSuperAdmin)
                                    <td class="whitespace-nowrap px-5 py-4 align-top text-sm text-gray-600">{{ $submission->site?->name ?: '-' }}</td>
                                @endif
                                <td class="px-5 py-4 align-top">
                                    <div class="max-w-xl truncate text-sm font-medium text-gray-900">{{ $submission->title_snapshot }}</div>
                                    <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                        <span>{{ $submission->platformLabel() }}</span>
                                        <span>{{ $submission->resource?->title ?: '-' }}</span>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 align-top text-sm font-semibold text-gray-900">{{ $submission->points_amount }}</td>
                                <td class="whitespace-nowrap px-5 py-4 align-top">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 {{ $statusClass }}">{{ $submission->statusLabel() }}</span>
                                    @if ($submission->last_error_message)
                                        <div class="mt-1 max-w-48 truncate text-xs text-red-500" title="{{ $submission->last_error_message }}">{{ $submission->last_error_message }}</div>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 align-top text-xs text-gray-500">
                                    <div>提交：{{ $submission->submitted_at?->format('Y-m-d H:i') ?: '-' }}</div>
                                    <div class="mt-1">同步：{{ $submission->last_synced_at?->format('Y-m-d H:i') ?: '-' }}</div>
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 align-top text-right">
                                    <a href="{{ route('admin.media-distribution.submissions.show', ['submission' => $submission->id]) }}" class="inline-flex h-8 items-center rounded-md border border-indigo-100 bg-indigo-50 px-3 text-sm font-medium text-indigo-700 hover:bg-indigo-100">详情</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $isSuperAdmin ? 7 : 6 }}" class="px-5 py-12 text-center">
                                    <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                                        <i data-lucide="inbox" class="h-5 w-5"></i>
                                    </div>
                                    <div class="mt-3 text-sm font-medium text-gray-900">暂无投稿订单</div>
                                    <div class="mt-1 text-sm text-gray-500">选择文章和媒体后即可创建订单。</div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-5 py-4">{{ $submissions->links() }}</div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const picker = document.querySelector('[data-media-submission-picker]');
            if (!picker) {
                return;
            }

            const submitButton = picker.querySelector('[data-submit-button]');
            const articleCount = picker.querySelector('[data-article-count]');
            const mediaCount = picker.querySelector('[data-media-count]');
            const orderCount = picker.querySelector('[data-order-count]');
            const mediaList = picker.querySelector('[data-media-list]');
            const mediaSearchInput = picker.querySelector('[data-media-search]');
            const mediaSearchStatus = picker.querySelector('[data-media-search-status]');
            const selectedMediaList = picker.querySelector('[data-selected-media-list]');
            const mediaHiddenInputs = picker.querySelector('[data-media-hidden-inputs]');
            const mediaSearchUrl = picker.getAttribute('data-media-search-url');
            const selectedMedia = new Map();
            let mediaSearchTimer = null;
            let mediaSearchRequest = 0;

            const articleCheckedCount = function () {
                return picker.querySelectorAll('input[name="article_ids[]"]:checked').length;
            };

            const updateCount = function () {
                const articles = articleCheckedCount();
                const media = selectedMedia.size;
                const total = articles * media;

                if (articleCount) articleCount.textContent = String(articles);
                if (mediaCount) mediaCount.textContent = String(media);
                if (orderCount) orderCount.textContent = '预计生成订单：' + articles + ' × ' + media + ' = ' + total;
                if (submitButton) submitButton.disabled = total === 0;
            };

            const renderSelectedMedia = function () {
                if (!selectedMediaList || !mediaHiddenInputs) {
                    updateCount();
                    return;
                }

                selectedMediaList.innerHTML = '';
                mediaHiddenInputs.innerHTML = '';

                if (selectedMedia.size === 0) {
                    const empty = document.createElement('span');
                    empty.className = 'text-xs text-gray-400';
                    empty.setAttribute('data-selected-media-empty', '');
                    empty.textContent = '暂未选择媒体';
                    selectedMediaList.appendChild(empty);
                }

                selectedMedia.forEach(function (media) {
                    const badge = document.createElement('span');
                    badge.className = 'inline-flex max-w-full items-center gap-2 rounded-full border border-indigo-100 bg-indigo-50 px-2.5 py-1 text-xs text-indigo-800';
                    badge.setAttribute('data-selected-media-id', String(media.id));

                    const text = document.createElement('span');
                    text.className = 'max-w-48 truncate';
                    text.textContent = media.title + ' · ' + media.price + ' 积分';

                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'font-semibold text-indigo-500 hover:text-indigo-800';
                    remove.setAttribute('data-remove-media-id', String(media.id));
                    remove.textContent = '×';

                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'media_resource_ids[]';
                    input.value = String(media.id);

                    badge.appendChild(text);
                    badge.appendChild(remove);
                    selectedMediaList.appendChild(badge);
                    mediaHiddenInputs.appendChild(input);
                });

                picker.querySelectorAll('[data-media-option]').forEach(function (checkbox) {
                    checkbox.checked = selectedMedia.has(String(checkbox.value));
                });
                updateCount();
            };

            const mediaFromCheckbox = function (checkbox) {
                return {
                    id: String(checkbox.value),
                    title: checkbox.getAttribute('data-media-title') || '',
                    platform: checkbox.getAttribute('data-media-platform') || '',
                    source: checkbox.getAttribute('data-media-source') || '',
                    price: checkbox.getAttribute('data-media-price') || '0.00',
                };
            };

            const renderMediaResults = function (items, total, hasMore) {
                if (!mediaList) {
                    return;
                }

                mediaList.innerHTML = '';

                if (!items.length) {
                    const empty = document.createElement('div');
                    empty.className = 'rounded-md border border-dashed border-slate-200 bg-white px-4 py-10 text-center text-sm text-gray-500';
                    empty.textContent = '没有匹配的可投稿媒体';
                    mediaList.appendChild(empty);
                    if (mediaSearchStatus) {
                        mediaSearchStatus.textContent = '没有匹配结果，请换个关键词。';
                    }
                    return;
                }

                items.forEach(function (media) {
                    const label = document.createElement('label');
                    label.className = 'flex cursor-pointer gap-3 rounded-md border border-transparent px-3 py-3 hover:border-indigo-100 hover:bg-white';
                    label.setAttribute('data-media-item', '');

                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.value = String(media.id);
                    checkbox.checked = selectedMedia.has(String(media.id));
                    checkbox.className = 'mt-1 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500';
                    checkbox.setAttribute('data-media-option', '');
                    checkbox.setAttribute('data-media-title', media.title || '');
                    checkbox.setAttribute('data-media-platform', media.platform || '');
                    checkbox.setAttribute('data-media-source', media.source_type || '');
                    checkbox.setAttribute('data-media-price', media.sale_price || '0.00');

                    const body = document.createElement('span');
                    body.className = 'min-w-0 flex-1';

                    const top = document.createElement('span');
                    top.className = 'flex min-w-0 items-center justify-between gap-3';

                    const title = document.createElement('span');
                    title.className = 'truncate text-sm font-medium text-gray-900';
                    title.textContent = media.title || '-';

                    const price = document.createElement('span');
                    price.className = 'shrink-0 rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700';
                    price.textContent = (media.sale_price || '0.00') + ' 积分';

                    const meta = document.createElement('span');
                    meta.className = 'mt-1 flex flex-wrap items-center gap-2 text-xs text-gray-500';

                    const platform = document.createElement('span');
                    platform.textContent = media.platform || '-';
                    const source = document.createElement('span');
                    source.textContent = media.source_type || '-';
                    const id = document.createElement('span');
                    id.textContent = '#' + String(media.id);

                    top.appendChild(title);
                    top.appendChild(price);
                    meta.appendChild(platform);
                    meta.appendChild(source);
                    meta.appendChild(id);
                    body.appendChild(top);
                    body.appendChild(meta);
                    label.appendChild(checkbox);
                    label.appendChild(body);
                    mediaList.appendChild(label);
                });

                if (mediaSearchStatus) {
                    mediaSearchStatus.textContent = hasMore
                        ? '已显示前 ' + items.length + ' 条，共 ' + total + ' 条，请继续输入关键词缩小范围。'
                        : '已显示 ' + items.length + ' 条，共 ' + total + ' 条。';
                }
            };

            const searchMedia = function () {
                if (!mediaSearchUrl) {
                    return;
                }

                const keyword = mediaSearchInput ? mediaSearchInput.value.trim() : '';
                const currentRequest = ++mediaSearchRequest;
                if (mediaSearchStatus) {
                    mediaSearchStatus.textContent = keyword === '' ? '正在加载最近同步媒体...' : '正在搜索全部可投稿媒体...';
                }

                const url = new URL(mediaSearchUrl, window.location.origin);
                url.searchParams.set('q', keyword);
                url.searchParams.set('per_page', '50');

                fetch(url.toString(), {
                    headers: {'Accept': 'application/json'},
                    credentials: 'same-origin',
                })
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error('search failed');
                        }
                        return response.json();
                    })
                    .then(function (payload) {
                        if (currentRequest !== mediaSearchRequest) {
                            return;
                        }
                        renderMediaResults(payload.items || [], Number(payload.total || 0), Boolean(payload.has_more));
                    })
                    .catch(function () {
                        if (currentRequest !== mediaSearchRequest) {
                            return;
                        }
                        if (mediaSearchStatus) {
                            mediaSearchStatus.textContent = '媒体搜索失败，请稍后重试。';
                        }
                    });
            };

            const filterArticleList = function (keyword) {
                const normalized = String(keyword || '').trim().toLowerCase();
                picker.querySelectorAll('[data-article-item]').forEach(function (item) {
                    const text = String(item.getAttribute('data-search') || '').toLowerCase();
                    const visible = normalized === '' || text.includes(normalized);
                    item.classList.toggle('hidden', !visible);
                });
            };

            picker.querySelector('[data-article-search]')?.addEventListener('input', function (event) {
                filterArticleList(event.target.value);
            });

            mediaSearchInput?.addEventListener('input', function () {
                window.clearTimeout(mediaSearchTimer);
                mediaSearchTimer = window.setTimeout(searchMedia, 250);
            });

            picker.addEventListener('change', function (event) {
                if (event.target.matches('input[name="article_ids[]"]')) {
                    updateCount();
                    return;
                }

                if (event.target.matches('[data-media-option]')) {
                    const media = mediaFromCheckbox(event.target);
                    if (event.target.checked) {
                        selectedMedia.set(media.id, media);
                    } else {
                        selectedMedia.delete(media.id);
                    }
                    renderSelectedMedia();
                }
            });

            picker.addEventListener('click', function (event) {
                const selectType = event.target.closest('[data-select-visible]')?.getAttribute('data-select-visible');
                const clearType = event.target.closest('[data-clear-selection]')?.getAttribute('data-clear-selection');
                const removeMediaId = event.target.closest('[data-remove-media-id]')?.getAttribute('data-remove-media-id');

                if (removeMediaId) {
                    event.preventDefault();
                    selectedMedia.delete(String(removeMediaId));
                    renderSelectedMedia();
                    return;
                }

                const type = selectType || clearType;

                if (!type) {
                    return;
                }

                if (type === 'media') {
                    event.preventDefault();
                    if (selectType) {
                        picker.querySelectorAll('[data-media-option]').forEach(function (checkbox) {
                            const media = mediaFromCheckbox(checkbox);
                            selectedMedia.set(media.id, media);
                        });
                    } else {
                        selectedMedia.clear();
                    }
                    renderSelectedMedia();
                    return;
                }

                event.preventDefault();
                picker.querySelectorAll('[data-article-item]').forEach(function (item) {
                    if (selectType && item.classList.contains('hidden')) {
                        return;
                    }

                    const checkbox = item.querySelector('input[type="checkbox"]');
                    if (checkbox) checkbox.checked = Boolean(selectType);
                });
                updateCount();
            });

            picker.querySelectorAll('[data-media-option]:checked').forEach(function (checkbox) {
                const media = mediaFromCheckbox(checkbox);
                selectedMedia.set(media.id, media);
            });
            renderSelectedMedia();
        });
    </script>
@endpush
