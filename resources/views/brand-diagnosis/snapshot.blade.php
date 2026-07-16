<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $snapshot ? $snapshot['question'].' - AI 回答快照' : '快照不存在' }} - GEOFlow</title>
    <script src="{{ asset('js/tailwindcss.play-cdn.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('assets/css/brand-diagnosis-snapshot.css') }}">
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 antialiased">
    @if ($snapshot)
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-5xl flex-col gap-4 px-4 py-5 sm:px-6 md:flex-row md:items-center md:justify-between">
                <div class="flex min-w-0 items-center gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-slate-950 text-sm font-bold text-white">GEO</div>
                    <div class="min-w-0">
                        <div class="text-xs font-semibold text-orange-600">GEOFlow AI Snapshot</div>
                        <div class="truncate text-base font-bold text-slate-950">AI 回答快照</div>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                    <span>诊断时间 {{ $snapshot['checked_at'] ?: '-' }}</span>
                    <span class="h-1 w-1 rounded-full bg-slate-300"></span>
                    <span>内容由对应模型生成</span>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6">
            <section aria-labelledby="snapshot-question">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700">
                        @if ($snapshot['platform_logo'])
                            <img src="{{ $snapshot['platform_logo'] }}" alt="{{ $snapshot['platform'] }} logo" class="h-5 w-5 rounded object-contain">
                        @endif
                        {{ $snapshot['platform'] }}
                    </span>
                    @if ($snapshot['brand'])
                        <span class="rounded-md bg-orange-50 px-3 py-2 text-sm font-semibold text-orange-700">{{ $snapshot['brand'] }}</span>
                    @endif
                </div>
                <h1 id="snapshot-question" class="mt-5 text-2xl font-bold leading-9 text-slate-950 sm:text-3xl">{{ $snapshot['question'] }}</h1>
            </section>

            <section class="mt-7 border border-slate-200 bg-white px-5 py-6 shadow-sm sm:px-8 sm:py-8" aria-label="模型回答">
                <div class="mb-5 flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-4">
                    <h2 class="text-lg font-bold text-slate-950">模型回答</h2>
                    @if ($snapshot['official_share_url'])
                        <a href="{{ $snapshot['official_share_url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex h-9 items-center rounded-md border border-orange-200 bg-orange-50 px-3 text-sm font-semibold text-orange-700 hover:bg-orange-100">官方对话</a>
                    @endif
                </div>
                <div class="snapshot-answer">
                    {!! $snapshot['answer_html'] ?: '<p class="text-slate-500">暂无回答内容。</p>' !!}
                </div>
            </section>

            @if ($snapshot['sources'])
                <section class="mt-8" aria-labelledby="snapshot-sources">
                    <h2 id="snapshot-sources" class="text-lg font-bold text-slate-950">引用来源</h2>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        @foreach ($snapshot['sources'] as $source)
                            <a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer" class="border border-slate-200 bg-white px-4 py-3 hover:border-orange-300 hover:text-orange-700">
                                <span class="block text-sm font-semibold leading-6">{{ $source['title'] }}</span>
                                <span class="mt-1 block truncate text-xs text-slate-500">{{ $source['domain'] ?: $source['url'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            <footer class="mt-10 border-t border-slate-200 pt-5 text-xs leading-6 text-slate-500">
                本页面保存诊断时的模型回答与引用记录，供结果核验使用。
            </footer>
        </main>
    @else
        <main class="mx-auto flex min-h-screen max-w-3xl items-center px-4 py-12 sm:px-6">
            <section class="w-full border border-slate-200 bg-white px-6 py-12 text-center shadow-sm sm:px-10">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-md bg-slate-100 text-slate-500">
                    <span class="text-xl font-bold">!</span>
                </div>
                <h1 class="mt-5 text-2xl font-bold text-slate-950">快照不存在</h1>
                <p class="mt-2 text-sm text-slate-500">请检查链接是否完整，或联系链接提供方。</p>
            </section>
        </main>
    @endif
</body>
</html>
