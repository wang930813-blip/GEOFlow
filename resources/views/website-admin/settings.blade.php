@extends('website-admin.layout')

@section('content')
<div class='wa-page-heading'>
    <div><span class='wa-eyebrow'>网站信息与搜索展示</span><h1>网站设置</h1><p>集中维护官网基础信息和搜索摘要。</p></div>
</div>
<form method='POST' action='{{ route('website-admin.settings.save') }}' class='wa-form-stack' data-wa-settings data-wa-invalid-field='{{ $errors->any() ? array_key_first($errors->messages()) : '' }}' @if ($imageUploadAvailable) enctype='multipart/form-data' @endif>
    @csrf @method('PUT')
    <input type='hidden' name='settings_tab' value='basic' data-wa-current-tab>
    <div class='wa-tabs' role='tablist' aria-label='网站设置分类'>
        <button type='button' id='wa-tab-basic' class='wa-tab is-active' role='tab' aria-selected='true' aria-controls='wa-settings-basic' data-wa-tab='basic'>基本信息</button>
        <button type='button' id='wa-tab-seo' class='wa-tab' role='tab' aria-selected='false' aria-controls='wa-settings-seo' data-wa-tab='seo'>SEO 设置</button>
    </div>
    <section class='wa-panel wa-form-panel' id='wa-settings-basic' role='tabpanel' aria-labelledby='wa-tab-basic' data-wa-settings-panel='basic' data-wa-editable='{{ $context->allows('site.settings.basic') ? 'true' : 'false' }}'>
        <div class='wa-panel-heading'><div><h2>基本信息</h2><p>用于网站标题、首页介绍、品牌标识和浏览器标签。</p></div></div>
        <div class='wa-form-grid'>
            <label class='wa-field'><span>网站名称 <b>*</b></span><input name='site_name' value='{{ old('site_name', $settings['site_name'] ?? $context->site->name) }}' maxlength='120' required autocomplete='organization'></label>
            <label class='wa-field'><span>网站副标题</span><input name='site_subtitle' value='{{ old('site_subtitle', $settings['site_subtitle'] ?? '') }}' maxlength='255'></label>
            <label class='wa-field wa-field-wide'><span>网站简介</span><textarea name='site_description' rows='4' maxlength='2000'>{{ old('site_description', $settings['site_description'] ?? '') }}</textarea><small>用一到两句话说明企业业务、产品或服务。</small></label>
            <label class='wa-field wa-field-wide'><span>网站关键词</span><input name='site_keywords' value='{{ old('site_keywords', $settings['site_keywords'] ?? '') }}' maxlength='500'><small>以逗号分隔主题词，不要重复堆砌。</small></label>
            @php
                $siteLogoValue = old('site_logo', $settings['site_logo'] ?? '');
                $siteFaviconValue = old('site_favicon', $settings['site_favicon'] ?? '');
            @endphp
            <div class='wa-field wa-brand-asset-field'>
                <span>网站 Logo</span>
                <div class='wa-brand-asset-controls @if ($imageUploadAvailable) has-upload @endif'>
                    @if ($imageUploadAvailable)
                        <input class='wa-cover-file-input' id='wa-site-logo-file' name='site_logo_file' type='file' accept='image/jpeg,image/png,image/webp' aria-label='选择网站 Logo 图片'>
                        <div class='wa-cover-actions'><label class='wa-button wa-button-secondary' for='wa-site-logo-file'><i data-lucide='upload' aria-hidden='true'></i>上传网站 Logo</label></div>
                    @endif
                    <label class='wa-field wa-brand-url-field'><span>图片地址</span><input id='wa-site-logo-url' name='site_logo' value='{{ $siteLogoValue }}' maxlength='1000' placeholder='https:// 或 / 开头' inputmode='url'><small>@if ($imageUploadAvailable) 上传图片后保存会优先使用该图片。 @else 启用并完整配置云存储插件后可上传图片。 @endif</small></label>
                </div>
                <div class='wa-cover-preview wa-brand-logo-preview'>
                    <img id='wa-site-logo-preview' @if ($siteLogoValue !== '') src='{{ $siteLogoValue }}' @endif alt='网站 Logo 预览' @if ($siteLogoValue === '') hidden @endif>
                    <span id='wa-site-logo-empty' @if ($siteLogoValue !== '') hidden @endif><i data-lucide='image' aria-hidden='true'></i>暂无网站 Logo</span>
                </div>
            </div>
            <div class='wa-field wa-brand-asset-field'>
                <span>浏览器图标</span>
                <div class='wa-brand-asset-controls @if ($imageUploadAvailable) has-upload @endif'>
                    @if ($imageUploadAvailable)
                        <input class='wa-cover-file-input' id='wa-site-favicon-file' name='site_favicon_file' type='file' accept='.ico' aria-label='选择 ICO 浏览器图标文件'>
                        <div class='wa-cover-actions'><label class='wa-button wa-button-secondary' for='wa-site-favicon-file'><i data-lucide='upload' aria-hidden='true'></i>上传浏览器图标</label></div>
                    @endif
                    <label class='wa-field wa-brand-url-field'><span>图标地址</span><input id='wa-site-favicon-url' name='site_favicon' value='{{ $siteFaviconValue }}' maxlength='1000' placeholder='https://example.com/favicon.ico 或 /favicon.ico' inputmode='url'><small>仅支持 .ico 格式；建议文件包含 16×16 与 32×32 像素规格，上传文件最大 5 MB。</small></label>
                </div>
            </div>
        </div>
    </section>
    <section class='wa-panel wa-form-panel' id='wa-settings-seo' role='tabpanel' aria-labelledby='wa-tab-seo' data-wa-settings-panel='seo' data-wa-editable='{{ $context->allows('site.settings.seo') ? 'true' : 'false' }}' hidden>
        <div class='wa-panel-heading'><div><h2>SEO 设置</h2><p>配置搜索结果中的标题和摘要模板；页面自身内容优先级更高。</p></div></div>
        <div class='wa-form-grid'>
            <label class='wa-field wa-field-wide'><span>页面标题模板</span><input name='seo_title_template' value='{{ old('seo_title_template', $settings['seo_title_template'] ?? '') }}' maxlength='255' placeholder='{title} - {site_name}'><small>可使用 {title} 和 {site_name} 作为替换变量。</small></label>
            <label class='wa-field wa-field-wide'><span>页面摘要模板</span><textarea name='seo_description_template' rows='3' maxlength='255' placeholder='{description}'>{{ old('seo_description_template', $settings['seo_description_template'] ?? '') }}</textarea></label>
            <div class='wa-callout wa-field-wide'><strong>搜索引擎文件</strong><p>站点地图和爬虫规则由系统自动提供。</p><div class='wa-inline-links'><a href='/sitemap.xml' target='_blank' rel='noopener'>查看站点地图</a><a href='/robots.txt' target='_blank' rel='noopener'>查看 robots.txt</a><a href='/llms.txt' target='_blank' rel='noopener'>查看 llms.txt</a></div></div>
        </div>
    </section>
    <div class='wa-form-actions wa-sticky-actions'><span>保存后会更新官网页面及搜索元数据。</span><button class='wa-button wa-button-primary' type='submit' data-wa-settings-save><i data-lucide='save' aria-hidden='true'></i>保存设置</button></div>
