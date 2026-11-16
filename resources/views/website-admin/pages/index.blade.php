@extends('website-admin.layout')

@section('content')
<div class='wa-page-heading'>
    <div><span class='wa-eyebrow'>官网展示内容</span><h1>单页管理</h1><p>编辑关于我们、联系我们等不需要归入文章列表的页面。</p></div>
    @if ($context->allows('pages.create'))<a class='wa-button wa-button-primary' href='{{ route('website-admin.pages.create') }}'><i data-lucide='plus' aria-hidden='true'></i>新增单页</a>@endif
</div>
<section class='wa-panel'>
    <div class='wa-panel-heading'><div><h2>独立页面</h2><p>页面发布后可通过固定网址访问。</p></div><span class='wa-count'>{{ $pages->total() }} 个页面</span></div>
    @if ($pages->isEmpty())
        <div class='wa-empty'><strong>还没有独立页面</strong><p>为企业介绍、联系方式或其他固定内容创建页面。</p>@if ($context->allows('pages.create'))<a class='wa-button wa-button-primary' href='{{ route('website-admin.pages.create') }}'>创建第一个单页</a>@endif</div>
    @else
        <div class='wa-table-wrap'><table class='wa-table'><thead><tr><th>页面</th><th>导航显示</th><th>状态</th><th>最近更新</th><th>操作</th></tr></thead><tbody>
            @foreach ($pages as $websitePage)
                @php($pageUrl = in_array($websitePage->slug, ['about', 'contact'], true) ? route('site.'.($websitePage->slug === 'about' ? 'about' : 'contact')) : route('site.page', $websitePage->slug))
                @php($canEditPage = $context->allows('pages.edit') && ($websitePage->status !== 'published' || $context->allows('pages.publish')))
                @php($canDeletePage = $context->allows('pages.delete') && ($websitePage->status !== 'published' || $context->allows('pages.publish')))
                <tr>
                    <td><strong class='wa-table-title'>{{ $websitePage->title }}</strong><small class='wa-table-subtitle'>{{ $websitePage->meta_description ?: '/page/'.$websitePage->slug }}</small></td>
                    <td>{{ $websitePage->show_in_navigation ? '显示' : '隐藏' }}</td>
                    <td><span class='wa-status wa-status-{{ $websitePage->status === 'published' ? 'success' : 'muted' }}'>{{ $websitePage->status === 'published' ? '已发布' : '草稿' }}</span></td>
                    <td>{{ $websitePage->updated_at?->format('Y-m-d H:i') }}</td>
                    <td><div class='wa-row-actions'>
                        @if ($canEditPage)<a href='{{ route('website-admin.pages.edit', $websitePage->id) }}'>编辑</a>@endif
                        @if ($canDeletePage)<form method='POST' action='{{ route('website-admin.pages.delete', $websitePage->id) }}' data-wa-confirm='确定移除这个单页吗？'>@csrf @method('DELETE')<button type='submit' class='wa-text-button wa-danger-text'>移除</button></form>@endif
                        @if ($websitePage->status === 'published')<a href='{{ $pageUrl }}' target='_blank' rel='noopener'>查看 ↗</a>@endif
                    </div></td>
                </tr>
            @endforeach
        </tbody></table></div>
        <div class='wa-pagination'>{{ $pages->links() }}</div>
    @endif
</section>
@endsection
