@extends('site.layout')

@section('content')
    <div id="mainContent" class="site-container px-4 py-8 sm:px-6 lg:px-8">
        <section class="article-shell p-8">
            <p class="mb-2 text-sm font-semibold text-blue-600">CONTACT</p>
            <h1 class="text-3xl font-bold text-gray-900">联系我们</h1>

            <div class="mt-8 grid gap-5 md:grid-cols-2">
                <div class="rounded-lg border border-gray-100 p-5">
                    <h2 class="text-lg font-semibold text-gray-900">联系方式</h2>
                    @forelse($contactInfoLines as $line)
                        <p class="mt-2 text-gray-600">{{ $line }}</p>
                    @empty
                        <p class="mt-2 text-gray-500">暂未配置联系方式。</p>
                    @endforelse
                </div>
                <div class="rounded-lg border border-gray-100 p-5">
                    <h2 class="text-lg font-semibold text-gray-900">公司地址</h2>
                    <p class="mt-2 text-gray-600">{{ $companyAddress !== '' ? $companyAddress : '暂未配置公司地址。' }}</p>
                </div>
            </div>
        </section>
    </div>
@endsection
