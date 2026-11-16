@extends('site.layout')

@php
    $coverSource = trim((string) $product->cover_image);
    $coverImageUrl = preg_match('/^(https?:\/\/|\/)[^\r\n]*$/i', $coverSource) === 1
        ? \App\Support\GeoFlow\ImageUrlNormalizer::toPublicUrl($coverSource)
        : '';
    $productGalleryImages = collect((array) ($product->gallery ?? []))
        ->map(static fn ($image): string => trim((string) $image))
        ->filter(static fn (string $image): bool => $image !== '' && preg_match('/^(https?:\/\/|\/)[^\r\n]*$/i', $image) === 1)
        ->map(static fn (string $image): string => \App\Support\GeoFlow\ImageUrlNormalizer::toPublicUrl($image))
        ->reject(static fn (string $image): bool => $image === $coverImageUrl)
        ->unique()
        ->values();
    $productSchemaImages = collect(array_filter([$coverImageUrl, ...$productGalleryImages->all()]))
        ->map(static fn (string $image): string => str_starts_with($image, '//')
            ? request()->getScheme().':'.$image
            : url($image))
        ->unique()
        ->values();
@endphp

@push('head')
    @php
        $productStructuredData = [
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $product->title,
        'description' => $pageDescription,
        'image' => $productSchemaImages->isEmpty()
            ? null
            : ($productSchemaImages->count() === 1 ? $productSchemaImages->first() : $productSchemaImages->all()),
        'category' => $product->category?->name,
        'url' => $canonicalUrl,
        ];
    @endphp
    <script type='application/ld+json'>@json($productStructuredData)</script>
@endpush

@section('content')
    <div id='mainContent' class='site-container px-4 py-10 sm:px-6 lg:px-8'>
        <nav class='mb-6 text-sm text-gray-600' aria-label='面包屑'>
            <a class='hover:text-emerald-800' href='{{ route('site.home') }}'>首页</a><span class='mx-2 text-gray-400'>/</span>
            <a class='hover:text-emerald-800' href='{{ route('site.products.index') }}'>产品中心</a>
            @if ($product->category)<span class='mx-2 text-gray-400'>/</span><a class='hover:text-emerald-800' href='{{ route('site.products.index', ['category' => $product->category->slug]) }}'>{{ $product->category->name }}</a>@endif
        </nav>
        <article class='mx-auto max-w-4xl'>
            <header class='border-b border-gray-200 pb-7'>
                @if ($product->category)<span class='text-sm font-semibold text-emerald-800'>{{ $product->category->name }}</span>@endif
                <h1 class='mt-2 text-3xl font-bold leading-tight text-gray-950 sm:text-4xl'>{{ $product->title }}</h1>
                @if ($product->summary)<p class='mt-4 max-w-3xl text-base leading-7 text-gray-600'>{{ $product->summary }}</p>@endif
            </header>
            @if ($coverImageUrl !== '')<img src='{{ $coverImageUrl }}' alt='{{ $product->title }}' class='mt-8 max-h-[560px] w-full object-contain' decoding='async'>@endif
            @if ($productGalleryImages->isNotEmpty())
                <section class='mt-9' aria-labelledby='product-gallery-title'>
                    <h2 id='product-gallery-title' class='text-xl font-semibold text-gray-950'>产品相册</h2>
                    <div class='mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3'>
                        @foreach ($productGalleryImages as $index => $galleryImage)
                            <figure class='overflow-hidden border border-gray-200 bg-gray-50'>
                                <img src='{{ $galleryImage }}' alt='{{ $product->title }} 图片 {{ $index + 1 }}' class='aspect-[4/3] w-full object-contain p-2' loading='lazy' decoding='async'>
                            </figure>
                        @endforeach
                    </div>
                </section>
            @endif
            <div class='article-content mt-8'>{!! $contentHtml !!}</div>
            <footer class='mt-10 border-t border-gray-200 pt-6'><a class='inline-flex min-h-11 items-center border border-gray-300 px-4 text-sm font-semibold text-gray-800 hover:border-emerald-700 hover:text-emerald-800' href='{{ route('site.products.index') }}'>返回产品中心</a></footer>
        </article>
    </div>
@endsection
