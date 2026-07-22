@extends('admin.layouts.app')

@php
    $maxRate = max([1, ...array_map(static fn ($row) => (int) $row['rate'], $mentionRateRanking)]);
    $maxCount = max([1, ...array_map(static fn ($row) => (int) $row['count'], $mentionCountRanking)]);
    $recordFilters = $diagnosisRecordFilters ?? ['brand' => '', 'start_date' => '', 'end_date' => ''];
    $recordPaginator = $diagnosisRecordPaginator ?? null;
    $reportOptions = array_values(array_filter($reportRecords ?? $diagnosisRecords, static fn ($record) => (bool) $record['has_report']));
    $reportPageSize = 5;
    $reportTotalPages = max(1, (int) ceil(count($reportOptions) / $reportPageSize));
    $singleReportPrintUrl = count($reportOptions) === 1
        ? route('admin.brand-diagnosis.report.download', ['run' => $reportOptions[0]['id']])
        : '';
    $rankingTargetRow = static function (array $rows, array $fallback): array {
        foreach ($rows as $row) {
            if (!empty($row['is_target_brand'])) {
                return $row;
            }
        }

        return $rows[count($rows) - 1] ?? $fallback;
    };
    $rankingTargetIsInline = static function (array $row): bool {
        if (empty($row['is_target_brand']) || !is_numeric($row['display_rank'] ?? null)) {
            return false;
        }

        return (int) $row['display_rank'] <= 10;
    };
    $rankingVisibleRows = static function (array $rows) use ($rankingTargetIsInline): array {
        return array_slice(array_values(array_filter($rows, static function (array $row) use ($rankingTargetIsInline): bool {
            return empty($row['is_target_brand']) || $rankingTargetIsInline($row);
        })), 0, 10);
    };
    $rankingHasInlineTarget = static function (array $rows) use ($rankingTargetIsInline): bool {
        foreach ($rows as $row) {
            if ($rankingTargetIsInline($row)) {
                return true;
            }
        }

        return false;
    };
    $sourceMinPageSize = 5;
    $metricDefinitions = [
        'score' => [
            'name' => '品牌得分',
            'desc' => '量化品牌在AI平台综合表现的影响力',
            'formula' => '品牌得分 = 品牌提及率*0.75+品牌提及次数*0.1+平均提及排名*0.1+正常情感倾向*0.05',
        ],
        'mention_rate' => [
            'name' => '品牌提及率',
            'desc' => '用户与AI的自然对话中，品牌被主动想起、需要和讨论的基础概率',
            'formula' => '品牌提及率 = 提及本品牌的AI对话 ÷ 监测的全部AI对话总数 × 100%',
        ],
        'average_rank' => [
            'name' => '平均提及排名',
            'desc' => '用户与AI的自然对话中，品牌在众多竞争者中，被置于哪一层级',
            'formula' => '平均提及排名 = 本品牌在所有AI对话中的排名总和 ÷ 提及本品牌的总AI对话数',
            'note' => '默认显示提及率前20的品牌参与计算，减少低样本波动带来的排名噪音。',
        ],
        'mention_count' => [
            'name' => '品牌提及次数',
            'desc' => '品牌在监测周期内，被AI主动提及/推荐的总次数',
            'formula' => '品牌提及次数 = 所有监测AI对话中提及该品牌的次数的总和',
            'note' => '一次对话，存在被AI主动提及/推荐多次的情况，全部参与计算。',
        ],
        'sentiment_rate' => [
            'name' => '正面/中性情感倾向',
            'desc' => '用户与AI的自然对话中，品牌每一次被AI提及所承载的情感温度',
            'formula' => '正面/中型情感倾向=（正面情感对话数+中性情感对话数） ÷ 总提及对话数*100%',
        ],
    ];
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <!-- <div class="mb-2 inline-flex items-center gap-2 rounded-full border border-orange-200 bg-orange-50 px-3 py-1 text-xs font-semibold text-orange-700">
                    <i data-lucide="radar" class="h-3.5 w-3.5"></i>
                    GEO 品牌可见度
                </div> -->
                <h1 class="text-2xl font-bold text-gray-900">品牌诊断/报告</h1>
                <p class="mt-1 text-sm text-gray-600">品牌诊断工作台，用于展示模型选择、诊断记录、品牌表现、引用来源和 AI 对话记录。</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    data-export-report
                    data-report-count="{{ count($reportOptions) }}"
                    data-single-report-download-url="{{ $singleReportPrintUrl }}"
                    class="inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50"
                >
                    <i data-lucide="download" class="h-4 w-4"></i>
                    导出报告
                </button>
            </div>
        </div>

        <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm sm:p-5" aria-labelledby="brand-diagnosis-form-title">
            <form method="POST" action="{{ route('admin.brand-diagnosis.store') }}" class="flex flex-col gap-4 lg:flex-row lg:items-start" data-brand-diagnosis-form data-reuse-check-url="{{ route('admin.brand-diagnosis.reusable-questions') }}">
                @csrf
                <input type="hidden" name="reuse_questions" value="0" data-reuse-questions-field>
                <div class="min-w-0 flex-1">
                    <label id="brand-diagnosis-form-title" for="brand-name" class="mb-2 block text-sm font-semibold text-gray-900">品牌名称</label>
                    <div class="flex flex-col gap-3 sm:flex-row">
                        <input id="brand-name" name="brand_name" type="text" value="{{ old('brand_name') }}" class="min-w-0 flex-1 rounded-md border border-slate-200 bg-white text-sm" aria-label="品牌名称">
                        <button type="submit" class="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-md bg-orange-600 px-5 text-sm font-semibold text-white hover:bg-orange-700">
                            <i data-lucide="search" class="h-4 w-4"></i>
                            搜索一下
                        </button>
                    </div>
                    @error('brand_name')
                        <p class="mt-2 text-xs font-medium text-red-600">{{ $message }}</p>
                    @enderror
                    <p class="mt-2 text-xs text-gray-500">选择模型后，可生成品牌提及率、提及次数、排名和引用来源报告。</p>
                </div>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:w-[520px]">
                    @foreach ($models as $model)
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <div class="flex items-center gap-2">
                                @if (! empty($model['logo']))
                                    <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-white shadow-sm">
                                        <img src="{{ $model['logo'] }}" alt="{{ $model['name'] }} logo" class="h-7 w-7 rounded-full object-contain">
                                    </span>
                                @else
                                    <span class="{{ $model['color'] }} inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white">{{ $model['initial'] }}</span>
                                @endif
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-semibold text-gray-900">{{ $model['name'] }}</div>
                                    <div class="text-xs text-gray-500">{{ $model['desc'] }}</div>
                                </div>
                            </div>
                            <div class="mt-3 space-y-2 text-xs text-gray-700">
                                <div class="flex min-h-6 items-center gap-2 text-gray-600">
                                    <span class="inline-flex h-2 w-2 rounded-full bg-orange-500"></span>
                                    网页
                                </div>
                                @if ($model['available'])
                                    <label class="flex min-h-6 items-center gap-2">
                                        <input name="platforms[]" value="{{ $model['key'] }}" type="checkbox" checked data-platform-checkbox data-platform-name="{{ $model['name'] }}">
                                        诊断
                                    </label>
                                @else
                                    <div class="flex min-h-6 items-center gap-2 text-gray-400">
                                        <span class="inline-flex h-2 w-2 rounded-full bg-slate-300"></span>
                                        待接入
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </form>
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-4">
                <div class="flex flex-wrap items-center gap-4 text-sm text-gray-600">
                    <span class="inline-flex min-h-9 items-center gap-2">
                        <span class="inline-flex h-2 w-2 rounded-full bg-orange-500"></span>
                        数据来源：<span data-selected-platforms>豆包、DeepSeek、千问、文心一言</span>
                    </span>
                </div>
                <div class="text-xs text-gray-500">模型限定：豆包、千问、文心一言、DeepSeek</div>
            </div>
        </section>

        <section class="mt-6 rounded-lg border border-slate-200 bg-white p-4 shadow-sm sm:p-5" aria-labelledby="metric-definition-title">
            <div class="mb-4 flex flex-col gap-1">
                <h2 id="metric-definition-title" class="text-base font-semibold text-gray-900">指标释义</h2>
                <p class="text-sm text-gray-600">诊断结果会按以下口径计算，便于对比不同时间的品牌表现。</p>
            </div>
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                @foreach ($metricDefinitions as $definition)
                    <div class="h-full rounded-lg border border-slate-200 bg-white p-4">
                        <div class="text-sm font-semibold text-gray-900">{{ $definition['name'] }}</div>
                        <p class="mt-2 text-xs leading-5 text-gray-600">{{ $definition['desc'] }}</p>
                        <p class="mt-2 text-xs leading-5 text-gray-500">{{ $definition['formula'] }}</p>
                        @if (! empty($definition['note']))
                            <p class="mt-2 text-xs leading-5 text-gray-500">{{ $definition['note'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        <section class="mt-6 rounded-lg border border-slate-200 bg-white shadow-sm" aria-labelledby="diagnosis-record-title">
            <div class="flex flex-col gap-4 border-b border-slate-200 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 id="diagnosis-record-title" class="text-lg font-semibold text-gray-900">诊断记录</h2>
                    <p class="mt-1 text-sm text-gray-600">每次诊断都会生成一条独立记录，收起后保留摘要指标，方便快速对比。</p>
                </div>
                <form method="GET" action="{{ route('admin.brand-diagnosis.index') }}" class="flex flex-col gap-3 sm:flex-row">
                    <label class="sr-only" for="brand-keyword">品牌</label>
                    <input id="brand-keyword" name="brand" type="search" value="{{ $recordFilters['brand'] ?? '' }}" placeholder="品牌" class="h-10 w-full sm:w-44">
                    <label class="sr-only" for="start-date">开始日期</label>
                    <input id="start-date" name="start_date" type="date" value="{{ $recordFilters['start_date'] ?? '' }}" placeholder="开始日期" class="h-10 w-full sm:w-36">
                    <label class="sr-only" for="end-date">结束日期</label>
                    <input id="end-date" name="end_date" type="date" value="{{ $recordFilters['end_date'] ?? '' }}" placeholder="结束日期" class="h-10 w-full sm:w-36">
                    <button type="submit" class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-orange-600 px-4 text-sm font-semibold text-white hover:bg-orange-700">
                        <i data-lucide="search" class="h-4 w-4"></i>
                        搜索
                    </button>
                    @if (($recordFilters['brand'] ?? '') !== '' || ($recordFilters['start_date'] ?? '') !== '' || ($recordFilters['end_date'] ?? '') !== '')
                        <a href="{{ route('admin.brand-diagnosis.index') }}" class="inline-flex h-10 items-center justify-center rounded-md border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-600 hover:bg-slate-50">重置</a>
                    @endif
                </form>
            </div>

            <div class="px-5 py-5">
                @if (count($diagnosisRecords) === 0)
                    <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-5 py-10 text-center">
                        <div class="text-sm font-semibold text-gray-900">暂无诊断记录</div>
                        <div class="mt-2 text-sm text-gray-500">输入品牌名称并搜索后，每次诊断都会新增一条记录。</div>
                    </div>
                @endif
                <div
                    class="space-y-4"
                    data-diagnosis-record-pager
                    data-diagnosis-page-size="5"
                    data-diagnosis-total-records="{{ $recordPaginator?->total() ?? count($diagnosisRecords) }}"
                >
                    @foreach ($diagnosisRecords as $record)
                        @php
                            $recordQuestions = $record['questions'] ?? [];
                            $recordSources = $record['sources'] ?? [];
                            $recordConversations = $record['conversations'] ?? [];
                            $recordPlatformOptions = $record['platform_options'] ?? [['value' => 'all', 'label' => '全部平台']];
                            $recordPlatformData = $record['platform_data'] ?? [];
                            $recordMentionRateRanking = $record['rankings']['mention_rate'] ?? [];
                            $recordMentionCountRanking = $record['rankings']['mention_count'] ?? [];
                            $recordAverageRankings = $record['rankings']['average_rank'] ?? [];
                            $recordMentionRateRows = $rankingVisibleRows($recordMentionRateRanking);
                            $recordMentionCountRows = $rankingVisibleRows($recordMentionCountRanking);
                            $recordAverageRankRows = $rankingVisibleRows($recordAverageRankings);
                            $recordMentionRateTarget = $rankingTargetRow($recordMentionRateRanking, ['brand' => $record['brand'], 'rate' => (int) $record['metrics']['mention_rate'], 'display_rank' => '99+']);
                            $recordMentionCountTarget = $rankingTargetRow($recordMentionCountRanking, ['brand' => $record['brand'], 'count' => (int) $record['metrics']['mention_count'], 'display_rank' => '99+']);
                            $recordAverageRankTarget = $rankingTargetRow($recordAverageRankings, ['brand' => $record['brand'], 'rate' => (int) $record['metrics']['mention_rate'], 'rank' => (string) ($record['metrics']['average_rank'] ?? '0'), 'display_rank' => '99+']);
                            $recordMentionRateTargetInline = $rankingHasInlineTarget($recordMentionRateRows);
                            $recordMentionCountTargetInline = $rankingHasInlineTarget($recordMentionCountRows);
                            $recordAverageRankTargetInline = $rankingHasInlineTarget($recordAverageRankRows);
                            $recordMaxRate = max([1, ...array_map(static fn ($row) => (int) $row['rate'], $recordMentionRateRows)]);
                            $recordMaxCount = max([1, ...array_map(static fn ($row) => (int) $row['count'], $recordMentionCountRows)]);
                            $recordMetricCards = [
                                ['label' => '品牌得分 / 100', 'value' => $record['metrics']['score'], 'suffix' => '', 'value_class' => 'text-orange-600'],
                                ['label' => '品牌提及率', 'value' => $record['metrics']['mention_rate'], 'suffix' => '%', 'value_class' => 'text-gray-900'],
                                ['label' => '平均提及排名', 'value' => $record['metrics']['average_rank'], 'suffix' => '名', 'value_class' => 'text-gray-900'],
                                ['label' => '品牌提及次数', 'value' => $record['metrics']['mention_count'], 'suffix' => '次', 'value_class' => 'text-gray-900'],
                                ['label' => '正面/中性情感倾向', 'value' => $record['metrics']['sentiment_rate'], 'suffix' => '%', 'value_class' => 'text-gray-900'],
                            ];
                        @endphp
                        <article class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm" data-diagnosis-record data-active-platform="all">
                            <script type="application/json" data-record-platform-data>@json($recordPlatformData)</script>
                            <div class="grid gap-4 p-4 lg:grid-cols-[210px_1fr_132px] lg:items-center">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-orange-600 text-xs font-bold text-white">{{ mb_substr($record['brand'], 0, 1) }}</span>
                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-semibold text-gray-900">{{ $record['brand'] }}</div>
                                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                            <span>{{ $record['status'] }}</span>
                                            <span class="h-1 w-1 rounded-full bg-slate-300"></span>
                                            <span>记录 #{{ $record['id'] }}</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
                                    @foreach ($recordMetricCards as $metricCard)
                                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-center">
                                            <div class="{{ $metricCard['value_class'] }} text-xl font-bold" data-metric-card="{{ $loop->index }}">{{ $metricCard['value'] }}{{ $metricCard['suffix'] }}</div>
                                            <div class="mt-1 text-xs text-gray-500">{{ $metricCard['label'] }}</div>
                                        </div>
                                    @endforeach
                                </div>

                                <div class="flex flex-col items-start gap-3 lg:items-end">
                                    <div class="text-xs text-gray-500">{{ $record['created_at'] }}</div>
                                    <div class="flex items-center gap-2">
                                        <button
                                            type="button"
                                            data-record-toggle
                                            aria-expanded="{{ $record['expanded'] ? 'true' : 'false' }}"
                                            class="inline-flex h-9 items-center justify-center rounded-md border border-orange-200 bg-orange-50 px-3 text-xs font-semibold text-orange-700 hover:bg-orange-100"
                                        ><span data-record-toggle-label>{{ $record['expanded'] ? '收起结果' : '查看结果' }}</span></button>
                                        <form method="POST" action="{{ route('admin.brand-diagnosis.destroy', ['run' => $record['id']]) }}" onsubmit="return confirm('确认删除这条品牌诊断记录吗？删除后监测中心将不再引用这条诊断数据。');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="inline-flex h-9 items-center justify-center rounded-md border border-red-200 bg-white px-3 text-xs font-semibold text-red-600 hover:bg-red-50">
                                                删除
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <div data-record-detail @class(['hidden' => ! $record['expanded'], 'border-t border-slate-200 bg-white p-4'])>
                                <div class="rounded-lg border border-slate-200 bg-orange-50/40 px-3 py-2 text-xs text-gray-600">
                                    记录 #{{ $record['id'] }} 当前状态：{{ $record['status'] }}。诊断完成后会展示所选模型真实联网回答和引用来源。
                                </div>
                                @if (trim((string) ($record['brand_profile'] ?? '')) !== '')
                                    <section class="mt-3 rounded-lg border border-blue-100 bg-blue-50/60 px-4 py-3">
                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                            <h3 class="text-sm font-semibold text-gray-900">品牌介绍</h3>
                                            @if (trim((string) ($record['brand_profile_model'] ?? '')) !== '')
                                                <span class="text-xs text-blue-700">来源：{{ $record['brand_profile_model'] }}</span>
                                            @endif
                                        </div>
                                        <p class="mt-2 whitespace-pre-line text-sm leading-6 text-gray-700">{{ $record['brand_profile'] }}</p>
                                    </section>
                                @endif

                        <form method="POST" action="{{ route('admin.brand-diagnosis.confirm', ['run' => $record['id']]) }}" class="mt-5 rounded-lg border border-slate-200 bg-white p-4" data-confirm-diagnosis-form>
                            @csrf
                            <div class="mb-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <div class="text-sm font-semibold text-gray-900">AI问题</div>
                                    <div class="mt-1 text-xs text-gray-500">可修改问题后再确认诊断，确认后将开始调用所选模型并计入一次诊断。</div>
                                </div>
                                @if (in_array($record['raw_status'] ?? '', ['questions_ready', 'awaiting_confirmation', 'completed', 'failed'], true) && count($recordQuestions) > 0)
                                    <button type="submit" class="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-md bg-orange-600 px-4 text-sm font-semibold text-white hover:bg-orange-700 disabled:cursor-not-allowed disabled:bg-orange-300" data-confirm-diagnosis-submit>
                                        <i data-lucide="play" class="h-4 w-4"></i>
                                        确认诊断
                                    </button>
                                @endif
                            </div>
                            @if ($errors->has('questions'))
                                <div class="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs font-medium text-red-600">{{ $errors->first('questions') }}</div>
                            @endif
                            <div class="grid gap-3 lg:grid-cols-2">
                                @forelse ($recordQuestions as $question)
                                    <label class="block rounded-lg border border-slate-200 bg-slate-50 p-3">
                                        @php
                                            $coreTerm = trim((string) ($question['core_term'] ?? ''));
                                            $questionType = trim((string) ($question['type'] ?? ''));
                                        @endphp
                                        <span class="mb-2 flex items-center gap-2 text-xs font-medium text-slate-600">
                                            <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-blue-600 text-[11px] font-bold text-white">{{ $question['rank'] }}</span>
                                            @if ($coreTerm !== '')
                                                <span class="inline-flex max-w-[12rem] items-center truncate rounded bg-blue-50 px-1.5 py-0.5 text-[11px] font-medium text-blue-700" title="{{ $coreTerm }}">{{ $coreTerm }}</span>
                                                @if ($questionType !== '')
                                                    <span class="text-[11px] text-slate-400">+</span>
                                                    <span class="inline-flex max-w-[10rem] items-center truncate rounded bg-orange-50 px-1.5 py-0.5 text-[11px] font-medium text-orange-700" title="{{ $questionType }}">{{ $questionType }}</span>
                                                @endif
                                            @endif
                                        </span>
                                        <textarea
                                            name="questions[{{ $question['id'] }}]"
                                            rows="2"
                                            maxlength="240"
                                            class="w-full resize-y rounded-md border border-slate-200 bg-white text-sm leading-6 text-gray-800 focus:border-orange-400 focus:ring-orange-400"
                                            @if (! in_array($record['raw_status'] ?? '', ['questions_ready', 'awaiting_confirmation', 'completed', 'failed'], true)) readonly @endif
                                        >{{ old('questions.'.$question['id'], $question['text']) }}</textarea>
                                    </label>
                                @empty
                                    <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-gray-500 lg:col-span-2">AI 问题生成后会显示在这里。</div>
                                @endforelse
                            </div>
                        </form>

                <div class="mt-6">
                    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <h2 class="text-lg font-semibold text-gray-900">品牌表现</h2>
                        <select class="h-10 w-full sm:w-44" aria-label="平台筛选" data-platform-filter>
                            @foreach ($recordPlatformOptions as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
                        <div class="flex min-h-[420px] flex-col rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                            <h3 class="mb-4 text-base font-semibold text-gray-900">品牌提及率</h3>
                            <div class="space-y-3" data-ranking-list="mention_rate">
                                @foreach ($recordMentionRateRows as $index => $row)
                                    @php
                                        $isTargetRow = !empty($row['is_target_brand']);
                                    @endphp
                                    <div @class([
                                        'grid grid-cols-[24px_88px_1fr_40px] items-center gap-2 text-sm',
                                        'rounded-md border border-orange-200 bg-orange-50 px-2 py-1 font-semibold text-orange-700' => $isTargetRow,
                                    ]) @if ($isTargetRow) data-ranking-row-target="mention_rate" @endif>
                                        <span @class(['text-xs', 'text-orange-600' => $isTargetRow, 'text-gray-500' => ! $isTargetRow])>{{ $row['display_rank'] ?? $index + 1 }}</span>
                                        <span @class(['truncate font-medium transition-colors hover:text-orange-700', 'text-orange-700' => $isTargetRow, 'text-gray-700' => ! $isTargetRow]) data-ranking-brand title="{{ $row['title'] ?? $row['brand'] }}">{{ $row['brand'] }}</span>
                                        <span class="h-2 rounded-full bg-slate-100">
                                            @php
                                                $rateWidth = (int) round(((int) $row['rate'] / $recordMaxRate) * 100);
                                            @endphp
                                            <span @class(['block h-2 rounded-full w-['.max(4, $rateWidth).'%]', 'bg-orange-500' => $isTargetRow, 'bg-blue-600' => ! $isTargetRow])></span>
                                        </span>
                                        <span @class(['text-right text-xs font-semibold', 'text-orange-700' => $isTargetRow, 'text-blue-700' => ! $isTargetRow])>{{ $row['rate'] }}%</span>
                                    </div>
                                @endforeach
                            </div>
                            <div @class(['mt-auto flex items-center gap-2 rounded-md border border-orange-200 bg-orange-50 px-3 py-2 text-xs font-semibold text-orange-700', 'hidden' => $recordMentionRateTargetInline]) data-ranking-target="mention_rate" title="{{ $recordMentionRateTarget['title'] ?? $recordMentionRateTarget['brand'] ?? $record['brand'] }}">
                                <span class="rounded bg-orange-600 px-1.5 py-0.5 text-white">{{ $recordMentionRateTarget['display_rank'] ?? '99+' }}</span>
                                <span class="truncate transition-colors hover:text-orange-800" data-ranking-brand title="{{ $recordMentionRateTarget['title'] ?? $recordMentionRateTarget['brand'] ?? $record['brand'] }}">{{ $recordMentionRateTarget['brand'] }}</span>
                                <span class="shrink-0">{{ $recordMentionRateTarget['rate'] }}%</span>
                            </div>
                        </div>

                        <div class="flex min-h-[420px] flex-col rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                            <h3 class="mb-4 text-base font-semibold text-gray-900">品牌提及次数</h3>
                            <div class="space-y-3" data-ranking-list="mention_count">
                                @foreach ($recordMentionCountRows as $index => $row)
                                    @php
                                        $isTargetRow = !empty($row['is_target_brand']);
                                    @endphp
                                    <div @class([
                                        'grid grid-cols-[24px_88px_1fr_32px] items-center gap-2 text-sm',
                                        'rounded-md border border-orange-200 bg-orange-50 px-2 py-1 font-semibold text-orange-700' => $isTargetRow,
                                    ]) @if ($isTargetRow) data-ranking-row-target="mention_count" @endif>
                                        <span @class(['text-xs', 'text-orange-600' => $isTargetRow, 'text-gray-500' => ! $isTargetRow])>{{ $row['display_rank'] ?? $index + 1 }}</span>
                                        <span @class(['truncate font-medium transition-colors hover:text-orange-700', 'text-orange-700' => $isTargetRow, 'text-gray-700' => ! $isTargetRow]) data-ranking-brand title="{{ $row['title'] ?? $row['brand'] }}">{{ $row['brand'] }}</span>
                                        <span class="h-2 rounded-full bg-slate-100">
                                            @php
                                                $countWidth = (int) round(((int) $row['count'] / $recordMaxCount) * 100);
                                            @endphp
                                            <span @class(['block h-2 rounded-full w-['.max(4, $countWidth).'%]', 'bg-orange-500' => $isTargetRow, 'bg-blue-600' => ! $isTargetRow])></span>
                                        </span>
                                        <span @class(['text-right text-xs font-semibold', 'text-orange-700' => $isTargetRow, 'text-blue-700' => ! $isTargetRow])>{{ $row['count'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                            <div @class(['mt-auto flex items-center gap-2 rounded-md border border-orange-200 bg-orange-50 px-3 py-2 text-xs font-semibold text-orange-700', 'hidden' => $recordMentionCountTargetInline]) data-ranking-target="mention_count" title="{{ $recordMentionCountTarget['title'] ?? $recordMentionCountTarget['brand'] ?? $record['brand'] }}">
                                <span class="rounded bg-orange-600 px-1.5 py-0.5 text-white">{{ $recordMentionCountTarget['display_rank'] ?? '99+' }}</span>
                                <span class="truncate transition-colors hover:text-orange-800" data-ranking-brand title="{{ $recordMentionCountTarget['title'] ?? $recordMentionCountTarget['brand'] ?? $record['brand'] }}">{{ $recordMentionCountTarget['brand'] }}</span>
                                <span class="shrink-0">{{ $recordMentionCountTarget['count'] }}</span>
                            </div>
                        </div>

                        <div class="flex min-h-[420px] flex-col rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                            <h3 class="mb-4 text-base font-semibold text-gray-900">平均提及排名</h3>
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead class="bg-slate-50 text-xs text-gray-500">
                                        <tr>
                                            <th class="px-3 py-2 text-left font-semibold">品牌</th>
                                            <th class="px-3 py-2 text-right font-semibold">提及率</th>
                                            <th class="px-3 py-2 text-right font-semibold">平均排名</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100" data-ranking-list="average_rank">
                                        @foreach ($recordAverageRankRows as $index => $row)
                                            @php
                                                $isTargetRow = !empty($row['is_target_brand']);
                                            @endphp
                                            <tr @class(['bg-orange-50 text-orange-700' => $isTargetRow]) @if ($isTargetRow) data-ranking-row-target="average_rank" @endif>
                                                <td class="px-3 py-2">
                                                    <span @class(['mr-2 text-xs', 'text-orange-600' => $isTargetRow, 'text-gray-500' => ! $isTargetRow])>{{ $row['display_rank'] ?? $index + 1 }}</span><span @class(['font-medium transition-colors hover:text-orange-700', 'text-orange-700' => $isTargetRow]) data-ranking-brand title="{{ $row['title'] ?? $row['brand'] }}">{{ $row['brand'] }}</span>
                                                </td>
                                                <td @class(['px-3 py-2 text-right font-medium', 'text-orange-700' => $isTargetRow, 'text-gray-700' => ! $isTargetRow])>{{ $row['rate'] }}%</td>
                                                <td @class(['px-3 py-2 text-right font-medium', 'text-orange-700' => $isTargetRow, 'text-gray-700' => ! $isTargetRow])>{{ $row['rank'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div @class(['mt-auto flex items-center gap-2 rounded-md border border-orange-200 bg-orange-50 px-3 py-2 text-xs font-semibold text-orange-700', 'hidden' => $recordAverageRankTargetInline]) data-ranking-target="average_rank" title="{{ $recordAverageRankTarget['title'] ?? $recordAverageRankTarget['brand'] ?? $record['brand'] }}">
                                <span class="rounded bg-orange-600 px-1.5 py-0.5 text-white">{{ $recordAverageRankTarget['display_rank'] ?? '99+' }}</span>
                                <span class="truncate transition-colors hover:text-orange-800" data-ranking-brand title="{{ $recordAverageRankTarget['title'] ?? $recordAverageRankTarget['brand'] ?? $record['brand'] }}">{{ $recordAverageRankTarget['brand'] }}</span>
                                <span class="shrink-0">{{ $recordAverageRankTarget['rank'] }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 items-stretch gap-5 xl:grid-cols-2">
                    <div class="flex h-full flex-col rounded-lg border border-slate-200 bg-white p-4 shadow-sm xl:h-[640px]">
                        <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <h3 class="text-base font-semibold text-gray-900">引用来源</h3>
                            @if (count($recordSources) > 0)
                                <span class="text-xs text-gray-500" data-source-count>共 {{ count($recordSources) }} 条</span>
                            @endif
                        </div>
                        <div class="flex min-h-0 flex-1 flex-col" data-source-pager data-min-page-size="{{ $sourceMinPageSize }}">
                            <div class="flex-1 space-y-3" data-source-list>
                                @forelse ($recordSources as $sourceIndex => $source)
                                    <div @class(['hidden' => $sourceIndex >= $sourceMinPageSize, 'flex gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3']) data-source-item data-platform-key="{{ $source['platform_key'] ?? '' }}">
                                        @if (! empty($source['logo']))
                                            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-white shadow-sm ring-1 ring-slate-100">
                                                <img src="{{ $source['logo'] }}" alt="{{ $source['platform'] ?? 'AI' }} logo" class="h-7 w-7 rounded-full object-contain">
                                            </span>
                                        @else
                                            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-white text-xs font-bold text-orange-700 shadow-sm">{{ $source['icon'] ?? 'AI' }}</span>
                                        @endif
                                        <div class="min-w-0 flex-1">
                                            <div class="mb-1 flex flex-wrap items-center gap-2">
                                                <span class="rounded bg-orange-50 px-2 py-0.5 text-xs font-medium text-orange-700">{{ $source['category'] }}</span>
                                                <span class="text-xs text-gray-500">{{ $source['platform'] }}</span>
                                            </div>
                                            <div class="truncate text-sm font-semibold text-gray-900">{{ $source['title'] }}</div>
                                            <div class="mt-1 text-xs text-gray-500">引用AI问题：{{ $source['questions'] }}　引用平台：{{ $source['models'] }}</div>
                                        </div>
                                        <div class="flex shrink-0 items-center gap-2">
                                            @if (! empty($source['url']))
                                                <a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer" class="rounded-md bg-orange-50 px-3 py-2 text-xs font-semibold text-orange-700">原文</a>
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-gray-500">暂无引用来源，等待诊断完成。</div>
                                @endforelse
                            </div>
                            @if (count($recordSources) > $sourceMinPageSize)
                                <div class="mt-auto flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3" data-source-pagination>
                                    <button type="button" data-source-prev class="inline-flex h-8 items-center gap-1 rounded-md border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-45">
                                        <i data-lucide="chevron-left" class="h-3.5 w-3.5"></i>
                                        上一页
                                    </button>
                                    <span class="text-xs text-gray-500" data-source-page-label>第 1 页</span>
                                    <button type="button" data-source-next class="inline-flex h-8 items-center gap-1 rounded-md border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-45">
                                        下一页
                                        <i data-lucide="chevron-right" class="h-3.5 w-3.5"></i>
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="flex h-full flex-col rounded-lg border border-slate-200 bg-white p-4 shadow-sm xl:h-[640px]">
                        <h3 class="mb-4 text-base font-semibold text-gray-900">AI 对话记录</h3>
                        <div class="flex min-h-0 flex-1 flex-col" data-conversation-pager data-min-page-size="{{ $sourceMinPageSize }}">
                            <div class="min-h-0 flex-1 space-y-3 overflow-y-auto pr-1" data-conversation-list>
                            @forelse ($recordConversations as $conversation)
                                <div @class(['hidden' => $loop->index >= $sourceMinPageSize, 'rounded-lg border border-slate-200 bg-slate-50 p-3']) data-conversation-item data-platform-key="{{ $conversation['platform_key'] ?? '' }}">
                                    <script type="application/json" data-conversation-detail>@json($conversation)</script>
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="inline-flex items-center gap-1.5 rounded bg-white px-2 py-0.5 text-xs font-medium text-gray-700 ring-1 ring-slate-200">
                                                    @if (! empty($conversation['platform_logo']))
                                                        <img src="{{ $conversation['platform_logo'] }}" alt="{{ $conversation['platform'] ?? 'AI' }} logo" class="h-4 w-4 rounded-full object-contain">
                                                    @endif
                                                    <span>{{ $conversation['platform'] ?? 'AI' }}</span>
                                                </span>
                                                <div class="truncate text-sm font-semibold text-gray-900">{{ $conversation['question'] }}</div>
                                            </div>
                                            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-gray-500" data-visible-brand-list title="{{ implode('、', $conversation['brands'] ?? []) }}">
                                                <span>提及品牌</span>
                                                @forelse (($conversation['visible_brands'] ?? array_slice($conversation['brands'] ?? [], 0, 4)) as $brand)
                                                    <span class="rounded bg-blue-50 px-2 py-1 font-medium text-blue-700">{{ $brand }}</span>
                                                @empty
                                                    <span class="rounded bg-slate-100 px-2 py-1 font-medium text-slate-500">未提及</span>
                                                @endforelse
                                                @if (($conversation['hidden_brand_count'] ?? max(0, count($conversation['brands'] ?? []) - 4)) > 0)
                                                    <span class="rounded bg-slate-100 px-2 py-1 font-medium text-slate-500">...</span>
                                                @endif
                                            </div>
                                            @if (! empty($conversation['answer']))
                                                <p class="mt-2 line-clamp-2 text-xs leading-5 text-gray-600">{{ $conversation['answer'] }}</p>
                                            @endif
                                        </div>
                                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                                            @if (! empty($conversation['snapshot_url']))
                                                <a href="{{ $conversation['snapshot_url'] }}" target="_blank" rel="noopener noreferrer" title="打开快照凭证" class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-600 hover:border-orange-200 hover:text-orange-700">
                                                    <i data-lucide="file-check-2" class="h-4 w-4"></i>
                                                </a>
                                            @endif
                                            @if (! empty($conversation['official_share_url']))
                                                <a href="{{ $conversation['official_share_url'] }}" target="_blank" rel="noopener noreferrer" title="打开官方链接" class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-orange-200 bg-orange-50 text-orange-700 hover:bg-orange-100">
                                                    <i data-lucide="external-link" class="h-4 w-4"></i>
                                                </a>
                                            @endif
                                            <button type="button" data-conversation-detail-open class="inline-flex h-9 items-center justify-center rounded-md bg-orange-50 px-3 text-xs font-semibold text-orange-700 hover:bg-orange-100">AI对话详情</button>
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-gray-500">暂无 AI 对话记录，等待诊断完成。</div>
                            @endforelse
                            </div>
                            @if (count($recordConversations) > $sourceMinPageSize)
                                <div class="mt-auto flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3" data-conversation-pagination>
                                    <button type="button" data-conversation-prev class="inline-flex h-8 items-center gap-1 rounded-md border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-45">
                                        <i data-lucide="chevron-left" class="h-3.5 w-3.5"></i>
                                        上一页
                                    </button>
                                    <span class="text-xs text-gray-500" data-conversation-page-label>第 1 页</span>
                                    <button type="button" data-conversation-next class="inline-flex h-8 items-center gap-1 rounded-md border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-45">
                                        下一页
                                        <i data-lucide="chevron-right" class="h-3.5 w-3.5"></i>
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                                <div class="mt-6 flex flex-wrap justify-center gap-3 border-t border-slate-200 pt-5">
                    @if ($record['has_report'] ?? false)
                        <a href="{{ route('admin.brand-diagnosis.report', ['run' => $record['id']]) }}" target="_blank" rel="noopener noreferrer" class="inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 bg-white px-5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            <i data-lucide="file-text" class="h-4 w-4"></i>
                            查看报告
                        </a>
                        @if ($record['can_manage_official_links'] ?? false)
                            <a href="{{ route('admin.brand-diagnosis.official-links.edit', ['run' => $record['id']]) }}" class="inline-flex h-10 items-center gap-2 rounded-md border border-orange-200 bg-orange-50 px-5 text-sm font-semibold text-orange-700 hover:bg-orange-100">
                                <i data-lucide="link-2" class="h-4 w-4"></i>
                                官方链接管理
                            </a>
                        @endif
                    @else
                        <span class="inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 bg-slate-50 px-5 text-sm font-semibold text-slate-400">
                            <i data-lucide="file-clock" class="h-4 w-4"></i>
                            报告生成中
                        </span>
                    @endif
                    <button type="button" data-record-toggle class="inline-flex h-10 items-center gap-2 rounded-md border border-orange-200 bg-orange-50 px-5 text-sm font-semibold text-orange-700 hover:bg-orange-100">
                        <i data-lucide="chevrons-up" class="h-4 w-4"></i>
                        <span data-record-toggle-label>收起结果</span>
                    </button>
                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
                @if ($recordPaginator)
                    <div class="mt-5 border-t border-slate-200 pt-4">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="text-sm text-gray-500">
                                共 {{ $recordPaginator->total() }} 条诊断记录，当前显示 {{ $recordPaginator->firstItem() ?? 0 }}-{{ $recordPaginator->lastItem() ?? 0 }} 条
                            </div>
                            @if ($recordPaginator->lastPage() > 1)
                                <div class="min-w-0">
                                    {{ $recordPaginator->onEachSide(1)->links() }}
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </section>

        <div
            data-report-modal
            class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/45 px-4 py-6"
            role="dialog"
            aria-modal="true"
            aria-labelledby="report-modal-title"
        >
            <div class="w-full max-w-md rounded-lg bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                    <h2 id="report-modal-title" class="text-base font-semibold text-gray-900">选择导出报告</h2>
                    <button type="button" data-report-modal-close class="inline-flex h-8 w-8 items-center justify-center rounded-md text-gray-500 hover:bg-slate-100" aria-label="关闭">
                        <i data-lucide="x" class="h-4 w-4"></i>
                    </button>
                </div>
                <div
                    class="px-5 py-4"
                    data-report-pager
                    data-report-page-size="{{ $reportPageSize }}"
                    data-report-total-pages="{{ $reportTotalPages }}"
                >
                    <div class="space-y-3">
                    @foreach ($reportOptions as $report)
                        @php
                            $reportViewUrl = route('admin.brand-diagnosis.report', ['run' => $report['id']]);
                            $reportDownloadUrl = route('admin.brand-diagnosis.report.download', ['run' => $report['id']]);
                        @endphp
                        <div data-report-item @class(['hidden' => $loop->index >= $reportPageSize])>
                        <div
                            data-report-option
                            class="flex w-full items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white px-4 py-3 text-left hover:border-orange-200 hover:bg-orange-50"
                        >
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-semibold text-gray-900">{{ $report['brand'] }} 报告</span>
                                <span class="mt-1 block text-xs text-gray-500">{{ $report['created_at'] }}</span>
                            </span>
                            <span class="flex shrink-0 items-center gap-2">
                                <a
                                    href="{{ $reportViewUrl }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    data-report-view
                                    data-report-link-action
                                    class="inline-flex h-8 items-center gap-1.5 rounded-md border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 hover:border-orange-200 hover:text-orange-700"
                                >
                                    <i data-lucide="eye" class="h-3.5 w-3.5"></i>
                                    查看
                                </a>
                                <a
                                    href="{{ $reportDownloadUrl }}"
                                    data-report-download
                                    data-report-link-action
                                    class="inline-flex h-8 items-center gap-1.5 rounded-md bg-orange-600 px-3 text-xs font-semibold text-white hover:bg-orange-700"
                                >
                                    <i data-lucide="download" class="h-3.5 w-3.5"></i>
                                    导出PDF
                                </a>
                            </span>
                        </div>
                        </div>
                    @endforeach
                    </div>
                    @if (count($reportOptions) > $reportPageSize)
                        <div class="mt-4 flex items-center justify-between gap-3 border-t border-slate-200 pt-3" data-report-pagination>
                            <button type="button" data-report-prev class="inline-flex h-8 items-center justify-center rounded-md border border-slate-200 bg-white px-3 text-xs font-medium text-slate-600 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50">
                                上一页
                            </button>
                            <span class="text-xs text-gray-500" data-report-page-label>第 1 / {{ $reportTotalPages }} 页</span>
                            <button type="button" data-report-next class="inline-flex h-8 items-center justify-center rounded-md border border-slate-200 bg-white px-3 text-xs font-medium text-slate-600 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50">
                                下一页
                            </button>
                        </div>
                    @endif
                </div>
            </div>
        </div>
        <div
            data-conversation-modal
            class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/45 px-4 py-6"
            role="dialog"
            aria-modal="true"
            aria-labelledby="conversation-modal-title"
        >
            <div class="flex max-h-[86vh] w-full max-w-3xl flex-col rounded-lg bg-white shadow-xl">
                <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                    <div class="min-w-0">
                        <div class="mb-2 flex flex-wrap items-center gap-2">
                            <span class="inline-flex items-center gap-1.5 rounded bg-orange-50 px-2 py-0.5 text-xs font-medium text-orange-700">
                                <img src="" alt="" class="hidden h-4 w-4 rounded-full object-contain" data-conversation-modal-platform-logo>
                                <span data-conversation-modal-platform>AI</span>
                            </span>
                            <span class="text-xs text-gray-500" data-conversation-modal-status></span>
                        </div>
                        <h2 id="conversation-modal-title" class="text-base font-semibold text-gray-900">AI对话详情</h2>
                        <p class="mt-1 text-sm text-gray-600" data-conversation-modal-question></p>
                    </div>
                    <button type="button" data-conversation-modal-close class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-gray-500 hover:bg-slate-100" aria-label="关闭">
                        <i data-lucide="x" class="h-4 w-4"></i>
                    </button>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                    <section>
                        <h3 class="mb-2 text-sm font-semibold text-gray-900">对话详情</h3>
                        <div class="whitespace-pre-wrap rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-6 text-gray-700" data-conversation-modal-answer></div>
                    </section>
                    <section class="mt-5">
                        <h3 class="mb-2 text-sm font-semibold text-gray-900">引用记录</h3>
                        <div class="space-y-2" data-conversation-modal-sources></div>
                    </section>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const platformCheckboxes = Array.from(document.querySelectorAll('[data-platform-checkbox]'));
            const selectedPlatformsLabel = document.querySelector('[data-selected-platforms]');
            const renderSelectedPlatforms = () => {
                const names = platformCheckboxes
                    .filter((checkbox) => checkbox.checked)
                    .map((checkbox) => checkbox.dataset.platformName || checkbox.value)
                    .filter(Boolean);

                if (selectedPlatformsLabel) {
                    selectedPlatformsLabel.textContent = names.length > 0 ? names.join('、') : '未选择';
                }
            };

            platformCheckboxes.forEach((checkbox) => {
                checkbox.addEventListener('change', renderSelectedPlatforms);
            });
            renderSelectedPlatforms();

            document.querySelectorAll('[data-record-toggle]').forEach((button) => {
                button.addEventListener('click', () => {
                    const record = button.closest('[data-diagnosis-record]');
                    const detail = record?.querySelector('[data-record-detail]');
                    if (!record || !detail) return;

                    const shouldExpand = detail.classList.contains('hidden');
                    detail.classList.toggle('hidden', !shouldExpand);

                    record.querySelectorAll('[data-record-toggle]').forEach((toggle) => {
                        toggle.setAttribute('aria-expanded', shouldExpand ? 'true' : 'false');
                        const label = toggle.querySelector('[data-record-toggle-label]');
                        if (label) {
                            label.textContent = shouldExpand ? '收起结果' : '查看结果';
                        }
                    });
                });
            });

            const exportButton = document.querySelector('[data-export-report]');
            const reportModal = document.querySelector('[data-report-modal]');
            const reportCount = Number(exportButton?.dataset.reportCount || 0);
            const reportPager = reportModal?.querySelector('[data-report-pager]');
            const reportItems = Array.from(reportPager?.querySelectorAll('[data-report-item]') || []);
            const reportPageSize = Math.max(1, Number(reportPager?.dataset.reportPageSize || 5));
            const reportPrevButton = reportPager?.querySelector('[data-report-prev]');
            const reportNextButton = reportPager?.querySelector('[data-report-next]');
            const reportPageLabel = reportPager?.querySelector('[data-report-page-label]');
            let reportCurrentPage = 1;

            const renderReportPage = () => {
                const reportTotalPages = Math.max(1, Math.ceil(reportItems.length / reportPageSize));
                reportCurrentPage = Math.max(1, Math.min(reportCurrentPage, reportTotalPages));
                const start = (reportCurrentPage - 1) * reportPageSize;
                const end = start + reportPageSize;

                reportItems.forEach((item, index) => {
                    item.classList.toggle('hidden', index < start || index >= end);
                });

                if (reportPageLabel) {
                    reportPageLabel.textContent = `第 ${reportCurrentPage} / ${reportTotalPages} 页`;
                }
                if (reportPrevButton) {
                    reportPrevButton.disabled = reportCurrentPage <= 1;
                }
                if (reportNextButton) {
                    reportNextButton.disabled = reportCurrentPage >= reportTotalPages;
                }
            };

            const openReportModal = () => {
                reportCurrentPage = 1;
                renderReportPage();
                reportModal?.classList.remove('hidden');
                reportModal?.classList.add('flex');
            };

            const closeReportModal = () => {
                reportModal?.classList.add('hidden');
                reportModal?.classList.remove('flex');
            };

            exportButton?.addEventListener('click', () => {
                if (reportCount < 1) {
                    window.alert('当前页面暂无可导出的报告');
                    return;
                }

                if (reportCount === 1) {
                    const url = exportButton.dataset.singleReportDownloadUrl;
                    if (url) {
                        window.open(url, '_blank', 'noopener,noreferrer');
                    }
                    return;
                }

                openReportModal();
            });

            reportModal?.querySelectorAll('[data-report-modal-close]').forEach((button) => {
                button.addEventListener('click', closeReportModal);
            });

            reportModal?.addEventListener('click', (event) => {
                if (event.target === reportModal) {
                    closeReportModal();
                }
            });

            reportPrevButton?.addEventListener('click', () => {
                reportCurrentPage = Math.max(1, reportCurrentPage - 1);
                renderReportPage();
            });

            reportNextButton?.addEventListener('click', () => {
                reportCurrentPage += 1;
                renderReportPage();
            });

            renderReportPage();

            const brandDiagnosisForm = document.querySelector('[data-brand-diagnosis-form]');
            let brandDiagnosisReuseChecked = false;
            brandDiagnosisForm?.addEventListener('submit', async (event) => {
                if (brandDiagnosisReuseChecked) {
                    return;
                }

                const brandInput = brandDiagnosisForm.querySelector('[name="brand_name"]');
                const reuseField = brandDiagnosisForm.querySelector('[data-reuse-questions-field]');
                const brandName = (brandInput?.value || '').trim();
                if (!brandName || !reuseField || !brandDiagnosisForm.dataset.reuseCheckUrl) {
                    return;
                }

                event.preventDefault();
                reuseField.value = '0';

                try {
                    const url = new URL(brandDiagnosisForm.dataset.reuseCheckUrl, window.location.origin);
                    url.searchParams.set('brand_name', brandName);
                    const response = await fetch(url.toString(), {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                    const payload = response.ok ? await response.json() : null;
                    if (payload?.available) {
                        const createdAt = payload?.preview?.created_at ? `\n最近一次：${payload.preview.created_at}` : '';
                        reuseField.value = window.confirm(`检测到该品牌最近一次生成的 AI 问题，是否复用并回填到问题框？${createdAt}`) ? '1' : '0';
                    }
                } catch (error) {
                    reuseField.value = '0';
                }

                brandDiagnosisReuseChecked = true;
                brandDiagnosisForm.requestSubmit();
            });

            reportModal?.querySelectorAll('[data-report-link-action]').forEach((link) => {
                link.addEventListener('click', () => {
                    closeReportModal();
                });
            });

            document.querySelectorAll('[data-confirm-diagnosis-form]').forEach((form) => {
                form.addEventListener('submit', () => {
                    const button = form.querySelector('[data-confirm-diagnosis-submit]');
                    if (!button) return;

                    button.disabled = true;
                    button.innerHTML = '<span class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>诊断中...';
                });
            });

            const conversationModal = document.querySelector('[data-conversation-modal]');
            const conversationPlatform = conversationModal?.querySelector('[data-conversation-modal-platform]');
            const conversationPlatformLogo = conversationModal?.querySelector('[data-conversation-modal-platform-logo]');
            const conversationStatus = conversationModal?.querySelector('[data-conversation-modal-status]');
            const conversationQuestion = conversationModal?.querySelector('[data-conversation-modal-question]');
            const conversationAnswer = conversationModal?.querySelector('[data-conversation-modal-answer]');
            const conversationSources = conversationModal?.querySelector('[data-conversation-modal-sources]');

            const closeConversationModal = () => {
                conversationModal?.classList.add('hidden');
                conversationModal?.classList.remove('flex');
            };

            const sourceLink = (source) => {
                const row = document.createElement('div');
                row.className = 'rounded-lg border border-slate-200 bg-white px-3 py-2';

                const title = document.createElement(source?.url ? 'a' : 'div');
                title.className = 'block text-sm font-semibold text-gray-900 hover:text-orange-700';
                title.textContent = source?.title || source?.url || '未命名引用';
                if (source?.url) {
                    title.href = source.url;
                    title.target = '_blank';
                    title.rel = 'noopener noreferrer';
                }

                const meta = document.createElement('div');
                meta.className = 'mt-1 text-xs text-gray-500';
                meta.textContent = [source?.domain, source?.type].filter(Boolean).join(' · ') || '网页来源';

                row.append(title, meta);
                return row;
            };

            const openConversationModal = (conversation) => {
                if (!conversationModal) return;
                if (conversationPlatform) conversationPlatform.textContent = conversation?.platform || 'AI';
                if (conversationPlatformLogo) {
                    const logo = conversation?.platform_logo || '';
                    if (logo) {
                        conversationPlatformLogo.src = logo;
                        conversationPlatformLogo.alt = `${conversation?.platform || 'AI'} logo`;
                        conversationPlatformLogo.classList.remove('hidden');
                    } else {
                        conversationPlatformLogo.removeAttribute('src');
                        conversationPlatformLogo.alt = '';
                        conversationPlatformLogo.classList.add('hidden');
                    }
                }
                if (conversationStatus) conversationStatus.textContent = conversation?.status || '';
                if (conversationQuestion) conversationQuestion.textContent = conversation?.question || '';
                if (conversationAnswer) conversationAnswer.textContent = conversation?.answer || '暂无回答内容。';
                if (conversationSources) {
                    conversationSources.innerHTML = '';
                    const sources = Array.isArray(conversation?.sources) ? conversation.sources : [];
                    if (sources.length === 0) {
                        const empty = document.createElement('div');
                        empty.className = 'rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center text-sm text-gray-500';
                        empty.textContent = '暂无引用记录。';
                        conversationSources.appendChild(empty);
                    } else {
                        sources.forEach((source) => conversationSources.appendChild(sourceLink(source)));
                    }
                }
                conversationModal.classList.remove('hidden');
                conversationModal.classList.add('flex');
            };

            document.querySelectorAll('[data-conversation-detail-open]').forEach((button) => {
                button.addEventListener('click', () => {
                    const item = button.closest('[data-conversation-item]');
                    const node = item?.querySelector('[data-conversation-detail]');
                    if (!node) return;
                    try {
                        openConversationModal(JSON.parse(node.textContent || '{}'));
                    } catch (error) {
                        openConversationModal({});
                    }
                });
            });

            conversationModal?.querySelectorAll('[data-conversation-modal-close]').forEach((button) => {
                button.addEventListener('click', closeConversationModal);
            });

            conversationModal?.addEventListener('click', (event) => {
                if (event.target === conversationModal) {
                    closeConversationModal();
                }
            });

            const metricOrder = [
                { key: 'score', suffix: '' },
                { key: 'mention_rate', suffix: '%' },
                { key: 'average_rank', suffix: '名' },
                { key: 'mention_count', suffix: '次' },
                { key: 'sentiment_rate', suffix: '%' },
            ];
            const widthClasses = ['w-0', 'w-1/12', 'w-2/12', 'w-3/12', 'w-4/12', 'w-5/12', 'w-6/12', 'w-7/12', 'w-8/12', 'w-9/12', 'w-10/12', 'w-11/12', 'w-full'];

            const widthClass = (value, maxValue) => {
                if (!value || !maxValue) return widthClasses[0];
                const index = Math.max(1, Math.min(12, Math.ceil((Number(value) / Number(maxValue)) * 12)));
                return widthClasses[index];
            };

            const readRecordPlatformData = (record) => {
                if (record.platformDataCache) {
                    return record.platformDataCache;
                }
                const node = record.querySelector('[data-record-platform-data]');
                if (!node) return {};
                try {
                    record.platformDataCache = JSON.parse(node.textContent || '{}');
                    return record.platformDataCache;
                } catch (error) {
                    return {};
                }
            };

            const renderMetricCards = (record, metrics = {}) => {
                record.querySelectorAll('[data-metric-card]').forEach((card, index) => {
                    const item = metricOrder[index];
                    if (!item) return;
                    const value = metrics[item.key] ?? 0;
                    card.textContent = `${value}${item.suffix}`;
                });
            };

            const renderTarget = (container, row, suffix) => {
                if (!container) return;
                container.innerHTML = '';
                container.title = row?.title || row?.brand || '-';
                const badge = document.createElement('span');
                badge.className = 'rounded bg-orange-600 px-1.5 py-0.5 text-white';
                badge.textContent = row?.display_rank || '99+';
                container.appendChild(badge);
                const brand = document.createElement('span');
                brand.className = 'truncate transition-colors hover:text-orange-800';
                brand.dataset.rankingBrand = '';
                brand.title = row?.title || row?.brand || '-';
                brand.textContent = row?.brand || '-';
                const value = document.createElement('span');
                value.className = 'shrink-0';
                value.textContent = `${row?.[suffix.key] ?? suffix.fallback}${suffix.text}`;
                container.append(brand, value);
            };

            const isInlineTargetRow = (row) => {
                const rank = Number(row?.display_rank);

                return Boolean(row?.is_target_brand) && Number.isFinite(rank) && rank <= 10;
            };

            const visibleRankingRows = (rows) => rows
                .filter((row) => !row?.is_target_brand || isInlineTargetRow(row))
                .slice(0, 10);

            const renderMentionRanking = (record, key) => {
                const data = readRecordPlatformData(record);
                const payload = data[record.dataset.activePlatform || 'all'] || data.all || {};
                const rows = payload.rankings?.[key] || [];
                const list = record.querySelector(`[data-ranking-list="${key}"]`);
                const target = rows.find((row) => row.is_target_brand) || rows[rows.length - 1] || {};
                const displayRows = visibleRankingRows(rows);
                const targetInline = displayRows.some((row) => row.is_target_brand);
                const valueKey = key === 'mention_count' ? 'count' : 'rate';
                const maxValue = Math.max(1, ...displayRows.map((row) => Number(row[valueKey] || 0)));
                if (!list) return;

                list.innerHTML = '';
                displayRows.forEach((row, index) => {
                    const isTarget = Boolean(row.is_target_brand);
                    const item = document.createElement('div');
                    item.className = (key === 'mention_count'
                        ? 'grid grid-cols-[24px_88px_1fr_32px] items-center gap-2 text-sm'
                        : 'grid grid-cols-[24px_88px_1fr_40px] items-center gap-2 text-sm')
                        + (isTarget ? ' rounded-md border border-orange-200 bg-orange-50 px-2 py-1 font-semibold text-orange-700' : '');
                    if (isTarget) {
                        item.dataset.rankingRowTarget = key;
                    }

                    const order = document.createElement('span');
                    order.className = isTarget ? 'text-xs text-orange-600' : 'text-xs text-gray-500';
                    order.textContent = String(row.display_rank || index + 1);
                    const brand = document.createElement('span');
                    brand.className = `truncate font-medium transition-colors hover:text-orange-700 ${isTarget ? 'text-orange-700' : 'text-gray-700'}`;
                    brand.dataset.rankingBrand = '';
                    brand.title = row.title || row.brand || '-';
                    brand.textContent = row.brand || '-';
                    const bar = document.createElement('span');
                    bar.className = 'h-2 rounded-full bg-slate-100';
                    const fill = document.createElement('span');
                    fill.className = `block h-2 rounded-full ${isTarget ? 'bg-orange-500' : 'bg-blue-600'} ${widthClass(row[valueKey], maxValue)}`;
                    bar.appendChild(fill);
                    const value = document.createElement('span');
                    value.className = `text-right text-xs font-semibold ${isTarget ? 'text-orange-700' : 'text-blue-700'}`;
                    value.textContent = key === 'mention_count' ? String(row.count || 0) : `${row.rate || 0}%`;

                    item.append(order, brand, bar, value);
                    list.appendChild(item);
                });

                if (displayRows.length === 0) {
                    const empty = document.createElement('div');
                    empty.className = 'rounded-md border border-dashed border-slate-200 bg-slate-50 px-3 py-4 text-center text-xs text-gray-500';
                    empty.textContent = '暂无竞品提及数据';
                    list.appendChild(empty);
                }

                const targetContainer = record.querySelector(`[data-ranking-target="${key}"]`);
                targetContainer?.classList.toggle('hidden', targetInline);
                if (!targetInline) {
                    renderTarget(
                        targetContainer,
                        target,
                        key === 'mention_count' ? { key: 'count', fallback: 0, text: '' } : { key: 'rate', fallback: 0, text: '%' }
                    );
                }
            };

            const renderAverageRanking = (record) => {
                const data = readRecordPlatformData(record);
                const payload = data[record.dataset.activePlatform || 'all'] || data.all || {};
                const rows = payload.rankings?.average_rank || [];
                const list = record.querySelector('[data-ranking-list="average_rank"]');
                const target = rows.find((row) => row.is_target_brand) || rows[rows.length - 1] || {};
                const displayRows = visibleRankingRows(rows);
                const targetInline = displayRows.some((row) => row.is_target_brand);
                if (!list) return;

                list.innerHTML = '';
                displayRows.forEach((row, index) => {
                    const isTarget = Boolean(row.is_target_brand);
                    const tr = document.createElement('tr');
                    if (isTarget) {
                        tr.className = 'bg-orange-50 text-orange-700';
                        tr.dataset.rankingRowTarget = 'average_rank';
                    }
                    const brand = document.createElement('td');
                    brand.className = 'px-3 py-2';
                    const order = document.createElement('span');
                    order.className = isTarget ? 'mr-2 text-xs text-orange-600' : 'mr-2 text-xs text-gray-500';
                    order.textContent = String(row.display_rank || index + 1);
                    const brandName = document.createElement('span');
                    brandName.className = `font-medium transition-colors hover:text-orange-700 ${isTarget ? 'text-orange-700' : ''}`;
                    brandName.dataset.rankingBrand = '';
                    brandName.title = row.title || row.brand || '-';
                    brandName.textContent = row.brand || '-';
                    brand.appendChild(order);
                    brand.appendChild(brandName);
                    const rate = document.createElement('td');
                    rate.className = `px-3 py-2 text-right font-medium ${isTarget ? 'text-orange-700' : 'text-gray-700'}`;
                    rate.textContent = `${row.rate || 0}%`;
                    const rank = document.createElement('td');
                    rank.className = `px-3 py-2 text-right font-medium ${isTarget ? 'text-orange-700' : 'text-gray-700'}`;
                    rank.textContent = row.rank || '0';
                    tr.append(brand, rate, rank);
                    list.appendChild(tr);
                });

                if (displayRows.length === 0) {
                    const tr = document.createElement('tr');
                    const td = document.createElement('td');
                    td.className = 'px-3 py-6 text-center text-xs text-gray-500';
                    td.colSpan = 3;
                    td.textContent = '暂无竞品排名数据';
                    tr.appendChild(td);
                    list.appendChild(tr);
                }

                const targetContainer = record.querySelector('[data-ranking-target="average_rank"]');
                targetContainer?.classList.toggle('hidden', targetInline);
                if (!targetInline) {
                    renderTarget(targetContainer, target, { key: 'rank', fallback: '0', text: '' });
                }
            };

            const renderRecordPlatform = (record, platform) => {
                const data = readRecordPlatformData(record);
                const payload = data[platform] || data.all || {};
                record.dataset.activePlatform = data[platform] ? platform : 'all';
                renderMetricCards(record, payload.metrics || {});
                renderMentionRanking(record, 'mention_rate');
                renderMentionRanking(record, 'mention_count');
                renderAverageRanking(record);

                const activePlatform = record.dataset.activePlatform || 'all';
                const sourceCount = record.querySelector('[data-source-count]');
                const sourceItems = Array.from(record.querySelectorAll('[data-source-item]'));
                const activeSourceCount = sourceItems.filter((item) => activePlatform === 'all' || item.dataset.platformKey === activePlatform).length;
                if (sourceCount) {
                    sourceCount.textContent = `共 ${activeSourceCount} 条`;
                }
                record.querySelectorAll('[data-source-pager]').forEach((pager) => {
                    if (typeof pager.resetSourcePage === 'function') {
                        pager.resetSourcePage();
                        return;
                    }
                    pager.renderSourcePage?.();
                });

                record.querySelectorAll('[data-conversation-item]').forEach((item) => {
                    item.classList.toggle('hidden', activePlatform !== 'all' && item.dataset.platformKey !== activePlatform);
                });
                record.querySelectorAll('[data-conversation-pager]').forEach((pager) => {
                    if (typeof pager.resetConversationPage === 'function') {
                        pager.resetConversationPage();
                        return;
                    }
                    pager.renderConversationPage?.();
                });
            };

            document.querySelectorAll('[data-source-pager]').forEach((pager) => {
                const items = Array.from(pager.querySelectorAll('[data-source-item]'));
                const minPageSize = Math.max(1, Number(pager.dataset.minPageSize || 5));
                const prevButton = pager.querySelector('[data-source-prev]');
                const nextButton = pager.querySelector('[data-source-next]');
                const pageLabel = pager.querySelector('[data-source-page-label]');
                let pageSize = minPageSize;
                let totalPages = Math.max(1, Math.ceil(items.length / pageSize));
                let currentPage = 1;

                const renderPage = () => {
                    const activePlatform = pager.closest('[data-diagnosis-record]')?.dataset.activePlatform || 'all';
                    const eligibleItems = items.filter((item) => activePlatform === 'all' || item.dataset.platformKey === activePlatform);
                    pageSize = minPageSize;
                    totalPages = Math.max(1, Math.ceil(eligibleItems.length / pageSize));
                    currentPage = Math.min(currentPage, totalPages);

                    const start = (currentPage - 1) * pageSize;
                    const end = start + pageSize;
                    items.forEach((item) => item.classList.add('hidden'));
                    eligibleItems.forEach((item, index) => {
                        item.classList.toggle('hidden', index < start || index >= end);
                    });

                    if (pageLabel) {
                        pageLabel.textContent = `第 ${currentPage} / ${totalPages} 页`;
                    }
                    if (prevButton) {
                        prevButton.disabled = currentPage <= 1;
                    }
                    if (nextButton) {
                        nextButton.disabled = currentPage >= totalPages;
                    }
                };

                prevButton?.addEventListener('click', () => {
                    currentPage = Math.max(1, currentPage - 1);
                    renderPage();
                });

                nextButton?.addEventListener('click', () => {
                    currentPage = Math.min(totalPages, currentPage + 1);
                    renderPage();
                });

                pager.renderSourcePage = renderPage;
                pager.resetSourcePage = () => {
                    currentPage = 1;
                    renderPage();
                };
                renderPage();
            });

            document.querySelectorAll('[data-conversation-pager]').forEach((pager) => {
                const items = Array.from(pager.querySelectorAll('[data-conversation-item]'));
                const minPageSize = Math.max(1, Number(pager.dataset.minPageSize || 5));
                const prevButton = pager.querySelector('[data-conversation-prev]');
                const nextButton = pager.querySelector('[data-conversation-next]');
                const pageLabel = pager.querySelector('[data-conversation-page-label]');
                let pageSize = minPageSize;
                let totalPages = Math.max(1, Math.ceil(items.length / pageSize));
                let currentPage = 1;

                const renderPage = () => {
                    const activePlatform = pager.closest('[data-diagnosis-record]')?.dataset.activePlatform || 'all';
                    const eligibleItems = items.filter((item) => activePlatform === 'all' || item.dataset.platformKey === activePlatform);
                    pageSize = minPageSize;
                    totalPages = Math.max(1, Math.ceil(eligibleItems.length / pageSize));
                    currentPage = Math.min(currentPage, totalPages);

                    const start = (currentPage - 1) * pageSize;
                    const end = start + pageSize;
                    items.forEach((item) => item.classList.add('hidden'));
                    eligibleItems.forEach((item, index) => {
                        item.classList.toggle('hidden', index < start || index >= end);
                    });

                    if (pageLabel) {
                        pageLabel.textContent = `第 ${currentPage} / ${totalPages} 页`;
                    }
                    if (prevButton) {
                        prevButton.disabled = currentPage <= 1;
                    }
                    if (nextButton) {
                        nextButton.disabled = currentPage >= totalPages;
                    }
                };

                prevButton?.addEventListener('click', () => {
                    currentPage = Math.max(1, currentPage - 1);
                    renderPage();
                });

                nextButton?.addEventListener('click', () => {
                    currentPage = Math.min(totalPages, currentPage + 1);
                    renderPage();
                });

                pager.renderConversationPage = renderPage;
                pager.resetConversationPage = () => {
                    currentPage = 1;
                    renderPage();
                };
                renderPage();
            });

            document.querySelectorAll('[data-platform-filter]').forEach((select) => {
                select.addEventListener('change', () => {
                    const record = select.closest('[data-diagnosis-record]');
                    if (!record) return;
                    renderRecordPlatform(record, select.value || 'all');
                });
            });

        });
    </script>
@endpush
