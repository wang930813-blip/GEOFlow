@extends('theme.qcx-niapps-018.layout')
@php($themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars())) @php($activeNav = 'news')
@section('content')
<section class="page-hero"><div class="page-header"><p class="eyebrow">项目</p><h1>数字项目与增长观察</h1><p class="lead">这里只展示平台文章服务提供的真实内容。</p></div></section>
<section class="page-content"><div class="article-grid">@forelse($articles ?? [] as $article)@include('theme.qcx-niapps-018.partials.article-card', ['article' => $article])@empty<p class="article-status">暂无资讯内容。</p>@endforelse</div>@if(isset($articles) && method_exists($articles, 'hasPages') && $articles->hasPages())<nav class="article-pagination" aria-label="资讯分页">{{ $articles->links() }}</nav>@endif</section>
@endsection
