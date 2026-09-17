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
                    <div class="news-filters" aria-label="资讯筛选"><button type="button" data-news-filter="latest" aria-pressed="true">最新</button><button type="button" data-news-filter="featured" aria-pressed="false">精选</button><button type="button" data-news-filter="hot" aria-pressed="false">热门</button></div>
                </div>
                <div class="article-grid article-grid-catalog" data-news-list data-news-source="latest" data-news-source-from-query="true" data-news-limit="6" data-state="{{ $newsArticles->isEmpty() ? 'empty' : 'success' }}" aria-live="polite" aria-busy="false">
                    @forelse($newsArticles as $article)
                        @include('theme.template01.partials.article-card', ['article' => $article])
                    @empty
                        <div class="news-state" data-state="empty"><strong>暂无资讯内容。</strong></div>
                    @endforelse
                </div>
                <div class="news-pagination" aria-label="资讯分页"><button class="icon-button" type="button" data-news-previous disabled aria-label="上一页" title="上一页">←</button><span data-news-page-label>第 1 页</span><button class="icon-button" type="button" data-news-next disabled aria-label="下一页" title="下一页">→</button></div>
            </div>
        </section>
    </main>

    @include('theme.template01.partials.footer', ['template01' => $template01])
@endsection
