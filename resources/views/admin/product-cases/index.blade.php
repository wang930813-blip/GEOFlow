@extends('admin.layouts.app')

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.product_cases.admin.management_title') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.product_cases.admin.management_desc') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.product-case-library.index') }}" target="_blank" rel="noopener noreferrer" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50">
                    <i data-lucide="external-link" class="h-4 w-4"></i>
                    {{ __('admin.product_cases.admin.public_list') }}
                </a>
                <a href="{{ route('admin.product-cases.create') }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700">
                    <i data-lucide="plus" class="h-4 w-4"></i>
                    {{ __('admin.product_cases.admin.new_case') }}
                </a>
            </div>
        </div>

        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <form method="GET" action="{{ route('admin.product-cases.index') }}" class="grid gap-4 md:grid-cols-[1fr_220px_auto]">
                <label class="block">
                    <span class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.product_cases.admin.search') }}</span>
                    <input name="keyword" value="{{ $filters['keyword'] ?? '' }}" placeholder="{{ __('admin.product_cases.admin.search_placeholder') }}" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                </label>
                <label class="block">
                    <span class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.product_cases.admin.status_filter') }}</span>
                    <select name="status" class="block h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-gray-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                        <option value="">{{ __('admin.product_cases.admin.all_statuses') }}</option>
                        @foreach($statusLabels as $statusKey => $statusLabel)
                            <option value="{{ $statusKey }}" @selected(($filters['status'] ?? '') === $statusKey)>{{ $statusLabel }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="flex items-end gap-2">
                    <button type="submit" class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-slate-900 px-4 text-sm font-medium text-white transition hover:bg-slate-800">
                        <i data-lucide="search" class="h-4 w-4"></i>
                        {{ __('admin.product_cases.admin.filter') }}
                    </button>
                    <a href="{{ route('admin.product-cases.index') }}" class="inline-flex h-10 items-center justify-center rounded-md border border-slate-200 bg-white px-3 text-sm font-medium text-slate-600 transition hover:bg-slate-50">{{ __('admin.product_cases.admin.reset') }}</a>
                </div>
            </form>
        </section>

        <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table data-product-cases-admin-table class="w-full min-w-[980px] table-fixed divide-y divide-slate-200">
                    <colgroup>
                        <col class="w-[30%]">
                        <col class="w-[16%]">
                        <col class="w-[14%]">
                        <col class="w-[10%]">
                        <col class="w-[15%]">
                        <col class="w-[15%]">
                    </colgroup>
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">{{ __('admin.product_cases.admin.table.case') }}</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">{{ __('admin.product_cases.admin.table.linked_site') }}</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">{{ __('admin.product_cases.admin.table.industry_region') }}</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">{{ __('admin.product_cases.admin.table.status') }}</th>
                            <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-500">{{ __('admin.product_cases.admin.table.published_at') }}</th>
                            <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-500">{{ __('admin.product_cases.admin.table.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @forelse($cases as $case)
                            <tr>
                                <td class="px-5 py-4 align-top">
                                    <div class="truncate font-semibold text-slate-950" title="{{ $case->title }}">{{ $case->title }}</div>
                                    <div class="mt-1 truncate text-sm text-slate-500" title="{{ $case->company_name ?: __('admin.product_cases.admin.brand_not_set') }}">{{ $case->company_name ?: __('admin.product_cases.admin.brand_not_set') }}</div>
                                    <div class="mt-1 truncate text-xs text-slate-400" title="/{{ $case->slug }}">/{{ $case->slug }}</div>
                                </td>
                                <td class="px-5 py-4 align-top text-sm text-slate-600">
                                    @if($case->site)
                                        <div class="truncate font-medium text-slate-900" title="{{ $case->site->name }}">{{ $case->site->name }}</div>
                                        <div class="mt-1 truncate text-xs text-slate-400" title="{{ $case->site->domain ?: __('admin.product_cases.admin.domain_not_bound') }}">{{ $case->site->domain ?: __('admin.product_cases.admin.domain_not_bound') }}</div>
                                    @else
                                        <span class="text-slate-400">{{ __('admin.product_cases.admin.not_linked') }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 align-top text-sm text-slate-600">
                                    <div>{{ $case->displayIndustry() ?: __('admin.product_cases.admin.industry_not_set') }}</div>
                                    <div class="mt-1 text-xs text-slate-400">{{ $case->displayRegion() ?: __('admin.product_cases.admin.region_not_set') }}</div>
                                </td>
                                <td class="px-5 py-4 align-top">
                                    @php
                                        $statusClass = match($case->status) {
                                            \App\Models\ProductCase::STATUS_PUBLISHED => 'bg-green-100 text-green-800',
                                            \App\Models\ProductCase::STATUS_HIDDEN => 'bg-slate-100 text-slate-700',
                                            default => 'bg-amber-100 text-amber-800',
                                        };
                                    @endphp
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $statusClass }}">{{ $statusLabels[$case->status] ?? $case->status }}</span>
                                </td>
                                <td class="px-5 py-4 align-top text-sm text-slate-600">
                                    {{ $case->published_at?->format('Y-m-d H:i') ?: '-' }}
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 align-top text-right">
                                    <div class="inline-flex items-center justify-end gap-2">
                                        @if($case->status === \App\Models\ProductCase::STATUS_PUBLISHED)
                                            <a href="{{ route('admin.product-case-library.show', ['slug' => $case->slug]) }}" target="_blank" rel="noopener noreferrer" class="text-sm font-medium text-slate-600 hover:text-slate-950">{{ __('admin.product_cases.admin.view') }}</a>
                                        @endif
                                        <a href="{{ route('admin.product-cases.edit', ['product_case' => $case->id]) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">{{ __('admin.product_cases.admin.edit') }}</a>
                                        <form method="POST" action="{{ route('admin.product-cases.toggle-status', ['product_case' => $case->id]) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-sm font-medium {{ $case->status === \App\Models\ProductCase::STATUS_PUBLISHED ? 'text-amber-600 hover:text-amber-800' : 'text-green-600 hover:text-green-800' }}">
                                                {{ $case->status === \App\Models\ProductCase::STATUS_PUBLISHED ? __('admin.product_cases.admin.hide') : __('admin.product_cases.admin.publish') }}
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.product-cases.destroy', ['product_case' => $case->id]) }}" class="inline" onsubmit="return confirm(@js(__('admin.product_cases.admin.delete_confirm')));">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-sm font-medium text-red-600 hover:text-red-800">{{ __('admin.product_cases.admin.delete') }}</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-12 text-center text-sm text-slate-500">{{ __('admin.product_cases.admin.empty') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($cases->hasPages())
                <div class="border-t border-slate-200 px-5 py-4">
                    {{ $cases->onEachSide(1)->links() }}
                </div>
            @endif
        </section>
    </div>
@endsection
