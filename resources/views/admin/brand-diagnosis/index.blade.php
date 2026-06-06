@extends('admin.layouts.app')

@php
    $maxRate = max(1, ...array_map(static fn ($row) => (int) $row['rate'], $mentionRateRanking));
    $maxCount = max(1, ...array_map(static fn ($row) => (int) $row['count'], $mentionCountRanking));
    $reportOptions = array_values(array_filter($diagnosisRecords, static fn ($record) => (bool) $record['has_report']));
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
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start">
                <div class="min-w-0 flex-1">
                    <label id="brand-diagnosis-form-title" for="brand-name" class="mb-2 block text-sm font-semibold text-gray-900">品牌名称</label>
                    <div class="flex flex-col gap-3 sm:flex-row">
                        <input id="brand-name" type="text" value="策影GEO" class="min-w-0 flex-1 rounded-md border border-slate-200 bg-white text-sm" aria-label="品牌名称">
                        <button type="button" class="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-md bg-orange-600 px-5 text-sm font-semibold text-white hover:bg-orange-700">
                            <i data-lucide="search" class="h-4 w-4"></i>
                            搜索一下
                        </button>
                    </div>
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
                                <label class="flex min-h-6 items-center gap-2">
                                    <input type="checkbox" @checked($model['deep'])>
                                    深度思考
                                </label>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-4">
                <div class="flex flex-wrap items-center gap-4 text-sm text-gray-600">
                    <label class="inline-flex min-h-9 items-center gap-2">
                        <input type="checkbox">
                        全选
                    </label>
                    <label class="inline-flex min-h-9 items-center gap-2">
                        <input type="checkbox" checked>
                        深度思考
                    </label>
                </div>
                <div class="text-xs text-gray-500">模型限定：豆包、千问、文心一言、DeepSeek</div>
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
                <div class="space-y-4">
                    @foreach ($diagnosisRecords as $record)
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
                                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-center">
                                        <div class="text-xl font-bold text-orange-600">{{ $record['metrics']['score'] }}</div>
                                        <div class="mt-1 text-xs text-gray-500">品牌得分 / 100</div>
                                    </div>
                                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-center">
                                        <div class="text-xl font-bold text-gray-900">{{ $record['metrics']['mention_rate'] }}%</div>
                                        <div class="mt-1 text-xs text-gray-500">品牌提及率</div>
                                    </div>
                                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-center">
                                        <div class="text-xl font-bold text-gray-900">{{ $record['metrics']['average_rank'] }}名</div>
                                        <div class="mt-1 text-xs text-gray-500">平均提及排名</div>
                                    </div>
                                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-center">
                                        <div class="text-xl font-bold text-gray-900">{{ $record['metrics']['mention_count'] }}次</div>
                                        <div class="mt-1 text-xs text-gray-500">品牌提及次数</div>
                                    </div>
                                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-center">
                                        <div class="text-xl font-bold text-gray-900">{{ $record['metrics']['sentiment_rate'] }}%</div>
                                        <div class="mt-1 text-xs text-gray-500">正面/中性情感倾向</div>
                                    </div>
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
                                        <button type="button" class="inline-flex h-9 items-center justify-center rounded-md border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 hover:bg-slate-50">重新搜索</button>
                                    </div>
                                </div>
                            </div>

                            <div data-record-detail @class(['hidden' => ! $record['expanded'], 'border-t border-slate-200 bg-white p-4'])>
                                <div class="rounded-lg border border-slate-200 bg-orange-50/40 px-3 py-2 text-xs text-gray-600">
                                    当前展示为静态报告样例，真实诊断接入后这里会按记录 #{{ $record['id'] }} 展示对应结果。
                                </div>

                <div class="mt-5 rounded-lg border border-slate-200 bg-white p-4">
                    <div class="mb-3 text-sm font-semibold text-gray-900">AI问题</div>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($questions as $question)
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
                                @foreach ($mentionRateRanking as $index => $row)
                                    <div class="grid grid-cols-[24px_88px_1fr_40px] items-center gap-2 text-sm">
                                        <span class="text-xs text-gray-500">{{ $index + 1 }}</span>
                                        <span class="truncate font-medium text-gray-700">{{ $row['brand'] }}</span>
                                        <span class="h-2 rounded-full bg-slate-100">
                                            <span class="block h-2 rounded-full bg-blue-600" style="width: {{ ((int) $row['rate'] / $maxRate) * 100 }}%"></span>
                                        </span>
                                        <span class="text-right text-xs font-semibold text-blue-700">{{ $row['rate'] }}%</span>
                                    </div>
                                @endforeach
                            </div>
                            <div class="mt-4 flex items-center gap-2 rounded-md border border-orange-200 bg-orange-50 px-3 py-2 text-xs font-semibold text-orange-700">
                                <span class="rounded bg-orange-600 px-1.5 py-0.5 text-white">26</span>
                                策影GEO 0%
                            </div>
                        </div>

                        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                            <h3 class="mb-4 text-base font-semibold text-gray-900">品牌提及次数</h3>
                            <div class="space-y-3">
                                @foreach ($mentionCountRanking as $index => $row)
                                    <div class="grid grid-cols-[24px_88px_1fr_32px] items-center gap-2 text-sm">
                                        <span class="text-xs text-gray-500">{{ $index + 1 }}</span>
                                        <span class="truncate font-medium text-gray-700">{{ $row['brand'] }}</span>
                                        <span class="h-2 rounded-full bg-slate-100">
                                            <span class="block h-2 rounded-full bg-blue-600" style="width: {{ ((int) $row['count'] / $maxCount) * 100 }}%"></span>
                                        </span>
                                        <span class="text-right text-xs font-semibold text-blue-700">{{ $row['count'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                            <div class="mt-4 flex items-center gap-2 rounded-md border border-orange-200 bg-orange-50 px-3 py-2 text-xs font-semibold text-orange-700">
                                <span class="rounded bg-orange-600 px-1.5 py-0.5 text-white">26</span>
                                策影GEO 0
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
                                        @foreach ($averageRankings as $index => $row)
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

                <div class="mt-6 grid grid-cols-1 gap-5 xl:grid-cols-2">
                    <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                        <h3 class="mb-4 text-base font-semibold text-gray-900">引用来源</h3>
                        <div class="space-y-3">
                            @foreach ($sources as $source)
                                <div class="flex gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
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
                                        <button type="button" class="rounded-md bg-orange-50 px-3 py-2 text-xs font-semibold text-orange-700">原文</button>
                                        <button type="button" class="rounded-md border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700">详情</button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                        <h3 class="mb-4 text-base font-semibold text-gray-900">AI 对话记录</h3>
                        <div class="space-y-3">
                            @foreach ($conversations as $conversation)
                                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="min-w-0">
                                            <div class="truncate text-sm font-semibold text-gray-900">{{ $conversation['question'] }}</div>
                                            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                                <span>提及品牌</span>
                                                @foreach ($conversation['brands'] as $brand)
                                                    <span class="rounded bg-blue-50 px-2 py-1 font-medium text-blue-700">{{ $brand }}</span>
                                                @endforeach
                                            </div>
                                        </div>
                                        <button type="button" class="inline-flex h-9 shrink-0 items-center justify-center rounded-md bg-orange-50 px-3 text-xs font-semibold text-orange-700">AI对话详情</button>
                                    </div>
                                </div>
                            @endforeach
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
        });
    </script>
@endpush
