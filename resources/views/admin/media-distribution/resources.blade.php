@extends('admin.layouts.app')

@section('content')
    @php
        $apiColumns = [
            ['PC权重', ['pc_weigh', 'pc_weight']],
            ['出稿率', 'publish_rate'],
            ['移动权重', ['wap_weigh', 'wap_weight']],
            ['接口状态', 'status_label'],
        ];
    @endphp
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">分发媒体</h1>
                <p class="mt-1 text-sm text-gray-600">同步网站媒体和第三方自媒体资源，按销售价给站点投稿消耗积分。</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.media-distribution.submissions.index') }}" class="inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    <i data-lucide="send" class="h-4 w-4"></i>
                    投稿订单
                </a>
                <a href="{{ route('admin.media-distribution.credits.index') }}" class="inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    <i data-lucide="coins" class="h-4 w-4"></i>
                    积分
                </a>
                @if ($isSuperAdmin)
                    <a href="{{ route('admin.media-distribution.settings.index') }}" class="inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50">
                        <i data-lucide="settings" class="h-4 w-4"></i>
                        接口配置
                    </a>
                    <form method="POST" action="{{ route('admin.media-distribution.resources.sync') }}">
                        @csrf
                        <button type="submit" class="inline-flex h-10 items-center gap-2 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white hover:bg-indigo-700">
                            <i data-lucide="refresh-cw" class="h-4 w-4"></i>
                            同步资源
                        </button>
                    </form>
                @endif
            </div>
        </div>

        @if ($isSuperAdmin)
            <form method="POST" action="{{ route('admin.media-distribution.resources.price-multiplier') }}" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                @csrf
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">积分价设置倍率</label>
                        <p class="text-xs text-gray-500">统一按接口成本价乘以倍率生成积分价，例如 1.5 表示成本价的 1.5 倍。</p>
                    </div>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <input name="price_multiplier" required type="number" step="0.01" min="0" value="{{ old('price_multiplier', $priceMultiplier) }}" class="h-10 w-full rounded-md border border-slate-200 px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 sm:w-36">
                        <button type="submit" class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white hover:bg-indigo-700">
                            <i data-lucide="calculator" class="h-4 w-4"></i>
                            应用到全部媒体
                        </button>
                    </div>
                </div>
            </form>
        @endif

        <form method="GET" action="{{ route('admin.media-distribution.resources.index') }}" class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-6">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">媒体类型</label>
                    <select name="source_type" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                        <option value="">全部</option>
                        <option value="website_media" @selected($sourceType === 'website_media')>网站媒体</option>
                        <option value="zi_media" @selected($sourceType === 'zi_media')>第三方自媒体</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">分类</label>
                    <select name="category" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                        <option value="">全部</option>
                        @foreach ($categories as $item)
                            <option value="{{ $item }}" @selected($category === $item)>{{ $item }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">状态</label>
                    <select name="status" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                        <option value="" @selected($status === '')>全部</option>
                        <option value="active" @selected($status === 'active')>可投稿</option>
                        <option value="inactive" @selected($status === 'inactive')>不可用</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-gray-700">搜索媒体</label>
                    <input name="search" value="{{ $search }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="输入媒体名称">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">积分价</label>
                    <div class="grid grid-cols-2 gap-2">
                        <input name="min_price" value="{{ $minPrice }}" class="h-10 rounded-md border border-slate-200 px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="最低">
                        <input name="max_price" value="{{ $maxPrice }}" class="h-10 rounded-md border border-slate-200 px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="最高">
                    </div>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="inline-flex h-10 w-full items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50">
                        <i data-lucide="search" class="h-4 w-4"></i>
                        筛选
                    </button>
                </div>
            </div>
        </form>

        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-sm font-semibold text-gray-900">批量投稿</h2>
                    <p class="text-xs text-gray-500">勾选多个媒体后进入下一页，可选择多篇文章一起投稿。</p>
                </div>
                <form id="bulk-media-submit-form" method="GET" action="{{ route('admin.media-distribution.submissions.index') }}"></form>
                <button form="bulk-media-submit-form" type="submit" class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white hover:bg-indigo-700">
                    <i data-lucide="send" class="h-4 w-4"></i>
                    批量投稿
                </button>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-max divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="w-12 whitespace-nowrap px-5 py-3 text-left">
                                <span class="sr-only">选择</span>
                            </th>
                            <th class="min-w-44 whitespace-nowrap px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">媒体</th>
                            <th class="w-56 max-w-56 whitespace-nowrap px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">备注</th>
                            @foreach ($apiColumns as [$label])
                                <th class="min-w-24 whitespace-nowrap px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">{{ $label }}</th>
                            @endforeach
                            @if ($isSuperAdmin)
                                <th class="min-w-24 whitespace-nowrap px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">成本价</th>
                            @endif
                            <th class="min-w-24 whitespace-nowrap px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">积分价</th>
                            <th class="min-w-24 whitespace-nowrap px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">状态</th>
                            <th class="min-w-28 whitespace-nowrap px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-500">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @forelse ($resources as $resource)
                            <tr>
                                <td class="whitespace-nowrap px-5 py-4 align-top">
                                    <input form="bulk-media-submit-form" type="checkbox" name="media_resource_ids[]" value="{{ (int) $resource->id }}" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 align-top">
                                    <div class="font-medium text-gray-900">{{ $resource->title }}</div>
                                    <div class="mt-1 text-xs text-gray-500">{{ $resource->sourceLabel() }} · {{ $resource->external_resource_id }}</div>
                                    @if($resource->case_link !== '')
                                        <a href="{{ $resource->case_link }}" target="_blank" rel="noopener noreferrer" class="mt-1 inline-flex text-xs text-indigo-600 hover:text-indigo-800">案例链接</a>
                                    @endif
                                </td>
                                <td class="w-56 max-w-56 px-5 py-4 align-top text-sm text-gray-600">
                                    <div class="truncate" title="{{ $resource->remarks }}">{{ $resource->remarks }}</div>
                                </td>
                                @foreach ($apiColumns as [$label, $key])
                                    @php
                                        $value = $key === 'status_label' ? $resource->apiStatusLabel() : $resource->apiField($key);
                                    @endphp
                                    <td class="whitespace-nowrap px-5 py-4 align-top text-sm text-gray-700">{{ $value }}</td>
                                @endforeach
                                @if ($isSuperAdmin)
                                    <td class="whitespace-nowrap px-5 py-4 align-top text-sm text-gray-700">{{ $resource->cost_price }}</td>
                                @endif
                                <td class="whitespace-nowrap px-5 py-4 align-top text-sm font-medium text-gray-900">
                                    {{ $resource->sale_price }}
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 align-top">
                                    <span class="inline-flex whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium {{ $resource->status === 'active' ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-600' }}">
                                        {{ $resource->status === 'active' ? '可投稿' : '不可用' }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 align-top text-right">
                                    <div class="flex flex-col items-end gap-2">
                                        @if ($isSuperAdmin)
                                            <button type="button" class="whitespace-nowrap text-sm font-medium text-slate-600 hover:text-slate-900" data-open-price-modal="media-price-modal-{{ $resource->id }}">专属价设置</button>
                                        @endif
                                        <a href="{{ route('admin.media-distribution.submissions.index', ['media_resource_id' => $resource->id]) }}" class="whitespace-nowrap text-sm font-medium text-indigo-600 hover:text-indigo-800">去投稿</a>
                                    </div>
                                    @if ($isSuperAdmin)
                                        <div id="media-price-modal-{{ $resource->id }}" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 px-4 py-6" data-price-modal>
                                            <div class="w-full max-w-lg rounded-lg bg-white p-6 text-left shadow-xl">
                                                <div class="flex items-start justify-between gap-4">
                                                    <div>
                                                        <h2 class="text-lg font-semibold text-gray-900">专属价设置</h2>
                                                        <p class="mt-1 text-sm text-gray-500">{{ $resource->title }}</p>
                                                    </div>
                                                    <button type="button" class="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" data-close-price-modal>
                                                        <i data-lucide="x" class="h-5 w-5"></i>
                                                    </button>
                                                </div>
                                                <div class="mt-5 grid grid-cols-2 gap-3 text-sm">
                                                    <div class="rounded-md bg-slate-50 px-3 py-2">
                                                        <div class="text-xs text-slate-500">接口成本价</div>
                                                        <div class="mt-1 font-medium text-slate-900">{{ $resource->cost_price }}</div>
                                                    </div>
                                                    <div class="rounded-md bg-slate-50 px-3 py-2">
                                                        <div class="text-xs text-slate-500">当前积分价</div>
                                                        <div class="mt-1 font-medium text-slate-900">{{ $resource->sale_price }}</div>
                                                    </div>
                                                </div>
                                                <form method="POST" action="{{ route('admin.media-distribution.resources.site-price', ['resource' => $resource->id]) }}" class="mt-5 space-y-4">
                                                    @csrf
                                                    <div>
                                                        <label class="mb-1 block text-sm font-medium text-gray-700">站点</label>
                                                        <select name="site_id" required class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                                                            <option value="">选择站点</option>
                                                            @foreach ($sites as $site)
                                                                <option value="{{ $site->id }}">{{ $site->name }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                    <div>
                                                        <label class="mb-1 block text-sm font-medium text-gray-700">站点专属积分价</label>
                                                        <input name="sale_price" required type="number" step="0.01" min="0" class="block h-10 w-full rounded-md border border-slate-200 px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="输入专属价">
                                                    </div>
                                                    <div class="flex justify-end gap-2">
                                                        <button type="button" class="inline-flex h-10 items-center rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50" data-close-price-modal>取消</button>
                                                        <button type="submit" class="inline-flex h-10 items-center rounded-md bg-slate-900 px-4 text-sm font-medium text-white hover:bg-slate-700">保存</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $isSuperAdmin ? 11 : 10 }}" class="px-5 py-10 text-center text-sm text-gray-500">暂无媒体资源，请先同步。</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-5 py-4">
                {{ $resources->links() }}
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('click', function (event) {
            const openButton = event.target.closest('[data-open-price-modal]');
            if (openButton) {
                const modal = document.getElementById(openButton.getAttribute('data-open-price-modal'));
                modal?.classList.remove('hidden');
                modal?.classList.add('flex');
                return;
            }

            const closeButton = event.target.closest('[data-close-price-modal]');
            const clickedBackdrop = event.target.matches('[data-price-modal]');
            if (closeButton || clickedBackdrop) {
                const modal = event.target.closest('[data-price-modal]') || event.target;
                modal?.classList.add('hidden');
                modal?.classList.remove('flex');
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') {
                return;
            }
            document.querySelectorAll('[data-price-modal]').forEach(function (modal) {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            });
        });
    </script>
@endpush
