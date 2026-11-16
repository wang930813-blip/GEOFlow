@extends('site.layout')

@section('content')
    <div id='mainContent' class='site-container px-4 py-10 sm:px-6 lg:px-8'>
        <section class='mb-8 border-b border-gray-200 pb-7'>
            <p class='mb-2 text-sm font-semibold text-emerald-700'>产品目录</p>
            <h1 class='text-3xl font-bold text-gray-950'>{{ $selectedCategory?->name ?? '产品中心' }}</h1>
            <p class='mt-3 max-w-3xl leading-7 text-gray-600'>{{ $selectedCategory?->description ?: $siteTitle.'产品介绍与产品信息' }}</p>
        </section>

        @if ($categories->isNotEmpty())
            <nav class='mb-7 flex flex-wrap gap-2' aria-label='产品分类'>
                <a href='{{ route('site.products.index') }}' @class(['inline-flex min-h-10 items-center border px-4 text-sm font-medium transition-colors', 'border-emerald-700 bg-emerald-700 text-white' => ! $selectedCategory, 'border-gray-300 bg-white text-gray-700 hover:border-emerald-700 hover:text-emerald-800' => $selectedCategory])>全部产品</a>
                @foreach ($categories as $category)
                    <a href='{{ route('site.products.index', ['category' => $category->slug]) }}' @class(['inline-flex min-h-10 items-center border px-4 text-sm font-medium transition-colors', 'border-emerald-700 bg-emerald-700 text-white' => $selectedCategory?->id === $category->id, 'border-gray-300 bg-white text-gray-700 hover:border-emerald-700 hover:text-emerald-800' => $selectedCategory?->id !== $category->id])>{{ $category->name }}</a>
                @endforeach
            </nav>
        @endif

        @if ($products->isEmpty())
            <div class='border border-gray-200 bg-white px-6 py-14 text-center'>
                <h2 class='text-lg font-semibold text-gray-900'>暂时没有公开产品</h2>
                <p class='mt-2 text-sm text-gray-600'>请查看其他分类，或稍后再来访问。</p>
            </div>
        @else
            <div class='grid gap-5 sm:grid-cols-2 lg:grid-cols-3'>
                @foreach ($products as $product)
                    <article class='overflow-hidden border border-gray-200 bg-white transition-shadow hover:shadow-md'>
                        <a href='{{ route('site.products.show', $product->slug) }}' class='block focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700'>
                            @if ($product->cover_image)
                                <img src='{{ $product->cover_image }}' alt='{{ $product->title }}' class='aspect-[4/3] w-full object-cover' loading='lazy' decoding='async'>
                            @else
                                <div class='grid aspect-[4/3] place-items-center bg-gray-100 text-4xl font-semibold text-emerald-800' aria-hidden='true'>{{ mb_substr($product->title, 0, 1) }}</div>
                            @endif
                            <div class='p-5'>
                                @if ($product->category)<span class='text-xs font-semibold text-emerald-800'>{{ $product->category->name }}</span>@endif
                                <h2 class='mt-2 text-lg font-semibold text-gray-950'>{{ $product->title }}</h2>
                                @if ($product->summary)<p class='mt-2 line-clamp-3 text-sm leading-6 text-gray-600'>{{ $product->summary }}</p>@endif
                                <span class='mt-4 inline-flex items-center gap-2 text-sm font-semibold text-emerald-800'>查看产品介绍 <span aria-hidden='true'>→</span></span>
                            </div>
                        </a>
                    </article>
                @endforeach
            </div>
            @if ($products->hasPages())<div class='mt-8'>{{ $products->links() }}</div>@endif
        @endif
    </div>
@endsection
