/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-18
 * @Time: 15:37
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： rate-limit.test.ts
 * @Description: 验证 MCP 请求按可信代理客户端 IP 和 Bearer Token 不可逆指纹独立限流。
 */

import assert from 'node:assert/strict';
import type { Server } from 'node:http';
import { once } from 'node:events';
import { describe, it } from 'node:test';
import { createMcpExpressApp } from '@modelcontextprotocol/sdk/server/express.js';
import type { Request, Response as ExpressResponse } from 'express';
import { createBearerTokenFingerprint, createMcpRateLimiters } from '../src/rate-limit.js';

/**
 * @Name: closeServer
 * @Description: 关闭临时 HTTP 服务并等待所有连接释放，避免并发验证残留监听句柄。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-18 15:37:25
 * @UpdateTime: 2026-07-18 15:37:25
 *
 * @Param: Server server Node HTTP 服务
 * @Return: Promise<void> 服务关闭完成
 */
async function closeServer(server: Server): Promise<void> {
    await new Promise<void>((resolve, reject) => {
        server.close((error) => {
            if (error) {
                reject(error);
                return;
            }

            resolve();
        });
    });
}

void describe('MCP 请求限流', () => {
    void it('Bearer Token 指纹不可逆且不包含明文 Key', () => {
        const token = 'plain-secret-mcp-key';
        const fingerprint = createBearerTokenFingerprint(token);

        assert.equal(fingerprint.length, 64);
        assert.match(fingerprint, /^[a-f0-9]{64}$/u);
        assert.equal(fingerprint.includes(token), false);
    });

    void it('通过可信代理 IP 和 Token 指纹分别限制请求', async () => {
        const app = createMcpExpressApp({ host: '127.0.0.1', allowedHosts: ['127.0.0.1'] });
        app.set('trust proxy', ['loopback']);
        app.post(
            '/mcp',
            ...createMcpRateLimiters({ windowMs: 60_000, ipMaxRequests: 2, tokenMaxRequests: 1 }),
            (_request: Request, response: ExpressResponse) => {
                response.json({ ok: true });
            },
        );

        const server = app.listen(0, '127.0.0.1');
        await once(server, 'listening');
        const address = server.address();
        if (address === null || typeof address === 'string') {
            throw new Error('临时 HTTP 服务未返回 TCP 监听地址');
        }
        const baseUrl = `http://127.0.0.1:${address.port}/mcp`;

        try {
            const request = async (ip: string, token: string): Promise<globalThis.Response> =>
                await fetch(baseUrl, {
                    method: 'POST',
                    headers: {
                        Authorization: `Bearer ${token}`,
                        'Content-Type': 'application/json',
                        'X-Forwarded-For': ip,
                    },
                    body: '{}',
                });

            assert.equal((await request('203.0.113.10', 'token-a')).status, 200);

            const tokenLimitedResponse = await request('203.0.113.11', 'token-a');
            assert.equal(tokenLimitedResponse.status, 429);
            const tokenLimitedBody = await tokenLimitedResponse.text();
            assert.doesNotMatch(tokenLimitedBody, /token-a|203\.0\.113\.11/u);

            assert.equal((await request('203.0.113.11', 'token-b')).status, 200);
            assert.equal((await request('203.0.113.11', 'token-c')).status, 429);
        } finally {
            await closeServer(server);
        }
    });
});
