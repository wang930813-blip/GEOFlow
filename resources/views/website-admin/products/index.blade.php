@extends('website-admin.layout')

@section('content')
<div class='wa-page-heading'>
    <div><span class='wa-eyebrow'>官网展示内容</span><h1>产品管理</h1><p>管理官网产品目录、产品介绍和公开状态。</p></div>
    @if ($context->allows('products.create'))<a class='wa-button wa-button-primary' href='{{ route('website-admin.products.create') }}'><i data-lucide='plus' aria-hidden='true'></i>新增产品</a>@endif
</div>
<section class='wa-panel'>
    <div class='wa-panel-heading'><div><h2>产品目录</h2><p>草稿仅在后台可见；已发布产品会显示在官网。</p></div><span class='wa-count'>{{ $products->total() }} 件产品</span></div>
    @if ($products->isEmpty())
        <div class='wa-empty'><strong>还没有产品</strong><p>添加产品名称、介绍与封面后，可发布到官网产品中心。</p>@if ($context->allows('products.create'))<a class='wa-button wa-button-primary' href='{{ route('website-admin.products.create') }}'>新增产品</a>@endif</div>
    @else
        <div class='wa-table-wrap'><table class='wa-table'><thead><tr><th>产品</th><th>分类</th><th>展示</th><th>状态</th><th>最近更新</th><th>操作</th></tr></thead><tbody>
            @foreach ($products as $product)
                @php($canEditProduct = $context->allows('products.edit') && ($product->status !== 'published' || $context->allows('products.publish')))
                @php($canDeleteProduct = $context->allows('products.delete') && ($product->status !== 'published' || $context->allows('products.publish')))
                <tr>
                    <td><div class='wa-product-cell'>@if ($product->cover_image)<img src='{{ $product->cover_image }}' alt='' loading='lazy'>@else<span class='wa-product-placeholder' aria-hidden='true'>{{ mb_substr($product->title, 0, 1) }}</span>@endif<div><strong class='wa-table-title'>{{ $product->title }}</strong><small class='wa-table-subtitle'>{{ $product->summary ?: $product->slug }}</small></div></div></td>
                    <td>{{ $product->category?->name ?? '未分类' }}</td>
                    <td>{{ $product->is_featured ? '推荐' : '普通' }}</td>
                    <td><span class='wa-status wa-status-{{ $product->status === 'published' ? 'success' : 'muted' }}'>{{ $product->status === 'published' ? '已发布' : '草稿' }}</span></td>
                    <td>{{ $product->updated_at?->format('Y-m-d H:i') }}</td>
                    <td><div class='wa-row-actions'>
                        @if ($canEditProduct)<a href='{{ route('website-admin.products.edit', $product->id) }}'>编辑</a>@endif
                        @if ($canDeleteProduct)<form method='POST' action='{{ route('website-admin.products.delete', $product->id) }}' data-wa-confirm='确定移除这个产品吗？'>@csrf @method('DELETE')<button type='submit' class='wa-text-button wa-danger-text'>移除</button></form>@endif
                        @if ($product->status === 'published')<a href='{{ route('site.products.show', $product->slug) }}' target='_blank' rel='noopener'>查看 ↗</a>@endif
                    </div></td>
                </tr>
            @endforeach
        </tbody></table></div>
        <div class='wa-pagination'>{{ $products->links() }}</div>
    @endif
</section>
@endsection