</form>
@endsection

@push('scripts')
<script>
(() => {
    const form = document.querySelector('[data-wa-settings]');
    const tabs = Array.from(document.querySelectorAll('[data-wa-tab]'));
    const panels = Array.from(document.querySelectorAll('[data-wa-settings-panel]'));
    if (!tabs.length || !panels.length) return;
    panels.forEach(panel => {
        if (panel.dataset.waEditable === 'true') return;
        panel.querySelectorAll('input, textarea, select').forEach(field => { field.disabled = true; });
    });
    const activate = name => {
        const currentTab = form?.querySelector('[data-wa-current-tab]');
        if (currentTab) currentTab.value = name;
        tabs.forEach(tab => {
            const active = tab.dataset.waTab === name;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
        });
        panels.forEach(panel => { panel.hidden = panel.dataset.waSettingsPanel !== name; });
        const activePanel = panels.find(panel => panel.dataset.waSettingsPanel === name);
        const saveButton = form?.querySelector('[data-wa-settings-save]');
        if (saveButton) saveButton.hidden = activePanel?.dataset.waEditable !== 'true';
    };
    /**
     * @Name: bindAssetPreview
     * @Description: 根据地址输入或本地待上传文件更新网站图片预览。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 18:17:00
     * @UpdateTime: 2026-11-12 18:24:07
     * @Param string fileId 上传文件控件 ID
     * @Param string urlId 图片地址控件 ID
     * @Param string imageId 图片预览控件 ID
     * @Param string emptyId 无图片时的占位控件 ID
     * @Return void 更新对应图片预览
     * @Throws 无
     */
    const bindAssetPreview = (fileId, urlId, imageId, emptyId) => {
        const file = document.getElementById(fileId);
        const url = document.getElementById(urlId);
        const image = document.getElementById(imageId);
        const empty = document.getElementById(emptyId);
        if (!url || !image || !empty) return;
        let objectUrl = null;
        const show = source => {
            image.hidden = !source;
            empty.hidden = Boolean(source);
            if (source) image.src = source;
            else image.removeAttribute('src');
        };
        image.addEventListener('error', () => show(''));
        url.addEventListener('input', () => show(url.value.trim()));
        file?.addEventListener('change', () => {
            const selected = file.files?.[0];
            if (!selected) return;
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = URL.createObjectURL(selected);
            show(objectUrl);
        });
    };
    bindAssetPreview('wa-site-logo-file', 'wa-site-logo-url', 'wa-site-logo-preview', 'wa-site-logo-empty');
    const invalidField = Array.from(form?.elements ?? []).find(element => element.name === form?.dataset.waInvalidField);
    const invalidPanel = invalidField?.closest('[data-wa-settings-panel]');
    const initial = invalidPanel?.dataset.waSettingsPanel ?? new URLSearchParams(window.location.search).get('tab');
    const firstEditable = panels.find(panel => panel.dataset.waEditable === 'true')?.dataset.waSettingsPanel;
    activate(tabs.some(tab => tab.dataset.waTab === initial) ? initial : (firstEditable ?? 'basic'));
    let revealingInvalidField = false;
    form?.addEventListener('invalid', event => {
        if (revealingInvalidField) return;
        const panel = event.target.closest('[data-wa-settings-panel]');
        if (!panel) return;
        revealingInvalidField = true;
        activate(panel.dataset.waSettingsPanel);
        window.setTimeout(() => { revealingInvalidField = false; }, 0);
    }, true);
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activate(tab.dataset.waTab));
        tab.addEventListener('keydown', event => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length;
            tabs[next].focus();
            activate(tabs[next].dataset.waTab);
        });
    });
})();
</script>
@endpush
