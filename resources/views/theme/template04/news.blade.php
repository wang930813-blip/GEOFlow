@extends('theme.template04.layout')

@section('content')
<main class="page-shell">
    <header class="page-heading">
        <p class="eyebrow">资讯中心</p>
        <h1>最新资讯</h1>
        <p class="lead">查看已发布的文章与动态。</p>
    </header>

    <section class="section">
        <div class="grid">
            @forelse($articles as $article)
                @include('theme.template04.partials.article-card', ['article' => $article, 'cardSummaries' => $cardSummaries ?? []])
            @empty
                <p role="status">暂无资讯内容。</p>
            @endforelse
        </div>
        @if($articles->hasPages())
            <nav class="article-pagination" aria-label="资讯分页">
                @if(!$articles->onFirstPage())
                    <a class="button button-secondary" href="{{ $articles->previousPageUrl() }}">上一页</a>
                @endif
                <span aria-current="page">第 {{ $articles->currentPage() }} 页</span>
                @if($articles->hasMorePages())
                    <a class="button button-secondary" href="{{ $articles->nextPageUrl() }}">下一页</a>
                @endif
            </nav>
        @endif
    </section>
</main>
@endsection
