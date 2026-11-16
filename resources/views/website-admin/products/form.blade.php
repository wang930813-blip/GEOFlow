@extends('website-admin.layout')

@php
    $coverImageValue = (string) old('cover_image', $product->cover_image);
    $coverPreview = preg_match('/^(https?:\/\/|\/)[^\r\n]*$/i', $coverImageValue) === 1
        ? \App\Support\GeoFlow\ImageUrlNormalizer::toPublicUrl($coverImageValue)
        : '';
    $galleryText = (string) old('gallery_urls_text', implode("\n", (array) ($product->gallery ?? [])));
@endphp

@section('content')
<div class='wa-page-heading'><div><span class='wa-eyebrow'>官网展示内容</span><h1>{{ $isCreate ? '新增产品' : '编辑产品' }}</h1><p>完善产品介绍、封面和相册图片，可在发布前检查完整预览。</p></div><a class='wa-button wa-button-secondary' href='{{ route('website-admin.products') }}'>返回产品</a></div>
<form id='wa-product-form' method='POST' action='{{ $isCreate ? route('website-admin.products.store') : route('website-admin.products.update', $product->id) }}' class='wa-editor-layout' @if ($imageUploadAvailable) enctype='multipart/form-data' @endif>
    @csrf @if (!$isCreate) @method('PUT') @endif
    <section class='wa-panel wa-form-panel'>
        <div class='wa-panel-heading'><div><h2>产品内容</h2><p>完善名称、简介、图片和产品详细介绍。</p></div></div>
        <div class='wa-form-grid'>
            <label class='wa-field wa-field-wide'><span>产品名称 <b>*</b></span><input name='title' value='{{ old('title', $product->title) }}' maxlength='200' required autofocus></label>
            <label class='wa-field wa-field-wide'><span>内容分类</span><select name='category_id'><option value=''>不指定分类</option>@foreach ($categories as $category)<option value='{{ $category->id }}' @selected((string) old('category_id', $product->category_id) === (string) $category->id)>{{ $category->name }}</option>@endforeach</select></label>
            <label class='wa-field wa-field-wide'><span>产品摘要</span><textarea name='summary' rows='3' maxlength='1000'>{{ old('summary', $product->summary) }}</textarea></label>
            <div class='wa-field wa-field-wide wa-cover-field'>
                <span>封面图</span>
                <div class='wa-cover-preview' id='wa-product-cover-preview'>
                    <img id='wa-product-cover-image' @if ($coverPreview !== '') src='{{ $coverPreview }}' @endif alt='产品封面预览' @if ($coverPreview === '') hidden @endif>
                    <span id='wa-product-cover-empty' @if ($coverPreview !== '') hidden @endif><i data-lucide='image' aria-hidden='true'></i>暂无封面</span>
                </div>
                @if ($imageUploadAvailable)
                    <input class='wa-cover-file-input' id='wa-product-cover-file' name='cover_file' type='file' accept='image/jpeg,image/png,image/webp' aria-label='选择产品封面图片'>
                    <div class='wa-cover-actions'><label class='wa-button wa-button-secondary' for='wa-product-cover-file'><i data-lucide='upload' aria-hidden='true'></i>上传封面</label></div>
                @endif
            </div>
            <label class='wa-field wa-field-wide'><span>封面图片地址</span><input id='wa-product-cover-url' name='cover_image' value='{{ $coverImageValue }}' maxlength='1000' placeholder='HTTP(S) 地址或 / 开头'><small>@if ($imageUploadAvailable) 上传新图片时优先使用上传文件。 @else 填写已有图片的 HTTP(S) 地址或站内路径；启用并完整配置云存储插件后可上传图片。 @endif</small></label>
            <div class='wa-field wa-field-wide wa-gallery-field'>
                <span>产品相册</span>
                <textarea id='wa-product-gallery-urls' name='gallery_urls_text' rows='5' maxlength='11000' placeholder='每行填写一张图片的 HTTP(S) 地址或站内路径'>{{ $galleryText }}</textarea>
                <small>每行填写一张图片地址，最多十张；删除地址即可从相册移除对应图片。</small>
                @if ($imageUploadAvailable)
                    <input class='wa-cover-file-input' id='wa-product-gallery-files' name='gallery_files[]' type='file' accept='image/jpeg,image/png,image/webp' multiple aria-label='选择产品相册图片'>
                    <div class='wa-cover-actions'>
                        <label class='wa-button wa-button-secondary' for='wa-product-gallery-files'><i data-lucide='images' aria-hidden='true'></i>上传相册图片</label>
                        <button class='wa-text-button' id='wa-product-gallery-clear-files' type='button' hidden>清除待上传图片</button>
                    </div>
                @else
                    <small>云存储插件未启用或配置未完成，相册当前仅支持填写已有图片地址。</small>
                @endif
                <div class='wa-gallery-meta'><span class='wa-gallery-count' id='wa-product-gallery-count' aria-live='polite'>0 / 10 张</span><span>图片预览</span></div>
                <div class='wa-gallery-preview' id='wa-product-gallery-preview' aria-label='产品相册预览'></div>
            </div>
            <div class='wa-field wa-field-wide'><span>{{ $label ?? '产品介绍' }} <b>*</b></span>@include('website-admin.partials.markdown-editor', ['fieldName' => 'content', 'fieldId' => 'wa-product-content', 'label' => '产品介绍', 'value' => old('content', $product->content)])</div>
        </div>
    </section>
    <aside class='wa-editor-side'>
        <section class='wa-panel wa-form-panel'>
            <div class='wa-panel-heading'><div><h2>发布与展示</h2><p>未发布的产品不会出现在官网。</p></div></div>
            <div class='wa-form-grid wa-one-column'>
                <label class='wa-field'><span>公开状态</span>
                    @if ($context->allows('products.publish'))
                        <select name='status'><option value='draft' @selected(old('status', $product->status) === 'draft')>保存为草稿</option><option value='published' @selected(old('status', $product->status) === 'published')>发布到官网</option></select>
                    @else
                        <input type='hidden' name='status' value='{{ old('status', $product->status ?: 'draft') }}'><span class='wa-readonly-state'>{{ old('status', $product->status) === 'published' ? '已发布' : '草稿' }}</span>
                    @endif
                </label>
                <label class='wa-field'><span>展示排序</span><input type='number' name='sort_order' min='0' max='65535' value='{{ old('sort_order', $product->sort_order ?? 0) }}'><small>首页推荐产品优先展示；同组内排序值从大到小，相同数值按更新时间由新到旧。默认 0。</small></label>
                <label class='wa-check-row'><input type='checkbox' name='is_featured' value='1' @checked(old('is_featured', $product->is_featured))><span><strong>首页推荐</strong><small>在产品中心中优先显示。</small></span></label>
            </div>
        </section>
        <section class='wa-panel wa-form-panel'>
            <div class='wa-panel-heading'><div><h2>搜索摘要</h2><p>为产品详情页补充搜索元数据。</p></div></div>
            <div class='wa-form-grid wa-one-column'>
                <label class='wa-field'><span>搜索标题</span><input name='meta_title' value='{{ old('meta_title', $product->meta_title) }}' maxlength='255'></label>
                <label class='wa-field'><span>搜索摘要</span><textarea name='meta_description' rows='4' maxlength='320'>{{ old('meta_description', $product->meta_description) }}</textarea></label>
            </div>
        </section>
        <div class='wa-form-actions wa-form-actions-stacked'><a class='wa-button wa-button-secondary' href='{{ route('website-admin.products') }}'>取消</a><button class='wa-button wa-button-primary' type='submit'><i data-lucide='save' aria-hidden='true'></i>保存产品</button></div>
    </aside>
