@extends('site.layout')

@php
    $siteData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $products = collect($siteData['products_services']['items']);
@endphp

@section('content')
    <div id="mainContent" class="site-container px-4 py-8 sm:px-6 lg:px-8">
        <section class="article-shell p-8">
            <p class="mb-2 text-sm font-semibold text-blue-600">PRODUCTS &amp; SERVICES</p>
            <h1 class="text-3xl font-bold text-gray-900">产品服务</h1>

            @if($products->isEmpty())
                <p class="mt-6 text-gray-500">暂无产品</p>
            @else
                <div class="mt-8 grid grid-cols-1 gap-6 md:grid-cols-2">
                    @foreach($products as $product)
                        <article class="rounded-lg border border-gray-100 p-6">
                            <h2 class="text-xl font-semibold text-gray-900">{{ $product['name'] }}</h2>
                            <p class="mt-3 leading-7 text-gray-600">{{ $product['summary'] }}</p>
                            @if($product['url'] !== '')
                                <a class="mt-5 inline-flex font-semibold text-blue-600 hover:text-blue-700" href="{{ $product['url'] }}">查看详情</a>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
@endsection
