@extends('admin.layouts.app')

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('admin.product_cases.admin.create_title') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.product_cases.admin.create_desc') }}</p>
            </div>
            <a href="{{ route('admin.product-cases.index') }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50">
                <i data-lucide="arrow-left" class="h-4 w-4"></i>
                {{ __('admin.product_cases.admin.back_to_list') }}
            </a>
        </div>

        @include('admin.product-cases._form', [
            'action' => route('admin.product-cases.store'),
            'method' => 'POST',
        ])
    </div>
@endsection
