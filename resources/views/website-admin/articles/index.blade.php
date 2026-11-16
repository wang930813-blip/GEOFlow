@extends('website-admin.layout')

@section('content')
<div class='wa-page-heading'><div><span class='wa-eyebrow'>官网内容</span><h1>文章管理</h1><p>编辑官网文章、完善搜索摘要，并控制文章公开状态。</p></div>@if ($context->allows('articles.create'))<a class='wa-button wa-button-primary' href='{{ route('website-admin.articles.create') }}'>新建文章</a>@endif</div>
<section class='wa-panel'>
    <div class='wa-panel-heading'><div><h2>文章列表</h2></div><span class='wa-count'>{{ $articles->total() }} 篇</span></div>
    @if ($articles->isEmpty())
        <div class='wa-empty'><strong>还没有文章</strong><p>创建文章或使用 AI 生成一份可编辑草稿。</p>@if ($context->allows('articles.create'))<a class='wa-button wa-button-primary' href='{{ route('website-admin.articles.create') }}'>新建文章</a>@endif</div>
    @else
        <div class='wa-table-wrap'><table class='wa-table'><thead><tr><th>标题</th><th>分类</th><th>状态</th><th>更新时间</th><th>操作</th></tr></thead><tbody>
            @foreach ($articles as $article)
                @php($canEditArticle = $context->allows('articles.edit') && ($article->status !== 'published' || $context->allows('articles.publish')))
                @php($canDeleteArticle = $context->allows('articles.delete') && ($article->status !== 'published' || $context->allows('articles.publish')))
                <tr>
                    <td><strong class='wa-table-title'>{{ $article->title }}</strong><small class='wa-table-subtitle'>{{ $article->meta_description ?: $article->excerpt }}</small></td>
                    <td>{{ $article->category?->name ?? '未分类' }}</td>
                    <td><span class='wa-status wa-status-{{ $article->status === 'published' ? 'success' : 'muted' }}'>{{ $article->status === 'published' ? '已发布' : '草稿' }}</span></td>
                    <td>{{ $article->updated_at?->format('Y-m-d H:i') }}</td>
                    <td><div class='wa-row-actions'>
                        @if ($canEditArticle)<a href='{{ route('website-admin.articles.edit', $article->id) }}'>编辑</a>@endif
                        @if ($canDeleteArticle)<form method='POST' action='{{ route('website-admin.articles.delete', $article->id) }}' data-wa-confirm='确定移除这篇文章吗？'>@csrf @method('DELETE')<button type='submit' class='wa-text-button wa-danger-text'>移除</button></form>@endif
                        @if ($article->status === 'published')<a href='{{ route('site.article', $article->slug) }}' target='_blank' rel='noopener'>查看 ↗</a>@endif
                    </div></td>
                </tr>
            @endforeach
        </tbody></table></div>
        <div class='wa-pagination'>{{ $articles->links() }}</div>
    @endif
</section>
@endsection
