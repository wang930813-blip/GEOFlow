@php
    $selectedSiteId = (int) old('site_id', $case->site_id);
    $selectedOwnerId = (int) old('owner_admin_id', $case->owner_admin_id);
    $selectedIndustry = \App\Models\ProductCase::normalizeIndustryLabel((string) old('industry', $case->industry));
    $selectedRegion = \App\Models\ProductCase::normalizeRegionLabel((string) old('region', $case->region));
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
                <span class="mb-1 block text-sm font-medium text-gray-700">Linked Site / Brand</span>
                <select name="site_id" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                    <option value="">No linked site</option>
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
                <span class="mb-1 block text-sm font-medium text-gray-700">Owner</span>
                <select name="owner_admin_id" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                    <option value="">Follow site owner</option>
                    @foreach($admins as $admin)
                        @php
                            $adminLabel = trim((string) $admin->display_name) !== '' ? $admin->display_name.' ('.$admin->username.')' : $admin->username;
                        @endphp
                        <option value="{{ $admin->id }}" @selected($selectedOwnerId === (int) $admin->id)>{{ $adminLabel }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="mb-1 block text-sm font-medium text-gray-700">Status</span>
                <select name="status" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                    @foreach($statusLabels as $statusKey => $statusLabel)
                        <option value="{{ $statusKey }}" @selected(old('status', $case->status ?? \App\Models\ProductCase::STATUS_DRAFT) === $statusKey)>{{ $statusLabel }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <div class="grid gap-5 lg:grid-cols-[1fr_260px]">
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-gray-700">Case Title</span>
                <input name="title" required value="{{ old('title', $case->title) }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="Brand GEO Growth Case">
            </label>
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-gray-700">Slug</span>
                <input name="slug" value="{{ old('slug', $case->slug) }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="Leave blank to auto-generate">
            </label>
        </div>

        <div class="grid gap-5 md:grid-cols-3">
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-gray-700">Company / Brand Name</span>
                <input name="company_name" value="{{ old('company_name', $case->company_name) }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
            </label>
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-gray-700">Industry</span>
                <select name="industry" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                    <option value="">Select industry</option>
                    @foreach($industryOptions as $industry)
                        <option value="{{ $industry }}" @selected($selectedIndustry === $industry)>{{ $industry }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-gray-700">Global Region</span>
                <select name="region" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                    <option value="">Select region</option>
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
                <span class="mb-1 block text-sm font-medium text-gray-700">Cover URL</span>
                <input name="cover_url" value="{{ old('cover_url', $case->cover_url) }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="https://example.com/cover.jpg">
            </label>
        </div>

        <div class="grid gap-5 md:grid-cols-3">
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-gray-700">Customer Level</span>
                <input name="customer_level" value="{{ old('customer_level', $case->customer_level) }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
            </label>
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-gray-700">Service Start Date</span>
                <input type="date" name="started_at" value="{{ old('started_at', optional($case->started_at)->format('Y-m-d')) }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
            </label>
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-gray-700">Published At</span>
                <input type="datetime-local" name="published_at" value="{{ $publishedAt }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
            </label>
        </div>

        <div class="grid gap-5 md:grid-cols-[180px]">
            <label class="block">
                <span class="mb-1 block text-sm font-medium text-gray-700">Sort Order</span>
                <input type="number" name="sort_order" value="{{ old('sort_order', $case->sort_order ?? 0) }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
            </label>
        </div>

        <label class="block">
            <span class="mb-1 block text-sm font-medium text-gray-700">Summary</span>
            <textarea name="summary" rows="3" class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm leading-6 text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="Used for listing cards and SEO description">{{ old('summary', $case->summary) }}</textarea>
        </label>

        <label class="block">
            <span class="mb-1 block text-sm font-medium text-gray-700">Case Content</span>
            <textarea name="content" rows="16" class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 font-mono text-sm leading-6 text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="Markdown supported">{{ old('content', $case->content) }}</textarea>
        </label>

        <div class="flex justify-end gap-3 border-t border-slate-200 pt-5">
            <a href="{{ route('admin.product-cases.index') }}" class="inline-flex h-10 items-center rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Cancel</a>
            <button type="submit" class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white transition hover:bg-indigo-700">
                <i data-lucide="save" class="h-4 w-4"></i>
                {{ $submitLabel }}
            </button>
        </div>
    </form>
</section>
