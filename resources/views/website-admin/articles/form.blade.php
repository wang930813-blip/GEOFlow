@extends('website-admin.layout')

@php($coverPreview = \App\Support\GeoFlow\ImageUrlNormalizer::toPublicUrl((string) old('cover_image', $article->cover_image)))

@section('content')
<div class='wa-page-heading'><div><span class='wa-eyebrow'>官网内容</span><h1>{{ $isCreate ? '新建文章' : '编辑文章' }}</h1><p>使用清晰标题、简洁摘要和有依据的正文内容。</p></div><div class='wa-article-heading-actions'>@if ($context->allows('articles.ai'))<button class='wa-button wa-button-primary' id='wa-ai-open' type='button' aria-haspopup='dialog' aria-controls='wa-ai-dialog'><i data-lucide='sparkles' aria-hidden='true'></i>AI 生成文章</button>@endif<a class='wa-button wa-button-secondary' href='{{ route('website-admin.articles') }}'>返回文章列表</a></div></div>
<div class='wa-alert wa-alert-success' id='wa-article-generated' role='status' hidden>文章草稿已自动保存，请核对内容。</div>
<div class='wa-alert wa-alert-danger' id='wa-article-save-error' role='alert' hidden></div>
<form id='wa-article-form' method='POST' @if ($coverUploadAvailable) enctype='multipart/form-data' @endif action='{{ $isCreate ? route('website-admin.articles.store') : route('website-admin.articles.update', $article->id) }}' data-generate-url='{{ route('website-admin.articles.generate') }}'>
    @csrf @if (!$isCreate) @method('PUT') @endif
    <div class='wa-editor-layout'>
        <section class='wa-panel wa-form-panel' id='wa-manual-panel'>
            <div class='wa-panel-heading'><div><h2>文章内容</h2><p>正文支持 Markdown 格式，保存后可在前台搜索和阅读。</p></div></div>
            <div class='wa-form-grid'>
                <label class='wa-field wa-field-wide'><span>文章标题 <b>*</b></span><input name='title' value='{{ old('title', $article->title) }}' maxlength='500' required></label>
                <label class='wa-field wa-field-wide'><span>文章摘要</span><textarea name='excerpt' rows='3' maxlength='2000'>{{ old('excerpt', $article->excerpt) }}</textarea></label>
                <div class='wa-field wa-field-wide'><span>正文 <b>*</b></span>@include('website-admin.partials.markdown-editor', ['fieldName' => 'content', 'fieldId' => 'wa-article-content', 'label' => '文章正文', 'value' => old('content', $article->content)])</div>
            </div>
        </section>
        <aside class='wa-editor-side'>
            <section class='wa-panel wa-form-panel'>
                <div class='wa-panel-heading'><div><h2>发布设置</h2><p>草稿仅在后台可见。</p></div></div>
                <div class='wa-form-grid wa-one-column'>
                    <label class='wa-field'><span>文章分类</span><select name='category_id'><option value=''>默认分类</option>@foreach ($categories as $category)<option value='{{ $category->id }}' @selected((string) old('category_id', $article->category_id) === (string) $category->id)>{{ $category->name }}</option>@endforeach</select>@if ($context->allows('categories.create'))<small>还没有分类？<a href='{{ route('website-admin.categories.create') }}'>创建内容分类</a></small>@endif</label>
                    <div class='wa-field wa-cover-field'><span>文章封面</span>
                        <div class='wa-cover-preview' id='wa-cover-preview'>
                            <img id='wa-cover-image' @if($coverPreview !== '') src='{{ $coverPreview }}' @endif alt='文章封面预览' @if($coverPreview === '') hidden @endif>
                            <span id='wa-cover-empty' @if($coverPreview !== '') hidden @endif><i data-lucide='image' aria-hidden='true'></i>暂无封面</span>
                        </div>
                        @if ($coverUploadAvailable)
                            <input class='wa-cover-file-input' id='wa-cover-file' name='cover_file' type='file' accept='image/jpeg,image/png,image/webp' aria-label='选择文章封面图片'>
                            <div class='wa-cover-actions'><label class='wa-button wa-button-secondary' for='wa-cover-file'><i data-lucide='upload' aria-hidden='true'></i>上传封面</label></div>
                        @endif
                    </div>
                    <label class='wa-field'><span>封面图片地址</span><input id='wa-cover-url' name='cover_image' value='{{ old('cover_image', $article->cover_image) }}' maxlength='1000' placeholder='https:// 或 /storage/ 开头'><small>@if ($coverUploadAvailable) 也可以填写已有图片地址；上传新图片时优先使用上传文件。 @else 填写已有图片的 HTTPS 地址或站内路径。 @endif</small></label>
                    <label class='wa-field'><span>搜索摘要</span><textarea name='meta_description' rows='4' maxlength='320' placeholder='用于搜索结果与社交分享'>{{ old('meta_description', $article->meta_description) }}</textarea><small>建议不超过 160 个中文字符。</small></label>
                    <label class='wa-field'><span>关键词</span><input name='keywords' value='{{ old('keywords', $article->keywords) }}' maxlength='1000'></label>
                    <div class='wa-form-actions wa-form-actions-stacked'><button class='wa-button wa-button-secondary' type='submit' name='publish' value='0'>保存草稿</button>@if ($context->allows('articles.publish'))<button class='wa-button wa-button-primary' type='submit' name='publish' value='1'>{{ $article->status === 'published' ? '保存并发布' : '发布文章' }}</button>@endif</div>
                </div>
            </section>
        </aside>
    </div>
