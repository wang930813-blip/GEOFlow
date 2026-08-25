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
            'url' => $canonicalUrl ?? route('site.category', $category->slug),
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
    <div id="mainContent" class="tx-shell tx-layout">
        <section class="tx-feed">
            <div class="tx-page-head tx-category-head">
                <span class="tx-eyebrow">{{ $siteTitle }} / {{ __('front.nav.categories') }}</span>
                <h1>{{ $category->name }}</h1>
                @if(trim((string) $category->description) !== '')
                    <p>{{ $category->description }}</p>
                @else
                    <p>{{ $pageDescription }}</p>
                @endif
                <div class="tx-tab-row" aria-label="{{ __('front.nav.categories') }}">
                    @foreach((isset($navCategories) ? collect($navCategories) : collect([$category])) as $categoryItem)
                        <a href="{{ route('site.category', $categoryItem->slug) }}" class="{{ $categoryItem->slug === $category->slug ? 'is-active' : '' }}">{{ $categoryItem->name }}</a>
                    @endforeach
                </div>
            </div>

            <section class="tx-section">
                <div class="tx-section-heading">
                    <span>{{ $category->name }}</span>
                </div>
                <div class="tx-card-stack">
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

        @include('theme.tech-insight-20260819.partials.sidebar')
    </div>
@endsection
