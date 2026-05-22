@extends('admin.layouts.app')

@php
    $formAction = $isEdit
        ? route('admin.keyword-libraries.update', ['libraryId' => (int) $libraryId])
        : route('admin.keyword-libraries.store');
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center space-x-4">
            <a href="{{ route('admin.keyword-libraries.index') }}" class="text-gray-400 hover:text-gray-600">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $isEdit ? __('admin.button.edit') : __('admin.keyword_libraries.modal_create') }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ __('admin.keyword_libraries.subtitle') }}</p>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-6">
                <form method="POST" action="{{ $formAction }}" class="space-y-6">
                    @csrf
                    @if ($isEdit)
                        @method('PUT')
                    @endif
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.keyword_libraries.field_name') }}</label>
                        <input type="text" name="name" required value="{{ old('name', (string) ($libraryForm['name'] ?? '')) }}" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="{{ __('admin.keyword_libraries.placeholder_name') }}">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('admin.keyword_libraries.field_description') }}</label>
                        <textarea name="description" rows="4" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="{{ __('admin.keyword_libraries.placeholder_description') }}">{{ old('description', (string) ($libraryForm['description'] ?? '')) }}</textarea>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">公司/品牌</label>
                            <input type="text" name="company_name" value="{{ old('company_name', (string) ($libraryForm['company_name'] ?? '')) }}" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="例如 Acme">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">领域关键词</label>
                            <input type="text" name="domain_keyword" value="{{ old('domain_keyword', (string) ($libraryForm['domain_keyword'] ?? '')) }}" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="例如 GEO 优化">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">行业</label>
                            <input type="text" name="industry" value="{{ old('industry', (string) ($libraryForm['industry'] ?? '')) }}" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="例如 SaaS">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">品牌描述</label>
                        <textarea name="brand_description" rows="4" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" placeholder="用于关键词蒸馏、问题变体和后续收录检测的品牌背景">{{ old('brand_description', (string) ($libraryForm['brand_description'] ?? '')) }}</textarea>
                    </div>
                    <div class="flex justify-end gap-3">
                        <a href="{{ route('admin.keyword-libraries.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            {{ __('admin.button.cancel') }}
                        </a>
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                            {{ __('admin.button.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
