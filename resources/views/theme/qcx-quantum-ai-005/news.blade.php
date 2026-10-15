@extends('theme.qcx-quantum-ai-005.layout')

@php($themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars()))

@section('content')
<main class="page-shell">
    <header class="page-heading">
        <p class="eyebrow">资讯</p>
        <h1>最新内容</h1>
        <p class="lead">{{ $themeData['site']['brand_tagline'] }}</p>
    </header>
    <section class="section">
        <div class="grid">
            @forelse($articles as $article)
                @include('theme.qcx-quantum-ai-005.partials.article-card', ['article' => $article])
            @empty
                <p>暂无资讯内容。</p>
            @endforelse
        </div>
        <div class="article-pagination">{{ $articles->links() }}</div>
    </section>
</main>
@endsection
