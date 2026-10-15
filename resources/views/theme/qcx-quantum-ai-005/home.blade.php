@extends('theme.qcx-quantum-ai-005.layout')

@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $home = $themeData['home'];
    $products = collect($themeData['products_services']['items']);
    $latestArticles = $themeData['featured_articles']->merge($themeData['articles'])->unique('id')->take(3)->values();
@endphp

@section('content')
<section class="hero">
    <div>
        <p class="eyebrow">{{ $home['hero']['eyebrow'] }}</p>
        <h1>{{ $home['hero']['title'] }}</h1>
        <p class="lead">{{ $home['hero']['description'] }}</p>
        <div class="actions">
            <a class="button button-primary" href="{{ $home['hero']['primary_action']['url'] }}">{{ $home['hero']['primary_action']['label'] }} →</a>
            <a class="button button-secondary" href="{{ $home['hero']['secondary_action']['url'] }}">{{ $home['hero']['secondary_action']['label'] }}</a>
        </div>
    </div>
    <div class="hero-art" aria-hidden="true"><div class="orb"></div></div>
</section>

<section class="section">
    <div class="section-heading">
        <p class="eyebrow">{{ $home['introduction']['title'] }}</p>
        <h2>{{ $themeData['site']['brand_tagline'] }}</h2>
    </div>
    <div class="prose">{!! $home['introduction']['content'] !!}</div>
</section>

@if($products->isNotEmpty())
    <section class="section">
        <div class="section-heading">
            <p class="eyebrow">{{ $themeData['products_services']['title'] }}</p>
            <h2>{{ $themeData['products_services']['summary'] ?: $themeData['products_services']['title'] }}</h2>
        </div>
        <div class="grid">
            @foreach($products->take(3) as $item)
                <article class="panel">
                    <span class="icon-box">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                    <h3>{{ $item['name'] }}</h3>
                    <p>{{ $item['summary'] }}</p>
                </article>
            @endforeach
        </div>
    </section>
@endif

<section class="section">
    <div class="section-heading"><p class="eyebrow">资讯</p><h2>了解近期动态</h2></div>
    <div class="grid">
        @forelse($latestArticles as $article)
            @include('theme.qcx-quantum-ai-005.partials.article-card', ['article' => $article])
        @empty
            <p>暂无资讯内容。</p>
        @endforelse
    </div>
</section>
@endsection
