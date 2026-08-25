/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-18
 * @Time: 15:37
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： config.test.ts
 * @Description: 验证 MCP 服务安全配置默认值、环境变量解析和启动前非法值拒绝规则。
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { loadConfig } from '../src/config.js';

void describe('MCP 服务配置', () => {
    void it('使用安全限流和直连网络默认值', () => {
        const config = loadConfig({});

        assert.deepEqual(config.trustedProxies, []);
        assert.equal(config.requireHttps, false);
        assert.deepEqual(config.rateLimit, {
            windowMs: 60_000,
            ipMaxRequests: 120,
            tokenMaxRequests: 60,
        });
    });

    void it('读取可信代理和双维度限流环境变量', () => {
        const config = loadConfig({
            MCP_TRUSTED_PROXIES: ' loopback, 10.0.0.0/8, LOOPBACK ',
            MCP_RATE_LIMIT_WINDOW_MS: '120000',
            MCP_RATE_LIMIT_IP_MAX: '240',
            MCP_RATE_LIMIT_TOKEN_MAX: '80',
            MCP_REQUIRE_HTTPS: 'true',
        });

        assert.deepEqual(config.trustedProxies, ['loopback', '10.0.0.0/8']);
        assert.equal(config.requireHttps, true);
        assert.deepEqual(config.rateLimit, {
            windowMs: 120_000,
            ipMaxRequests: 240,
            tokenMaxRequests: 80,
        });
    });

    void it('拒绝非法限流值和非 HTTP 上游地址', () => {
        assert.throws(() => loadConfig({ MCP_RATE_LIMIT_IP_MAX: '0' }), /MCP_RATE_LIMIT_IP_MAX 必须是正整数/u);
        assert.throws(() => loadConfig({ MCP_REQUIRE_HTTPS: 'yes' }), /MCP_REQUIRE_HTTPS 必须是 true 或 false/u);
        assert.throws(
            () => loadConfig({ GEOFLOW_API_BASE_URL: 'file:///var/run/geoflow' }),
            /GEOFLOW_API_BASE_URL 仅支持 HTTP 或 HTTPS/u,
        );
    });
});
