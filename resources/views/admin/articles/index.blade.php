@extends('admin.layouts.app')

@php
    $isTrashView = (bool) ($isTrashView ?? false);
    $selectedTaskId = (int) ($filters['task_id'] ?? 0);
    $selectedStatus = (string) ($filters['status'] ?? '');
    $selectedReviewStatus = (string) ($filters['review_status'] ?? '');
    $selectedCategoryId = (int) ($filters['category_id'] ?? 0);
    $selectedAuthorId = (int) ($filters['author_id'] ?? 0);
    $selectedDateFrom = (string) ($filters['date_from'] ?? '');
    $selectedDateTo = (string) ($filters['date_to'] ?? '');
    $selectedSearch = (string) ($filters['search'] ?? '');
    $selectedPerPage = (int) ($filters['per_page'] ?? 20);
    $selectedTaskName = '';
    foreach ($tasks as $taskOption) {
        if ((int) ($taskOption['id'] ?? 0) === $selectedTaskId) {
            $selectedTaskName = (string) ($taskOption['name'] ?? '');
            break;
        }
    }
    $categoryManageUrl = route('admin.categories.index');
    $reviewCenterUrl = route('admin.articles.index', ['review_status' => 'pending']);
    $trashUrl = route('admin.articles.index', ['trashed' => 1]);
    $articlesIndexUrl = route('admin.articles.index');
    $clearTaskFilterUrl = route('admin.articles.index', request()->except(['task_id', 'page']));
    $selfMediaArticleAccounts = collect($selfMediaArticleAccounts ?? []);
    $selfMediaPlatformLabels = (array) ($selfMediaPlatformLabels ?? []);
    $canOperateArticles = (bool) ($canOperateArticles ?? true);
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ $pageTitle }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ $isTrashView ? __('admin.articles.trash.subtitle') : __('admin.articles.page_subtitle') }}</p>
            </div>
            @if($canOperateArticles)
            <div class="flex flex-wrap gap-2 justify-end">
                @if($isTrashView)
                    <a href="{{ $articlesIndexUrl }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.articles.trash.back') }}
                    </a>
                    <button type="button" onclick="submitEmptyTrash()" class="inline-flex items-center px-4 py-2 border border-red-200 text-sm font-medium rounded-md text-red-700 bg-white hover:bg-red-50">
                        <i data-lucide="trash-2" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.articles.trash.empty') }}
                    </button>
                @else
                    <a href="{{ route('admin.articles.create') }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                        <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.button.create_article') }}
                    </a>
                    <a href="{{ $categoryManageUrl }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        <i data-lucide="folder" class="w-4 h-4 mr-2"></i>
                        {{ __('admin.button.category_manage') }}
                    </a>
                    <a href="{{ $reviewCenterUrl }}" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                        <i data-lucide="eye" class="w-4 h-4 mr-1"></i>
                        {{ __('admin.button.review_center') }}
                    </a>
                @endif
                <a href="{{ $isTrashView ? $articlesIndexUrl : $trashUrl }}" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                    <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                    {{ $isTrashView ? __('admin.articles.page_title') : __('admin.button.trash') }}
                </a>
                <button type="button" onclick="toggleBatchActions()" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                    <i data-lucide="check-square" class="w-4 h-4 mr-1"></i>
                    {{ __('admin.button.bulk_actions') }}
                </button>
            </div>
            @endif
        </div>

        @if($isTrashView)
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white overflow-hidden shadow rounded-lg md:col-span-1">
                <div class="p-5">
                    <div class="flex items-center">
                        <i data-lucide="archive" class="h-6 w-6 text-orange-600"></i>
                        <div class="ml-5">
                            <div class="text-sm text-gray-500">{{ __('admin.articles.trash.stats_total') }}</div>
                            <div class="text-2xl font-semibold text-gray-900">{{ (int) ($stats['trashed_total'] ?? 0) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @else
        <div class="grid grid-cols-1 md:grid-cols-5 gap-6 mb-8">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <i data-lucide="file-text" class="h-6 w-6 text-blue-600"></i>
                        <div class="ml-5">
                            <div class="text-sm text-gray-500">{{ __('admin.articles.stats.total') }}</div>
                            <div class="text-2xl font-semibold text-gray-900">{{ (int) ($stats['total'] ?? 0) }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <i data-lucide="globe" class="h-6 w-6 text-green-600"></i>
                        <div class="ml-5">
                            <div class="text-sm text-gray-500">{{ __('admin.articles.stats.published') }}</div>
                            <div class="text-2xl font-semibold text-gray-900">{{ (int) ($stats['published'] ?? 0) }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <i data-lucide="edit" class="h-6 w-6 text-yellow-600"></i>
                        <div class="ml-5">
                            <div class="text-sm text-gray-500">{{ __('admin.articles.stats.draft') }}</div>
                            <div class="text-2xl font-semibold text-gray-900">{{ (int) ($stats['draft'] ?? 0) }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <i data-lucide="eye" class="h-6 w-6 text-purple-600"></i>
                        <div class="ml-5">
                            <div class="text-sm text-gray-500">{{ __('admin.articles.stats.pending_review') }}</div>
                            <div class="text-2xl font-semibold text-gray-900">{{ (int) ($stats['pending_review'] ?? 0) }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <i data-lucide="calendar" class="h-6 w-6 text-orange-600"></i>
                        <div class="ml-5">
                            <div class="text-sm text-gray-500">{{ __('admin.articles.stats.today') }}</div>
                            <div class="text-2xl font-semibold text-gray-900">{{ (int) ($stats['today'] ?? 0) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @endif

        <div class="bg-white shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">{{ __('admin.articles.filters.title') }}</h3>
            </div>
            <div class="px-6 py-4">
                @if($selectedTaskId > 0)
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                        <div class="inline-flex items-center gap-2">
                            <i data-lucide="filter" class="h-4 w-4"></i>
                            <span>{{ __('admin.articles.filters.current_task', ['task' => $selectedTaskName !== '' ? $selectedTaskName : '#'.$selectedTaskId]) }}</span>
                        </div>
                        <a href="{{ $clearTaskFilterUrl }}" class="inline-flex items-center font-medium text-blue-700 hover:text-blue-900">
                            <i data-lucide="x" class="mr-1 h-4 w-4"></i>
                            {{ __('admin.articles.filters.clear_task') }}
                        </a>
                    </div>
                @endif
                <form method="GET" class="space-y-4">
                    @if($isTrashView)
                        <input type="hidden" name="trashed" value="1">
                    @endif
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-7">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.articles.filters.task') }}</label>
                            <select name="task_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                <option value="">{{ __('admin.articles.filters.all_tasks') }}</option>
                                @foreach($tasks as $task)
                                    <option value="{{ (int) $task['id'] }}" @selected($selectedTaskId === (int) $task['id'])>{{ $task['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.articles.filters.category') }}</label>
                            <select name="category_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                <option value="">{{ __('admin.articles.filters.all_categories') }}</option>
                                @foreach($categories as $category)
                                    <option value="{{ (int) $category['id'] }}" @selected($selectedCategoryId === (int) $category['id'])>{{ $category['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if(!$isTrashView)
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.articles.filters.status') }}</label>
                            <select name="status" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                <option value="">{{ __('admin.articles.filters.all_status') }}</option>
                                <option value="draft" @selected($selectedStatus === 'draft')>{{ __('admin.articles.status.draft') }}</option>
                                <option value="published" @selected($selectedStatus === 'published')>{{ __('admin.articles.status.published') }}</option>
                                <option value="private" @selected($selectedStatus === 'private')>{{ __('admin.articles.status.private') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.articles.filters.review_status') }}</label>
                            <select name="review_status" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                <option value="">{{ __('admin.articles.filters.all_review') }}</option>
                                <option value="pending" @selected($selectedReviewStatus === 'pending')>{{ __('admin.articles.review.pending') }}</option>
                                <option value="approved" @selected($selectedReviewStatus === 'approved')>{{ __('admin.articles.review.approved') }}</option>
                                <option value="rejected" @selected($selectedReviewStatus === 'rejected')>{{ __('admin.articles.review.rejected') }}</option>
                                <option value="auto_approved" @selected($selectedReviewStatus === 'auto_approved')>{{ __('admin.articles.review.auto_approved') }}</option>
                            </select>
                        </div>
                        @endif
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.articles.filters.author') }}</label>
                            <select name="author_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                <option value="">{{ __('admin.articles.filters.all_authors') }}</option>
                                @foreach($authors as $author)
                                    <option value="{{ (int) $author['id'] }}" @selected($selectedAuthorId === (int) $author['id'])>{{ $author['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.articles.filters.date_from') }}</label>
                            <div class="mt-1">
                                <input type="date" name="date_from" value="{{ $selectedDateFrom }}" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.articles.filters.date_to') }}</label>
                            <div class="mt-1">
                                <input type="date" name="date_to" value="{{ $selectedDateTo }}" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                            </div>
                        </div>
                    </div>
                    <div class="flex items-end space-x-4">
                        <div class="flex-1">
                            <label class="block text-sm font-medium text-gray-700">{{ __('admin.articles.filters.search') }}</label>
                            <input type="text" name="search" value="{{ $selectedSearch }}" placeholder="{{ __('admin.articles.filters.search_placeholder') }}" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                        <div class="flex space-x-2">
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                                <i data-lucide="search" class="w-4 h-4 mr-2"></i>
                                {{ __('admin.button.search') }}
                            </button>
                            <a href="{{ $isTrashView ? route('admin.articles.index', ['trashed' => 1]) : route('admin.articles.index') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                <i data-lucide="x" class="w-4 h-4 mr-2"></i>
                                {{ __('admin.button.clear') }}
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-medium text-gray-900">
                        {{ $isTrashView ? __('admin.articles.trash.list_title') : __('admin.articles.list_title') }}
                        <span class="text-sm text-gray-500">{{ __('admin.articles.list_total', ['count' => $articles->total()]) }}</span>
                    </h3>
                    @if($canOperateArticles)
                    <div class="flex flex-wrap gap-2">
                        @if(!$isTrashView)
                        <a href="{{ route('admin.articles.create') }}" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-blue-600 hover:bg-blue-700">
                            <i data-lucide="plus" class="w-4 h-4 mr-1"></i>
                            {{ __('admin.button.create_article') }}
                        </a>
                        <a href="{{ $reviewCenterUrl }}" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="eye" class="w-4 h-4 mr-1"></i>
                            {{ __('admin.button.review_center') }}
                        </a>
                        @endif
                        <a href="{{ $isTrashView ? $articlesIndexUrl : $trashUrl }}" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                            {{ $isTrashView ? __('admin.articles.page_title') : __('admin.button.trash') }}
                        </a>
                        <button type="button" onclick="toggleBatchActions()" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="check-square" class="w-4 h-4 mr-1"></i>
                            {{ __('admin.button.bulk_actions') }}
                        </button>
                    </div>
                    @endif
                </div>
            </div>

            @if($articles->isEmpty())
                <div class="px-6 py-8 text-center">
                    <i data-lucide="inbox" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">{{ $isTrashView ? __('admin.articles.trash.empty_title') : __('admin.articles.empty_title') }}</h3>
                    <p class="text-gray-500 mb-4">{{ $isTrashView ? __('admin.articles.trash.empty_desc') : __('admin.articles.empty_desc') }}</p>
                    @if($isTrashView)
                        <a href="{{ $articlesIndexUrl }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.articles.trash.back') }}
                        </a>
                    @elseif($canOperateArticles)
                        <a href="{{ route('admin.tasks.create') }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                            {{ __('admin.button.generate_articles') }}
                        </a>
                    @endif
                </div>
            @else
                @if($canOperateArticles)
                <div id="batch-actions" class="hidden px-6 py-3 bg-gray-50 border-b border-gray-200">
                    <form method="POST" action="{{ route('admin.articles.batch.update-status') }}" id="batch-form">
                        @csrf
                        <div id="batch-selected-ids"></div>
                        <div class="flex items-center space-x-4">
                            <span class="text-sm text-gray-600">
                                @if(__('admin.articles.bulk.selected_prefix') !== '')
                                    <span>{{ __('admin.articles.bulk.selected_prefix') }}</span>
                                @endif
                                <span id="selected-count">0</span>
                                <span>{{ __('admin.articles.bulk.selected_suffix') }}</span>
                            </span>
                            <select name="action" id="batch-action" class="border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                <option value="">{{ __('admin.articles.bulk.select_action') }}</option>
                                @if($isTrashView)
                                    <option value="batch_restore">{{ __('admin.articles.trash.action_restore') }}</option>
                                    <option value="batch_force_delete">{{ __('admin.articles.trash.action_force_delete') }}</option>
                                @else
                                    <option value="batch_update_status">{{ __('admin.articles.bulk.status_to') }}</option>
                                    <option value="batch_update_review">{{ __('admin.articles.bulk.review_to') }}</option>
                                    <option value="delete_articles">{{ __('admin.articles.bulk.delete') }}</option>
                                @endif
                            </select>
                            @if(!$isTrashView)
                            <select name="new_status" id="status-select" class="hidden border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                <option value="draft">{{ __('admin.articles.status.draft') }}</option>
                                <option value="published">{{ __('admin.articles.status.published') }}</option>
                                <option value="private">{{ __('admin.articles.status.private') }}</option>
                            </select>
                            <select name="review_status" id="review-select" class="hidden border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                <option value="pending">{{ __('admin.articles.review.pending') }}</option>
                                <option value="approved">{{ __('admin.articles.review.approved') }}</option>
                                <option value="rejected">{{ __('admin.articles.review.rejected') }}</option>
                                <option value="auto_approved">{{ __('admin.articles.review.auto_approved') }}</option>
                            </select>
                            @endif
                            <button type="submit" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-blue-600 hover:bg-blue-700">
                                {{ __('admin.button.execute') }}
                            </button>
                            <button type="button" onclick="toggleBatchActions()" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                                {{ __('admin.button.cancel') }}
                            </button>
                        </div>
                    </form>
                </div>
                @endif

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                        <tr>
                            @if($canOperateArticles)
                            <th class="batch-checkbox hidden px-6 py-3 text-left">
                                <input type="checkbox" id="select-all" class="rounded border-gray-300 text-blue-600 shadow-sm">
                            </th>
                            @endif
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.articles.column.id') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.articles.column.info') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.articles.column.task_author') }}</th>
                            @if(!$isTrashView)
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.articles.column.workflow') }}</th>
                            @endif
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ $isTrashView ? __('admin.articles.trash.column.deleted_at') : __('admin.articles.column.created_at') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('admin.articles.column.actions') }}</th>
                        </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($articles as $article)
                            @php
                                $statusClass = match((string) $article->status) {
                                    'published' => 'bg-green-100 text-green-800 border border-green-200',
                                    'draft' => 'bg-amber-100 text-amber-800 border border-amber-200',
                                    default => 'bg-gray-100 text-gray-700 border border-gray-200'
                                };
                                $reviewClass = match((string) $article->review_status) {
                                    'approved' => 'bg-emerald-100 text-emerald-800 border border-emerald-200',
                                    'auto_approved' => 'bg-sky-100 text-sky-800 border border-sky-200',
                                    'rejected' => 'bg-red-100 text-red-800 border border-red-200',
                                    default => 'bg-yellow-100 text-yellow-800 border border-yellow-200'
                                };
                                $distributionTotal = (int) ($article->distribution_total_count ?? 0);
                                $distributionSynced = (int) ($article->distribution_synced_count ?? 0);
                                $distributionFailed = (int) ($article->distribution_failed_count ?? 0);
                                $distributionPending = max(0, $distributionTotal - $distributionSynced - $distributionFailed);
                                $distributionBadge = null;
                                if (!$isTrashView && $distributionTotal > 0) {
                                    if ($distributionFailed > 0) {
                                        $distributionBadge = [
                                            'label' => __('admin.distribution.article_status.failed'),
                                            'detail' => $distributionFailed.'/'.$distributionTotal,
                                            'class' => 'bg-red-50 text-red-700 ring-red-100',
                                        ];
                                    } elseif ($distributionSynced >= $distributionTotal) {
                                        $distributionBadge = [
                                            'label' => __('admin.distribution.article_status.synced'),
                                            'detail' => $distributionSynced.'/'.$distributionTotal,
                                            'class' => 'bg-emerald-50 text-emerald-700 ring-emerald-100',
                                        ];
                                    } else {
                                        $distributionBadge = [
                                            'label' => __('admin.distribution.article_status.queued'),
                                            'detail' => $distributionPending.'/'.$distributionTotal,
                                            'class' => 'bg-sky-50 text-sky-700 ring-sky-100',
                                        ];
                                    }
                                }
                            @endphp
                            <tr class="hover:bg-gray-50">
                                @if($canOperateArticles)
                                <td class="batch-checkbox hidden px-6 py-4">
                                    <input type="checkbox" value="{{ (int) $article->id }}" class="article-checkbox rounded border-gray-300 text-blue-600 shadow-sm">
                                </td>
                                @endif
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-mono">#{{ (int) $article->id }}</td>
                                <td class="px-6 py-4">
                                    <div class="text-sm font-medium text-gray-900 truncate">
                                        @if($isTrashView || ! $canOperateArticles)
                                            <span>{{ $article->title }}</span>
                                        @else
                                            <a href="{{ route('admin.articles.edit', ['articleId' => (int) $article->id]) }}" class="hover:text-blue-600">{{ $article->title }}</a>
                                        @endif
                                    </div>
                                    @if((string) ($article->excerpt ?? '') !== '')
                                        <p class="text-xs text-gray-500 mt-1">{{ \Illuminate\Support\Str::limit((string) $article->excerpt, 100) }}</p>
                                    @endif
                                    @if((string) ($article->keywords ?? '') !== '')
                                        <div class="text-xs text-blue-600 mt-1">{{ __('admin.articles.keywords') }}: {{ $article->keywords }}</div>
                                    @endif
                                    @if(!$isTrashView && (!empty($article->is_hot) || !empty($article->is_featured)))
                                        <div class="mt-2 flex flex-wrap gap-1.5">
                                            @if(!empty($article->is_hot))
                                                <span class="inline-flex items-center rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 ring-1 ring-red-100">{{ __('admin.articles.badge.hot') }}</span>
                                            @endif
                                            @if(!empty($article->is_featured))
                                                <span class="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 ring-1 ring-blue-100">{{ __('admin.articles.badge.featured') }}</span>
                                            @endif
                                        </div>
                                    @endif
                                    @if($distributionBadge !== null)
                                        <div class="mt-2">
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 {{ $distributionBadge['class'] }}">
                                                <i data-lucide="send" class="mr-1 h-3 w-3"></i>
                                                {{ $distributionBadge['label'] }}
                                                <span class="ml-1 font-mono text-[11px] opacity-80">{{ $distributionBadge['detail'] }}</span>
                                            </span>
                                        </div>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    @if((string) ($article->task->name ?? '') !== '')
                                        <div class="text-blue-600">{{ $article->task->name }}</div>
                                    @endif
                                    <div>{{ $article->author->name ?? '' }}</div>
                                    @if((int) ($article->is_ai_generated ?? 0) === 1)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800">{{ __('admin.articles.ai_generated') }}</span>
                                    @endif
                                </td>
                                @if(!$isTrashView)
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex flex-col gap-1">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $statusClass }}">
                                            {{ __('admin.articles.publish_prefix') }}: {{ __('admin.articles.status.'.(string) $article->status) }}
                                        </span>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $reviewClass }}">
                                            {{ __('admin.articles.review_prefix') }}: {{ __('admin.articles.review.'.(string) $article->review_status) }}
                                        </span>
                                    </div>
                                </td>
                                @endif
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    @if($isTrashView)
                                        <div>{{ optional($article->deleted_at)->format('Y-m-d H:i') }}</div>
                                        <div class="text-xs text-gray-400">{{ __('admin.articles.trash.created_prefix') }} {{ optional($article->created_at)->format('m-d H:i') }}</div>
                                    @else
                                        <div>{{ optional($article->created_at)->format('m-d H:i') }}</div>
                                        @if($article->published_at)
                                            <div class="text-xs text-green-600">{{ __('admin.articles.published_at', ['time' => $article->published_at->format('m-d H:i')]) }}</div>
                                        @endif
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    @if($isTrashView)
                                        @if($canOperateArticles)
                                        <div class="flex items-center space-x-2">
                                            <form method="POST" action="{{ route('admin.articles.restore', ['articleId' => (int) $article->id]) }}" class="inline" onsubmit="return confirm(@json(__('admin.articles.trash.confirm_restore')))">
                                                @csrf
                                                <button type="submit" class="text-green-600 hover:text-green-800" title="{{ __('admin.articles.trash.action_restore') }}">
                                                    <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('admin.articles.force-delete', ['articleId' => (int) $article->id]) }}" class="inline" onsubmit="return confirm(@json(__('admin.articles.trash.confirm_delete')))">
                                                @csrf
                                                <button type="submit" class="text-red-600 hover:text-red-800" title="{{ __('admin.articles.trash.action_force_delete') }}">
                                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                </button>
                                            </form>
                                        </div>
                                        @else
                                            <span class="text-gray-400">-</span>
                                        @endif
                                    @else
                                        <div class="flex items-center space-x-2">
                                            @if($canOperateArticles)
                                            <a href="{{ route('admin.articles.edit', ['articleId' => (int) $article->id]) }}" class="text-green-600 hover:text-green-800" title="{{ __('admin.button.edit') }}">
                                                <i data-lucide="edit" class="w-4 h-4"></i>
                                            </a>
                                            @endif
                                            <a href="{{ route('admin.articles.download', ['articleId' => (int) $article->id]) }}" class="text-blue-600 hover:text-blue-800" title="{{ __('admin.button.download_word') }}">
                                                <i data-lucide="download" class="w-4 h-4"></i>
                                            </a>
                                            @if($canOperateArticles)
                                            @if($selfMediaArticleAccounts->isNotEmpty())
                                                <button
                                                    type="button"
                                                    class="js-self-media-publish text-indigo-600 hover:text-indigo-800"
                                                    data-action="{{ route('admin.articles.self-media.publish', ['articleId' => (int) $article->id]) }}"
                                                    data-title="{{ (string) $article->title }}"
                                                    title="发布自媒体"
                                                >
                                                    <i data-lucide="radio-tower" class="w-4 h-4"></i>
                                                </button>
                                            @else
                                                <button type="button" class="cursor-not-allowed text-gray-300" title="请先绑定支持文章发布的自媒体账号" disabled>
                                                    <i data-lucide="radio-tower" class="w-4 h-4"></i>
                                                </button>
                                            @endif
                                            @if((string) $article->review_status === 'pending')
                                                <button type="button" onclick="quickReview({{ (int) $article->id }}, 'approved')" class="text-green-600 hover:text-green-800" title="{{ __('admin.articles.action.approve') }}">
                                                    <i data-lucide="check" class="w-4 h-4"></i>
                                                </button>
                                                <button type="button" onclick="quickReview({{ (int) $article->id }}, 'rejected')" class="text-red-600 hover:text-red-800" title="{{ __('admin.articles.action.reject') }}">
                                                    <i data-lucide="x" class="w-4 h-4"></i>
                                                </button>
                                            @endif
                                            <button type="button" onclick="deleteArticle({{ (int) $article->id }})" class="text-red-600 hover:text-red-800" title="{{ __('admin.button.delete') }}">
                                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                                            </button>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="px-6 py-4 border-t border-gray-200">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div class="text-sm text-gray-700">
                            {{ __('admin.articles.pagination.summary', ['from' => $articles->firstItem() ?? 0, 'to' => $articles->lastItem() ?? 0, 'total' => $articles->total()]) }}
                            @if($articles->lastPage() > 1)
                                <span class="ml-2 text-gray-500">{{ __('admin.articles.pagination.pages', ['page' => $articles->currentPage(), 'total_pages' => $articles->lastPage()]) }}</span>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            <form method="GET" class="flex items-center gap-2">
                                @foreach(request()->except(['per_page', 'page']) as $key => $value)
                                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                @endforeach
                                <input type="hidden" name="page" value="1">
                                <label for="per-page-input" class="text-sm text-gray-600">{{ __('admin.articles.pagination.per_page') }}</label>
                                <input id="per-page-input" type="number" name="per_page" min="10" max="100" step="1" value="{{ $selectedPerPage }}" class="w-20 rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 shadow-sm">
                                <button type="submit" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('admin.button.apply') }}</button>
                            </form>
                        </div>
                    </div>
                    <div class="mt-4">
                        {{ $articles->onEachSide(1)->links() }}
                    </div>
                </div>
            @endif
        </div>

        @if(!$isTrashView)
            <div id="self-media-publish-modal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="self-media-publish-title" role="dialog" aria-modal="true">
                <div class="flex min-h-screen items-center justify-center px-4 py-6">
                    <div class="fixed inset-0 bg-gray-900/50" onclick="closeSelfMediaPublishModal()"></div>
                    <div class="relative w-full max-w-2xl rounded-lg bg-white shadow-xl">
                        <form id="self-media-publish-form" method="POST" action="">
                            @csrf
                            <div class="border-b border-gray-200 px-6 py-4">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <h3 id="self-media-publish-title" class="text-lg font-semibold text-gray-900">发布自媒体</h3>
                                        <p class="mt-1 text-sm text-gray-500">选择已绑定的平台账号，按选择的平台数量扣自媒体发布次数。</p>
                                        <p id="self-media-publish-article-title" class="mt-2 line-clamp-2 text-sm font-medium text-gray-700"></p>
                                    </div>
                                    <button type="button" onclick="closeSelfMediaPublishModal()" class="rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                                        <i data-lucide="x" class="h-5 w-5"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="max-h-[60vh] overflow-y-auto px-6 py-5">
                                @if($selfMediaArticleAccounts->isEmpty())
                                    <div class="rounded-lg border border-dashed border-gray-300 px-4 py-8 text-center">
                                        <i data-lucide="radio-tower" class="mx-auto h-8 w-8 text-gray-300"></i>
                                        <div class="mt-3 text-sm font-medium text-gray-800">暂无可发布的自媒体账号</div>
                                        <div class="mt-1 text-xs text-gray-500">请先到自媒体账号绑定页面，绑定支持文章发布的平台。</div>
                                    </div>
                                @else
                                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        @foreach($selfMediaArticleAccounts as $account)
                                            @php
                                                $platform = (string) $account->platform;
                                                $platformLabel = (string) ($selfMediaPlatformLabels[$platform] ?? $platform);
                                                $platformLogo = (string) ($selfMediaPlatformLogos[$platform] ?? '');
                                                $accountName = trim((string) ($account->account_name ?? '')) ?: (string) $account->crebee_account_id;
                                                $avatar = trim((string) ($account->avatar ?? ''));
                                            @endphp
                                            <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-gray-200 bg-white p-3 hover:border-indigo-300 hover:bg-indigo-50/40">
                                                <input type="checkbox" name="crebee_account_ids[]" value="{{ (int) $account->id }}" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                                                <span class="relative flex h-12 w-12 shrink-0 items-center justify-center">
                                                    @if($avatar !== '')
                                                        <img src="{{ $avatar }}" alt="" class="h-11 w-11 rounded-full border border-gray-200 object-cover" referrerpolicy="no-referrer">
                                                    @else
                                                        <span class="flex h-11 w-11 items-center justify-center rounded-full bg-gray-100 text-sm font-semibold text-gray-700">
                                                            {{ mb_substr($accountName, 0, 1, 'UTF-8') }}
                                                        </span>
                                                    @endif
                                                    @if($platformLogo !== '')
                                                        <span class="absolute -bottom-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full border border-white bg-white shadow-sm">
                                                            <img src="{{ asset($platformLogo) }}" alt="{{ $platformLabel }}" class="h-4 w-4 object-contain">
                                                        </span>
                                                    @endif
                                                </span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block truncate text-sm font-medium text-gray-900">{{ $accountName }}</span>
                                                    <span class="mt-0.5 block text-xs text-gray-500">{{ $platformLabel }}</span>
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            <div class="flex items-center justify-between gap-3 border-t border-gray-200 bg-gray-50 px-6 py-4">
                                <div class="text-xs text-gray-500">一篇内容选择多个平台时，每个平台计 1 次。</div>
                                <div class="flex items-center gap-2">
                                    <button type="button" onclick="closeSelfMediaPublishModal()" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                        取消
                                    </button>
                                    <button id="self-media-publish-submit" type="submit" @disabled($selfMediaArticleAccounts->isEmpty()) class="inline-flex items-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-gray-300">
                                        <i data-lucide="send" class="mr-2 h-4 w-4"></i>
                                        确认发布
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection

@push('scripts')
    <script>
        const ARTICLES_I18N = @json($articlesI18n);
        const TRASH_I18N = @json($trashI18n);
        const IS_TRASH_VIEW = @json($isTrashView);
        const EMPTY_TRASH_URL = @json(route('admin.articles.trash.empty'));

        function openSelfMediaPublishModal(action, articleTitle) {
            const modal = document.getElementById('self-media-publish-modal');
            const form = document.getElementById('self-media-publish-form');
            if (!modal || !form) {
                return;
            }

            form.action = action;
            form.querySelectorAll('input[name="crebee_account_ids[]"]').forEach((node) => node.checked = false);
            const titleNode = document.getElementById('self-media-publish-article-title');
            if (titleNode) {
                titleNode.textContent = articleTitle ? `文章：${articleTitle}` : '';
            }
            modal.classList.remove('hidden');
        }

        function closeSelfMediaPublishModal() {
            const modal = document.getElementById('self-media-publish-modal');
            if (modal) {
                modal.classList.add('hidden');
            }
        }

        function toggleBatchActions() {
            const batchActions = document.getElementById('batch-actions');
            const checkboxes = document.querySelectorAll('.batch-checkbox');
            if (!batchActions) {
                return;
            }

            const isHidden = batchActions.classList.contains('hidden');
            if (isHidden) {
                batchActions.classList.remove('hidden');
                checkboxes.forEach((node) => node.classList.remove('hidden'));
                return;
            }

            batchActions.classList.add('hidden');
            checkboxes.forEach((node) => node.classList.add('hidden'));
            document.querySelectorAll('.article-checkbox').forEach((node) => node.checked = false);
            const selectAll = document.getElementById('select-all');
            if (selectAll) {
                selectAll.checked = false;
            }
            updateSelectedCount();
        }

        function updateSelectedCount() {
            const countElement = document.getElementById('selected-count');
            if (!countElement) {
                return;
            }
            countElement.textContent = String(document.querySelectorAll('.article-checkbox:checked').length);
        }

        const ARTICLE_BATCH_ROUTES = @json($articleBatchRoutes);

        function submitEmptyTrash() {
            if (!confirm(TRASH_I18N.confirmEmpty)) {
                return;
            }
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = EMPTY_TRASH_URL;
            form.style.display = 'none';
            form.innerHTML = `<input type="hidden" name="_token" value="{{ csrf_token() }}">`;
            document.body.appendChild(form);
            form.submit();
        }

        function submitAction(action, articleId, extra = {}) {
            const targetAction = ARTICLE_BATCH_ROUTES[action] ?? '';
            if (targetAction === '') {
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = targetAction;
            form.style.display = 'none';
            form.innerHTML = `
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                <input type="hidden" name="article_ids[]" value="${articleId}">
            `;
            Object.entries(extra).forEach(([key, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = String(value);
                form.appendChild(input);
            });
            document.body.appendChild(form);
            form.submit();
        }

        function deleteArticle(articleId) {
            if (!confirm(ARTICLES_I18N.confirmDelete)) {
                return;
            }
            submitAction('delete_articles', articleId);
        }

        function quickReview(articleId, status) {
            const actionText = status === 'approved' ? ARTICLES_I18N.reviewApproved : ARTICLES_I18N.reviewRejected;
            if (!confirm(ARTICLES_I18N.confirmQuickReview.replace('__ACTION__', actionText))) {
                return;
            }
            submitAction('batch_update_review', articleId, { review_status: status });
        }

        document.addEventListener('DOMContentLoaded', function() {
            const selectAll = document.getElementById('select-all');
            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    document.querySelectorAll('.article-checkbox').forEach((node) => node.checked = this.checked);
                    updateSelectedCount();
                });
            }

            document.querySelectorAll('.article-checkbox').forEach((node) => {
                node.addEventListener('change', updateSelectedCount);
            });

            document.querySelectorAll('.js-self-media-publish').forEach((button) => {
                button.addEventListener('click', function() {
                    openSelfMediaPublishModal(this.dataset.action || '', this.dataset.title || '');
                });
            });

            const batchAction = document.getElementById('batch-action');
            if (batchAction && !IS_TRASH_VIEW) {
                batchAction.addEventListener('change', function() {
                    const statusSelect = document.getElementById('status-select');
                    const reviewSelect = document.getElementById('review-select');
                    statusSelect?.classList.add('hidden');
                    reviewSelect?.classList.add('hidden');
                    if (this.value === 'batch_update_status') {
                        statusSelect?.classList.remove('hidden');
                    } else if (this.value === 'batch_update_review') {
                        reviewSelect?.classList.remove('hidden');
                    }
                });
            }

            const batchForm = document.getElementById('batch-form');
            if (batchForm) {
                batchForm.addEventListener('submit', function(event) {
                    const selected = document.querySelectorAll('.article-checkbox:checked');
                    if (selected.length === 0) {
                        event.preventDefault();
                        alert(IS_TRASH_VIEW ? TRASH_I18N.alertSelect : ARTICLES_I18N.selectArticles);
                        return;
                    }

                    const action = document.getElementById('batch-action')?.value ?? '';
                    if (action === '') {
                        event.preventDefault();
                        alert(ARTICLES_I18N.selectAction);
                        return;
                    }

                    const targetAction = ARTICLE_BATCH_ROUTES[action] ?? '';
                    if (targetAction === '') {
                        event.preventDefault();
                        alert(ARTICLES_I18N.selectAction);
                        return;
                    }
                    batchForm.action = targetAction;

                    if (IS_TRASH_VIEW) {
                        if (action === 'batch_restore' && !confirm(TRASH_I18N.confirmBatchRestore.replace('__COUNT__', String(selected.length)))) {
                            event.preventDefault();
                            return;
                        }
                        if (action === 'batch_force_delete' && !confirm(TRASH_I18N.confirmBatchForceDelete.replace('__COUNT__', String(selected.length)))) {
                            event.preventDefault();
                            return;
                        }
                    } else {
                    if (action === 'batch_update_status' && !(document.getElementById('status-select')?.value ?? '')) {
                        event.preventDefault();
                        alert(ARTICLES_I18N.selectStatus);
                        return;
                    }

                    if (action === 'batch_update_review' && !(document.getElementById('review-select')?.value ?? '')) {
                        event.preventDefault();
                        alert(ARTICLES_I18N.selectReview);
                        return;
                    }

                    if (action === 'delete_articles' && !confirm(ARTICLES_I18N.confirmDeleteSelected.replace('__COUNT__', selected.length))) {
                        event.preventDefault();
                        return;
                    }
                    }

                    const selectedIdsContainer = document.getElementById('batch-selected-ids');
                    if (!selectedIdsContainer) {
                        return;
                    }
                    selectedIdsContainer.innerHTML = '';
                    selected.forEach((checkbox) => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'article_ids[]';
                        input.value = checkbox.value;
                        selectedIdsContainer.appendChild(input);
                    });
                });
            }

            const selfMediaForm = document.getElementById('self-media-publish-form');
            if (selfMediaForm) {
                selfMediaForm.addEventListener('submit', function(event) {
                    const selected = selfMediaForm.querySelectorAll('input[name="crebee_account_ids[]"]:checked');
                    if (selected.length === 0) {
                        event.preventDefault();
                        alert('请选择要发布的自媒体平台');
                        return;
                    }

                    const submitButton = document.getElementById('self-media-publish-submit');
                    if (submitButton) {
                        submitButton.disabled = true;
                        submitButton.textContent = '提交中...';
                    }
                });
            }
        });
    </script>
@endpush
