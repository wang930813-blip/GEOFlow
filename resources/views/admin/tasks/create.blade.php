@extends('admin.layouts.app')

@php
    $isEdit = (bool) ($isEdit ?? false);
    $taskForm = is_array($taskForm ?? null) ? $taskForm : [];
    $hasCategories = (bool) ($hasCategories ?? true);
    $categoryCreateUrl = (string) ($categoryCreateUrl ?? route('admin.categories.create'));
    $t = static fn (string $key, array $replace = []): string => __("admin.$key", $replace);
    $selectedDistributionChannelIds = collect(old('distribution_channel_ids', $taskForm['distribution_channel_ids'] ?? []))
        ->map(static fn ($id): string => (string) $id)
        ->all();
    $publishScope = (string) old('publish_scope', (string) ($taskForm['publish_scope'] ?? 'local_and_distribution'));
    $scheduledPublishEnabled = old('scheduled_publish_enabled', (string) ($taskForm['scheduled_publish_enabled'] ?? '0'));
    $scheduledPublishAt = (string) old('scheduled_publish_at', (string) ($taskForm['scheduled_publish_at'] ?? ''));
    $selectedTitleLibraryId = (string) old('title_library_id', (string) ($taskForm['title_library_id'] ?? ''));
    $selectedTitleLibrary = collect($formOptions['titleLibraries'] ?? [])->first(
        static fn (array $library): bool => (string) ($library['id'] ?? '') === $selectedTitleLibraryId
    );
    $createdCount = max(0, (int) ($taskForm['created_count'] ?? 0));
    $articleLimit = max(1, (int) old('article_limit', (int) ($taskForm['article_limit'] ?? 10)));
    $plannedRemaining = max(0, $articleLimit - $createdCount);
    $taskFormI18n = [
        'checking' => $t('task_create.readiness.checking'),
        'publishCadence' => $t('task_create.ai_quality.publish_cadence', ['minutes' => '__MINUTES__']),
        'blockedTitle' => $t('task_create.readiness.dialog_blocked_title'),
        'warningTitle' => $t('task_create.readiness.dialog_warning_title'),
        'requestFailed' => $t('task_create.readiness.request_failed'),
        'knowledgeBaseLimit' => $t('task_create.error.knowledge_base_limit'),
        'distributionCount' => $t('task_create.label.distribution_channel_selected_count', ['count' => '__COUNT__']),
        'knowledgeBaseCount' => $t('task_create.label.knowledge_base_selected_count', ['count' => '__COUNT__', 'max' => 5]),
        'adjustLimit' => $t('task_create.readiness.actions.adjust_limit', ['count' => '__COUNT__']),
        'savePaused' => $t('task_create.readiness.actions.save_paused'),
        'saveExistingPaused' => $t('task_create.readiness.actions.save_existing_paused'),
        'requestFailedIssue' => [
            'code' => 'request_failed',
            'severity' => 'warning',
            'title' => $t('task_create.readiness.issue.request_failed.title'),
            'message' => $t('task_create.readiness.issue.request_failed.message'),
            'impact' => $t('task_create.readiness.issue.request_failed.impact'),
            'suggestions' => [
                $t('task_create.readiness.issue.request_failed.suggestion_1'),
                $t('task_create.readiness.issue.request_failed.suggestion_2'),
            ],
        ],
    ];
    $initialTitleReadinessReport = session('title_readiness_report');
@endphp

