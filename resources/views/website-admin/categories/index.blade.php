@extends('website-admin.layout')

@section('content')
<div class='wa-page-heading'>
    <div><span class='wa-eyebrow'>内容结构</span><h1>分类管理</h1><p>同一分类可同时用于文章与产品，官网访客可按分类浏览。</p></div>
    @if ($context->allows('categories.create'))<a class='wa-button wa-button-primary' href='{{ route('website-admin.categories.create') }}'><i data-lucide='plus' aria-hidden='true'></i>新建分类</a>@endif
</div>
<section class='wa-panel'>
    <div class='wa-panel-heading'><div><h2>分类列表</h2></div><span class='wa-count'>{{ $categories->total() }} 个分类</span></div>
    @if ($categories->isEmpty())
        <div class='wa-empty'><strong>还没有内容分类</strong><p>创建分类后，可在文章和产品中重复使用。</p>@if ($context->allows('categories.create'))<a class='wa-button wa-button-primary' href='{{ route('website-admin.categories.create') }}'>创建第一个分类</a>@endif</div>
    @else
        <div class='wa-table-wrap'><table class='wa-table'><thead><tr><th>分类</th><th>文章</th><th>产品</th><th>排序</th><th>操作</th></tr></thead><tbody>
            @foreach ($categories as $category)
                <tr><td><strong class='wa-table-title'>{{ $category->name }}</strong><small class='wa-table-subtitle'>{{ $category->description ?: '暂无分类说明' }}</small></td><td>{{ $category->article_count }}</td><td>{{ $category->product_count }}</td><td>{{ $category->sort_order }}</td><td><div class='wa-row-actions'>@if ($context->allows('categories.edit'))<a href='{{ route('website-admin.categories.edit', $category->id) }}'>编辑</a>@endif @if ($context->allows('categories.delete'))<form method='POST' action='{{ route('website-admin.categories.delete', $category->id) }}' data-wa-confirm='确定删除此分类吗？'>@csrf @method('DELETE')<button type='submit' class='wa-text-button wa-danger-text'>删除</button></form>@endif</div></td></tr>
            @endforeach
        </tbody></table></div>
        <div class='wa-pagination'>{{ $categories->links() }}</div>
    @endif
</section>
@endsection
