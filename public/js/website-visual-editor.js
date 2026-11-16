/**
 * Created by Codex.
 * @Date: 2026-11-16
 * @Time: 15:20:05
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： website-visual-editor.js
 * @Description: 管理网站装修表单、区块选择和官网预览 iframe 的实时消息同步。
 */
(() => {
    const form = document.querySelector('[data-website-editor]');
    if (!form) return;

    const stateNode = form.querySelector('[data-editor-state]');
    const frame = form.querySelector('[data-editor-preview-frame]');
    const frameWrap = form.querySelector('[data-editor-frame-wrap]');
    const sectionList = form.querySelector('[data-editor-section-list]');
    const navigationList = form.querySelector('[data-editor-navigation-list]');
    const currentSectionLabel = form.querySelector('[data-editor-current-section]');
    const dirtyLabel = form.querySelector('[data-editor-dirty]');
    if (!stateNode || !frame || !sectionList) return;

    let state;
    try {
        state = JSON.parse(stateNode.textContent || '{}');
    } catch {
        return;
    }

    const markDirty = () => {
        if (dirtyLabel) dirtyLabel.hidden = false;
    };
    const activeSection = () => sectionList.querySelector('.is-selected')?.dataset.editorSectionItem || '';
    const selectSection = (sectionId, focusField = '') => {
        const item = sectionList.querySelector(`[data-editor-section-item="${CSS.escape(sectionId)}"]`);
        if (!item) return;

        sectionList.querySelectorAll('[data-editor-section-item]').forEach(row => {
            const active = row === item;
            row.classList.toggle('is-selected', active);
            row.querySelector('[data-editor-select-section]')?.setAttribute('aria-pressed', String(active));
        });

        form.querySelectorAll('[data-editor-fields-for]').forEach(panel => {
            panel.hidden = panel.dataset.editorFieldsFor !== sectionId;
        });
        if (currentSectionLabel) {
            currentSectionLabel.textContent = item.querySelector('[data-editor-select-section] span')?.textContent || '';
        }
        if (focusField) {
            form.querySelector(`[data-editor-field="${CSS.escape(focusField)}"]`)?.focus({ preventScroll: true });
        }
        frame.contentWindow?.postMessage({ type: 'website-visual-editor:select', sectionId }, window.location.origin);
    };

    const currentState = () => ({
        sectionOrder: Array.from(sectionList.querySelectorAll('[data-editor-section-item]'))
            .map(item => item.dataset.editorSectionItem),
        hiddenSections: Array.from(sectionList.querySelectorAll('[data-editor-section-visibility]'))
            .filter(input => !input.checked)
            .map(input => input.dataset.editorSectionVisibility),
        navigationOrder: Array.from(navigationList?.querySelectorAll('[data-editor-navigation-item]') || [])
            .map(item => item.dataset.editorNavigationItem),
        hiddenNavigation: Array.from(navigationList?.querySelectorAll('[data-editor-navigation-visibility]') || [])
            .filter(input => !input.checked)
            .map(input => input.dataset.editorNavigationVisibility),
        navigationLabels: Object.fromEntries(Array.from(navigationList?.querySelectorAll('[data-editor-navigation-label]') || [])
            .map(field => [field.dataset.editorNavigationLabel, field.value])),
        fields: Object.fromEntries(Array.from(form.querySelectorAll('[data-editor-field]'))
            .map(field => [field.dataset.editorField, field.value])),
    });

    /**
     * @Name: sendPreview
     * @Description: 向当前预览页发送最新编辑配置，并恢复侧栏当前选中的首页区块。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 15:20:05
     * @UpdateTime: 2026-11-16 15:20:05
     * @Param 无
     * @Return {void} 无返回值
     * @Throws 无
     */
    const sendPreview = () => {
        frame.contentWindow?.postMessage({ type: 'website-visual-editor:update', payload: currentState() }, window.location.origin);
        const sectionId = activeSection();
        if (sectionId) {
            frame.contentWindow?.postMessage({ type: 'website-visual-editor:select', sectionId }, window.location.origin);
        }
    };

    const moveSection = (item, direction) => {
        const sibling = direction === 'up' ? item.previousElementSibling : item.nextElementSibling;
        if (!sibling) return;
        if (direction === 'up') sectionList.insertBefore(item, sibling);
        else sectionList.insertBefore(sibling, item);
        markDirty();
        sendPreview();
    };

    sectionList.addEventListener('click', event => {
        const moveButton = event.target.closest('[data-editor-move]');
        if (moveButton) {
            const item = moveButton.closest('[data-editor-section-item]');
            if (item) moveSection(item, moveButton.dataset.editorMove);
            return;
        }

        const selectButton = event.target.closest('[data-editor-select-section]');
        if (selectButton) selectSection(selectButton.dataset.editorSelectSection);
    });

    navigationList?.addEventListener('click', event => {
        const moveButton = event.target.closest('[data-editor-navigation-move]');
        if (!moveButton) return;
        const item = moveButton.closest('[data-editor-navigation-item]');
        const direction = moveButton.dataset.editorNavigationMove;
        const sibling = direction === 'up' ? item?.previousElementSibling : item?.nextElementSibling;
        if (!item || !sibling) return;
        if (direction === 'up') navigationList.insertBefore(item, sibling);
        else navigationList.insertBefore(sibling, item);
        markDirty();
        sendPreview();
    });

    sectionList.addEventListener('change', event => {
        const toggle = event.target.closest('[data-editor-section-visibility]');
        if (!toggle) return;
        toggle.closest('[data-editor-section-item]')?.classList.toggle('is-hidden', !toggle.checked);
        markDirty();
        sendPreview();
    });

    navigationList?.addEventListener('change', event => {
        const toggle = event.target.closest('[data-editor-navigation-visibility]');
        if (!toggle) return;
        toggle.closest('[data-editor-navigation-item]')?.classList.toggle('is-hidden', !toggle.checked);
        markDirty();
        sendPreview();
    });

    navigationList?.addEventListener('input', event => {
        if (!event.target.matches('[data-editor-navigation-label]')) return;
        markDirty();
        sendPreview();
    });

    let draggedItem = null;
    sectionList.addEventListener('dragstart', event => {
        const item = event.target.closest('[data-editor-section-item]');
        if (!item) return;
        draggedItem = item;
        item.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
    });
    sectionList.addEventListener('dragend', () => {
        draggedItem?.classList.remove('is-dragging');
        draggedItem = null;
        sendPreview();
    });
    sectionList.addEventListener('dragover', event => {
        if (!draggedItem) return;
        event.preventDefault();
        const target = event.target.closest('[data-editor-section-item]');
        if (!target || target === draggedItem) return;
        const bounds = target.getBoundingClientRect();
        sectionList.insertBefore(draggedItem, event.clientY < bounds.top + bounds.height / 2 ? target : target.nextSibling);
        markDirty();
    });

    form.addEventListener('input', event => {
        if (!event.target.matches('[data-editor-field]')) return;
        markDirty();
        sendPreview();
    });

    form.querySelectorAll('[data-editor-device]').forEach(button => {
        button.addEventListener('click', () => {
            const mobile = button.dataset.editorDevice === 'mobile';
            frameWrap?.classList.toggle('is-mobile', mobile);
            form.querySelectorAll('[data-editor-device]').forEach(deviceButton => {
                const active = deviceButton === button;
                deviceButton.classList.toggle('is-active', active);
                deviceButton.setAttribute('aria-pressed', String(active));
            });
        });
    });

    form.addEventListener('submit', () => {
        form.querySelectorAll('[data-editor-generated]').forEach(input => input.remove());
        const liveState = currentState();
        liveState.sectionOrder.forEach(sectionId => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'section_order[]';
            input.value = sectionId;
            input.dataset.editorGenerated = 'true';
            form.append(input);
        });
        liveState.hiddenSections.forEach(sectionId => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'hidden_sections[]';
            input.value = sectionId;
            input.dataset.editorGenerated = 'true';
            form.append(input);
        });
        liveState.navigationOrder.forEach(navigationId => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'navigation_order[]';
            input.value = navigationId;
            input.dataset.editorGenerated = 'true';
            form.append(input);
        });
        liveState.hiddenNavigation.forEach(navigationId => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'hidden_navigation[]';
            input.value = navigationId;
            input.dataset.editorGenerated = 'true';
            form.append(input);
        });
    });

    frame.addEventListener('load', sendPreview);
    window.addEventListener('message', event => {
        if (event.origin !== window.location.origin || event.source !== frame.contentWindow) return;
        if (event.data?.type === 'website-visual-editor:ready') sendPreview();
        if (event.data?.type === 'website-visual-editor:field-selected') {
            const sectionId = event.data.sectionId;
            selectSection(sectionId, event.data.fieldId || '');
        }
        if (event.data?.type === 'website-visual-editor:section-selected') {
            selectSection(event.data.sectionId || '');
        }
    });

    const firstSection = state.sections?.[0];
    if (firstSection) selectSection(firstSection.id);
})();
