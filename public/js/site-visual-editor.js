/**
 * Created by Codex.
 * @Date: 2026-11-16
 * @Time: 15:20:05
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： site-visual-editor.js
 * @Description: 将站点装修配置应用到官网，并为同源编辑预览提供双向选择和实时更新。
 */
(() => {
    const configNode = document.getElementById('website-visual-editor-config');
    if (!configNode) return;

    let savedConfig = {};
    try {
        savedConfig = JSON.parse(configNode.textContent || '{}');
    } catch {
        return;
    }

    const previewMode = new URLSearchParams(window.location.search).get('site_editor_preview') === '1'
        && window.parent !== window;
    let selectedSection = '';

    const renderField = (fieldId, value) => {
        document.querySelectorAll(`[data-site-editable-field="${CSS.escape(fieldId)}"]`).forEach(element => {
            const lines = String(value ?? '').split(/\r\n|\r|\n/);
            element.replaceChildren();
            lines.forEach((line, index) => {
                if (index > 0) element.append(document.createElement('br'));
                element.append(document.createTextNode(line));
            });
        });
    };

    /**
     * @Name: applyConfig
     * @Description: 将区块顺序、显隐、导航和文案应用到当前页面；预览配置只操作当前文档节点。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 14:40:28
     * @UpdateTime: 2026-11-16 14:40:28
     * @Param {Object} config 当前站点装修配置
     * @Return {void} 无返回值
     * @Throws 无
     */
    const applyConfig = config => {
        const elements = Array.from(document.querySelectorAll('[data-site-section]'));
        const sectionMap = new Map(elements.map(element => [element.dataset.siteSection, element]));
        const order = Array.isArray(config.sectionOrder) ? config.sectionOrder : [];
        const hidden = new Set(Array.isArray(config.hiddenSections) ? config.hiddenSections : []);
        const orderedElements = [...order, ...elements.map(element => element.dataset.siteSection)]
            .filter((id, index, all) => sectionMap.has(id) && all.indexOf(id) === index)
            .map(id => sectionMap.get(id));

        if (orderedElements.length > 1) {
            const parent = orderedElements[0].parentElement;
            if (orderedElements.every(element => element.parentElement === parent)) {
                const fragment = document.createDocumentFragment();
                orderedElements.forEach(element => fragment.append(element));
                parent.insertBefore(fragment, parent.firstElementChild);
            }
        }

        elements.forEach(element => {
            const isHidden = hidden.has(element.dataset.siteSection);
            element.hidden = isHidden;
            if (isHidden) element.style.setProperty('display', 'none', 'important');
            else element.style.removeProperty('display');
            element.classList.toggle('site-editor-selected', previewMode && element.dataset.siteSection === selectedSection);
        });

        const navigationElements = Array.from(document.querySelectorAll('[data-site-nav-item]'));
        const navigationMap = new Map();
        navigationElements.forEach(element => {
            const id = element.dataset.siteNavItem;
            if (!navigationMap.has(id)) navigationMap.set(id, []);
            navigationMap.get(id).push(element);
        });
        const navigationOrder = Array.isArray(config.navigationOrder) ? config.navigationOrder : [];
        const hiddenNavigation = new Set(Array.isArray(config.hiddenNavigation) ? config.hiddenNavigation : []);
        const navigationLabels = config.navigationLabels && typeof config.navigationLabels === 'object'
            ? config.navigationLabels
            : {};
        const orderedNavigationIds = [...navigationOrder, ...navigationElements.map(element => element.dataset.siteNavItem)]
            .filter((id, index, all) => navigationMap.has(id) && all.indexOf(id) === index);
        const orderedNavigation = orderedNavigationIds.flatMap(id => navigationMap.get(id));
        const navigationParents = new Set(orderedNavigation.map(element => element.parentElement));
        navigationParents.forEach(parent => {
            const parentItems = orderedNavigation.filter(element => element.parentElement === parent);
            if (parentItems.length > 1) {
                const fragment = document.createDocumentFragment();
                parentItems.forEach(element => fragment.append(element));
                parent.append(fragment);
            }
        });
        navigationElements.forEach(element => {
            const id = element.dataset.siteNavItem;
            const isHidden = hiddenNavigation.has(id);
            element.hidden = isHidden;
            if (isHidden) element.style.setProperty('display', 'none', 'important');
            else element.style.removeProperty('display');
            const label = element.querySelector('[data-site-nav-label]');
            if (label && Object.hasOwn(navigationLabels, id)) label.textContent = String(navigationLabels[id]);
        });

        if (config.fields && typeof config.fields === 'object') {
            Object.entries(config.fields).forEach(([fieldId, value]) => renderField(fieldId, value));
        }
    };

    applyConfig(savedConfig);

    if (!previewMode) return;

    const editorStyle = document.createElement('style');
    editorStyle.textContent = '.site-editor-selected{outline:2px solid #16816b!important;outline-offset:-2px!important;cursor:pointer!important}';
    document.head.append(editorStyle);

    /**
     * @Name: preservePreviewNavigation
     * @Description: 保留当前 iframe 的装修预览状态；站内页面继续在 iframe 中打开，外部页面改在新标签打开。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 15:20:05
     * @UpdateTime: 2026-11-16 15:20:05
     * @Param {HTMLAnchorElement} anchor 当前点击的链接
     * @Return {void} 无返回值
     * @Throws 无
     */
    const preservePreviewNavigation = anchor => {
        if (anchor.hasAttribute('download') || anchor.target === '_blank') return;

        const destination = new URL(anchor.href, window.location.href);
        if (!['http:', 'https:'].includes(destination.protocol)) return;

        if (destination.origin === window.location.origin) {
            destination.searchParams.set('site_editor_preview', '1');
            anchor.target = '_self';
            anchor.href = destination.toString();
            return;
        }

        anchor.target = '_blank';
        anchor.rel = 'noopener noreferrer';
    };

    document.addEventListener('click', event => {
        const field = event.target.closest('[data-site-editable-field]');
        if (field) {
            const section = field.closest('[data-site-section]');
            if (!section) return;

            event.preventDefault();
            event.stopPropagation();
            selectedSection = section.dataset.siteSection;
            applyConfig(savedConfig);
            window.parent.postMessage({
                type: 'website-visual-editor:field-selected',
                sectionId: selectedSection,
                fieldId: field.dataset.siteEditableField || '',
            }, window.location.origin);
            return;
        }

        const anchor = event.target.closest('a[href]');
        if (anchor) {
            preservePreviewNavigation(anchor);
            return;
        }

        const section = event.target.closest('[data-site-section]');
        if (!section) return;
        event.preventDefault();
        event.stopPropagation();
        selectedSection = section.dataset.siteSection;
        applyConfig(savedConfig);
        window.parent.postMessage({
            type: 'website-visual-editor:section-selected',
            sectionId: selectedSection,
        }, window.location.origin);
    }, true);

    window.addEventListener('message', event => {
        if (event.origin !== window.location.origin || event.source !== window.parent) return;
        if (event.data?.type === 'website-visual-editor:update') {
            savedConfig = event.data.payload || {};
            applyConfig(savedConfig);
        }
        if (event.data?.type === 'website-visual-editor:select') {
            selectedSection = String(event.data.sectionId || '');
            applyConfig(savedConfig);
        }
    });

    window.parent.postMessage({ type: 'website-visual-editor:ready' }, window.location.origin);
})();
