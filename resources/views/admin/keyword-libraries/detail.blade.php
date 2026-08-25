@extends('admin.layouts.app')

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <a href="{{ route('admin.keyword-libraries.index') }}" class="text-gray-400 hover:text-gray-600">
                        <i data-lucide="arrow-left" class="w-5 h-5"></i>
                    </a>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">{{ $library->name }}</h1>
                        <p class="mt-1 text-sm text-gray-600">{{ $library->description !== '' ? $library->description : __('admin.keyword_detail.no_description') }}</p>
                    </div>
                </div>
                <div class="flex space-x-2">
                    <button type="button" onclick="showEditModal()" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-sm font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                        <i data-lucide="edit" class="w-4 h-4 mr-1"></i>
                        {{ __('admin.keyword_detail.edit_info') }}
                    </button>
                    <button type="button" onclick="showAddModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                        <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.keyword_detail.add_keyword') }}
                    </button>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="key" class="h-6 w-6 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.keyword_detail.total_keywords') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ $keywords->total() }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="trending-up" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.keyword_detail.usage_total') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ $usageTotal }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="calendar" class="h-6 w-6 text-purple-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.keyword_detail.created_date') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ optional($library->created_at)->format('m-d') ?? '-' }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="clock" class="h-6 w-6 text-orange-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">{{ __('admin.keyword_detail.updated_date') }}</dt>
                                <dd class="text-lg font-medium text-gray-900">{{ optional($library->updated_at)->format('m-d') ?? '-' }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">项目与品牌信息</h3>
            </div>
            <div class="px-6 py-4 grid grid-cols-1 md:grid-cols-4 gap-4 text-sm">
                <div>
                    <div class="text-gray-500">公司/品牌</div>
                    <div class="mt-1 font-medium text-gray-900">{{ $library->company_name ?: '-' }}</div>
                </div>
                <div>
                    <div class="text-gray-500">领域关键词</div>
                    <div class="mt-1 font-medium text-gray-900">{{ $library->domain_keyword ?: '-' }}</div>
                </div>
                <div>
                    <div class="text-gray-500">行业</div>
                    <div class="mt-1 font-medium text-gray-900">{{ $library->industry ?: '-' }}</div>
                </div>
                <div>
                    <div class="text-gray-500">状态</div>
                    <div class="mt-1 font-medium text-gray-900">{{ $library->status ?: 'active' }}</div>
                </div>
                <div class="md:col-span-4">
                    <div class="text-gray-500">品牌描述</div>
                    <div class="mt-1 text-gray-900 whitespace-pre-line">{{ $library->brand_description ?: '-' }}</div>
                </div>
            </div>
        </div>

        @php
            $hasRunningInclusionRun = ($inclusionRuns ?? collect())->contains(
                static fn ($run): bool => in_array((string) $run->status, ['pending', 'running'], true)
            );
        @endphp

        <div class="bg-white shadow rounded-lg mb-6" data-inclusion-running="{{ $hasRunningInclusionRun ? '1' : '0' }}">
            <div class="px-6 py-4">
                <div class="flex items-center justify-between">
                    <form method="GET" class="flex items-center space-x-4">
                        <div class="flex-1">
                            <input type="text" name="search" value="{{ $search }}"
                                placeholder="{{ __('admin.keyword_detail.search_placeholder') }}"
                                class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                            <i data-lucide="search" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.button.search') }}
                        </button>
                        <a href="{{ route('admin.keyword-libraries.detail', ['libraryId' => (int) $library->id]) }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="x" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.button.clear') }}
                        </a>
                    </form>
                    <div class="flex space-x-2">
                        <button type="button" onclick="toggleBatchActions()" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="check-square" class="w-4 h-4 mr-1"></i>
                            {{ __('admin.keyword_detail.batch_actions') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-medium text-gray-900">AI 搜索收录检测</h3>
                        <p class="mt-1 text-sm text-gray-500">基于关键词的问题变体，检测豆包、千问、DeepSeek、腾讯元宝、文心一言是否命中关键词和品牌。</p>
                    </div>
                    <form method="POST" action="{{ route('admin.keyword-libraries.inclusion-checks.store', ['libraryId' => (int) $library->id]) }}" class="flex flex-wrap items-center gap-3">
                        @csrf
                        @foreach (($inclusionPlatforms ?? ['doubao' => '豆包', 'qianwen' => '千问', 'deepseek' => 'DeepSeek', 'yuanbao' => '腾讯元宝', 'wenxin' => '文心一言']) as $platformValue => $platformLabel)
                            <label class="inline-flex items-center text-sm text-gray-700">
                                <input type="checkbox" name="platforms[]" value="{{ $platformValue }}" checked class="rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500">
                                <span class="ml-1">{{ $platformLabel }}</span>
                            </label>
                        @endforeach
                        <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                            <i data-lucide="radar" class="w-4 h-4 mr-2"></i>
                            开始检测
                        </button>
                    </form>
                </div>
            </div>
            <div class="px-6 py-4 grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div>
                    @include('admin.keyword-libraries.partials.inclusion-runs', ['inclusionRuns' => $inclusionRuns ?? collect()])
                </div>
                <div>
                    @include('admin.keyword-libraries.partials.inclusion-daily-reports', ['library' => $library, 'inclusionDailyReports' => $inclusionDailyReports ?? collect()])
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-medium text-gray-900">
                        {{ __('admin.keyword_detail.list_title') }}
                        <span class="text-sm text-gray-500">{{ __('admin.keyword_detail.list_total', ['count' => $keywords->total()]) }}</span>
                    </h3>
                </div>
            </div>

            @if ($keywords->isEmpty())
                <div class="px-6 py-8 text-center">
                    <i data-lucide="search" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">{{ __('admin.keyword_detail.empty') }}</h3>
                    <p class="text-gray-500 mb-4">{{ $search !== '' ? __('admin.keyword_detail.empty_search') : __('admin.keyword_detail.empty_desc') }}</p>
                    @if ($search === '')
                        <button type="button" onclick="showAddModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.keyword_detail.add_keyword') }}
                        </button>
                    @endif
                </div>
            @else
                <div id="batch-actions" class="hidden px-6 py-3 bg-gray-50 border-b border-gray-200">
                    <form method="POST" action="{{ route('admin.keyword-libraries.keywords.delete', ['libraryId' => (int) $library->id]) }}" id="batch-form">
                        @csrf
                        <div class="flex items-center space-x-4">
                            <span class="text-sm text-gray-600" id="selected-keyword-count">{{ __('admin.keyword_detail.selected_count', ['count' => 0]) }}</span>
                            <button type="submit" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-red-600 hover:bg-red-700">
                                <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                                {{ __('admin.keyword_detail.delete_selected') }}
                            </button>
                            <button type="button" onclick="toggleBatchActions()" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                                {{ __('admin.button.cancel') }}
                            </button>
                        </div>
                    </form>
                </div>

                <div class="px-6 py-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                        @foreach ($keywords as $keyword)
                            <div class="group p-3 border border-gray-200 rounded-lg hover:bg-gray-50">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex items-center gap-2 min-w-0 flex-1">
                                        <input type="checkbox" form="batch-form" name="keyword_ids[]" value="{{ (int) $keyword->id }}" class="keyword-checkbox hidden rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                        <form method="POST" action="{{ route('admin.keyword-libraries.keywords.update', ['libraryId' => (int) $library->id, 'keywordId' => (int) $keyword->id]) }}" class="flex min-w-0 flex-1 items-center gap-2">
                                            @csrf
                                            @method('PUT')
                                            <input type="text" name="keyword" value="{{ $keyword->keyword }}" maxlength="200" class="block min-w-0 flex-1 rounded-md border-gray-300 text-sm font-medium text-gray-900 shadow-sm focus:border-blue-500 focus:ring-blue-500" aria-label="编辑关键词">
                                            <button type="submit" title="保存关键词" class="shrink-0 rounded-md border border-gray-200 bg-white p-2 text-gray-500 hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700">
                                                <i data-lucide="save" class="w-4 h-4"></i>
                                            </button>
                                        </form>
                                    </div>
                                    <button type="button" onclick="deleteKeyword({{ (int) $keyword->id }}, @js($keyword->keyword))" class="text-red-600 hover:text-red-800 opacity-0 group-hover:opacity-100 transition-opacity">
                                        <i data-lucide="x" class="w-4 h-4"></i>
                                    </button>
                                </div>
                                <div class="mt-3 space-y-2">
                                    <div class="flex items-center justify-between text-xs text-gray-500">
                                        <span>问题变体 {{ (int) ($keyword->question_variants_count ?? 0) }}</span>
                                        <button type="button" onclick="generateQuestionVariants({{ (int) $keyword->id }})" class="inline-flex items-center rounded border border-indigo-200 bg-indigo-50 px-2 py-1 text-indigo-700 hover:bg-indigo-100">
                                            <i data-lucide="sparkles" class="w-3.5 h-3.5 mr-1"></i>
                                            AI 生成
                                        </button>
                                    </div>
                                    @if ($keyword->questionVariants->isNotEmpty())
                                        <div class="space-y-2">
                                            @foreach ($keyword->questionVariants as $variant)
                                                <div class="flex gap-2">
                                                    <form method="POST" action="{{ route('admin.keyword-libraries.keywords.questions.update', ['libraryId' => (int) $library->id, 'keywordId' => (int) $keyword->id, 'questionId' => (int) $variant->id]) }}" class="flex min-w-0 flex-1 gap-2">
                                                        @csrf
                                                        @method('PUT')
                                                        <input type="text" name="question" value="{{ $variant->question }}" maxlength="500" class="block w-full rounded-md border-gray-300 text-xs text-gray-700 shadow-sm focus:border-blue-500 focus:ring-blue-500" aria-label="编辑问题变体">
                                                        <button type="submit" title="保存问题变体" class="shrink-0 rounded-md border border-gray-200 bg-white px-2 py-1 text-gray-500 hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700">
                                                            <i data-lucide="save" class="w-3.5 h-3.5"></i>
                                                        </button>
                                                    </form>
                                                    <form method="POST" action="{{ route('admin.keyword-libraries.keywords.questions.delete', ['libraryId' => (int) $library->id, 'keywordId' => (int) $keyword->id, 'questionId' => (int) $variant->id]) }}" onsubmit="return confirm('确认删除这个问题变体？')" class="shrink-0">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" title="删除问题变体" class="rounded-md border border-red-100 bg-white px-2 py-1 text-red-500 hover:border-red-200 hover:bg-red-50 hover:text-red-700">
                                                            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                    <form method="POST" action="{{ route('admin.keyword-libraries.keywords.questions.store', ['libraryId' => (int) $library->id, 'keywordId' => (int) $keyword->id]) }}" class="flex gap-2">
                                        @csrf
                                        <input type="text" name="question" maxlength="500" class="block w-full rounded-md border-gray-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="添加一个用户会问的问题">
                                        <button type="submit" class="shrink-0 rounded-md bg-blue-600 px-2 py-1 text-xs font-medium text-white hover:bg-blue-700">保存</button>
                                    </form>
                                    <div id="question-status-{{ (int) $keyword->id }}" class="text-xs text-gray-500"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                @if ($keywords->lastPage() > 1)
                    <div class="px-6 py-4 border-t border-gray-200">
                        <div class="flex items-center justify-between">
                            <div class="text-sm text-gray-700">
                                {{ __('admin.keyword_detail.pagination', ['start' => $keywords->firstItem(), 'end' => $keywords->lastItem(), 'total' => $keywords->total()]) }}
                            </div>
                            <div>
                                {{ $keywords->links() }}
                            </div>
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </div>

    <form method="POST" action="{{ route('admin.keyword-libraries.keywords.delete', ['libraryId' => (int) $library->id]) }}" id="single-delete-form" class="hidden">
        @csrf
        <input type="hidden" name="keyword_ids[]" id="single-delete-keyword-id" value="">
    </form>

    <div id="add-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-10 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('admin.keyword_detail.modal_add') }}</h3>
                <form method="POST" action="{{ route('admin.keyword-libraries.keywords.store', ['libraryId' => (int) $library->id]) }}">
                    @csrf
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.keyword_detail.field_keyword') }}</label>
                            <input type="text" name="keyword" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="{{ __('admin.keyword_detail.placeholder_keyword') }}">
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideAddModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            {{ __('admin.button.cancel') }}
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                            {{ __('admin.button.add') }}
                        </button>
                    </div>
                </form>

                <div class="my-6 border-t border-gray-200"></div>

                <div class="space-y-4">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900">AI 生成相关关键词</h4>
                            <p class="mt-1 text-xs text-gray-500">输入一个种子词，生成适合 GEO 抓取、AI 搜索引用和内容选题的相关关键词。</p>
                        </div>
                        <i data-lucide="sparkles" class="w-5 h-5 text-blue-600"></i>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-[1fr_120px] gap-3">
                        <div>
                            <label for="ai-seed-keyword" class="block text-sm font-medium text-gray-700">种子关键词</label>
                            <input type="text" id="ai-seed-keyword" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="例如：GEO优化">
                        </div>
                        <div>
                            <label for="ai-keyword-count" class="block text-sm font-medium text-gray-700">数量</label>
                            <input type="number" id="ai-keyword-count" min="1" max="100" value="20" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-3">
                        <div id="ai-keyword-status" class="text-sm text-gray-500" aria-live="polite"></div>
                        <button type="button" id="ai-keyword-generate-button" onclick="generateKeywordSuggestions()" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed">
                            <i data-lucide="sparkles" class="w-4 h-4 mr-2"></i>
                            生成相关词
                        </button>
                    </div>

                    <form method="POST" action="{{ route('admin.keyword-libraries.keywords.bulk-store', ['libraryId' => (int) $library->id]) }}" id="ai-keyword-bulk-form" class="hidden">
                        @csrf
                        <div class="rounded-md border border-gray-200 bg-gray-50">
                            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200">
                                <label class="inline-flex items-center text-sm font-medium text-gray-700">
                                    <input type="checkbox" id="ai-keyword-select-all" checked class="rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500" onchange="toggleAllKeywordSuggestions()">
                                    <span class="ml-2">全选建议词</span>
                                </label>
                                <span id="ai-keyword-selected-count" class="text-xs text-gray-500"></span>
                            </div>
                            <div id="ai-keyword-suggestions" class="max-h-64 overflow-y-auto p-4 grid grid-cols-1 sm:grid-cols-2 gap-2"></div>
                        </div>
                        <div class="mt-4 flex justify-end">
                            <button type="submit" onclick="return prepareKeywordSuggestionSubmit()" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                                <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                                加入关键词库
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div id="edit-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('admin.keyword_detail.modal_edit') }}</h3>
                <form method="POST" action="{{ route('admin.keyword-libraries.detail.update', ['libraryId' => (int) $library->id]) }}">
                    @csrf
                    @method('PUT')
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.keyword_detail.field_name') }}</label>
                            <input type="text" name="name" required value="{{ old('name', (string) $library->name) }}" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.keyword_detail.field_description') }}</label>
                            <textarea name="description" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">{{ old('description', (string) ($library->description ?? '')) }}</textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">公司/品牌</label>
                            <input type="text" name="company_name" value="{{ old('company_name', (string) ($library->company_name ?? '')) }}" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">领域关键词</label>
                                <input type="text" name="domain_keyword" value="{{ old('domain_keyword', (string) ($library->domain_keyword ?? '')) }}" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">行业</label>
                                <input type="text" name="industry" value="{{ old('industry', (string) ($library->industry ?? '')) }}" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">品牌描述</label>
                            <textarea name="brand_description" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">{{ old('brand_description', (string) ($library->brand_description ?? '')) }}</textarea>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-between space-x-3">
                        <button type="button" onclick="showImportModal()" class="px-4 py-2 border border-blue-200 rounded-md text-sm font-medium text-blue-700 bg-blue-50 hover:bg-blue-100">
                            {{ __('admin.button.import') }}
                        </button>
                        <div class="space-x-3">
                            <button type="button" onclick="hideEditModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                                {{ __('admin.button.cancel') }}
                            </button>
                            <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                                {{ __('admin.button.save') }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="import-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-10 mx-auto p-5 border w-2/3 max-w-2xl shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('admin.keyword_libraries.modal_import') }} <span class="text-blue-600">{{ $library->name }}</span></h3>
                <form method="POST" action="{{ route('admin.keyword-libraries.import', ['libraryId' => (int) $library->id]) }}">
                    @csrf
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.keyword_libraries.field_keywords') }}</label>
                            <textarea name="keywords_text" rows="10" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm" placeholder="{{ __('admin.keyword_libraries.placeholder_keywords') }}"></textarea>
                        </div>
                        <div class="text-sm text-gray-500">
                            <p class="mb-2">{{ __('admin.keyword_libraries.format_title') }}</p>
                            <ul class="list-disc list-inside space-y-1">
                                <li>{{ __('admin.keyword_libraries.format_line') }}</li>
                                <li>{{ __('admin.keyword_libraries.format_comma') }}</li>
                                <li>{{ __('admin.keyword_libraries.format_dedupe') }}</li>
                            </ul>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideImportModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            {{ __('admin.button.cancel') }}
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                            {{ __('admin.keyword_libraries.import_button') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://js.pusher.com/8.4.0/pusher.min.js"></script>
    <script>
        const KEYWORD_INCLUSION_REALTIME = @json($inclusionRealtime ?? ['enabled' => false]);

        async function refreshInclusionSnapshot() {
            if (!KEYWORD_INCLUSION_REALTIME.snapshot_url) {
                return;
            }

            try {
                const response = await fetch(KEYWORD_INCLUSION_REALTIME.snapshot_url, {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const data = await response.json();
                if (!response.ok || !data.success) {
                    return;
                }

                const runsPanel = document.getElementById('inclusion-runs-panel');
                if (runsPanel && typeof data.runs_html === 'string') {
                    runsPanel.outerHTML = data.runs_html;
                }

                const dailyReportsPanel = document.getElementById('inclusion-daily-reports-panel');
                if (dailyReportsPanel && typeof data.daily_reports_html === 'string') {
                    dailyReportsPanel.outerHTML = data.daily_reports_html;
                }

                if (window.lucide) {
                    window.lucide.createIcons();
                }
            } catch (error) {
                console.warn('Inclusion snapshot refresh failed', error);
            }
        }

        function initKeywordInclusionRealtime() {
            if (!KEYWORD_INCLUSION_REALTIME.enabled || !KEYWORD_INCLUSION_REALTIME.key || !KEYWORD_INCLUSION_REALTIME.channel || typeof window.Pusher === 'undefined') {
                return;
            }

            const pusher = new window.Pusher(KEYWORD_INCLUSION_REALTIME.key, {
                cluster: 'mt1',
                wsHost: KEYWORD_INCLUSION_REALTIME.host,
                wsPort: KEYWORD_INCLUSION_REALTIME.port || 80,
                wssPort: KEYWORD_INCLUSION_REALTIME.port || 443,
                forceTLS: KEYWORD_INCLUSION_REALTIME.scheme === 'https',
                enabledTransports: ['ws', 'wss'],
                authEndpoint: @js(url('/broadcasting/auth')),
                auth: {
                    headers: {
                        'X-CSRF-TOKEN': @js(csrf_token()),
                    },
                },
            });

            const channel = pusher.subscribe(`private-${KEYWORD_INCLUSION_REALTIME.channel}`);
            channel.bind('keyword-library.inclusion.updated', () => {
                refreshInclusionSnapshot();
            });
        }

        function showAddModal() {
            document.getElementById('add-modal').classList.remove('hidden');
        }

        function hideAddModal() {
            document.getElementById('add-modal').classList.add('hidden');
        }

        let aiKeywordSuggestions = [];

        function setAiKeywordStatus(message, isError = false) {
            const status = document.getElementById('ai-keyword-status');
            status.textContent = message || '';
            status.className = isError ? 'text-sm text-red-600' : 'text-sm text-gray-500';
        }

        function setAiKeywordLoading(isLoading) {
            const button = document.getElementById('ai-keyword-generate-button');
            button.disabled = isLoading;
            button.innerHTML = isLoading
                ? '<span class="inline-block w-4 h-4 mr-2 border-2 border-white border-t-transparent rounded-full animate-spin"></span>生成中'
                : '<i data-lucide="sparkles" class="w-4 h-4 mr-2"></i>生成相关词';
            window.lucide?.createIcons?.();
        }

        async function generateKeywordSuggestions() {
            const seedInput = document.getElementById('ai-seed-keyword');
            const countInput = document.getElementById('ai-keyword-count');
            const seedKeyword = seedInput.value.trim();
            const count = Number.parseInt(countInput.value, 10) || 20;

            if (!seedKeyword) {
                setAiKeywordStatus('请输入种子关键词', true);
                seedInput.focus();
                return;
            }

            setAiKeywordLoading(true);
            setAiKeywordStatus('正在生成适合 GEO 抓取的相关关键词...');
            document.getElementById('ai-keyword-bulk-form').classList.add('hidden');

            try {
                const response = await fetch(@json(route('admin.keyword-libraries.keywords.suggest', ['libraryId' => (int) $library->id])), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || @json(csrf_token()),
                    },
                    body: JSON.stringify({
                        seed_keyword: seedKeyword,
                        count,
                    }),
                });
                const data = await response.json();

                if (!response.ok || !data.success) {
                    throw new Error(data.message || '关键词生成失败，请稍后重试');
                }

                aiKeywordSuggestions = Array.isArray(data.suggestions) ? data.suggestions : [];
                if (aiKeywordSuggestions.length <= 0) {
                    throw new Error('AI 未返回可用关键词，请换一个种子词重试');
                }

                renderKeywordSuggestions();
                setAiKeywordStatus(`已生成 ${aiKeywordSuggestions.length} 个建议词，可取消不需要的词后入库。`);
            } catch (error) {
                aiKeywordSuggestions = [];
                renderKeywordSuggestions();
                setAiKeywordStatus(error instanceof Error ? error.message : '关键词生成失败，请稍后重试', true);
            } finally {
                setAiKeywordLoading(false);
            }
        }

        function renderKeywordSuggestions() {
            const form = document.getElementById('ai-keyword-bulk-form');
            const container = document.getElementById('ai-keyword-suggestions');
            container.innerHTML = '';

            if (aiKeywordSuggestions.length <= 0) {
                form.classList.add('hidden');
                updateAiKeywordSelectedCount();
                return;
            }

            aiKeywordSuggestions.forEach((keyword, index) => {
                const label = document.createElement('label');
                label.className = 'flex items-start gap-2 rounded border border-gray-200 bg-white px-3 py-2 text-sm text-gray-800';

                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.className = 'ai-keyword-checkbox mt-0.5 rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500';
                checkbox.checked = true;
                checkbox.value = keyword;
                checkbox.addEventListener('change', updateAiKeywordSelectedCount);

                const text = document.createElement('span');
                text.className = 'break-all';
                text.textContent = keyword;

                label.appendChild(checkbox);
                label.appendChild(text);
                container.appendChild(label);
            });

            document.getElementById('ai-keyword-select-all').checked = true;
            form.classList.remove('hidden');
            updateAiKeywordSelectedCount();
        }

        function toggleAllKeywordSuggestions() {
            const checked = document.getElementById('ai-keyword-select-all').checked;
            document.querySelectorAll('.ai-keyword-checkbox').forEach((checkbox) => {
                checkbox.checked = checked;
            });
            updateAiKeywordSelectedCount();
        }

        function updateAiKeywordSelectedCount() {
            const selected = document.querySelectorAll('.ai-keyword-checkbox:checked').length;
            const total = document.querySelectorAll('.ai-keyword-checkbox').length;
            const counter = document.getElementById('ai-keyword-selected-count');
            if (counter) {
                counter.textContent = total > 0 ? `已选择 ${selected}/${total}` : '';
            }
        }

        function prepareKeywordSuggestionSubmit() {
            const form = document.getElementById('ai-keyword-bulk-form');
            form.querySelectorAll('input[name="keywords[]"]').forEach((input) => input.remove());

            const selected = Array.from(document.querySelectorAll('.ai-keyword-checkbox:checked'))
                .map((checkbox) => checkbox.value)
                .filter((keyword) => keyword.trim() !== '');

            if (selected.length <= 0) {
                setAiKeywordStatus('请至少选择一个关键词', true);
                return false;
            }

            selected.forEach((keyword) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'keywords[]';
                input.value = keyword;
                form.appendChild(input);
            });

            return true;
        }

        async function generateQuestionVariants(keywordId) {
            const status = document.getElementById(`question-status-${keywordId}`);
            if (status) {
                status.textContent = '正在生成问题变体...';
                status.className = 'text-xs text-gray-500';
            }

            const urlTemplate = @json(route('admin.keyword-libraries.keywords.questions.generate', ['libraryId' => (int) $library->id, 'keywordId' => '__KEYWORD_ID__']));
            try {
                const response = await fetch(urlTemplate.replace('__KEYWORD_ID__', String(keywordId)), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || @json(csrf_token()),
                    },
                    body: JSON.stringify({ count: 5 }),
                });
                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.message || '问题变体生成失败');
                }

                if (status) {
                    status.textContent = `已生成 ${Array.isArray(data.questions) ? data.questions.length : 0} 个问题，刷新后可查看`;
                    status.className = 'text-xs text-green-600';
                }
            } catch (error) {
                if (status) {
                    status.textContent = error instanceof Error ? error.message : '问题变体生成失败';
                    status.className = 'text-xs text-red-600';
                }
            }
        }

        function showEditModal() {
            document.getElementById('edit-modal').classList.remove('hidden');
        }

        function hideEditModal() {
            document.getElementById('edit-modal').classList.add('hidden');
        }

        function showImportModal() {
            document.getElementById('import-modal').classList.remove('hidden');
        }

        function hideImportModal() {
            document.getElementById('import-modal').classList.add('hidden');
        }

        function toggleBatchActions() {
            const batchActions = document.getElementById('batch-actions');
            const checkboxes = document.querySelectorAll('.keyword-checkbox');
            const isHidden = batchActions.classList.contains('hidden');

            if (isHidden) {
                batchActions.classList.remove('hidden');
                checkboxes.forEach((checkbox) => checkbox.classList.remove('hidden'));
            } else {
                batchActions.classList.add('hidden');
                checkboxes.forEach((checkbox) => {
                    checkbox.classList.add('hidden');
                    checkbox.checked = false;
                });
                updateSelectedCount();
            }
        }

        function updateSelectedCount() {
            const selected = document.querySelectorAll('.keyword-checkbox:checked').length;
            const text = @json(__('admin.keyword_detail.selected_count', ['count' => '{count}'])).replace('{count}', String(selected));
            const counter = document.getElementById('selected-keyword-count');
            if (counter) {
                counter.textContent = text;
            }
        }

        function deleteKeyword(keywordId, keywordName) {
            const confirmed = confirm(@json(__('admin.keyword_detail.confirm_delete_keyword', ['name' => '{name}'])).replace('{name}', keywordName));
            if (!confirmed) {
                return;
            }

            document.getElementById('single-delete-keyword-id').value = String(keywordId);
            document.getElementById('single-delete-form').submit();
        }

        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.keyword-checkbox').forEach((checkbox) => {
                checkbox.addEventListener('change', updateSelectedCount);
            });

            initKeywordInclusionRealtime();

            const batchForm = document.getElementById('batch-form');
            if (batchForm) {
                batchForm.addEventListener('submit', function (event) {
                    const selected = document.querySelectorAll('.keyword-checkbox:checked').length;
                    if (selected <= 0) {
                        event.preventDefault();
                        alert(@json(__('admin.keyword_detail.error.select_required')));
                        return;
                    }

                    const confirmed = confirm(@json(__('admin.keyword_detail.confirm_delete_selected', ['count' => '{count}'])).replace('{count}', String(selected)));
                    if (!confirmed) {
                        event.preventDefault();
                    }
                });
            }
        });

        window.onclick = function (event) {
            const addModal = document.getElementById('add-modal');
            const editModal = document.getElementById('edit-modal');
            const importModal = document.getElementById('import-modal');

            if (event.target === addModal) {
                hideAddModal();
            }
            if (event.target === editModal) {
                hideEditModal();
            }
            if (event.target === importModal) {
                hideImportModal();
            }
        };
    </script>
@endpush
