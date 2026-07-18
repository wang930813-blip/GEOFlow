/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-13
 * @Time: 16:38
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： mcp-server.js
 * @Description: 提供 MCP Server 用户页面的安全复制、有效期切换和客户端配置标签切换交互。
 */

(() => {
    'use strict';

    /**
     * @Name: copyText
     * @Description: 优先使用安全上下文剪贴板接口，降级时使用隐藏文本域完成复制。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-13 16:38:47
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Param: string text 需要复制的文本
     * @Return: Promise<boolean> 是否复制成功
     */
    async function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);

            return true;
        }

        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', 'readonly');
        textarea.style.position = 'fixed';
        textarea.style.inset = '-9999px auto auto -9999px';
        document.body.appendChild(textarea);
        textarea.select();

        let copied = false;
        try {
            copied = document.execCommand('copy');
        } finally {
            textarea.remove();
        }

        return copied;
    }

    /**
     * @Name: notify
     * @Description: 复用后台全局通知组件反馈复制结果，不存在时保持静默以避免阻断操作。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-13 16:38:47
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Param: string message 通知文本
     * @Param: string type 通知类型
     * @Return: void
     */
    function notify(message, type) {
        if (window.AdminUtils && typeof window.AdminUtils.showToast === 'function') {
            window.AdminUtils.showToast(message, type);
        }
    }

    /**
     * @Name: bindCopyButtons
     * @Description: 为带目标元素编号的复制按钮绑定事件，复制时仅读取页面已经展示的文本内容。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-13 16:38:47
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Return: void
     */
    function bindCopyButtons() {
        document.querySelectorAll('[data-copy-target]').forEach((button) => {
            button.addEventListener('click', async () => {
                const targetId = button.getAttribute('data-copy-target');
                const target = targetId ? document.getElementById(targetId) : null;
                const text = target?.textContent?.trim() ?? '';
                if (text === '') {
                    return;
                }

                try {
                    const copied = await copyText(text);
                    notify(copied ? '已复制到剪贴板' : '复制失败，请手动复制', copied ? 'success' : 'error');
                } catch (error) {
                    notify('复制失败，请手动复制', 'error');
                }
            });
        });
    }

    /**
     * @Name: bindConfigurationTabs
     * @Description: 切换 Streamable HTTP 与 stdio 桥接配置，并同步可访问性选中状态。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-13 16:38:47
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Return: void
     */
    function bindConfigurationTabs() {
        const tabs = Array.from(document.querySelectorAll('[data-mcp-tab]'));
        const panels = Array.from(document.querySelectorAll('[data-mcp-panel]'));

        tabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                const selected = tab.getAttribute('data-mcp-tab');

                tabs.forEach((item) => {
                    const active = item === tab;
                    item.setAttribute('aria-selected', active ? 'true' : 'false');
                    item.classList.toggle('bg-white', active);
                    item.classList.toggle('text-gray-900', active);
                    item.classList.toggle('shadow-sm', active);
                });

                panels.forEach((panel) => {
                    panel.classList.toggle('hidden', panel.getAttribute('data-mcp-panel') !== selected);
                });
            });
        });

        tabs.find((tab) => tab.getAttribute('aria-selected') === 'true')?.click();
    }

    /**
     * @Name: bindExpirationMode
     * @Description: 根据永不过期开关同步过期时间输入框状态，保留已填写时间以便用户取消开关后继续使用。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-19 01:17:52
     * @UpdateTime: 2026-07-19 01:17:52
     *
     * @Return: void
     */
    function bindExpirationMode() {
        const toggle = document.querySelector('[data-mcp-never-expires]');
        const expiresAt = document.querySelector('[data-mcp-expires-at]');
        const field = document.querySelector('[data-mcp-expires-at-field]');
        if (!(toggle instanceof HTMLInputElement) || !(expiresAt instanceof HTMLInputElement)) {
            return;
        }

        const syncState = () => {
            const disabled = toggle.checked;
            expiresAt.disabled = disabled;
            expiresAt.setAttribute('aria-disabled', disabled ? 'true' : 'false');
            field?.classList.toggle('opacity-50', disabled);
        };

        toggle.addEventListener('change', syncState);
        syncState();
    }

    document.addEventListener('DOMContentLoaded', () => {
        bindCopyButtons();
        bindExpirationMode();
        bindConfigurationTabs();
    });
})();
