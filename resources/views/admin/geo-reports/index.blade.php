@extends('admin.layouts.app')

@php
    $overview = $report['overview'] ?? [];
    $platforms = $report['platforms'] ?? [];
    $keywordRanking = $report['keywordRanking'] ?? [];
    $projectRanking = $report['projectRanking'] ?? [];
    $trend = $report['trend'] ?? [];
    $maxTrendChecks = max(1, ...array_map(static fn ($row) => (int) ($row['checks'] ?? 0), $trend ?: [['checks' => 0]]));
@endphp

@section('content')
    <div class="px-4 sm:px-0">
        <div class="mb-8 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">GEO 数据报表</h1>
                <p class="mt-1 text-sm text-gray-600">汇总项目、关键词、AI 搜索收录检测、平台命中和趋势表现。</p>
            </div>
            <a href="{{ route('admin.keyword-libraries.index') }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                <i data-lucide="key" class="w-4 h-4 mr-2"></i>
                管理项目关键词
            </a>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-lg bg-white p-5 shadow">
                <div class="text-sm font-medium text-gray-500">项目 / 关键词</div>
                <div class="mt-3 text-3xl font-semibold text-gray-900">{{ (int) ($overview['projects'] ?? 0) }} / {{ (int) ($overview['keywords'] ?? 0) }}</div>
                <div class="mt-1 text-xs text-gray-500">已纳入 GEO 管理的品牌项目与关键词</div>
            </div>
            <div class="rounded-lg bg-white p-5 shadow">
                <div class="text-sm font-medium text-gray-500">检测总数</div>
                <div class="mt-3 text-3xl font-semibold text-gray-900">{{ (int) ($overview['checks'] ?? 0) }}</div>
                <div class="mt-1 text-xs text-gray-500">来自豆包、千问、DeepSeek 的检测记录</div>
            </div>
            <div class="rounded-lg bg-white p-5 shadow">
                <div class="text-sm font-medium text-gray-500">关键词命中率</div>
                <div class="mt-3 text-3xl font-semibold text-green-700">{{ number_format((float) ($overview['keyword_hit_rate'] ?? 0), 1) }}%</div>
                <div class="mt-1 text-xs text-gray-500">检测答案中出现目标关键词的比例</div>
            </div>
            <div class="rounded-lg bg-white p-5 shadow">
                <div class="text-sm font-medium text-gray-500">品牌命中率</div>
                <div class="mt-3 text-3xl font-semibold text-blue-700">{{ number_format((float) ($overview['brand_hit_rate'] ?? 0), 1) }}%</div>
                <div class="mt-1 text-xs text-gray-500">检测答案中出现目标品牌的比例</div>
            </div>
        </div>

        <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
            <div class="rounded-lg bg-white p-6 shadow xl:col-span-2">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900">收录趋势</h2>
                    <span class="text-xs text-gray-500">最近 7 天</span>
                </div>
                <div class="mt-6 flex h-56 items-end gap-3">
                    @foreach ($trend as $day)
                        @php
                            $height = max(6, ((int) ($day['checks'] ?? 0) / $maxTrendChecks) * 100);
                        @endphp
                        <div class="flex min-w-0 flex-1 flex-col items-center gap-2">
                            <div class="flex h-40 w-full items-end rounded bg-gray-50 px-1">
                                <div class="w-full rounded-t bg-blue-600" style="height: {{ $height }}%"></div>
                            </div>
                            <div class="text-xs font-medium text-gray-700">{{ (int) ($day['checks'] ?? 0) }}</div>
                            <div class="truncate text-[11px] text-gray-500">{{ substr((string) ($day['date'] ?? ''), 5) }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="rounded-lg bg-white p-6 shadow">
                <h2 class="text-lg font-semibold text-gray-900">平台分布</h2>
                <div class="mt-4 space-y-4">
                    @forelse ($platforms as $platform)
                        <div>
                            <div class="flex items-center justify-between text-sm">
                                <span class="font-medium text-gray-900">{{ strtoupper((string) $platform['platform']) }}</span>
                                <span class="text-gray-500">{{ (int) $platform['checks'] }} 次</span>
                            </div>
                            <div class="mt-2 grid grid-cols-2 gap-2 text-xs">
                                <div class="rounded bg-green-50 px-2 py-1 text-green-700">关键词 {{ number_format((float) $platform['keyword_hit_rate'], 1) }}%</div>
                                <div class="rounded bg-blue-50 px-2 py-1 text-blue-700">品牌 {{ number_format((float) $platform['brand_hit_rate'], 1) }}%</div>
                            </div>
                        </div>
                    @empty
                        <div class="text-sm text-gray-500">暂无平台检测数据</div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
            <div class="rounded-lg bg-white shadow">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h2 class="text-lg font-semibold text-gray-900">关键词排行</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-6 py-3 text-left font-medium">关键词</th>
                                <th class="px-6 py-3 text-right font-medium">检测</th>
                                <th class="px-6 py-3 text-right font-medium">关键词</th>
                                <th class="px-6 py-3 text-right font-medium">品牌</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($keywordRanking as $row)
                                <tr>
                                    <td class="px-6 py-3 font-medium text-gray-900">{{ $row['keyword'] }}</td>
                                    <td class="px-6 py-3 text-right text-gray-600">{{ (int) $row['checks'] }}</td>
                                    <td class="px-6 py-3 text-right text-green-700">{{ number_format((float) $row['keyword_hit_rate'], 1) }}%</td>
                                    <td class="px-6 py-3 text-right text-blue-700">{{ number_format((float) $row['brand_hit_rate'], 1) }}%</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-6 py-8 text-center text-gray-500">暂无关键词排行数据</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-lg bg-white shadow">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h2 class="text-lg font-semibold text-gray-900">项目表现</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-6 py-3 text-left font-medium">项目</th>
                                <th class="px-6 py-3 text-left font-medium">品牌</th>
                                <th class="px-6 py-3 text-right font-medium">检测</th>
                                <th class="px-6 py-3 text-right font-medium">品牌命中</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($projectRanking as $row)
                                <tr>
                                    <td class="px-6 py-3 font-medium text-gray-900">{{ $row['project'] }}</td>
                                    <td class="px-6 py-3 text-gray-600">{{ $row['brand'] !== '' ? $row['brand'] : '-' }}</td>
                                    <td class="px-6 py-3 text-right text-gray-600">{{ (int) $row['checks'] }}</td>
                                    <td class="px-6 py-3 text-right text-blue-700">{{ number_format((float) $row['brand_hit_rate'], 1) }}%</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-6 py-8 text-center text-gray-500">暂无项目表现数据</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
