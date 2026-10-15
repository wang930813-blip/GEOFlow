/**
 * Created by Codex.
 * @Date: 2026-10-15
 * @Time: 18:36:21
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： admin-analytics-code.js
 * @Description: 对后台统计代码进行 Base64 传输，避免 HTML 片段破坏表单提交结构。
 */

(function () {
    const form = document.querySelector('[data-analytics-settings-form]');
    if (!form) {
        return;
    }

    form.addEventListener('formdata', function (event) {
        if (!event.formData.has('analytics_code')) {
            return;
        }

        const bytes = new TextEncoder().encode(event.formData.get('analytics_code'));
        const binary = Array.from(bytes, byte => String.fromCharCode(byte)).join('');
        event.formData.set('analytics_code_base64', btoa(binary));
        event.formData.delete('analytics_code');
    });
})();
