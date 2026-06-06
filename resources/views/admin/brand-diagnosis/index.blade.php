@extends('admin.layouts.app')

@php
    $maxRate = max([1, ...array_map(static fn ($row) => (int) $row['rate'], $mentionRateRanking)]);
    $maxCount = max([1, ...array_map(static fn ($row) => (int) $row['count'], $mentionCountRanking)]);
    $reportOptions = array_values(array_filter($diagnosisRecords, static fn ($record) => (bool) $record['has_report']));
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
                    class="inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50"
                >
                    <i data-lucide="download" class="h-4 w-4"></i>
                    导出报告
                </button>
            </div>
        </div>

        <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm sm:p-5" aria-labelledby="brand-diagnosis-form-title">
            <form method="POST" action="{{ route('admin.brand-diagnosis.store') }}" class="flex flex-col gap-4 lg:flex-row lg:items-start">
                @csrf
                <div class="min-w-0 flex-1">
                    <label id="brand-diagnosis-form-title" for="brand-name" class="mb-2 block text-sm font-semibold text-gray-900">品牌名称</label>
                    <div class="flex flex-col gap-3 sm:flex-row">
                        <input id="brand-name" name="brand_name" type="text" value="{{ old('brand_name', '策影GEO') }}" class="min-w-0 flex-1 rounded-md border border-slate-200 bg-white text-sm" aria-label="品牌名称">
                        <button type="submit" class="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-md bg-orange-600 px-5 text-sm font-semibold text-white hover:bg-orange-700">
                            <i data-lucide="search" class="h-4 w-4"></i>
                            搜索一下
                        </button>
                    </div>
                    @error('brand_name')
                        <p class="mt-2 text-xs font-medium text-red-600">{{ $message }}</p>
                    @enderror
                    <p class="mt-2 text-xs text-gray-500">选择模型和检索深度后，可生成品牌提及率、提及次数、排名和引用来源报告。</p>
                </div>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:w-[520px]">
                    @foreach ($models as $model)
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <div class="flex items-center gap-2">
                                <span class="{{ $model['color'] }} inline-flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold text-white">{{ $model['initial'] }}</span>
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
                                        <input name="platforms[]" value="{{ $model['key'] }}" type="checkbox" checked>
                                        启用诊断
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
                        当前先跑通豆包真实联网诊断
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
                <form class="flex flex-col gap-3 sm:flex-row">
                    <label class="sr-only" for="brand-keyword">品牌</label>
                    <input id="brand-keyword" type="search" placeholder="品牌" class="h-10 w-full sm:w-44">
                    <label class="sr-only" for="start-date">开始日期</label>
                    <input id="start-date" type="text" placeholder="开始日期" class="h-10 w-full sm:w-32">
                    <label class="sr-only" for="end-date">结束日期</label>
                    <input id="end-date" type="text" placeholder="结束日期" class="h-10 w-full sm:w-32">
                    <button type="button" class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-orange-600 px-4 text-sm font-semibold text-white hover:bg-orange-700">
                        <i data-lucide="search" class="h-4 w-4"></i>
                        搜索
                    </button>
                </form>
            </div>

            <div class="px-5 py-5">
                @if (count($diagnosisRecords) === 0)
                    <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-5 py-10 text-center">
                        <div class="text-sm font-semibold text-gray-900">暂无诊断记录</div>
                        <div class="mt-2 text-sm text-gray-500">输入品牌名称并搜索后，每次诊断都会新增一条记录。</div>
                    </div>
                @endif
                <div class="space-y-4">
                    @foreach ($diagnosisRecords as $record)
                        @php
                            $recordQuestions = $record['questions'] ?? [];
                            $recordSources = $record['sources'] ?? [];
                            $recordConversations = $record['conversations'] ?? [];
                            $recordMentionRateRanking = [['brand' => $record['brand'], 'rate' => (int) $record['metrics']['mention_rate']]];
                            $recordMentionCountRanking = [['brand' => $record['brand'], 'count' => (int) $record['metrics']['mention_count']]];
                            $recordAverageRankings = [[
                                'brand' => $record['brand'],
                                'rate' => (int) $record['metrics']['mention_rate'],
                                'rank' => $record['metrics']['average_rank'].'名',
                            ]];
                            $recordMaxRate = max([1, ...array_map(static fn ($row) => (int) $row['rate'], $recordMentionRateRanking)]);
                            $recordMaxCount = max([1, ...array_map(static fn ($row) => (int) $row['count'], $recordMentionCountRanking)]);
                            $recordMetricCards = [
                                ['label' => '品牌得分 / 100', 'value' => $record['metrics']['score'], 'suffix' => '', 'value_class' => 'text-orange-600'],
                                ['label' => '品牌提及率', 'value' => $record['metrics']['mention_rate'], 'suffix' => '%', 'value_class' => 'text-gray-900'],
                                ['label' => '平均提及排名', 'value' => $record['metrics']['average_rank'], 'suffix' => '名', 'value_class' => 'text-gray-900'],
                                ['label' => '品牌提及次数', 'value' => $record['metrics']['mention_count'], 'suffix' => '次', 'value_class' => 'text-gray-900'],
                                ['label' => '正面/中性情感倾向', 'value' => $record['metrics']['sentiment_rate'], 'suffix' => '%', 'value_class' => 'text-gray-900'],
                            ];
                        @endphp
                        <article class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm" data-diagnosis-record>
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
                                            <div class="{{ $metricCard['value_class'] }} text-xl font-bold">{{ $metricCard['value'] }}{{ $metricCard['suffix'] }}</div>
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
                                    </div>
                                </div>
                            </div>

                            <div data-record-detail @class(['hidden' => ! $record['expanded'], 'border-t border-slate-200 bg-white p-4'])>
                                <div class="rounded-lg border border-slate-200 bg-orange-50/40 px-3 py-2 text-xs text-gray-600">
                                    记录 #{{ $record['id'] }} 当前状态：{{ $record['status'] }}。诊断完成后会展示豆包真实联网回答和引用来源。
                                </div>

                <div class="mt-5 rounded-lg border border-slate-200 bg-white p-4">
                    <div class="mb-3 text-sm font-semibold text-gray-900">AI问题</div>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($recordQuestions as $question)
                            <span class="inline-flex min-h-8 items-center gap-2 rounded-md bg-blue-50 px-3 text-xs font-medium text-blue-700">
                                <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-blue-600 text-[11px] font-bold text-white">{{ $question['rank'] }}</span>
                                {{ $question['text'] }}
                                <span class="rounded bg-orange-50 px-1.5 py-0.5 text-[11px] text-orange-700">{{ $question['type'] }}</span>
                            </span>
                        @endforeach
                    </div>
                </div>

                <div class="mt-6">
                    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <h2 class="text-lg font-semibold text-gray-900">品牌表现</h2>
                        <select class="h-10 w-full sm:w-44" aria-label="平台筛选">
                            <option>全部平台</option>
                            <option>豆包</option>
                            <option>千问</option>
                            <option>文心一言</option>
                            <option>DeepSeek</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
                        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                            <h3 class="mb-4 text-base font-semibold text-gray-900">品牌提及率</h3>
                            <div class="space-y-3">
                                @foreach ($recordMentionRateRanking as $index => $row)
                                    <div class="grid grid-cols-[24px_88px_1fr_40px] items-center gap-2 text-sm">
                                        <span class="text-xs text-gray-500">{{ $index + 1 }}</span>
                                        <span class="truncate font-medium text-gray-700">{{ $row['brand'] }}</span>
                                        <span class="h-2 rounded-full bg-slate-100">
                                            <span class="block h-2 rounded-full bg-blue-600" style="width: {{ ((int) $row['rate'] / $recordMaxRate) * 100 }}%"></span>
                                        </span>
                                        <span class="text-right text-xs font-semibold text-blue-700">{{ $row['rate'] }}%</span>
                                    </div>
                                @endforeach
                            </div>
                            <div class="mt-4 flex items-center gap-2 rounded-md border border-orange-200 bg-orange-50 px-3 py-2 text-xs font-semibold text-orange-700">
                                <span class="rounded bg-orange-600 px-1.5 py-0.5 text-white">1</span>
                                {{ $record['brand'] }} {{ $record['metrics']['mention_rate'] }}%
                            </div>
                        </div>

                        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                            <h3 class="mb-4 text-base font-semibold text-gray-900">品牌提及次数</h3>
                            <div class="space-y-3">
                                @foreach ($recordMentionCountRanking as $index => $row)
                                    <div class="grid grid-cols-[24px_88px_1fr_32px] items-center gap-2 text-sm">
                                        <span class="text-xs text-gray-500">{{ $index + 1 }}</span>
                                        <span class="truncate font-medium text-gray-700">{{ $row['brand'] }}</span>
                                        <span class="h-2 rounded-full bg-slate-100">
                                            <span class="block h-2 rounded-full bg-blue-600" style="width: {{ ((int) $row['count'] / $recordMaxCount) * 100 }}%"></span>
                                        </span>
                                        <span class="text-right text-xs font-semibold text-blue-700">{{ $row['count'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                            <div class="mt-4 flex items-center gap-2 rounded-md border border-orange-200 bg-orange-50 px-3 py-2 text-xs font-semibold text-orange-700">
                                <span class="rounded bg-orange-600 px-1.5 py-0.5 text-white">1</span>
                                {{ $record['brand'] }} {{ $record['metrics']['mention_count'] }}
                            </div>
                        </div>

                        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
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
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach ($recordAverageRankings as $index => $row)
                                            <tr>
                                                <td class="px-3 py-2">
                                                    <span class="mr-2 text-xs text-gray-500">{{ $index + 1 }}</span>{{ $row['brand'] }}
                                                </td>
                                                <td class="px-3 py-2 text-right font-medium text-gray-700">{{ $row['rate'] }}%</td>
                                                <td class="px-3 py-2 text-right font-medium text-gray-700">{{ $row['rank'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 items-stretch gap-5 xl:grid-cols-2">
                    <div class="flex h-full flex-col rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <h3 class="text-base font-semibold text-gray-900">引用来源</h3>
                            @if (count($recordSources) > 0)
                                <span class="text-xs text-gray-500">共 {{ count($recordSources) }} 条</span>
                            @endif
                        </div>
                        <div class="flex min-h-0 flex-1 flex-col" data-source-pager data-min-page-size="{{ $sourceMinPageSize }}">
                            <div class="flex-1 space-y-3" data-source-list>
                                @forelse ($recordSources as $sourceIndex => $source)
                                    <div @class(['hidden' => $sourceIndex >= $sourceMinPageSize, 'flex gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3']) data-source-item>
                                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-white text-xs font-bold text-orange-700 shadow-sm">{{ $source['icon'] }}</div>
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

                    <div class="flex h-full flex-col rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                        <h3 class="mb-4 text-base font-semibold text-gray-900">AI 对话记录</h3>
                        <div class="flex-1 space-y-3">
                            @forelse ($recordConversations as $conversation)
                                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="min-w-0">
                                            <div class="truncate text-sm font-semibold text-gray-900">{{ $conversation['question'] }}</div>
                                            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                                <span>提及品牌</span>
                                                @forelse ($conversation['brands'] as $brand)
                                                    <span class="rounded bg-blue-50 px-2 py-1 font-medium text-blue-700">{{ $brand }}</span>
                                                @empty
                                                    <span class="rounded bg-slate-100 px-2 py-1 font-medium text-slate-500">未提及</span>
                                                @endforelse
                                            </div>
                                            @if (! empty($conversation['answer']))
                                                <p class="mt-2 line-clamp-2 text-xs leading-5 text-gray-600">{{ $conversation['answer'] }}</p>
                                            @endif
                                        </div>
                                        <button type="button" class="inline-flex h-9 shrink-0 items-center justify-center rounded-md bg-orange-50 px-3 text-xs font-semibold text-orange-700">AI对话详情</button>
                                    </div>
                                </div>
                            @empty
                                <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-gray-500">暂无 AI 对话记录，等待诊断完成。</div>
                            @endforelse
                        </div>
                    </div>
                </div>

                                <div class="mt-6 flex flex-wrap justify-center gap-3 border-t border-slate-200 pt-5">
                    <button type="button" class="inline-flex h-10 items-center gap-2 rounded-md border border-slate-200 bg-white px-5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        <i data-lucide="file-text" class="h-4 w-4"></i>
                        查看报告
                    </button>
                    <button type="button" data-record-toggle class="inline-flex h-10 items-center gap-2 rounded-md border border-orange-200 bg-orange-50 px-5 text-sm font-semibold text-orange-700 hover:bg-orange-100">
                        <i data-lucide="chevrons-up" class="h-4 w-4"></i>
                        <span data-record-toggle-label>收起结果</span>
                    </button>
                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
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
                <div class="space-y-3 px-5 py-4">
                    @foreach ($reportOptions as $report)
                        <button
                            type="button"
                            data-report-option
                            class="flex w-full items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white px-4 py-3 text-left hover:border-orange-200 hover:bg-orange-50"
                        >
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-semibold text-gray-900">{{ $report['brand'] }} 报告</span>
                                <span class="mt-1 block text-xs text-gray-500">{{ $report['created_at'] }}</span>
                            </span>
                            <i data-lucide="download" class="h-4 w-4 shrink-0 text-orange-600"></i>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
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

            const openReportModal = () => {
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
                    window.alert('已选择当前唯一报告，真实 PDF 接口接入后将直接导出。');
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

            reportModal?.querySelectorAll('[data-report-option]').forEach((button) => {
                button.addEventListener('click', () => {
                    closeReportModal();
                    window.alert('已选择报告，真实 PDF 接口接入后将导出该报告。');
                });
            });

            document.querySelectorAll('[data-source-pager]').forEach((pager) => {
                const items = Array.from(pager.querySelectorAll('[data-source-item]'));
                const list = pager.querySelector('[data-source-list]');
                const minPageSize = Math.max(1, Number(pager.dataset.minPageSize || 5));
                const prevButton = pager.querySelector('[data-source-prev]');
                const nextButton = pager.querySelector('[data-source-next]');
                const pageLabel = pager.querySelector('[data-source-page-label]');
                let pageSize = minPageSize;
                let totalPages = Math.max(1, Math.ceil(items.length / pageSize));
                let currentPage = 1;

                const calculatePageSize = () => {
                    const firstVisibleItem = items.find((item) => !item.classList.contains('hidden')) || items[0];
                    if (!firstVisibleItem || !list) {
                        return minPageSize;
                    }

                    const itemHeight = firstVisibleItem.getBoundingClientRect().height || 1;
                    const styles = window.getComputedStyle(list);
                    const rowGap = Number.parseFloat(styles.rowGap || styles.gap || '0') || 0;
                    const availableHeight = list.getBoundingClientRect().height || itemHeight * minPageSize;
                    const fitted = Math.floor((availableHeight + rowGap) / (itemHeight + rowGap));

                    return Math.max(minPageSize, fitted || minPageSize);
                };

                const renderPage = () => {
                    pageSize = Math.min(items.length || minPageSize, calculatePageSize());
                    totalPages = Math.max(1, Math.ceil(items.length / pageSize));
                    currentPage = Math.min(currentPage, totalPages);

                    const start = (currentPage - 1) * pageSize;
                    const end = start + pageSize;
                    items.forEach((item, index) => {
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

                window.addEventListener('resize', renderPage);
                renderPage();
            });
        });
    </script>
@endpush
