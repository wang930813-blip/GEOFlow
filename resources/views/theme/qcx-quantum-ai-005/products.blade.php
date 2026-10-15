@extends('theme.qcx-quantum-ai-005.layout')

@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $productData = $themeData['products_services'];
    $productItems = collect($productData['items']);
    $publicAsset = static fn (string $path): string => preg_match('/^(https?:)?\\/\\//', $path) === 1 || str_starts_with($path, '/') ? $path : asset($path);
@endphp

@section('content')
<main class="page-shell">
    <header class="page-heading">
        <p class="eyebrow">产品与服务</p>
        <h1>{{ $productData['title'] }}</h1>
        @if($productData['summary'] !== '')<p class="lead">{{ $productData['summary'] }}</p>@endif
    </header>
    <section class="section">
        <div class="grid">
            @forelse($productItems as $item)
                <article class="panel">
                    @if(!empty($item['image_url']))
                        <img src="{{ $publicAsset($item['image_url']) }}" alt="{{ $item['name'] }}" loading="lazy">
                    @endif
                    <h2>{{ $item['name'] }}</h2>
                    @if($item['summary'] !== '')<p>{{ $item['summary'] }}</p>@endif
                    @if($item['details'] !== '')<div class="prose">{!! $item['details'] !!}</div>@endif
                </article>
            @empty
                <p>暂无产品内容。</p>
            @endforelse
        </div>
    </section>
</main>
@endsection
