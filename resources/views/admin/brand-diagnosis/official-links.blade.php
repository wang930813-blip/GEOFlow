@extends('admin.layouts.app')

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <a href="{{ route('admin.brand-diagnosis.report', ['run' => $run->id]) }}" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-500 hover:text-orange-700">
                    <i data-lucide="arrow-left" class="h-4 w-4"></i>
                    返回诊断报告
                </a>
                <h1 class="mt-3 text-2xl font-bold text-slate-950">官方链接管理</h1>
                <p class="mt-1 text-sm text-slate-500">{{ $run->brand_name }} · {{ $run->created_at?->format('Y-m-d H:i') }}</p>
            </div>
            <div class="rounded-md border border-orange-200 bg-orange-50 px-3 py-2 text-sm font-semibold text-orange-700">
                共 {{ $results->count() }} 条模型回答
            </div>
        </div>

        @if (session('message'))
            <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ session('message') }}</div>
        @endif

        @if ($errors->any())
            <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.brand-diagnosis.official-links.update', ['run' => $run->id]) }}" class="space-y-4">
            @csrf
            @method('PUT')

            <div class="grid gap-3 rounded-md border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-[220px_1fr_auto] md:items-end">
                <div>
                    <label for="official-link-platform-filter" class="mb-1.5 block text-xs font-semibold text-slate-500">模型筛选</label>
                    <select id="official-link-platform-filter" class="block h-10 w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500">
                        <option value="">全部模型</option>
                        @foreach ($platformOptions as $option)
                            <option value="{{ $option['key'] }}">{{ $option['label'] }}（{{ $option['count'] }}）</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="official-link-question-filter" class="mb-1.5 block text-xs font-semibold text-slate-500">问题检索</label>
                    <input id="official-link-question-filter" type="search" placeholder="输入问题关键词" autocomplete="off" class="block h-10 w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500">
                </div>
                <div id="official-link-filter-count" class="rounded-md bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-500">
                    {{ $results->count() }} / {{ $results->count() }}
                </div>
            </div>

            <div class="overflow-hidden border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="w-20 px-4 py-3 text-left text-xs font-semibold text-slate-500">序号</th>
                                <th class="w-40 px-4 py-3 text-left text-xs font-semibold text-slate-500">平台</th>
                                <th class="min-w-72 px-4 py-3 text-left text-xs font-semibold text-slate-500">诊断问题</th>
                                <th class="min-w-96 px-4 py-3 text-left text-xs font-semibold text-slate-500">官方对话链接</th>
                                <th class="w-24 px-4 py-3 text-right text-xs font-semibold text-slate-500">快照</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($results as $result)
                                <tr class="align-top hover:bg-slate-50/70" data-official-link-row data-platform-key="{{ $result['platform_key'] }}" data-question-text="{{ $result['question'] }}">
                                    <td class="px-4 py-4 text-sm font-semibold text-slate-500">{{ $result['question_order'] }}</td>
                                    <td class="px-4 py-4">
                                        <span class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                                            @if ($result['platform_logo'])
                                                <img src="{{ $result['platform_logo'] }}" alt="{{ $result['platform'] }} logo" class="h-5 w-5 rounded object-contain">
                                            @endif
                                            {{ $result['platform'] }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 text-sm leading-6 text-slate-700">{{ $result['question'] }}</td>
                                    <td class="px-4 py-4">
                                        <label for="official-link-{{ $result['id'] }}" class="sr-only">{{ $result['question'] }}官方对话链接</label>
                                        <input id="official-link-{{ $result['id'] }}" type="url" name="official_links[{{ $result['id'] }}]" value="{{ old('official_links.'.$result['id'], $result['official_share_url']) }}" maxlength="2048" placeholder="https://" class="block h-10 w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500">
                                        <p class="mt-1.5 text-xs text-slate-400">允许域名：{{ $result['official_domains'] }}</p>
                                    </td>
                                    <td class="px-4 py-4 text-right">
                                        <a href="{{ $result['snapshot_url'] }}" target="_blank" rel="noopener noreferrer" title="打开系统快照" class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-600 hover:border-orange-200 hover:text-orange-700">
                                            <i data-lucide="external-link" class="h-4 w-4"></i>
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-12 text-center text-sm text-slate-500">暂无模型回答</td>
                                </tr>
                            @endforelse
                            <tr id="official-link-empty-filter-state" class="hidden">
                                <td colspan="5" class="px-4 py-12 text-center text-sm text-slate-500">没有匹配的模型回答</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="sticky bottom-4 flex justify-end">
                <button type="submit" class="inline-flex h-11 items-center gap-2 rounded-md bg-orange-600 px-5 text-sm font-semibold text-white shadow-lg shadow-orange-600/20 hover:bg-orange-700">
                    <i data-lucide="save" class="h-4 w-4"></i>
                    保存全部链接
                </button>
            </div>
        </form>
    </div>

    <script>
        (() => {
            const platformFilter = document.getElementById('official-link-platform-filter');
            const questionFilter = document.getElementById('official-link-question-filter');
            const countLabel = document.getElementById('official-link-filter-count');
            const emptyState = document.getElementById('official-link-empty-filter-state');
            const officialLinkRows = Array.from(document.querySelectorAll('[data-official-link-row]'));

            window.applyOfficialLinkFilters = function applyOfficialLinkFilters() {
                const platform = String(platformFilter?.value || '');
                const query = String(questionFilter?.value || '').trim().toLowerCase();
                let visible = 0;

                officialLinkRows.forEach(row => {
                    const rowPlatform = String(row.dataset.platformKey || '');
                    const rowQuestion = String(row.dataset.questionText || '').toLowerCase();
                    const matched = (!platform || rowPlatform === platform)
                        && (!query || rowQuestion.includes(query));

                    row.classList.toggle('hidden', !matched);
                    if (matched) {
                        visible += 1;
                    }
                });

                emptyState?.classList.toggle('hidden', visible > 0 || officialLinkRows.length === 0);
                if (countLabel) {
                    countLabel.textContent = `${visible} / ${officialLinkRows.length}`;
                }
            };

            platformFilter?.addEventListener('change', window.applyOfficialLinkFilters);
            questionFilter?.addEventListener('input', window.applyOfficialLinkFilters);
        })();
    </script>
@endsection