</form>
@if ($context->allows('articles.ai'))<dialog class='wa-ai-dialog' id='wa-ai-dialog' aria-labelledby='wa-ai-dialog-title'>
    <div class='wa-ai-dialog-heading'><h2 id='wa-ai-dialog-title'>AI 生成文章</h2><button class='wa-ai-dialog-close' id='wa-ai-close' type='button' aria-label='关闭生成窗口' title='关闭'><i data-lucide='x' aria-hidden='true'></i></button></div>
    <div class='wa-ai-dialog-body'>
        <label class='wa-field'><span>生成模型</span><select id='wa-ai-model'><option value=''>选择模型</option>@foreach ($aiModels as $aiModel)<option value='{{ $aiModel->id }}'>{{ $aiModel->name }} ({{ $aiModel->model_id }})</option>@endforeach</select></label>
        @if ($aiModels->isEmpty())<p class='wa-ai-unavailable'>当前站点没有可用的对话模型，请先由管理员配置。</p>@endif
        <label class='wa-field'><span>文章主题 <b>*</b></span><input id='wa-ai-topic' maxlength='300' placeholder='输入需要撰写的主题'></label>
        <label class='wa-field'><span>补充要求</span><textarea id='wa-ai-requirements' rows='4' maxlength='2000' placeholder='目标读者、重点信息、语气等'></textarea></label>
        @if ($article->status === 'published')<p class='wa-ai-publish-warning'>生成后保存为草稿，当前已发布文章将撤下。</p>@endif
        <p class='wa-ai-feedback' id='wa-ai-feedback' role='status' aria-live='polite'></p>
    </div>
    <div class='wa-ai-dialog-actions'><button class='wa-button wa-button-secondary' id='wa-ai-cancel' type='button'>取消</button><button class='wa-button wa-button-primary' id='wa-ai-generate' type='button' @disabled($aiModels->isEmpty())>生成并保存草稿</button></div>
</dialog>@endif
@endsection

@push('scripts')
<script>
(() => {
    const file = document.querySelector('#wa-cover-file');
    const url = document.querySelector('#wa-cover-url');
    const image = document.querySelector('#wa-cover-image');
    const empty = document.querySelector('#wa-cover-empty');
    let objectUrl = null;
    const preview = source => {
        image.hidden = !source;
        empty.hidden = !!source;
        if (source) image.src = source;
        else image.removeAttribute('src');
    };
    file?.addEventListener('change', () => {
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = file.files?.[0] ? URL.createObjectURL(file.files[0]) : null;
        preview(objectUrl || url.value.trim());
    });
    url.addEventListener('input', () => {
        if (file?.files?.length) file.value = '';
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = null;
        preview(url.value.trim());
    });
    image.addEventListener('error', () => { image.hidden = true; empty.hidden = false; });
    window.addEventListener('pagehide', () => { if (objectUrl) URL.revokeObjectURL(objectUrl); });
})();
</script>
@endpush

@if ($context->allows('articles.ai'))
@push('scripts')
<script>
const openButton = document.querySelector('#wa-ai-open');
const dialog = document.querySelector('#wa-ai-dialog');
const aiModel = document.querySelector('#wa-ai-model');
const aiTopic = document.querySelector('#wa-ai-topic');
const aiRequirements = document.querySelector('#wa-ai-requirements');
const generateButton = document.querySelector('#wa-ai-generate');
const articleForm = document.querySelector('#wa-article-form');
const generatedMessage = document.querySelector('#wa-article-generated');
const saveError = document.querySelector('#wa-article-save-error');
const feedback = document.querySelector('#wa-ai-feedback');
let generationController = null;
let saving = false;

