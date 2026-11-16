@php
    $editorId = $fieldId ?? 'wa-markdown-content';
@endphp
<div class='wa-markdown-editor' data-wa-markdown-editor data-preview-url='{{ route('website-admin.markdown.preview') }}'>
    <div class='wa-md-topline'>
        <div class='wa-md-toolbar' role='toolbar' aria-label='Markdown 格式工具'>
            <button type='button' data-md-before='**' data-md-after='**' data-md-placeholder='粗体文字' aria-label='粗体' title='粗体'><strong>B</strong></button>
            <button type='button' data-md-before='*' data-md-after='*' data-md-placeholder='斜体文字' aria-label='斜体' title='斜体'><em>I</em></button>
            <span class='wa-md-divider' aria-hidden='true'></span>
            <button type='button' data-md-line='## ' data-md-placeholder='二级标题' aria-label='插入二级标题' title='二级标题'>H2</button>
            <button type='button' data-md-line='- ' data-md-placeholder='列表项' aria-label='插入无序列表' title='无序列表'>列表</button>
            <button type='button' data-md-line='> ' data-md-placeholder='引用内容' aria-label='插入引用' title='引用'>引用</button>
            <button type='button' data-md-before='[' data-md-after='](https://)' data-md-placeholder='链接文字' aria-label='插入链接' title='链接'>链接</button>
            <button type='button' data-md-before='```text&#10;' data-md-after='&#10;```' data-md-placeholder='代码内容' aria-label='插入代码块' title='代码块'>&lt;/&gt;</button>
        </div>
        <div class='wa-md-mode' role='tablist' aria-label='编辑与预览'>
            <button type='button' class='is-active' role='tab' aria-selected='true' data-md-mode='edit'>编辑</button>
            <button type='button' role='tab' aria-selected='false' data-md-mode='preview'>预览</button>
        </div>
    </div>
    <div class='wa-md-workspace' data-md-view='split'>
        <div class='wa-md-source-pane'>
            <label class='wa-sr-only' for='{{ $editorId }}'>{{ $label ?? 'Markdown 正文' }}</label>
            <textarea id='{{ $editorId }}' name='{{ $fieldName ?? 'content' }}' rows='20' required data-wa-markdown-source>{{ $value ?? '' }}</textarea>
        </div>
        <div class='wa-md-preview-pane' data-wa-markdown-preview aria-live='polite' aria-label='正文预览'>
            <p class='wa-md-preview-empty'>输入正文后将在此显示预览。</p>
        </div>
    </div>
    <div class='wa-md-feedback' role='status' aria-live='polite' data-wa-markdown-feedback>支持标题、列表、引用、链接、代码和表格。</div>
</div>

@once
    @push('scripts')
    <script>
    (() => {
        document.querySelectorAll('[data-wa-markdown-editor]').forEach(editor => {
            const source = editor.querySelector('[data-wa-markdown-source]');
            const preview = editor.querySelector('[data-wa-markdown-preview]');
            const feedback = editor.querySelector('[data-wa-markdown-feedback]');
            const form = editor.closest('form');
            const token = form?.querySelector('input[name="_token"]')?.value;
            const modes = Array.from(editor.querySelectorAll('[data-md-mode]'));
            let timer = null;
            let sequence = 0;

            const renderPreview = async () => {
                const content = source.value;
                if (!content.trim()) {
                    preview.innerHTML = '<p class="wa-md-preview-empty">输入正文后将在此显示预览。</p>';
                    feedback.textContent = '支持标题、列表、引用、链接、代码和表格。';
                    return;
                }
                const requestId = ++sequence;
                editor.setAttribute('aria-busy', 'true');
                try {
                    const response = await fetch(editor.dataset.previewUrl, {
                        method: 'POST',
                        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                        body: JSON.stringify({ content })
                    });
                    const payload = await response.json();
                    if (!response.ok) throw new Error(payload.message || '预览暂时不可用。');
                    if (requestId === sequence) {
                        preview.innerHTML = payload.html || '<p class="wa-md-preview-empty">暂无正文。</p>';
                        feedback.textContent = '预览已更新';
                    }
                } catch (error) {
                    if (requestId === sequence) feedback.textContent = error.message || '预览暂时不可用。';
                } finally {
                    if (requestId === sequence) editor.removeAttribute('aria-busy');
                }
            };

            const schedulePreview = () => {
                window.clearTimeout(timer);
                timer = window.setTimeout(renderPreview, 260);
            };

            const setMode = mode => {
                editor.querySelector('[data-md-view]')?.setAttribute('data-md-view', mode);
                modes.forEach(button => {
                    const active = button.dataset.mdMode === mode;
                    button.classList.toggle('is-active', active);
                    button.setAttribute('aria-selected', String(active));
                });
                if (mode === 'preview') renderPreview();
            };

            editor.querySelectorAll('[data-md-before], [data-md-line]').forEach(button => {
                button.addEventListener('click', () => {
                    const start = source.selectionStart;
                    const end = source.selectionEnd;
                    const before = source.value.slice(0, start);
                    const selected = source.value.slice(start, end);
                    const after = source.value.slice(end);
                    let replacement = '';
                    let selectionStart = start;
                    let selectionEnd = end;
                    if (button.hasAttribute('data-md-line')) {
                        const lineStart = before.lastIndexOf('\n') + 1;
                        const lastSelectedCharacter = end > start && source.value[end - 1] === '\n' ? end - 1 : end;
                        const lineEndIndex = source.value.indexOf('\n', lastSelectedCharacter);
                        const lineEnd = lineEndIndex === -1 ? source.value.length : lineEndIndex;
                        const content = source.value.slice(lineStart, lineEnd) || button.dataset.mdPlaceholder;
                        const prefixed = content.split('\n').map(line => button.dataset.mdLine + line).join('\n');
                        replacement = source.value.slice(0, lineStart) + prefixed + source.value.slice(lineEnd);
                        selectionStart = lineStart + button.dataset.mdLine.length;
                        selectionEnd = lineStart + prefixed.length;
                    } else {
                        const prefix = button.dataset.mdBefore.replace(/&#10;/g, '\n');
                        const suffix = button.dataset.mdAfter.replace(/&#10;/g, '\n');
                        const body = selected || button.dataset.mdPlaceholder;
                        replacement = before + prefix + body + suffix + after;
                        selectionStart = start + prefix.length;
                        selectionEnd = selectionStart + body.length;
                    }
                    source.value = replacement;
                    source.focus();
                    source.setSelectionRange(selectionStart, selectionEnd);
                    source.dispatchEvent(new Event('input', { bubbles: true }));
                });
            });
            modes.forEach(button => button.addEventListener('click', () => setMode(button.dataset.mdMode)));
            source.addEventListener('input', schedulePreview);
            renderPreview();
        });
    })();
    </script>
    @endpush
@endonce
