@extends('site.layout')

@section('content')
    <div id="mainContent" class="site-container px-4 py-8 sm:px-6 lg:px-8">
        <section class="article-shell p-8">
            <p class="mb-2 text-sm font-semibold text-blue-600">ABOUT</p>
            <h1 class="text-3xl font-bold text-gray-900">关于我们</h1>
            <h2 class="mt-6 text-2xl font-semibold text-gray-900">{{ $siteTitle }}</h2>
            <p class="mt-4 max-w-3xl leading-8 text-gray-600">
                {{ $siteDescription !== '' ? $siteDescription : __('site.home_hero_fallback') }}
            </p>
        </section>
    </div>
@endsection
