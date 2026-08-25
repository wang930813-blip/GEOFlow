@extends('admin.layouts.app')

@section('content')
    <div class="mx-auto max-w-4xl space-y-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">创建生成视频</h1>
                <p class="mt-1 text-sm text-gray-600">提交后异步生成视频，生成完成前可在列表查看进度。</p>
            </div>
            <a href="{{ route('admin.video-generations.index') }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50">
                <i data-lucide="arrow-left" class="h-4 w-4"></i>
                返回列表
            </a>
        </div>

        <form method="POST" action="{{ route('admin.video-generations.store') }}" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            @csrf
            <div class="grid grid-cols-1 gap-5">
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">视频主题</span>
                    <input name="subject" value="{{ old('subject') }}" required maxlength="500" class="mt-1 block h-11 w-full rounded-md border border-slate-200 px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="例如：成都人工智能企业宣传片">
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">视频脚本</span>
                    <textarea name="script" rows="6" class="mt-1 block w-full rounded-md border border-slate-200 px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="可选，不填写时由视频生成服务自动生成">{{ old('script') }}</textarea>
                </label>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">封面图 URL</span>
                    <input name="cover_image" value="{{ old('cover_image') }}" maxlength="1000" class="mt-1 block h-11 w-full rounded-md border border-slate-200 px-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100" placeholder="发布到 B 站等视频平台时必填，可生成后在详情页补充">
                </label>

                <div class="flex justify-end gap-3 border-t border-slate-100 pt-5">
                    <a href="{{ route('admin.video-generations.index') }}" class="inline-flex h-10 items-center justify-center rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50">取消</a>
                    <button type="submit" class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white hover:bg-indigo-700">
                        <i data-lucide="sparkles" class="h-4 w-4"></i>
                        提交生成
                    </button>
                </div>
            </div>
        </form>
    </div>
@endsection
