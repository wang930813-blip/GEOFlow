<?php
/**
 * Created by Codex.
 * @Date: 2026-10-22
 * @Time: 17:05:42
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： news.blade.php
 * @Description: 服务端呈现真实资讯列表、筛选来源和分页。
 */
?>
@extends('theme.zk-violet-cloud-029.layout')
@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $activeNav = 'news';
    $pageTitle = '资讯中心 | ' . $themeData['site']['brand_name'];
    $pageDescription = '查看近期发布的资讯内容。';
    $canonicalUrl = \App\Support\Site\SitePageUrl::to('news');
    $newsLabel = ($newsSource ?? 'latest') === 'featured' ? '精选资讯' : ((($newsSource ?? 'latest') === 'hot') ? '热门资讯' : '最新资讯');
@endphp
@section('body')
<section class="page-hero reveal">
    <p class="eyebrow">{{ $newsLabel }}</p>
    <h1>资讯与动态</h1>
    <p class="page-lead">查看平台发布的近期内容。</p>
</section>
<section class="page-body section-shell">
    <div class="article-grid">
        @forelse($articles ?? [] as $article)
            @include('theme.zk-violet-cloud-029.partials.article-card', ['article' => $article])
        @empty
            <p class="empty-state">暂无资讯内容。</p>
        @endforelse
    </div>
    @if(isset($articles) && method_exists($articles, 'hasPages') && $articles->hasPages())
        <nav class="article-pagination" aria-label="资讯分页">{{ $articles->links() }}</nav>
    @endif
</section>
@endsection