openButton?.addEventListener('click', () => {
    feedback.textContent = '';
    dialog.showModal();
    aiModel.focus();
});

const closeDialog = () => {
    if (!saving) dialog.close();
};
document.querySelector('#wa-ai-close')?.addEventListener('click', closeDialog);
document.querySelector('#wa-ai-cancel')?.addEventListener('click', closeDialog);
dialog.addEventListener('cancel', event => {
    if (saving) event.preventDefault();
});
dialog.addEventListener('close', () => {
    if (generationController) {
        generationController.abort();
        generationController = null;
    }
    feedback.textContent = '';
    generateButton.disabled = aiModel.options.length < 2;
    generateButton.textContent = '生成并保存草稿';
});

const readResponse = async response => {
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new Error(Object.values(payload.errors || {})[0]?.[0] || payload.message || '请求失败，请稍后重试。');
    }
    return payload;
};
generateButton.addEventListener('click', async () => {
    if (!dialog.open || generationController || saving) return;
    const topic = aiTopic.value.trim();
    if (!aiModel.value) { feedback.textContent = '请先选择生成模型。'; aiModel.focus(); return; }
    if (topic.length < 2) { feedback.textContent = '请先填写文章主题。'; aiTopic.focus(); return; }
    generateButton.disabled = true;
    feedback.textContent = '正在生成，通常需要几十秒。';
    generatedMessage.hidden = true;
    saveError.hidden = true;
    const controller = new AbortController();
    generationController = controller;
    let generated = false;
    try {
        const token = articleForm.querySelector('input[name=_token]').value;
        const response = await fetch(articleForm.dataset.generateUrl, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
            body: JSON.stringify({ topic, model_id: Number(aiModel.value), requirements: aiRequirements.value.trim() }),
            signal: controller.signal
        });
        const payload = await readResponse(response);
        if (controller.signal.aborted || !dialog.open) return;
        if (!payload.title || !payload.content) throw new Error('生成内容不完整，请调整主题后重试。');
        ['title', 'excerpt', 'content', 'meta_description', 'keywords'].forEach(name => {
            if (typeof payload[name] !== 'string') return;
            const field = articleForm.elements.namedItem(name);
            field.value = payload[name];
            field.dispatchEvent(new Event('input', { bubbles: true }));
        });
        const category = articleForm.elements.namedItem('category_id');
        if (payload.category_id !== null && payload.category_id !== undefined && [...category.options].some(option => option.value === String(payload.category_id))) {
            category.value = String(payload.category_id);
        }
        generated = true;
        generationController = null;
        saving = true;
        generateButton.textContent = '正在保存草稿…';
        feedback.textContent = '正在保存草稿…';
        const formData = new FormData(articleForm);
        formData.set('publish', '0');
        const saveResponse = await fetch(articleForm.action, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
            body: formData
        });
        const saved = await readResponse(saveResponse);
        if (!saved.edit_url || !saved.update_url || saved.status !== 'draft') throw new Error('草稿保存状态无法确认，请刷新页面核对。');
        const coverUrl = articleForm.elements.namedItem('cover_image');
        coverUrl.value = saved.cover_image || '';
        const coverFile = articleForm.elements.namedItem('cover_file');
        if (coverFile) coverFile.value = '';
        coverUrl.dispatchEvent(new Event('input', { bubbles: true }));
        articleForm.action = saved.update_url;
        let method = articleForm.querySelector('[name=_method]');
        if (!method) {
            method = document.createElement('input');
            method.type = 'hidden';
            method.name = '_method';
            articleForm.append(method);
        }
        method.value = 'PUT';
        window.history.replaceState(null, '', saved.edit_url);
        document.querySelector('.wa-page-heading h1').textContent = '编辑文章';
        document.title = '编辑文章 - 网站管理';
        document.querySelector('.wa-ai-publish-warning')?.remove();
        saving = false;
        dialog.close();
        generatedMessage.hidden = false;
        articleForm.elements.namedItem('title').focus();
    } catch (error) {
        if (error.name === 'AbortError') return;
        if (generated) {
            saving = false;
            dialog.close();
            saveError.textContent = '内容已回填，但自动保存失败：' + (error.message || '请稍后重试。');
            saveError.hidden = false;
            saveError.scrollIntoView({ block: 'nearest' });
        } else {
            feedback.textContent = error.message || '文章生成失败，请稍后重试。';
        }
    } finally {
        if (generationController === controller) generationController = null;
        saving = false;
        generateButton.disabled = aiModel.options.length < 2;
        generateButton.textContent = '生成并保存草稿';
    }
});
</script>
@endpush
@endif
