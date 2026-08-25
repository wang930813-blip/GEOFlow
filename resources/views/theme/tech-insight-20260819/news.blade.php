@extends('theme.tech-insight-20260819.layout')

@push('head')
    @php
        $schemaAtContext = chr(64).'context';
        $schemaAtType = chr(64).'type';
        $schemaItems = [];
        foreach ((method_exists($articles, 'getCollection') ? $articles->getCollection() : collect($articles))->take(10) as $schemaArticle) {
            $schemaItems[] = [
                $schemaAtType => 'ListItem',
                'position' => count($schemaItems) + 1,
                'url' => route('site.article', $schemaArticle->slug),
                'name' => $schemaArticle->title,
            ];
        }
        $collectionSchema = [
            $schemaAtContext => 'https://schema.org',
            $schemaAtType => 'CollectionPage',
            'name' => $pageTitle,
            'description' => $pageDescription,
            'url' => $canonicalUrl ?? route('site.news'),
            'mainEntity' => [
                $schemaAtType => 'ItemList',
                'itemListElement' => $schemaItems,
            ],
        ];
    @endphp
    <script type="application/ld+json">
        {!! json_encode($collectionSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
    </script>
@endpush

@section('content')
    @php
        $categoryCards = collect($categories ?? []);
    @endphp

    <div id="mainContent" class="tx-shell tx-page-shell">
        <section class="tx-page-hero tx-page-hero--news">
            <img src="{{ asset('themes/tech-insight-20260819/assets/tech-banner-future.png') }}" alt="资讯" loading="eager">
            <div class="tx-page-hero__content">
                <span class="tx-eyebrow">NEWS</span>
                <h1>资讯</h1>
                <p>聚合文章分类与最新内容，快速进入对应资讯专题。</p>
            </div>
        </section>

        <section class="tx-topic-section tx-topic-section--page">
            <div class="tx-page-section-head">
                <span class="tx-eyebrow">资讯</span>
                <h2>资讯分类</h2>
            </div>

            <div class="tx-topic-grid">
                @forelse($categoryCards as $categoryItem)
                    @php
                        $description = trim((string) ($categoryItem->description ?? ''));
                    @endphp
                    <a href="{{ route('site.category', $categoryItem->slug) }}" class="tx-topic-card">
                        <div class="tx-topic-card__meta">
                            <span>{{ (int) ($categoryItem->published_count ?? 0) }} 篇</span>
                            <i data-lucide="arrow-up-right" aria-hidden="true"></i>
                        </div>
                        <strong>{{ $categoryItem->name }}</strong>
                        @if($description !== '')
                            <p>{{ \Illuminate\Support\Str::limit($description, 56) }}</p>
                        @endif
                    </a>
                @empty
                    <div class="tx-empty">暂无文章分类</div>
                @endforelse
            </div>
        </section>

        <div class="tx-content-grid tx-content-grid--compact">
            <section class="tx-content-main">
                <section class="tx-section">
                    <div class="tx-section-heading">
                        <span>最新资讯</span>
                    </div>
                    <div class="tx-article-grid">
                        @forelse($articles as $article)
                            @include('theme.tech-insight-20260819.partials.article-card', ['article' => $article])
                        @empty
                            <div class="tx-empty">{{ __('site.home_empty_title') }}</div>
                        @endforelse
                    </div>
                </section>

                <div class="tx-pagination">
                    {{ $articles->links() }}
                </div>
            </section>

            @include('theme.tech-insight-20260819.partials.sidebar', ['showFeedPanel' => false, 'showCategoryCloud' => false])
        </div>
    </div>
@endsection
