@php
    $selectedSiteId = (int) old('site_id', $case->site_id);
    $selectedOwnerId = (int) old('owner_admin_id', $case->owner_admin_id);
    $selectedIndustry = (string) old('industry', $case->industry);
    $selectedRegion = (string) old('region', $case->region);
    $publishedAt = old('published_at');
    if ($publishedAt === null && $case->published_at) {
        $publishedAt = $case->published_at->format('Y-m-d\TH:i');
    }
@endphp

<section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
    <form method="POST" action="{{ $action }}" class="space-y-6">
        @csrf
        @if(strtoupper($method) !== 'POST')
            @method($method)
        @endif

        <div class="grid gap-5 lg:grid-cols-3">
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-gray-700">关联站点/品牌</span>
                <select name="site_id" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                    <option value="">不关联站点</option>
                    @foreach($sites as $site)
                        @php
                            $ownerName = trim((string) ($site->owner?->display_name ?: $site->owner?->username ?: ''));
                            $siteLabel = $ownerName !== '' ? $site->name.' - '.$ownerName : $site->name;
                        @endphp
                        <option value="{{ $site->id }}" @selected($selectedSiteId === (int) $site->id)>{{ $siteLabel }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="mb-1 block text-sm font-medium text-gray-700">所属用户</span>
                <select name="owner_admin_id" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                    <option value="">跟随站点负责人</option>
                    @foreach($admins as $admin)
                        @php
                            $adminLabel = trim((string) $admin->display_name) !== '' ? $admin->display_name.' ('.$admin->username.')' : $admin->username;
                        @endphp
                        <option value="{{ $admin->id }}" @selected($selectedOwnerId === (int) $admin->id)>{{ $adminLabel }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="mb-1 block text-sm font-medium text-gray-700">展示状态</span>
                <select name="status" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                    @foreach($statusLabels as $statusKey => $statusLabel)
                        <option value="{{ $statusKey }}" @selected(old('status', $case->status ?? \App\Models\ProductCase::STATUS_DRAFT) === $statusKey)>{{ $statusLabel }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <div class="grid gap-5 lg:grid-cols-[1fr_260px]">
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-gray-700">案例标题</span>
                <input name="title" required value="{{ old('title', $case->title) }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="某某品牌 GEO 增长案例">
            </label>
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-gray-700">案例别名</span>
                <input name="slug" value="{{ old('slug', $case->slug) }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="留空自动生成">
            </label>
        </div>

        <div class="grid gap-5 md:grid-cols-3">
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-gray-700">公司/品牌名称</span>
                <input name="company_name" value="{{ old('company_name', $case->company_name) }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
            </label>
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-gray-700">行业</span>
                <select name="industry" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                    <option value="">请选择行业</option>
                    @foreach($industryOptions as $industry)
                        <option value="{{ $industry }}" @selected($selectedIndustry === $industry)>{{ $industry }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-gray-700">地区</span>
                <select name="region" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                    <option value="">请选择地区</option>
                    @foreach($regionOptions as $region)
                        <option value="{{ $region }}" @selected($selectedRegion === $region)>{{ $region }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <div class="grid gap-5 md:grid-cols-2">
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-gray-700">Logo URL</span>
                <input name="logo_url" value="{{ old('logo_url', $case->logo_url) }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="https://example.com/logo.png">
            </label>
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-gray-700">封面 URL</span>
                <input name="cover_url" value="{{ old('cover_url', $case->cover_url) }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="https://example.com/cover.jpg">
            </label>
        </div>

        <div class="grid gap-5 md:grid-cols-3">
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-gray-700">客户等级</span>
                <input name="customer_level" value="{{ old('customer_level', $case->customer_level) }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
            </label>
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-gray-700">服务开始日期</span>
                <input type="date" name="started_at" value="{{ old('started_at', optional($case->started_at)->format('Y-m-d')) }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
            </label>
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-gray-700">发布时间</span>
                <input type="datetime-local" name="published_at" value="{{ $publishedAt }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
            </label>
        </div>

        <div class="grid gap-5 md:grid-cols-[180px]">
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-gray-700">排序</span>
                <input type="number" name="sort_order" value="{{ old('sort_order', $case->sort_order ?? 0) }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
            </label>
        </div>

        <label class="block">
            <span class="mb-1 block text-sm font-medium text-gray-700">摘要</span>
            <textarea name="summary" rows="3" class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm leading-6 text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="用于列表和 SEO 描述">{{ old('summary', $case->summary) }}</textarea>
        </label>

        <label class="block">
            <span class="mb-1 block text-sm font-medium text-gray-700">案例正文</span>
            <textarea name="content" rows="16" class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 font-mono text-sm leading-6 text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="支持 Markdown">{{ old('content', $case->content) }}</textarea>
        </label>

        <div class="flex justify-end gap-3 border-t border-slate-200 pt-5">
            <a href="{{ route('admin.product-cases.index') }}" class="inline-flex h-10 items-center rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 transition hover:bg-slate-50">取消</a>
            <button type="submit" class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white transition hover:bg-indigo-700">
                <i data-lucide="save" class="h-4 w-4"></i>
                {{ $submitLabel }}
            </button>
        </div>
    </form>
</section>
