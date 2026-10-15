@extends('theme.qcx-quantum-ai-005.layout')
@section('content')
<main class="page-shell article-detail">
    <p class="eyebrow">{{ $article->category?->name ?? '资讯' }}</p>
    <h1>{{ $article->title }}</h1>
    <p class="lead">{{ $excerptPlain ?? $article->excerpt ?? '' }}</p>
    <div class="prose">{!! $contentHtml !!}</div>
    <p><a class="button button-secondary" href="{{ \App\Support\Site\SitePageUrl::to('news') }}">← 返回资讯中心</a></p>
</main>
@endsection
