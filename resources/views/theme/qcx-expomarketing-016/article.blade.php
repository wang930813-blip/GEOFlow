@extends('theme.qcx-expomarketing-016.layout')
@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $activeNav = 'news';
    $title = data_get($article ?? null, 'title', '博客详情');
    $summary = $excerptPlain ?? data_get($article ?? null, 'excerpt', data_get($article ?? null, 'summary', '博客详情。'));
    $published = data_get($article ?? null, 'published_at');
    $publishedLabel = is_object($published) && method_exists($published, 'format') ? $published->format('Y-m-d') : (is_string($published) ? $published : '');
    $publishedIso = is_object($published) && method_exists($published, 'toIso8601String') ? $published->toIso8601String() : $publishedLabel;
    $category = data_get($article ?? null, 'category.name', data_get($article ?? null, 'category_name', '博客'));
    $pageTitle = $title . ' | ' . $themeData['site']['brand_name'];
    $pageDescription = $summary;
    $canonicalUrl = \App\Support\Site\SitePageUrl::to('article', ['slug' => data_get($article ?? null, 'slug', '')]);
@endphp
@section('content')
<section class="page-hero"><article class="article-detail"><p class="eyebrow">{{ $category }}</p><h1>{{ $title }}</h1>@if($summary)<p class="lead">{{ $summary }}</p>@endif @if($publishedLabel !== '')<p class="item-meta"><time datetime="{{ $publishedIso }}">{{ $publishedLabel }}</time></p>@endif<div class="prose">{!! $contentHtml ?? '' !!}</div></article></section>
@endsection