@section('content')
    @if (! $isEdit)
        <nav aria-label="任务类型" class="mb-5 flex gap-3 border-b border-gray-200 pb-4" data-task-type-chooser>
            <a aria-current="page" class="rounded-md bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-700" href="{{ route('admin.tasks.create', ['content_type' => 'article']) }}">文章任务</a>
            <a class="rounded-md px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50" href="{{ route('admin.tasks.create', ['content_type' => 'topic']) }}">专题任务</a>
        </nav>
    @endif
    <div class="px-4 sm:px-0">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center space-x-4">
                <a href="{{ route('admin.tasks.index') }}" class="text-gray-400 hover:text-gray-600">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">{{ $isEdit ? $t('task_edit.page_heading') : $t('task_create.page_heading') }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ $t('task_create.page_subtitle') }}</p>
                </div>
            </div>
        </div>

        <div class="max-w-4xl mx-auto">
            @if (! $hasCategories)
                <div class="bg-amber-50 border border-amber-200 rounded-lg p-5">
                    <h3 class="text-base font-semibold text-amber-900">{{ $t('task_create.error.no_categories_configured') }}</h3>
                    <p class="mt-2 text-sm text-amber-800">{{ $t('task_create.help.no_categories_configured') }}</p>
                    <div class="mt-4">
                        <a href="{{ $categoryCreateUrl }}" class="inline-flex items-center px-4 py-2 border border-amber-300 rounded-md text-sm font-medium text-amber-900 bg-white hover:bg-amber-100">
                            <i data-lucide="folder-plus" class="w-4 h-4 mr-2"></i>
                            {{ $t('categories.add') }}
                        </a>
                    </div>
                </div>
            @else
            <form
                method="POST"
                action="{{ $isEdit ? route('admin.tasks.update', ['taskId' => $taskId]) : route('admin.tasks.store') }}"
                class="space-y-8"
                data-task-form
                data-title-readiness-url="{{ route('admin.tasks.title-readiness') }}"
                data-task-id="{{ $taskId ?? '' }}"
                data-created-count="{{ $createdCount }}"
            >
                @csrf
                @unless ($isEdit)
                    <input type="hidden" name="content_type" value="article">
                @endunless
                @if ($isEdit)
                    @method('PUT')
                @endif

                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">{{ $t('task_create.section.basic_title') }}</h3>
                        <p class="mt-1 text-sm text-gray-600">{{ $t('task_create.section.basic_desc') }}</p>
                    </div>
                    <div class="px-6 py-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="md:col-span-2">
                                <label for="task_name" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.task_name') }} *</label>
                                <input type="text" name="task_name" id="task_name" required value="{{ old('task_name', (string) ($taskForm['task_name'] ?? '')) }}"
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="{{ $t('task_create.placeholder.task_name') }}">
                            </div>
                            <div>
                                <label for="title_library_id" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.title_library') }} *</label>
                                <select name="title_library_id" id="title_library_id" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">{{ $t('task_create.option.select_title_library') }}</option>
                                    @foreach ($formOptions['titleLibraries'] as $library)
                                        <option
                                            value="{{ $library['id'] }}"
                                            data-title-name="{{ $library['name'] }}"
                                            data-title-total="{{ $library['count'] }}"
                                            data-title-used="{{ $library['used'] }}"
                                            data-title-available="{{ $library['available'] }}"
                                            data-title-manage-url="{{ $library['manage_url'] }}"
                                            @selected($selectedTitleLibraryId === (string) $library['id'])
                                        >
                                            {{ $t('task_create.option.library_readiness_count', ['name' => $library['name'], 'available' => $library['available'], 'total' => $library['count']]) }}{{ ($library['keyword_library_name'] ?? '') !== '' ? ' - GEO: '.(($library['company_name'] ?? '') !== '' ? $library['company_name'] : $library['keyword_library_name']) : '' }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs leading-5 text-gray-500" data-task-title-stats aria-live="polite">
                                    <span>{{ $t('task_create.readiness.stats.total') }} <strong class="font-semibold text-gray-700 tabular-nums" data-task-title-stat="total">{{ (int) ($selectedTitleLibrary['count'] ?? 0) }}</strong></span>
                                    <span>{{ $t('task_create.readiness.stats.used') }} <strong class="font-semibold text-gray-700 tabular-nums" data-task-title-stat="used">{{ (int) ($selectedTitleLibrary['used'] ?? 0) }}</strong></span>
                                    <span>{{ $t('task_create.readiness.stats.available') }} <strong class="font-semibold text-gray-700 tabular-nums" data-task-title-stat="available">{{ (int) ($selectedTitleLibrary['available'] ?? 0) }}</strong></span>
                                    <span>{{ $t('task_create.readiness.stats.remaining') }} <strong class="font-semibold text-gray-700 tabular-nums" data-task-title-stat="remaining">{{ $plannedRemaining }}</strong></span>
                                </div>
                            </div>
                            <div>
                                <label for="status" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.task_status') }}</label>
                                <select name="status" id="status" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option value="active" @selected(old('status', (string) ($taskForm['status'] ?? 'active')) === 'active')>{{ $t('task_create.option.status_active') }}</option>
                                    <option value="paused" @selected(old('status', (string) ($taskForm['status'] ?? 'active')) === 'paused')>{{ $t('task_create.option.status_paused') }}</option>
                                </select>
                            </div>
                            <div class="md:col-span-2">
                                <label for="fixed_title_id" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.fixed_title') }}</label>
                                <select name="fixed_title_id" id="fixed_title_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500" data-selected-title-id="{{ old('fixed_title_id', (string) ($taskForm['fixed_title_id'] ?? '')) }}">
                                    <option value="">{{ $t('task_create.option.select_title') }}</option>
                                    @foreach ($formOptions['titles'] as $titleOption)
                                        <option value="{{ $titleOption['id'] }}" data-library-id="{{ $titleOption['library_id'] }}" @selected((string) old('fixed_title_id', (string) ($taskForm['fixed_title_id'] ?? '')) === (string) $titleOption['id'])>
                                            {{ $titleOption['title'] }}{{ ($titleOption['keyword'] ?? '') !== '' ? ' - '.$titleOption['keyword'] : '' }}
                                        </option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.fixed_title') }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">{{ $t('task_create.section.content_title') }}</h3>
                        <p class="mt-1 text-sm text-gray-600">{{ $t('task_create.section.content_desc') }}</p>
                    </div>
                    <div class="px-6 py-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="prompt_id" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.content_prompt') }} *</label>
                                <select name="prompt_id" id="prompt_id" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">{{ $t('task_create.option.select_prompt') }}</option>
                                    @foreach ($formOptions['prompts'] as $prompt)
                                        <option value="{{ $prompt['id'] }}" @selected((string) old('prompt_id', (string) ($taskForm['prompt_id'] ?? '')) === (string) $prompt['id'])>{{ $prompt['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="ai_model_id" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.ai_model') }} *</label>
                                <select name="ai_model_id" id="ai_model_id" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">{{ $t('task_create.option.select_ai_model') }}</option>
                                    @foreach ($formOptions['aiModels'] as $model)
                                        <option value="{{ $model['id'] }}" @selected((string) old('ai_model_id', (string) ($taskForm['ai_model_id'] ?? '')) === (string) $model['id'])>{{ $model['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="model_selection_mode" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.model_selection_mode') }}</label>
                                <select name="model_selection_mode" id="model_selection_mode" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option value="fixed" @selected(old('model_selection_mode', (string) ($taskForm['model_selection_mode'] ?? 'fixed')) === 'fixed')>{{ $t('task_create.option.model_selection_fixed') }}</option>
                                    <option value="smart_failover" @selected(old('model_selection_mode', (string) ($taskForm['model_selection_mode'] ?? 'fixed')) === 'smart_failover')>{{ $t('task_create.option.model_selection_smart_failover') }}</option>
                                </select>
                                <p class="mt-1 text-sm text-gray-500">{!! $t('task_create.help.model_selection_mode') !!}</p>
                            </div>
                            <div>
                                <label for="knowledge_base_id" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.knowledge_base') }}</label>
                                <select name="knowledge_base_id" id="knowledge_base_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">{{ $t('task_create.option.no_knowledge_base') }}</option>
                                    @foreach ($formOptions['knowledgeBases'] as $kb)
                                        <option value="{{ $kb['id'] }}" @selected((string) old('knowledge_base_id', (string) ($taskForm['knowledge_base_id'] ?? '')) === (string) $kb['id'])>{{ $kb['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="author_id" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.author') }}</label>
                                <select name="author_id" id="author_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option value="0">{{ $t('task_create.option.random_author') }}</option>
                                    @foreach ($formOptions['authors'] as $author)
                                        <option value="{{ $author['id'] }}" @selected((string) old('author_id', (string) ($taskForm['author_id'] ?? '0')) === (string) $author['id'])>{{ $author['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">{{ $t('task_create.section.ai_quality_title') }}</h3>
                        <p class="mt-1 text-sm text-gray-600">{{ $t('task_create.section.ai_quality_desc') }}</p>
                    </div>
                    <div class="px-6 py-4 space-y-5">
                        <div>
                            <input type="hidden" name="ai_quality_enabled" value="0">
                            <label class="inline-flex items-center gap-2">
                                <input type="checkbox" name="ai_quality_enabled" id="ai_quality_enabled" value="1"
                                       @checked((bool) old('ai_quality_enabled', (bool) ($taskForm['ai_quality_enabled'] ?? false)))
                                       class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                <span class="text-sm font-medium text-gray-900">{{ $t('task_create.field.ai_quality_enabled') }}</span>
                            </label>
                            <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.ai_quality_enabled') }}</p>
                        </div>

                        <div id="ai-quality-settings" class="grid grid-cols-1 gap-5 md:grid-cols-2">
                            <div>
                                <label for="ai_quality_prompt_id" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.ai_quality_prompt') }}</label>
                                <select name="ai_quality_prompt_id" id="ai_quality_prompt_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="">{{ $t('task_create.option.select_ai_quality_prompt') }}</option>
                                    @foreach (($formOptions['aiQualityPrompts'] ?? []) as $prompt)
                                        <option value="{{ $prompt['id'] }}" @selected((string) old('ai_quality_prompt_id', (string) ($taskForm['ai_quality_prompt_id'] ?? '')) === (string) $prompt['id'])>{{ $prompt['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="ai_quality_model_id" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.ai_quality_model') }}</label>
                                <select name="ai_quality_model_id" id="ai_quality_model_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="">{{ $t('task_create.option.select_ai_quality_model') }}</option>
                                    @foreach (($formOptions['aiModels'] ?? []) as $model)
                                        <option value="{{ $model['id'] }}" @selected((string) old('ai_quality_model_id', (string) ($taskForm['ai_quality_model_id'] ?? '')) === (string) $model['id'])>{{ $model['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="ai_quality_knowledge_base_ids" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.ai_quality_knowledge_bases') }}</label>
                                @php($selectedQualityKnowledgeBaseIds = collect(old('ai_quality_knowledge_base_ids', $taskForm['ai_quality_knowledge_base_ids'] ?? []))->map(static fn ($id): string => (string) $id)->all())
                                <select name="ai_quality_knowledge_base_ids[]" id="ai_quality_knowledge_base_ids" multiple size="5" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    @foreach (($formOptions['knowledgeBases'] ?? []) as $knowledgeBase)
                                        <option value="{{ $knowledgeBase['id'] }}" @selected(in_array((string) $knowledgeBase['id'], $selectedQualityKnowledgeBaseIds, true))>{{ $knowledgeBase['name'] }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.ai_quality_knowledge_bases') }}</p>
                            </div>
                            <div>
                                <label for="ai_quality_retrieval_mode" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.ai_quality_retrieval_mode') }}</label>
                                <select name="ai_quality_retrieval_mode" id="ai_quality_retrieval_mode" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="atomic_first" @selected(old('ai_quality_retrieval_mode', (string) ($taskForm['ai_quality_retrieval_mode'] ?? 'chunk')) === 'atomic_first')>{{ $t('task_create.option.ai_quality_mode_atomic_first') }}</option>
                                    <option value="chunk" @selected(old('ai_quality_retrieval_mode', (string) ($taskForm['ai_quality_retrieval_mode'] ?? 'chunk')) === 'chunk')>{{ $t('task_create.option.ai_quality_mode_chunk') }}</option>
                                    <option value="knowledge_broad" @selected(old('ai_quality_retrieval_mode', (string) ($taskForm['ai_quality_retrieval_mode'] ?? 'chunk')) === 'knowledge_broad')>{{ $t('task_create.option.ai_quality_mode_knowledge_broad') }}</option>
                                </select>
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.ai_quality_retrieval_mode') }}</p>
                            </div>
                            <div>
                                <label for="ai_quality_pass_score" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.ai_quality_pass_score') }}</label>
                                <input type="number" name="ai_quality_pass_score" id="ai_quality_pass_score" min="1" max="100" value="{{ old('ai_quality_pass_score', (string) ($taskForm['ai_quality_pass_score'] ?? 85)) }}" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                                <label for="ai_quality_manual_override_min_score" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.ai_quality_manual_score') }}</label>
                                <input type="number" name="ai_quality_manual_override_min_score" id="ai_quality_manual_override_min_score" min="0" max="99" value="{{ old('ai_quality_manual_override_min_score', (string) ($taskForm['ai_quality_manual_override_min_score'] ?? 70)) }}" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.ai_quality_scores') }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">{{ $t('task_create.section.image_title') }}</h3>
                        <p class="mt-1 text-sm text-gray-600">{{ $t('task_create.section.image_desc') }}</p>
                    </div>
                    <div class="px-6 py-4">
                        @php($imageCountValue = (string) old('image_count', (string) ($taskForm['image_count'] ?? '1')))
                        @php($imageModeValue = (string) old('image_mode', (string) ($taskForm['image_mode'] ?? 'library')))
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="image_mode" class="block text-sm font-medium text-gray-700">配图模式</label>
                                <select name="image_mode" id="image_mode" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option value="none" @selected($imageModeValue === 'none')>不插入配图</option>
                                    <option value="library" @selected($imageModeValue === 'library')>使用图片库</option>
                                    <option value="ai" @selected($imageModeValue === 'ai')>AI 生成配图</option>
                                </select>
                            </div>
                            <div>
                                <label for="image_library_id" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.image_library') }}</label>
                                <select name="image_library_id" id="image_library_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">{{ $t('task_create.option.no_images') }}</option>
                                    @foreach ($formOptions['imageLibraries'] as $library)
                                        <option value="{{ $library['id'] }}" @selected((string) old('image_library_id', (string) ($taskForm['image_library_id'] ?? '')) === (string) $library['id'])>
                                            {{ $t('task_create.option.image_library_count', ['name' => $library['name'], 'count' => $library['count']]) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="ai_image_model_id" class="block text-sm font-medium text-gray-700">AI 图片模型</label>
                                <select name="ai_image_model_id" id="ai_image_model_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">请选择图片模型</option>
                                    @foreach (($formOptions['aiImageModels'] ?? []) as $model)
                                        <option value="{{ $model['id'] }}" @selected((string) old('ai_image_model_id', (string) ($taskForm['ai_image_model_id'] ?? '')) === (string) $model['id'])>{{ $model['name'] }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-sm text-gray-500">图片模型在“AI 模型管理”中新增，类型选择 Image。</p>
                            </div>
                            <div>
                                <label for="image_count" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.image_count') }}</label>
                                <select name="image_count" id="image_count" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option value="0" @selected($imageCountValue === '0')>{{ $t('task_create.option.no_image_count') }}</option>
                                    <option value="1" @selected($imageCountValue === '1')>{{ $t('task_create.option.image_count', ['count' => 1]) }}</option>
                                    <option value="2" @selected($imageCountValue === '2')>{{ $t('task_create.option.image_count', ['count' => 2]) }}</option>
                                    <option value="3" @selected($imageCountValue === '3')>{{ $t('task_create.option.image_count', ['count' => 3]) }}</option>
                                    <option value="4" @selected($imageCountValue === '4')>{{ $t('task_create.option.image_count', ['count' => 4]) }}</option>
                                    <option value="5" @selected($imageCountValue === '5')>{{ $t('task_create.option.image_count', ['count' => 5]) }}</option>
                                </select>
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.image_count') }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">{{ $t('task_create.section.publish_title') }}</h3>
                        <p class="mt-1 text-sm text-gray-600">{{ $t('task_create.section.publish_desc') }}</p>
                    </div>
                    <div class="px-6 py-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div id="need-review-option">
                                <div class="flex items-center">
                                    <input type="checkbox" name="need_review" id="need_review" @checked((bool) old('need_review', (bool) ($taskForm['need_review'] ?? false)))
                                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                    <label for="need_review" class="ml-2 block text-sm text-gray-900">{{ $t('task_create.field.need_review') }}</label>
                                </div>
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.need_review') }}</p>
                            </div>
                            <div>
                                <label for="publish_interval" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.publish_interval') }}</label>
                                <input type="number" name="publish_interval" id="publish_interval" min="1" value="{{ old('publish_interval', (string) ($taskForm['publish_interval'] ?? 60)) }}"
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.publish_interval') }}</p>
                            </div>
                            <div id="scheduled-publish-section" class="md:col-span-2 rounded-md border border-blue-100 bg-blue-50/60 px-4 py-3">
                                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    <div id="scheduled-publish-option">
                                        <div class="flex items-center">
                                            <input type="checkbox" name="scheduled_publish_enabled" id="scheduled_publish_enabled" value="1" @checked((string) $scheduledPublishEnabled === '1')
                                                   class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                            <label for="scheduled_publish_enabled" class="ml-2 block text-sm font-medium text-gray-900">{{ $t('task_create.field.scheduled_publish_enabled') }}</label>
                                        </div>
                                        <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.scheduled_publish_enabled') }}</p>
                                    </div>
                                    <div>
                                        <label for="scheduled_publish_at" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.scheduled_publish_at') }}</label>
                                        <input type="datetime-local" name="scheduled_publish_at" id="scheduled_publish_at" value="{{ $scheduledPublishAt }}"
                                               class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                        <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.scheduled_publish_at') }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">{{ $t('task_create.section.distribution_title') }}</h3>
                        <p class="mt-1 text-sm text-gray-600">{{ $t('task_create.section.distribution_desc') }}</p>
                    </div>
                    <div class="px-6 py-4">
                        <fieldset class="mb-5">
                            <legend class="text-sm font-medium text-gray-900">{{ $t('task_create.distribution.scope_title') }}</legend>
                            <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.distribution.scope_help') }}</p>
                            <div class="mt-4 grid grid-cols-1 gap-3">
                                <label class="flex cursor-pointer gap-3 rounded-md border border-gray-200 px-4 py-3 text-sm hover:border-blue-300 hover:bg-blue-50">
                                    <input type="radio" name="publish_scope" value="local_and_distribution" @checked($publishScope === 'local_and_distribution') class="mt-1 h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span>
                                        <span class="block font-medium text-gray-900">{{ $t('task_create.distribution.scope_local_and_distribution') }}</span>
                                        <span class="block text-gray-500">{{ $t('task_create.distribution.scope_local_and_distribution_desc') }}</span>
                                    </span>
                                </label>
                                <label class="flex cursor-pointer gap-3 rounded-md border border-gray-200 px-4 py-3 text-sm hover:border-blue-300 hover:bg-blue-50">
                                    <input type="radio" name="publish_scope" value="distribution_only" @checked($publishScope === 'distribution_only') class="mt-1 h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span>
                                        <span class="block font-medium text-gray-900">{{ $t('task_create.distribution.scope_distribution_only') }}</span>
                                        <span class="block text-gray-500">{{ $t('task_create.distribution.scope_distribution_only_desc') }}</span>
                                    </span>
                                </label>
                                <label class="flex cursor-pointer gap-3 rounded-md border border-gray-200 px-4 py-3 text-sm hover:border-blue-300 hover:bg-blue-50">
                                    <input type="radio" name="publish_scope" value="local_only" @checked($publishScope === 'local_only') class="mt-1 h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span>
                                        <span class="block font-medium text-gray-900">{{ $t('task_create.distribution.scope_local_only') }}</span>
                                        <span class="block text-gray-500">{{ $t('task_create.distribution.scope_local_only_desc') }}</span>
                                    </span>
                                </label>
                            </div>
                        </fieldset>

                        @if (! empty($formOptions['distributionChannels']))
                            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                @foreach ($formOptions['distributionChannels'] as $channel)
                                    @php($channelId = (string) $channel['id'])
                                    <label class="flex items-start gap-3 rounded-md border border-gray-200 px-4 py-3 text-sm hover:border-blue-300 hover:bg-blue-50">
                                        <input type="checkbox" name="distribution_channel_ids[]" value="{{ $channelId }}" @checked(in_array($channelId, $selectedDistributionChannelIds, true))
                                               class="mt-1 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                        <span class="min-w-0">
                                            <span class="block font-medium text-gray-900">{{ $channel['name'] }}</span>
                                            <span class="block break-all text-gray-500">{{ $channel['domain'] }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            <p class="mt-3 text-sm text-gray-500">{{ $t('task_create.distribution.help') }}</p>
                        @endif
                    </div>
                </div>

                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">{{ $t('task_create.section.seo_title') }}</h3>
                        <p class="mt-1 text-sm text-gray-600">{{ $t('task_create.section.seo_desc') }}</p>
                    </div>
                    <div class="px-6 py-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <div class="flex items-center">
                                    <input type="checkbox" name="auto_keywords" id="auto_keywords" @checked(old('auto_keywords', (string) ($taskForm['auto_keywords'] ?? '1')) === '1')
                                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                    <label for="auto_keywords" class="ml-2 block text-sm text-gray-900">{{ $t('task_create.field.auto_keywords') }}</label>
                                </div>
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.auto_keywords') }}</p>
                            </div>
                            <div>
                                <div class="flex items-center">
                                    <input type="checkbox" name="auto_description" id="auto_description" @checked(old('auto_description', (string) ($taskForm['auto_description'] ?? '1')) === '1')
                                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                    <label for="auto_description" class="ml-2 block text-sm text-gray-900">{{ $t('task_create.field.auto_description') }}</label>
                                </div>
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.auto_description') }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">{{ $t('task_create.section.category_title') }}</h3>
                        <p class="mt-1 text-sm text-gray-600">{{ $t('task_create.section.category_desc') }}</p>
                    </div>
                    @php($categoryMode = (string) old('category_mode', (string) ($taskForm['category_mode'] ?? 'smart')))
                    <div class="px-6 py-4 space-y-4">
                        <div>
                            <label class="text-base font-medium text-gray-900">{{ $t('task_create.field.category_mode') }}</label>
                            <p class="text-sm leading-5 text-gray-500">{{ $t('task_create.help.category_mode') }}</p>
                            <fieldset class="mt-4">
                                <legend class="sr-only">{{ $t('task_create.field.category_mode') }}</legend>
                                <div class="space-y-4">
                                    <div class="flex items-start">
                                        <div class="flex items-center h-5">
                                            <input id="category_smart" name="category_mode" type="radio" value="smart" @checked($categoryMode === 'smart')
                                                   class="focus:ring-blue-500 h-4 w-4 text-blue-600 border-gray-300">
                                        </div>
                                        <div class="ml-3 text-sm">
                                            <label for="category_smart" class="font-medium text-gray-700">{{ $t('task_create.option.category_smart') }}</label>
                                            <p class="text-gray-500">{{ $t('task_create.help.category_smart') }}</p>
                                        </div>
                                    </div>
                                    <div class="flex items-start">
                                        <div class="flex items-center h-5">
                                            <input id="category_fixed" name="category_mode" type="radio" value="fixed" @checked($categoryMode === 'fixed')
                                                   class="focus:ring-blue-500 h-4 w-4 text-blue-600 border-gray-300">
                                        </div>
                                        <div class="ml-3 text-sm">
                                            <label for="category_fixed" class="font-medium text-gray-700">{{ $t('task_create.option.category_fixed') }}</label>
                                            <p class="text-gray-500">{{ $t('task_create.help.category_fixed') }}</p>
                                        </div>
                                    </div>
                                    <div class="flex items-start">
                                        <div class="flex items-center h-5">
                                            <input id="category_random" name="category_mode" type="radio" value="random" @checked($categoryMode === 'random')
                                                   class="focus:ring-blue-500 h-4 w-4 text-blue-600 border-gray-300">
                                        </div>
                                        <div class="ml-3 text-sm">
                                            <label for="category_random" class="font-medium text-gray-700">{{ $t('task_create.option.category_random') }}</label>
                                            <p class="text-gray-500">{{ $t('task_create.help.category_random') }}</p>
                                        </div>
                                    </div>
                                </div>
                            </fieldset>
                        </div>
                        <div id="fixed-category-section" class="hidden">
                            <label for="fixed_category_id" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.fixed_category') }}</label>
                            <select name="fixed_category_id" id="fixed_category_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                <option value="">{{ $t('task_create.option.select_category') }}</option>
                                @foreach ($formOptions['categories'] as $category)
                                    <option value="{{ $category['id'] }}" @selected((string) old('fixed_category_id', (string) ($taskForm['fixed_category_id'] ?? '')) === (string) $category['id'])>{{ $category['name'] }}</option>
                                @endforeach
                            </select>
                            <p class="mt-2 text-sm text-gray-500">{{ $t('task_create.help.fixed_category') }}</p>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h4 class="text-sm font-medium text-gray-900 mb-2">{{ $t('task_create.preview.categories_title') }}</h4>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($formOptions['categories'] as $category)
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">{{ $category['name'] }}</span>
                                @endforeach
                            </div>
                            <p class="mt-2 text-xs text-gray-500">{{ $t('task_create.preview.categories_count', ['count' => count($formOptions['categories'])]) }}</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">{{ $t('task_create.section.advanced_title') }}</h3>
                        <p class="mt-1 text-sm text-gray-600">{{ $t('task_create.section.advanced_desc') }}</p>
                    </div>
                    <div class="px-6 py-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="article_limit" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.article_limit') }}</label>
                                <input type="number" name="article_limit" id="article_limit" min="1" value="{{ old('article_limit', (string) ($taskForm['article_limit'] ?? 10)) }}"
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.article_limit') }}</p>
                            </div>
                            <div>
                                <label for="draft_limit" class="block text-sm font-medium text-gray-700">{{ $t('task_create.field.draft_limit') }}</label>
                                <input type="number" name="draft_limit" id="draft_limit" min="1" value="{{ old('draft_limit', (string) ($taskForm['draft_limit'] ?? 10)) }}"
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.draft_limit') }}</p>
                            </div>
                            <div class="md:col-span-2">
                                <div class="flex items-center">
                                    <input type="checkbox" name="is_loop" id="is_loop" @checked(old('is_loop', (string) ($taskForm['is_loop'] ?? '1')) === '1')
                                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                    <label for="is_loop" class="ml-2 block text-sm text-gray-900">{{ $t('task_create.field.loop_mode') }}</label>
                                </div>
                                <p class="mt-1 text-sm text-gray-500">{{ $t('task_create.help.loop_mode') }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end space-x-4">
                    <a href="{{ route('admin.tasks.index') }}" class="px-6 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                        {{ __('admin.button.cancel') }}
                    </a>
                    <button type="submit" class="px-6 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700" data-task-form-submit>
                        <span data-task-form-submit-label>{{ $isEdit ? __('admin.task_edit.button.save_changes') : __('admin.button.create_task') }}</span>
                    </button>
                </div>
            </form>
            @endif
        </div>
    </div>
    @unless ($isEdit)
        <script>
            (()=>{const form=document.querySelector('[data-task-form]');if(!form)return;const key='geoflow:task:new:article';try{const entries=JSON.parse(sessionStorage.getItem(key)||'null');if(entries&&!@json($errors->any())){for(const [name,value]of entries){const nodes=Array.from(form.elements).filter(n=>n.name===name);for(const n of nodes){if(n.type==='checkbox'||n.type==='radio')n.checked=String(n.value)===String(value);else if(!n.multiple)n.value=value;}}}}catch{}const save=()=>{try{sessionStorage.setItem(key,JSON.stringify(Array.from(new FormData(form).entries()).filter(([n])=>!['_token','content_type'].includes(n))));}catch{}};document.querySelector('[data-task-type-chooser]')?.addEventListener('click',save);form.addEventListener('submit',()=>{try{sessionStorage.removeItem(key);}catch{}});})();
        </script>
    @endunless
    @if ($hasCategories)
        <dialog
            class="fixed inset-0 m-auto w-[min(600px,calc(100vw-2rem))] max-w-none overflow-hidden overscroll-contain rounded-lg border-0 bg-white p-0 text-left text-gray-900 shadow-2xl backdrop:bg-black/50"
            data-task-title-readiness-dialog
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="task-title-readiness-title"
            aria-describedby="task-title-readiness-summary task-title-readiness-recommendation"
        >
            <div class="flex max-h-[min(760px,calc(100dvh-2rem))] flex-col">
                <header class="flex items-start gap-4 border-b border-gray-200 px-6 py-5">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-red-50 text-red-600" data-task-readiness-icon-wrap aria-hidden="true">
                        <i data-lucide="triangle-alert" class="h-5 w-5" data-task-readiness-icon></i>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold uppercase text-gray-500">{{ $t('task_create.readiness.dialog_eyebrow') }}</p>
                        <h2 id="task-title-readiness-title" class="mt-1 text-lg font-semibold leading-6 text-gray-900" data-task-readiness-title>{{ $t('task_create.readiness.dialog_blocked_title') }}</h2>
                    </div>
                    <button type="button" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100" data-task-readiness-close aria-label="{{ $t('task_create.readiness.actions.close') }}">
                        <i data-lucide="x" class="h-4 w-4" aria-hidden="true"></i>
                    </button>
                </header>
                <div class="grid grid-cols-2 border-b border-gray-200 bg-gray-50 sm:grid-cols-4">
                    @foreach ([
                        'remaining' => $t('task_create.readiness.stats.remaining'),
                        'total' => $t('task_create.readiness.stats.total'),
                        'used' => $t('task_create.readiness.stats.used'),
                        'available' => $t('task_create.readiness.stats.available'),
                    ] as $statKey => $statLabel)
                        <div class="border-r border-gray-200 px-4 py-3 last:border-r-0">
                            <p class="text-xs text-gray-500">{{ $statLabel }}</p>
                            <p class="mt-1 text-lg font-semibold tabular-nums text-gray-900" data-task-readiness-stat="{{ $statKey }}">0</p>
                        </div>
                    @endforeach
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                    <p id="task-title-readiness-summary" class="text-sm leading-6 text-gray-700" data-task-readiness-summary></p>
                    <div class="mt-4 space-y-3" data-task-readiness-issues></div>
                    <div class="mt-4 rounded-lg bg-gray-50 px-4 py-3">
                        <p id="task-title-readiness-recommendation" class="text-sm leading-6 text-gray-700" data-task-readiness-recommendation></p>
                        <p class="mt-2 hidden text-sm font-medium text-amber-800" data-task-readiness-paused-hint hidden></p>
                    </div>
                </div>
                <footer class="flex flex-wrap justify-end gap-2 border-t border-gray-200 bg-gray-50 px-6 py-4 max-[520px]:flex-col">
                    <button type="button" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100" data-task-readiness-close>{{ $t('task_create.readiness.actions.close') }}</button>
                    <a href="#" target="_blank" rel="noopener" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100" data-task-readiness-manage>{{ $t('task_create.readiness.actions.manage_library') }}</a>
                    <button type="button" class="hidden rounded-md border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-900" data-task-readiness-adjust hidden></button>
                    <button type="button" class="hidden rounded-md border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-900" data-task-readiness-loop hidden>{{ $t('task_create.readiness.actions.enable_loop') }}</button>
                    <button type="button" class="hidden rounded-md bg-gray-800 px-4 py-2 text-sm font-semibold text-white" data-task-readiness-pause hidden>{{ $t('task_create.readiness.actions.save_paused') }}</button>
                    <button type="button" class="hidden rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white" data-task-readiness-acknowledge hidden>{{ $t('task_create.readiness.actions.acknowledge') }}</button>
                    <button type="button" class="hidden rounded-md border border-blue-300 bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-800" data-task-readiness-retry hidden>{{ $t('task_create.readiness.actions.retry') }}</button>
                    <button type="button" class="hidden rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white" data-task-readiness-server hidden>{{ $t('task_create.readiness.actions.server_check') }}</button>
                </footer>
            </div>
        </dialog>
        <script type="application/json" data-task-form-i18n>@json($taskFormI18n)</script>
        <script type="application/json" data-task-title-readiness-initial>@json($initialTitleReadinessReport)</script>
    @endif
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const isEditMode = @json($isEdit);

            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }

            const imageLibrarySelect = document.getElementById('image_library_id');
            const imageModeSelect = document.getElementById('image_mode');
            const aiImageModelSelect = document.getElementById('ai_image_model_id');
            const imageCountSelect = document.getElementById('image_count');
            const needReviewOption = document.getElementById('need-review-option');
            const needReviewCheckbox = document.getElementById('need_review');
            const publishIntervalInput = document.getElementById('publish_interval');
            const scheduledPublishSection = document.getElementById('scheduled-publish-section');
            const scheduledPublishOption = document.getElementById('scheduled-publish-option');
            const scheduledPublishCheckbox = document.getElementById('scheduled_publish_enabled');
            const scheduledPublishAtInput = document.getElementById('scheduled_publish_at');
            const publishScopeRadios = document.querySelectorAll('input[name="publish_scope"]');
            const articleLimitInput = document.getElementById('article_limit');
            const draftLimitInput = document.getElementById('draft_limit');
            const fixedCategorySection = document.getElementById('fixed-category-section');
            const fixedCategorySelect = document.getElementById('fixed_category_id');
            const categoryModeRadios = document.querySelectorAll('input[name="category_mode"]');
            const titleLibrarySelect = document.getElementById('title_library_id');
            const fixedTitleSelect = document.getElementById('fixed_title_id');
            const aiQualityToggle = document.getElementById('ai_quality_enabled');
            const aiQualitySettings = document.getElementById('ai-quality-settings');
            const form = document.querySelector('form');

            if (!form) {
                return;
            }

            function toggleAiQualitySettings() {
                const enabled = aiQualityToggle.checked;
                aiQualitySettings.querySelectorAll('input, select').forEach(function (control) {
                    control.disabled = !enabled;
                });
                aiQualitySettings.style.opacity = enabled ? '1' : '0.55';
            }

            aiQualityToggle.addEventListener('change', toggleAiQualitySettings);
            toggleAiQualitySettings();

            function toggleImageControls() {
                const mode = imageModeSelect.value || 'library';
                const usesLibrary = mode === 'library';
                const usesAi = mode === 'ai';

                imageLibrarySelect.disabled = !usesLibrary;
                aiImageModelSelect.disabled = !usesAi;
                imageCountSelect.disabled = mode === 'none';
                imageLibrarySelect.parentElement.style.opacity = usesLibrary ? '1' : '0.5';
                aiImageModelSelect.parentElement.style.opacity = usesAi ? '1' : '0.5';
                imageCountSelect.parentElement.style.opacity = mode === 'none' ? '0.5' : '1';
                aiImageModelSelect.required = usesAi;

                if (mode === 'none') {
                    imageCountSelect.value = '0';
                    imageLibrarySelect.value = '';
                    aiImageModelSelect.value = '';
                    return;
                }

                if (usesLibrary) {
                    aiImageModelSelect.value = '';
                    if (!imageLibrarySelect.value) {
                        imageCountSelect.value = '0';
                    } else if (imageCountSelect.value === '0') {
                        imageCountSelect.value = '1';
                    }
                    return;
                }

                if (usesAi) {
                    imageLibrarySelect.value = '';
                    if (imageCountSelect.value === '0') {
                        imageCountSelect.value = '1';
                    }
                }
            }

            function togglePublishInterval() {
                if (needReviewCheckbox.checked) {
                    publishIntervalInput.disabled = true;
                    publishIntervalInput.parentElement.style.opacity = '0.5';
                } else {
                    publishIntervalInput.disabled = false;
                    publishIntervalInput.parentElement.style.opacity = '1';
                }
            }

            function selectedPublishScope() {
                const selected = document.querySelector('input[name="publish_scope"]:checked');

                return selected ? selected.value : 'local_and_distribution';
            }

            function setOptionDisabledState(element, disabled) {
                if (!element) {
                    return;
                }

                element.style.opacity = disabled ? '0.5' : '1';
                element.classList.toggle('cursor-not-allowed', disabled);
            }

            function syncReviewPublishExclusion(changedBy = null) {
                const enabledForScope = selectedPublishScope() === 'local_only';

                if (!enabledForScope) {
                    scheduledPublishCheckbox.checked = false;
                } else if (changedBy === 'need_review' && needReviewCheckbox.checked) {
                    scheduledPublishCheckbox.checked = false;
                } else if (changedBy === 'scheduled_publish' && scheduledPublishCheckbox.checked) {
                    needReviewCheckbox.checked = false;
                } else if (changedBy === null && scheduledPublishCheckbox.checked && needReviewCheckbox.checked) {
                    needReviewCheckbox.checked = false;
                }

                const reviewDisabled = enabledForScope && scheduledPublishCheckbox.checked;
                const scheduledDisabled = !enabledForScope || needReviewCheckbox.checked;

                needReviewCheckbox.disabled = reviewDisabled;
                scheduledPublishCheckbox.disabled = scheduledDisabled;

                scheduledPublishSection.classList.toggle('hidden', !enabledForScope);
                scheduledPublishSection.style.opacity = enabledForScope ? '1' : '0.55';
                scheduledPublishAtInput.disabled = scheduledDisabled || !scheduledPublishCheckbox.checked;
                scheduledPublishAtInput.required = !scheduledDisabled && scheduledPublishCheckbox.checked;
                setOptionDisabledState(needReviewOption, reviewDisabled);
                setOptionDisabledState(scheduledPublishOption, scheduledDisabled);

                if (scheduledDisabled) {
                    scheduledPublishAtInput.required = false;
                }

                togglePublishInterval();
            }

            function toggleScheduledPublishControls() {
                syncReviewPublishExclusion();
            }

            function handleCategoryModeChange() {
                const selected = document.querySelector('input[name="category_mode"]:checked');
                if (!selected) {
                    return;
                }

                if (selected.value === 'fixed') {
                    fixedCategorySection.classList.remove('hidden');
                    fixedCategorySelect.required = true;
                } else {
                    fixedCategorySection.classList.add('hidden');
                    fixedCategorySelect.required = false;
                    fixedCategorySelect.value = '';
                }
            }

            function syncDraftLimitMax() {
                const articleLimit = Math.max(1, Number(articleLimitInput.value || 1));
                draftLimitInput.max = String(articleLimit);
                if (Number(draftLimitInput.value || 1) > articleLimit) {
                    draftLimitInput.value = String(articleLimit);
                }
            }

            function syncFixedTitleOptions() {
                const selectedLibraryId = titleLibrarySelect.value;
                const selectedTitleId = fixedTitleSelect.dataset.selectedTitleId || fixedTitleSelect.value;
                let selectedTitleStillVisible = false;

                Array.from(fixedTitleSelect.options).forEach((option) => {
                    if (!option.value) {
                        option.hidden = false;
                        return;
                    }

                    const visible = option.dataset.libraryId === selectedLibraryId;
                    option.hidden = !visible;
                    if (visible && option.value === selectedTitleId) {
                        selectedTitleStillVisible = true;
                    }
                });

                fixedTitleSelect.value = selectedTitleStillVisible ? selectedTitleId : '';
                fixedTitleSelect.disabled = !selectedLibraryId;
                fixedTitleSelect.parentElement.style.opacity = selectedLibraryId ? '1' : '0.6';
                fixedTitleSelect.dataset.selectedTitleId = fixedTitleSelect.value;
            }

            imageModeSelect.addEventListener('change', toggleImageControls);
            imageLibrarySelect.addEventListener('change', toggleImageControls);
            titleLibrarySelect.addEventListener('change', syncFixedTitleOptions);
            fixedTitleSelect.addEventListener('change', () => {
                fixedTitleSelect.dataset.selectedTitleId = fixedTitleSelect.value;
            });
            needReviewCheckbox.addEventListener('change', () => syncReviewPublishExclusion('need_review'));
            scheduledPublishCheckbox.addEventListener('change', () => syncReviewPublishExclusion('scheduled_publish'));
            publishScopeRadios.forEach((radio) => radio.addEventListener('change', () => syncReviewPublishExclusion('publish_scope')));
            articleLimitInput.addEventListener('input', syncDraftLimitMax);
            categoryModeRadios.forEach((radio) => radio.addEventListener('change', handleCategoryModeChange));

            form.addEventListener('submit', function (event) {
                if (!document.getElementById('task_name').value.trim()) {
                    alert(@json(__('admin.task_create.error.name_required')));
                    event.preventDefault();
                    return;
                }

                if (!document.getElementById('title_library_id').value) {
                    alert(@json(__('admin.task_create.error.title_library_required')));
                    event.preventDefault();
                    return;
                }

                if (!document.getElementById('prompt_id').value) {
                    alert(@json(__('admin.task_create.error.prompt_required')));
                    event.preventDefault();
                    return;
                }

                if (!document.getElementById('ai_model_id').value) {
                    alert(@json(__('admin.task_create.error.ai_model_required')));
                    event.preventDefault();
                    return;
                }

                if (imageModeSelect.value === 'ai' && !aiImageModelSelect.value) {
                    alert('请选择 AI 图片模型');
                    event.preventDefault();
                    return;
                }

                if (Number(draftLimitInput.value || 0) > Number(articleLimitInput.value || 0)) {
                    alert(@json(__('admin.task_create.error.draft_limit_too_large')));
                    event.preventDefault();
                    return;
                }

                if (!isEditMode && !confirm(@json(__('admin.task_create.confirm.create')))) {
                    event.preventDefault();
                }
            });

            toggleImageControls();
            syncFixedTitleOptions();
            syncReviewPublishExclusion();
            handleCategoryModeChange();
            syncDraftLimitMax();
        });
    </script>
@endpush
