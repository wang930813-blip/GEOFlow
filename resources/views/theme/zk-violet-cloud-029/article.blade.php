<?php
/**
 * Created by Codex.
 * @Date: 2026-10-22
 * @Time: 17:05:42
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： article.blade.php
 * @Description: 输出平台已清洗的文章正文和对应元数据。
 */
?>
@extends('theme.zk-violet-cloud-029.layout')
@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $activeNav = 'news';
    $title = data_get($article ?? null, 'title', '资讯详情');
    $summary = $excerptPlain ?? data_get($article ?? null, 'excerpt', data_get($article ?? null, 'summary', ''));
    $published = data_get($article ?? null, 'published_at');
    $publishedLabel = is_object($published) && method_exists($published, 'format')
        ? $published->format('Y-m-d')
        : (is_string($published) ? $published : '');
    $publishedIso = is_object($published) && method_exists($published, 'toIso8601String')
        ? $published->toIso8601String()
        : $publishedLabel;
    $category = data_get($article ?? null, 'category.name', data_get($article ?? null, 'category_name', '资讯'));
    $pageTitle = $title . ' | ' . $themeData['site']['brand_name'];
    $pageDescription = $summary;
    $canonicalUrl = \App\Support\Site\SitePageUrl::to('article', ['slug' => data_get($article ?? null, 'slug', '')]);
@endphp
@if($publishedIso !== '')
    @push('head')
        <x-json-ld :data="['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $title, 'datePublished' => $publishedIso, 'description' => $summary, 'mainEntityOfPage' => $canonicalUrl]" />
    @endpush
@endif
@section('body')
<article class="article-page">
    <p class="article-back"><a class="text-link" href="{{ \App\Support\Site\SitePageUrl::to('news') }}"><span aria-hidden="true">←</span> 返回资讯中心</a></p>
    <h1>{{ $title }}</h1>
    <header class="article-header">
        <p class="eyebrow">{{ $category }}</p>
        @if($publishedLabel !== '')<p class="item-meta"><time datetime="{{ $publishedIso }}">{{ $publishedLabel }}</time></p>@endif
        @if($summary)<p class="page-lead">{{ $summary }}</p>@endif
    </header>
    <div class="prose article-prose">{!! $contentHtml ?? '' !!}</div>
</article>
@endsection
