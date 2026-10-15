/**
 * Created by Codex.
 * @Date: 2026-10-15
 * @Time: 18:36:21
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： analytics-code.test.js
 * @Description: 验证后台统计代码表单的无损编码和字段保留行为。
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/js/admin-analytics-code.js', import.meta.url), 'utf8');

function bindFormDataHandler() {
    const listeners = {};
    const form = {
        addEventListener: (name, callback) => {
            listeners[name] = callback;
        },
    };

    vm.runInNewContext(source, {
        document: { querySelector: () => form },
        TextEncoder,
        btoa,
    });

    return data => listeners.formdata({ formData: data });
}

for (const code of [
    '<script defer src="https://analytics.example.test/script.js"></script>',
    '<!-- 中文统计 -->\n<script>window.analyticsName = "访问统计";</script>',
    '',
]) {
    test(`encodes analytics code without losing form fields (${code ? 'content' : 'empty'})`, () => {
        const encode = bindFormDataHandler();
        const data = new FormData();
        data.set('_token', 'csrf-token');
        data.set('site_name', 'Test site');
        data.set('analytics_code', code);

        encode(data);

        assert.equal(data.has('analytics_code'), false);
        assert.equal(
            Buffer.from(data.get('analytics_code_base64'), 'base64').toString('utf8'),
            code
        );
        assert.equal(data.get('_token'), 'csrf-token');
        assert.equal(data.get('site_name'), 'Test site');
    });
}

test('does not add an analytics field when the textarea is disabled', () => {
    const encode = bindFormDataHandler();
    const data = new FormData();
    data.set('site_name', 'Test site');

    encode(data);

    assert.equal(data.has('analytics_code_base64'), false);
});

test('loads safely without the analytics form', () => {
    vm.runInNewContext(source, {
        document: { querySelector: () => null },
    });
});
