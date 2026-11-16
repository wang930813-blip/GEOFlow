@extends('website-admin.layout')

@section('content')
<div class='wa-page-heading'><div><span class='wa-eyebrow'>内容结构</span><h1>{{ $isCreate ? '新建分类' : '编辑分类' }}</h1><p>分类可在文章与产品之间共用。</p></div><a class='wa-button wa-button-secondary' href='{{ route('website-admin.categories') }}'>返回分类</a></div>
<form method='POST' action='{{ $isCreate ? route('website-admin.categories.store') : route('website-admin.categories.update', $category->id) }}' class='wa-form-stack'>
    @csrf @if (!$isCreate) @method('PUT') @endif
    <section class='wa-panel wa-form-panel'>
        <div class='wa-panel-heading'><div><h2>分类信息</h2><p>分类名称用于官网展示，页面地址由系统自动生成。</p></div></div>
        <div class='wa-form-grid'>
            <label class='wa-field'><span>分类名称 <b>*</b></span><input name='name' value='{{ old('name', $category->name) }}' maxlength='100' required autofocus></label>
            <label class='wa-field wa-field-wide'><span>分类说明</span><textarea name='description' rows='4' maxlength='1000'>{{ old('description', $category->description) }}</textarea></label>
            <label class='wa-field'><span>排序值</span><input type='number' name='sort_order' min='0' max='65535' value='{{ old('sort_order', $category->sort_order ?? 0) }}'><small>数值越大越靠前；相同数值按页面自身规则排列。默认 0。</small></label>
        </div>
    </section>
    <div class='wa-form-actions wa-sticky-actions'><a class='wa-button wa-button-secondary' href='{{ route('website-admin.categories') }}'>取消</a><button class='wa-button wa-button-primary' type='submit'><i data-lucide='save' aria-hidden='true'></i>保存分类</button></div>
</form>
@endsection
