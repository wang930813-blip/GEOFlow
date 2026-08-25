@extends('admin.layouts.app')

@section('content')
    @php($canOpenB2BWebsites = (bool) ($canOpenB2BWebsites ?? true))
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">B2B行业网站</h1>
                <p class="mt-1 text-sm text-gray-600">展示当前账号可开通的行业网站，开通状态按账号和站点独立保存。</p>
            </div>
            <a href="{{ route('admin.dashboard') }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50">
                <i data-lucide="arrow-left" class="h-4 w-4"></i>
                返回首页
            </a>
        </div>

        <section class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
            @foreach ($b2bWebsites ?? [] as $website)
                <div class="flex min-h-[210px] flex-col justify-between rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200 transition hover:-translate-y-0.5 hover:shadow-md">
                    <div>
                        <div class="mb-3 flex h-7 items-center justify-end">
                            @if ($website['opened'])
                                <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 ring-1 ring-emerald-100">已开通</span>
                            @else
                                <span class="rounded-full bg-gray-50 px-2.5 py-1 text-xs font-medium text-gray-500 ring-1 ring-gray-200">未开通</span>
                            @endif
                        </div>

                        <div class="flex h-16 w-full items-center justify-center px-2 py-1">
                            <img src="{{ asset($website['logo']) }}" alt="{{ $website['name'] }} logo" class="max-h-14 max-w-[190px] object-contain">
                        </div>

                        <h3 class="mt-4 truncate text-base font-semibold leading-6 text-gray-900" title="{{ $website['name'] }}">{{ $website['name'] }}</h3>
                    </div>

                    <div class="mt-5">
                        @if ($website['opened'])
                            <button type="button" class="inline-flex w-full items-center justify-center rounded-lg border border-emerald-100 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700" disabled>
                                <i data-lucide="check-circle-2" class="mr-1.5 h-4 w-4"></i>
                                已开通
                            </button>
                        @elseif ($canOpenB2BWebsites)
                            <form method="POST" action="{{ route('admin.b2b-websites.open', ['websiteKey' => $website['key']]) }}">
                                @csrf
                                <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800">
                                    <i data-lucide="plus" class="mr-1.5 h-4 w-4"></i>
                                    开通
                                </button>
                            </form>
                        @else
                            <button type="button" class="inline-flex w-full items-center justify-center rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm font-medium text-gray-500" disabled>
                                仅查看
                            </button>
                        @endif
                    </div>
                </div>
            @endforeach
        </section>
    </div>
@endsection
