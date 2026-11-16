<?php
/**
 * Created by Codex.
 * @Date: 2026-10-22
 * @Time: 17:05:42
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： article-card.blade.php
 * @Description: 呈现真实文章的分类、时间、标题和摘要。
 */
?>
@php
    $articleTitle = data_get($article, 'title', '资讯标题');
    $articleSummary = data_get($article, 'excerpt', data_get($article, 'summary', ''));
    $articleSlug = data_get($article, 'slug', '');
    $articleDate = data_get($article, 'published_at');
    $articleDateLabel = is_object($articleDate) && method_exists($articleDate, 'format')
        ? $articleDate->format('Y-m-d')
        : (is_string($articleDate) ? $articleDate : '');
    $articleCategory = data_get($article, 'category.name', data_get($article, 'category_name', '资讯'));
@endphp
<article class="article-card">
    <p class="item-meta">{{ $articleCategory }}@if($articleDateLabel !== '') · {{ $articleDateLabel }}@endif</p>
    <h3><a href="{{ \App\Support\Site\SitePageUrl::to('article', ['slug' => $articleSlug]) }}">{{ $articleTitle }}</a></h3>
    @if($articleSummary)<p>{{ $articleSummary }}</p>@endif
</article>
