@extends('website-admin.layout')

@section('content')
<div class='wa-page-heading'>
    <div><span class='wa-eyebrow'>站点工作区</span><h1>概览</h1><p>查看官网内容状态和搜索基础配置。</p></div>
    @if ($context->allows('articles.create'))<a class='wa-button wa-button-primary' href='{{ route('website-admin.articles.create') }}'><i data-lucide='plus' aria-hidden='true'></i>新建文章</a>@endif
</div>
<section class='wa-stat-grid' aria-label='网站统计'>
    <div class='wa-stat wa-stat-articles'><span class='wa-stat-icon'><i data-lucide='newspaper' aria-hidden='true'></i></span><div><span>全部文章</span><strong>{{ number_format($articleCount) }}</strong><small>{{ number_format($publishedCount) }} 篇已发布 · {{ number_format($draftCount) }} 篇草稿</small></div></div>
    <div class='wa-stat wa-stat-products'><span class='wa-stat-icon'><i data-lucide='package' aria-hidden='true'></i></span><div><span>官网产品</span><strong>{{ number_format($productCount) }}</strong><small>{{ number_format($publishedProductCount) }} 件公开展示</small></div></div>
    <div class='wa-stat wa-stat-pages'><span class='wa-stat-icon'><i data-lucide='files' aria-hidden='true'></i></span><div><span>独立页面</span><strong>{{ number_format($pageCount) }}</strong><small>已创建的独立页面</small></div></div>
    <div class='wa-stat wa-stat-seo'><span class='wa-stat-icon'><i data-lucide='scan-search' aria-hidden='true'></i></span><div><span>SEO 基础项</span><strong>{{ $configuredSeoFields }}<i>/4</i></strong><small>已填写关键字段</small></div></div>
</section>
<div class='wa-dashboard-grid'>
    <section class='wa-panel'>
        <div class='wa-panel-heading'><div><h2>最近更新</h2><p>最近编辑的官网文章</p></div><a href='{{ route('website-admin.articles') }}'>查看全部 <i data-lucide='arrow-right' aria-hidden='true'></i></a></div>
        @if ($recentArticles->isEmpty())
            <div class='wa-empty'><strong>还没有文章</strong><p>新建一篇文章，开始完善官网内容。</p>@if ($context->allows('articles.create'))<a class='wa-button wa-button-primary' href='{{ route('website-admin.articles.create') }}'>新建文章</a>@endif</div>
        @else
            <div class='wa-table-wrap'><table class='wa-table'><thead><tr><th>文章</th><th>分类</th><th>状态</th><th>最近更新</th></tr></thead><tbody>
                @foreach ($recentArticles as $article)
                    <tr><td><a class='wa-table-title' href='{{ route('website-admin.articles.edit', $article->id) }}'>{{ $article->title }}</a></td><td>{{ $article->category?->name ?? '未分类' }}</td><td><span class='wa-status wa-status-{{ $article->status === 'published' ? 'success' : 'muted' }}'>{{ $article->status === 'published' ? '已发布' : '草稿' }}</span></td><td>{{ $article->updated_at?->format('Y-m-d H:i') }}</td></tr>
                @endforeach
            </tbody></table></div>
        @endif
    </section>
    <section class='wa-panel wa-setup-panel'>
        <div class='wa-panel-heading'><div><h2>官网设置</h2><p>站点公开信息与搜索入口</p></div></div>
        <div class='wa-setup-list'>
            @if ($context->allows('site.settings.read'))<a href='{{ route('website-admin.settings') }}'><span class='wa-setup-mark'><i data-lucide='settings-2' aria-hidden='true'></i></span><span><strong>完善网站设置</strong><small>{{ trim((string) ($settings['site_description'] ?? '')) !== '' ? '网站简介已填写' : '添加简介、SEO 标题和企业资料' }}</small></span><i data-lucide='chevron-right' aria-hidden='true'></i></a>@endif
            @if ($context->allows('categories.read'))<a href='{{ route('website-admin.categories') }}'><span class='wa-setup-mark'><i data-lucide='folder-tree' aria-hidden='true'></i></span><span><strong>整理内容分类</strong><small>文章与产品共用分类</small></span><i data-lucide='chevron-right' aria-hidden='true'></i></a>@endif
            @if ($context->allows('products.create'))<a href='{{ route('website-admin.products.create') }}'><span class='wa-setup-mark'><i data-lucide='package' aria-hidden='true'></i></span><span><strong>添加官网产品</strong><small>展示产品信息与详细介绍</small></span><i data-lucide='chevron-right' aria-hidden='true'></i></a>@endif
            @if ($context->allows('pages.create'))<a href='{{ route('website-admin.pages.create') }}'><span class='wa-setup-mark'><i data-lucide='files' aria-hidden='true'></i></span><span><strong>创建独立页面</strong><small>管理关于、联系及自定义页面</small></span><i data-lucide='chevron-right' aria-hidden='true'></i></a>@endif
        </div>
        <p class='wa-setup-note'>域名、HTTPS 与网站模板由主控制台的“网站管理”页面配置。</p>
        <div class='wa-crawler-links'><strong>搜索引擎入口</strong><a href='/sitemap.xml' target='_blank' rel='noopener'>sitemap.xml ↗</a><a href='/robots.txt' target='_blank' rel='noopener'>robots.txt ↗</a><a href='/llms.txt' target='_blank' rel='noopener'>llms.txt ↗</a></div>
    </section>
</div>
@endsection
