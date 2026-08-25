@extends('site.layout')

@section('content')
    <div id="mainContent" class="site-container px-4 py-8 sm:px-6 lg:px-8">
        <div class="article-shell mb-8 p-8">
            <p class="mb-2 text-sm font-semibold text-blue-600">NEWS</p>
            <h1 class="text-3xl font-bold text-gray-900">资讯</h1>
            <p class="mt-3 text-gray-600">{{ $pageDescription }}</p>
        </div>

        <section class="mb-10">
            <h2 class="mb-4 text-xl font-semibold text-gray-900">文章分类</h2>
            <div class="grid gap-4 md:grid-cols-3">
                @foreach($categories as $categoryItem)
                    <a href="{{ route('site.category', $categoryItem->slug) }}" class="article-shell p-5 hover:border-blue-200">
                        <div class="text-sm text-blue-600">{{ (int) ($categoryItem->published_count ?? 0) }} 篇</div>
                        <div class="mt-2 text-lg font-semibold text-gray-900">{{ $categoryItem->name }}</div>
                    </a>
                @endforeach
            </div>
        </section>

        <section class="space-y-6">
            @foreach($articles as $article)
                @include('site.partials.article-card', ['article' => $article, 'showFeaturedBadge' => false])
            @endforeach
        </section>

        <div class="mt-10">
            {{ $articles->links() }}
        </div>
    </div>
@endsection
