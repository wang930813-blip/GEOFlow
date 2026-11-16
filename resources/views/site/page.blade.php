@extends('site.layout')

@section('content')
    <div id='mainContent' class='site-container px-4 py-10 sm:px-6 lg:px-8'>
        <article class='article-shell mx-auto max-w-4xl border border-gray-200 bg-white p-6 sm:p-9'>
            <header class='border-b border-gray-200 pb-6'>
                <p class='mb-2 text-sm font-semibold text-emerald-800'>{{ $siteTitle }}</p>
                <h1 class='text-3xl font-bold leading-tight text-gray-950'>{{ $websitePage->title }}</h1>
            </header>
            <div class='article-content mt-7'>{!! $contentHtml !!}</div>
        </article>
    </div>
@endsection