</form>
@endsection

@push('scripts')
<script>
/**
 * Created by Codex.
 * @Date: 2026-11-13
 * @Time: 09:33:32
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： form.blade.php
 * @Description: 提供产品封面及相册图片的本地预览交互。
 */
(() => {
    const coverFile = document.querySelector('#wa-product-cover-file');
    const coverUrl = document.querySelector('#wa-product-cover-url');
    const coverImage = document.querySelector('#wa-product-cover-image');
    const coverEmpty = document.querySelector('#wa-product-cover-empty');
    const galleryFile = document.querySelector('#wa-product-gallery-files');
    const galleryUrls = document.querySelector('#wa-product-gallery-urls');
    const galleryPreview = document.querySelector('#wa-product-gallery-preview');
    const galleryCount = document.querySelector('#wa-product-gallery-count');
    const clearGalleryFiles = document.querySelector('#wa-product-gallery-clear-files');
    let coverObjectUrl = null;
    let galleryObjectUrls = [];
    const isPreviewableUrl = value => /^(https?:\/\/|\/)/i.test(value);

    const releaseCoverObjectUrl = () => {
        if (coverObjectUrl) URL.revokeObjectURL(coverObjectUrl);
        coverObjectUrl = null;
    };

    const showCoverPreview = source => {
        const previewSource = source.startsWith('blob:') || isPreviewableUrl(source) ? source : '';
        coverImage.hidden = !previewSource;
        coverEmpty.hidden = !!previewSource;
        if (previewSource) coverImage.src = previewSource;
        else coverImage.removeAttribute('src');
    };

    coverFile?.addEventListener('change', () => {
        releaseCoverObjectUrl();
        coverObjectUrl = coverFile.files?.[0] ? URL.createObjectURL(coverFile.files[0]) : null;
        showCoverPreview(coverObjectUrl || coverUrl.value.trim());
    });

    coverUrl.addEventListener('input', () => {
        if (coverFile?.files?.length) coverFile.value = '';
        releaseCoverObjectUrl();
        showCoverPreview(coverUrl.value.trim());
    });

    coverImage.addEventListener('error', () => {
        coverImage.hidden = true;
        coverEmpty.hidden = false;
    });

    const releaseGalleryObjectUrls = () => {
        galleryObjectUrls.forEach(objectUrl => URL.revokeObjectURL(objectUrl));
        galleryObjectUrls = [];
    };

    const appendPreview = (source, label, index) => {
        const item = document.createElement('figure');
        item.className = 'wa-gallery-preview-item';
        const image = document.createElement('img');
        image.src = source;
        image.alt = `产品相册第 ${index + 1} 张图片`;
        image.loading = 'lazy';
        image.addEventListener('error', () => {
            item.classList.add('is-unavailable');
            image.removeAttribute('src');
        }, { once: true });
        const caption = document.createElement('figcaption');
        caption.textContent = label;
        item.append(image, caption);
        galleryPreview.append(item);
    };

    const updateGalleryPreview = () => {
        releaseGalleryObjectUrls();
        galleryPreview.replaceChildren();

        const urls = [...new Set(galleryUrls.value.split(/\r\n|\r|\n/).map(value => value.trim()).filter(Boolean))];
        const files = Array.from(galleryFile?.files || []);
        const total = urls.length + files.length;
        galleryCount.textContent = `${total} / 10 张`;
        galleryCount.classList.toggle('is-over-limit', total > 10);
        if (clearGalleryFiles) clearGalleryFiles.hidden = files.length === 0;

        let index = 0;
        urls.forEach(url => {
            if (isPreviewableUrl(url)) {
                appendPreview(url, `图片 ${index + 1}`, index);
            } else {
                const unavailable = document.createElement('figure');
                unavailable.className = 'wa-gallery-preview-item is-unavailable';
                const caption = document.createElement('figcaption');
                caption.textContent = `第 ${index + 1} 张地址格式不支持预览`;
                unavailable.append(caption);
                galleryPreview.append(unavailable);
            }
            index += 1;
        });
        files.forEach(file => {
            const objectUrl = URL.createObjectURL(file);
            galleryObjectUrls.push(objectUrl);
            appendPreview(objectUrl, `待上传 ${index + 1}`, index);
            index += 1;
        });

        if (total === 0) {
            const empty = document.createElement('p');
            empty.className = 'wa-gallery-empty';
            empty.textContent = '添加图片地址或选择图片后，这里会显示相册预览。';
            galleryPreview.append(empty);
        }
    };

    galleryUrls.addEventListener('input', updateGalleryPreview);
    galleryFile?.addEventListener('change', updateGalleryPreview);
    clearGalleryFiles?.addEventListener('click', () => {
        galleryFile.value = '';
        updateGalleryPreview();
    });
    updateGalleryPreview();

    window.addEventListener('pagehide', () => {
        releaseCoverObjectUrl();
        releaseGalleryObjectUrls();
    });
})();
</script>
@endpush
