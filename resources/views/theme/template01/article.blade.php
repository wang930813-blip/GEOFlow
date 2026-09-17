@extends('theme.template01.layout')

@php
    $template01 = \App\Support\Site\Template01Data::fromView(get_defined_vars());
@endphp

@push('head')
    @php
        $schemaAtContext = chr(64).'context';
        $schemaAtType = chr(64).'type';
        $articleSchema = [
            $schemaAtContext => 'https://schema.org',
            $schemaAtType => 'Article',
            'headline' => $article->title,
            'description' => $pageDescription ?? '',
            'url' => $canonicalUrl ?? route('site.article', ['slug' => $article->slug]),
            'datePublished' => optional($article->published_at)->toIso8601String(),
        ];
    @endphp
    <x-json-ld :data="$articleSchema" />
@endpush

@section('body')
    @include('theme.template01.partials.header', ['template01' => $template01, 'activeNav' => 'news'])

    <main id="main-content">
        <article class="article-detail" data-state="success">
            <header class="article-hero section-dark" id="top" data-hero>
                <div class="container article-hero-inner">
                    <a class="text-link text-link-light" href="{{ \App\Support\Site\SitePageUrl::to('news') }}">返回资讯中心</a>
                    <div class="article-meta"><span data-article-category>{{ $article->category?->name ?? '资讯' }}</span>@if($article->published_at)<time data-article-date datetime="{{ $article->published_at->toIso8601String() }}">{{ $article->published_at->format('Y-m-d') }}</time>@endif</div>
                    <h1 data-article-title>{{ $article->title }}</h1>
                    <p class="page-lead" data-article-summary>{{ $pageDescription ?? $excerptPlain ?? '' }}</p>
                </div>
            </header>

            <section class="section section-light article-detail-section" aria-label="资讯正文">
                <div class="container article-detail-layout">
                    <div class="article-detail-status" data-article-status data-state="success" aria-live="polite">正文内容</div>
                    <div class="article-detail-body">
                        @if(trim((string) ($article->cover_image ?? '')) !== '')
                            <figure class="article-detail-cover" data-article-cover><img src="{{ $article->cover_image }}" alt="{{ $article->title }}"></figure>
                        @endif
                        <div class="article-prose" data-article-content>{!! $contentHtml !!}</div>
                    </div>
                </div>
            </section>
        </article>
    </main>

    @include('theme.template01.partials.footer', ['template01' => $template01])
@endsection
