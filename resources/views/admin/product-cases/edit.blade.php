@extends('admin.layouts.app')

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">编辑产品案例</h1>
                <p class="mt-1 text-sm text-gray-600">调整案例内容、展示状态和关联站点。</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if($case->status === \App\Models\ProductCase::STATUS_PUBLISHED)
                    <a href="{{ route('admin.product-case-library.show', ['slug' => $case->slug]) }}" target="_blank" rel="noopener noreferrer" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50">
                        <i data-lucide="external-link" class="h-4 w-4"></i>
                        查看公开页
                    </a>
                @endif
                <a href="{{ route('admin.product-cases.index') }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50">
                    <i data-lucide="arrow-left" class="h-4 w-4"></i>
                    返回列表
                </a>
            </div>
        </div>

        @include('admin.product-cases._form', [
            'action' => route('admin.product-cases.update', ['product_case' => $case->id]),
            'method' => 'PUT',
        ])
    </div>
@endsection
