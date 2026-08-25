@extends('admin.layouts.app')

@section('content')
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">监测中心</h1>
            <p class="mt-1 text-sm text-gray-600">统一承载品牌、GEO、收录与引用等监测能力，页面内容后续补充。</p>
        </div>

        <section class="rounded-lg border border-dashed border-slate-300 bg-white px-6 py-12 text-center shadow-sm">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-md bg-indigo-50 text-indigo-600">
                <i data-lucide="radar" class="h-6 w-6"></i>
            </div>
            <h2 class="mt-4 text-lg font-semibold text-gray-900">监测中心内容待配置</h2>
            <p class="mx-auto mt-2 max-w-2xl text-sm leading-6 text-slate-500">
                当前先完成菜单与权限入口，后续可在这里接入监测任务、趋势概览、异常提醒和报告入口。
            </p>
        </section>
    </div>
@endsection
