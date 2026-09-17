@extends('theme.template01.layout')

@php
    $template01 = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $newsArticles = $template01['articles'];
@endphp

@section('body')
    @include('theme.template01.partials.header', ['template01' => $template01, 'activeNav' => 'news'])

    <main id="main-content">
        <header class="page-hero section-dark" id="top" data-hero>
            <div class="container page-hero-inner">
                <div class="page-hero-copy reveal">
                    <p class="eyebrow"><span class="eyebrow-dot"></span>News</p>
                    <h1 data-text-animate>资讯与动态</h1>
                    <p class="page-lead">查看已发布的最新资讯、精选内容与热门动态。</p>
                </div>
            </div>
        </header>

        <section class="section section-light news-catalog" aria-labelledby="news-list-title">
            <div class="container">
                <div class="catalog-heading reveal">
                    <div><p class="eyebrow eyebrow-dark"><span class="eyebrow-dot"></span>内容列表</p><h2 id="news-list-title">浏览资讯内容</h2></div>
                    <nav class="news-filters" aria-label="资讯筛选">
                        @foreach(['latest' => '最新', 'featured' => '精选', 'hot' => '热门'] as $filterSource => $filterLabel)
                            <a
                                href="{{ \App\Support\Site\SitePageUrl::to('news', $filterSource === 'latest' ? [] : ['source' => $filterSource]) }}"
                                @class(['is-active' => ($newsSource ?? 'latest') === $filterSource])
                                @if(($newsSource ?? 'latest') === $filterSource) aria-current="page" @endif
                            >{{ $filterLabel }}</a>
                        @endforeach
                    </nav>
                </div>
                <div class="article-grid article-grid-catalog" data-state="{{ $newsArticles->isEmpty() ? 'empty' : 'success' }}">
                    @forelse($newsArticles as $article)
                        @include('theme.template01.partials.article-card', ['article' => $article])
                    @empty
                        <div class="news-state" data-state="empty"><strong>暂无资讯内容。</strong></div>
                    @endforelse
                </div>
                @if($articles->hasPages())
                    <nav class="news-pagination" aria-label="资讯分页">
                        @if($articles->onFirstPage())
                            <span class="icon-button is-disabled" aria-disabled="true" aria-label="上一页" title="上一页">←</span>
                        @else
                            <a class="icon-button" href="{{ $articles->previousPageUrl() }}" aria-label="上一页" title="上一页">←</a>
                        @endif
                        <span>第 {{ $articles->currentPage() }} 页</span>
                        @if($articles->hasMorePages())
                            <a class="icon-button" href="{{ $articles->nextPageUrl() }}" aria-label="下一页" title="下一页">→</a>
                        @else
                            <span class="icon-button is-disabled" aria-disabled="true" aria-label="下一页" title="下一页">→</span>
                        @endif
                    </nav>
                @endif
            </div>
        </section>
    </main>

    @include('theme.template01.partials.footer', ['template01' => $template01])
@endsection
