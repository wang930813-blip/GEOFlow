@extends('theme.template04.layout')

@php($template04 = \App\Support\Site\Template01Data::fromView(get_defined_vars()))
@php($productItems = collect($template04['products_services']['items']))

@section('content')
<main class="page-shell">
    <header class="page-heading">
        <p class="eyebrow">产品与服务</p>
        <h1>{{ $template04['products_services']['title'] }}</h1>
        @if($template04['products_services']['summary'] !== '')
            <p class="lead">{{ $template04['products_services']['summary'] }}</p>
        @endif
    </header>

    <section class="section">
        <div class="grid">
            @forelse($productItems as $item)
                <article class="panel">
                    @if($item['image_url'] !== '')
                        <img src="{{ $item['image_url'] }}" alt="{{ $item['name'] }}" loading="lazy">
                    @endif
                    <h2>{{ $item['name'] }}</h2>
                    <p>{{ $item['summary'] }}</p>
                    @if($item['details'] !== '')
                        <div class="prose">{!! $item['details'] !!}</div>
                    @endif
                    @if($item['url'] !== '')
                        <a class="button button-secondary" href="{{ $item['url'] }}">了解更多 <span aria-hidden="true">→</span></a>
                    @endif
                </article>
            @empty
                <p role="status">暂无产品与服务内容。</p>
            @endforelse
        </div>
    </section>
</main>
@endsection
