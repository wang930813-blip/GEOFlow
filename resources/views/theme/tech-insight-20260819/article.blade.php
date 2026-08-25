@extends('theme.tech-insight-20260819.layout')

@push('head')
    @php
        $schemaAtContext = chr(64).'context';
        $schemaAtType = chr(64).'type';
        $schemaAtId = chr(64).'id';
        $articleSchema = [
            $schemaAtContext => 'https://schema.org',
            $schemaAtType => 'NewsArticle',
            'headline' => $article->title,
            'description' => $pageDescription,
            'datePublished' => optional($article->published_at ?? $article->created_at)->toAtomString(),
            'dateModified' => optional($article->updated_at ?? $article->published_at ?? $article->created_at)->toAtomString(),
            'mainEntityOfPage' => [
                $schemaAtType => 'WebPage',
                $schemaAtId => $canonicalUrl ?? route('site.article', $article->slug),
            ],
            'author' => [
                $schemaAtType => 'Person',
                'name' => $article->author?->name ?? $siteTitle,
            ],
            'publisher' => [
                $schemaAtType => 'Organization',
                'name' => $siteTitle,
            ],
            'articleSection' => $article->category?->name,
            'keywords' => $tags,
        ];
    @endphp
    <meta property="og:title" content="{{ $article->title }}">
    <meta property="og:description" content="{{ $pageDescription }}">
    <meta property="og:type" content="article">
    <meta property="og:url" content="{{ $canonicalUrl ?? route('site.article', $article->slug) }}">
    @if($article->category)
        <meta property="article:section" content="{{ $article->category->name }}">
    @endif
    <script type="application/ld+json">
        {!! json_encode($articleSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
    </script>
@endpush

@section('content')
    <div id="mainContent" class="tx-shell tx-article-layout">
        <main class="tx-post-column">
            <nav class="tx-breadcrumb" aria-label="Breadcrumb">
                <a href="{{ route('site.home') }}">{{ __('front.nav.home') }}</a>
                @if($article->category)
                    <span>/</span>
                    <a href="{{ route('site.category', $article->category->slug) }}">{{ $article->category->name }}</a>
                @endif
                <span>/</span>
                <span>{{ $article->title }}</span>
            </nav>

            <article class="tx-article-main">
                <div class="tx-article-kicker">
                    @if($article->category)
                        <a href="{{ route('site.category', $article->category->slug) }}">{{ $article->category->name }}</a>
                    @endif
                    <time datetime="{{ ($article->published_at ?? $article->created_at)?->toAtomString() }}">
                        {{ ($article->published_at ?? $article->created_at)?->format('Y-m-d H:i') }}
                    </time>
                </div>

                <h1>{{ $article->title }}</h1>

                <div class="tx-post-info">
                    @if($article->author)
                        <span>{{ $article->author->name }}</span>
                    @endif
                    <span>{{ (int) $article->view_count }} views</span>
                </div>

                @if($excerptPlain !== '')
                    <p class="tx-article-excerpt">{{ $excerptPlain }}</p>
                @endif

                <div class="tx-prose">
                    {!! $contentHtml !!}
                </div>

                @if(!empty($tags))
                    <div class="tx-tag-list">
                        @foreach($tags as $tag)
                            <span>{{ $tag }}</span>
                        @endforeach
                    </div>
                @endif

                @if($stickyAd)
                    <section class="tx-ad-slot" data-ad-id="{{ $stickyAd['id'] }}">
                        @if($stickyAd['badge'] !== '')
                            <div class="tx-ad-slot__badge">{{ $stickyAd['badge'] }}</div>
                        @endif
                        @if($stickyAd['title'] !== '')
                            <h2>{{ $stickyAd['title'] }}</h2>
                        @endif
                        <p>{{ $stickyAd['copy'] }}</p>
                        <a href="{{ $stickyAd['button_url'] }}" class="tx-primary-link">{{ $stickyAd['button_text'] }}</a>
                    </section>
                @endif
            </article>

            @if($relatedArticles->isNotEmpty())
                <section class="tx-related-block">
                    <div class="tx-section-heading">
                        <span>{{ __('site.article_related') }}</span>
                    </div>
                    <div class="tx-related-grid">
                        @foreach($relatedArticles as $related)
                            <a href="{{ route('site.article', $related->slug) }}">
                                <span>{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                                <strong>{{ $related->title }}</strong>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
        </main>

        <aside class="tx-post-aside">
            @if($relatedArticles->isNotEmpty())
                <section class="tx-panel">
                    <div class="tx-section-heading">
                        <span>{{ __('site.article_related') }}</span>
                    </div>
                    <div class="tx-rank-list">
                        @foreach($relatedArticles as $related)
                            <a href="{{ route('site.article', $related->slug) }}">
                                <span>{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                                <strong>{{ $related->title }}</strong>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="tx-panel tx-signal-panel">
                <span class="tx-eyebrow">{{ $siteTitle }}</span>
                <h2>{{ $siteTitle }}</h2>
                @if($siteDescription !== '')
                    <p>{{ $siteDescription }}</p>
                @endif
                <a href="{{ route('site.home') }}" class="tx-link">{{ __('front.nav.home') }} <i data-lucide="arrow-right" aria-hidden="true"></i></a>
            </section>
        </aside>
    </div>
@endsection
