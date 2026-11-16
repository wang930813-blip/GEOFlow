@extends('website-admin.layout')

@section('content')
<div class='wa-page-heading'><div><span class='wa-eyebrow'>官网展示内容</span><h1>{{ $isCreate ? '新建单页' : '编辑单页' }}</h1><p>独立页面适用于企业介绍、联系方式及其他固定内容。</p></div><a class='wa-button wa-button-secondary' href='{{ route('website-admin.pages') }}'>返回单页</a></div>
<form method='POST' action='{{ $isCreate ? route('website-admin.pages.store') : route('website-admin.pages.update', $websitePage->id) }}' class='wa-editor-layout'>
    @csrf @if (!$isCreate) @method('PUT') @endif
    <section class='wa-panel wa-form-panel'>
        <div class='wa-panel-heading'><div><h2>页面正文</h2><p>内容支持 Markdown，可在保存前预览排版。</p></div></div>
        <div class='wa-form-grid'>
            <label class='wa-field wa-field-wide'><span>页面标题 <b>*</b></span><input name='title' value='{{ old('title', $websitePage->title) }}' maxlength='200' required autofocus></label>
            <label class='wa-field'><span>网址标识</span><input name='slug' value='{{ old('slug', $websitePage->slug) }}' maxlength='180' pattern='[a-z0-9]+(-[a-z0-9]+)*' autocomplete='off'><small>关于我们可使用 about，联系我们可使用 contact；留空时自动生成。</small></label>
            <label class='wa-field wa-field-wide'><span>页面正文 <b>*</b></span>@include('website-admin.partials.markdown-editor', ['fieldName' => 'content', 'fieldId' => 'wa-page-content', 'label' => '页面正文', 'value' => old('content', $websitePage->content)])</label>
        </div>
    </section>
    <aside class='wa-editor-side'>
        <section class='wa-panel wa-form-panel'>
            <div class='wa-panel-heading'><div><h2>发布与导航</h2><p>草稿页面仅在后台可见。</p></div></div>
            <div class='wa-form-grid wa-one-column'>
                <label class='wa-field'><span>公开状态</span>
                    @if ($context->allows('pages.publish'))
                        <select name='status'><option value='draft' @selected(old('status', $websitePage->status) === 'draft')>保存为草稿</option><option value='published' @selected(old('status', $websitePage->status) === 'published')>发布到官网</option></select>
                    @else
                        <input type='hidden' name='status' value='{{ old('status', $websitePage->status ?: 'draft') }}'><span class='wa-readonly-state'>{{ old('status', $websitePage->status) === 'published' ? '已发布' : '草稿' }}</span>
                    @endif
                </label>
                <label class='wa-field'><span>导航排序</span><input type='number' name='sort_order' min='0' max='65535' value='{{ old('sort_order', $websitePage->sort_order ?? 0) }}'><small>官网导航按排序值从大到小排列；相同数值按页面标题升序排列。关于我们和联系我们使用固定导航位置。默认 0。</small></label>
                <label class='wa-check-row'><input type='checkbox' name='show_in_navigation' value='1' @checked(old('show_in_navigation', $websitePage->show_in_navigation))><span><strong>显示在官网导航</strong><small>关于我们和联系我们使用固定导航位置。</small></span></label>
            </div>
        </section>
        <section class='wa-panel wa-form-panel'>
            <div class='wa-panel-heading'><div><h2>搜索摘要</h2><p>为搜索结果提供页面专属标题和说明。</p></div></div>
            <div class='wa-form-grid wa-one-column'>
                <label class='wa-field'><span>搜索标题</span><input name='meta_title' value='{{ old('meta_title', $websitePage->meta_title) }}' maxlength='255'></label>
                <label class='wa-field'><span>搜索摘要</span><textarea name='meta_description' rows='4' maxlength='320'>{{ old('meta_description', $websitePage->meta_description) }}</textarea></label>
            </div>
        </section>
        <div class='wa-form-actions wa-form-actions-stacked'><a class='wa-button wa-button-secondary' href='{{ route('website-admin.pages') }}'>取消</a><button class='wa-button wa-button-primary' type='submit'><i data-lucide='save' aria-hidden='true'></i>保存单页</button></div>
    </aside>
</form>
@endsection
