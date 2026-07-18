/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-13
 * @Time: 16:38
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： tool-registry.test.ts
 * @Description: 验证 GEO MCP 工具按 Key scope 动态发现，并拒绝普通 API Token 和未绑定站点凭证。
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { GeoFlowApiClient, GeoFlowApiError, type GeoFlowAuthContext } from '../src/geoflow-api-client.js';
import { assertMcpContext, createGeoMcpServer } from '../src/tool-registry.js';

const context: GeoFlowAuthContext = {
    token: {
        id: 1,
        name: '读取助手',
        scopes: ['mcp:connect', 'tasks:read', 'articles:read'],
        expires_at: null,
    },
    admin: {
        id: 2,
        name: '站点用户',
        role: 'site_user',
    },
    site: {
        id: 3,
        name: '企业站点',
    },
};

/**
 * @Name: createClient
 * @Description: 创建返回标准 GEO API 信封的内存客户端，供 MCP 协议工具发现验证使用。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-13 16:38:47
 * @UpdateTime: 2026-07-13 16:38:47
 *
 * @Return: GeoFlowApiClient 内存 GEO API 客户端
 */
function createClient(): GeoFlowApiClient {
    const fetcher: typeof fetch = (input) => {
        const url = input instanceof URL ? input : input instanceof Request ? new URL(input.url) : new URL(input);

        return Promise.resolve(
            new Response(
                JSON.stringify({
                    success: true,
                    data: {
                        path: url.pathname,
                    },
                }),
                {
                    status: 200,
                    headers: {
                        'Content-Type': 'application/json',
                    },
                },
            ),
        );
    };

    return new GeoFlowApiClient(new URL('https://geo.example.com/api/v1/'), 'secret-key', 1_000, fetcher);
}

void describe('GEO MCP 工具注册', () => {
    void it('仅发现当前 Key 已授权的 GEO 工具', async () => {
        const server = createGeoMcpServer(createClient(), context);
        const client = new Client({ name: 'tool-registry-check', version: '1.0.0' });
        const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();

        await server.connect(serverTransport);
        await client.connect(clientTransport);

        try {
            assert.doesNotMatch(client.getInstructions() || '', /geo_publish_article_to_media/);
            const tools = await client.listTools();
            const names = tools.tools.map((tool) => tool.name).sort();

            assert.deepEqual(names, [
                'geo_get_article',
                'geo_get_task',
                'geo_list_articles',
                'geo_list_task_runs',
                'geo_list_tasks',
            ]);
            assert.equal(names.includes('geo_run_task'), false);

            const result = await client.callTool({ name: 'geo_get_task', arguments: { task_id: 12 } });
            assert.equal(result.isError, undefined);
            assert.deepEqual(result.structuredContent, {
                result: {
                    path: '/api/v1/tasks/12',
                },
            });
        } finally {
            await client.close();
            await server.close();
        }
    });

    void it('具备文章写入和媒体权限时发现 AI 自动投稿工具', async () => {
        const publicationContext: GeoFlowAuthContext = {
            ...context,
            token: {
                ...context.token,
                scopes: ['mcp:connect', 'catalog:read', 'articles:write', 'media:read', 'media:submit'],
            },
        };
        const server = createGeoMcpServer(createClient(), publicationContext);
        const client = new Client({ name: 'publication-tool-registry-check', version: '1.0.0' });
        const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();

        await server.connect(serverTransport);
        await client.connect(clientTransport);

        try {
            assert.match(client.getInstructions() || '', /geo_publish_article_to_media/);
            const tools = await client.listTools();
            const names = tools.tools.map((tool) => tool.name).sort();

            assert.deepEqual(names, [
                'geo_create_article',
                'geo_get_catalog',
                'geo_get_media_channel',
                'geo_get_media_submission',
                'geo_list_media_channels',
                'geo_list_media_submissions',
                'geo_publish_article_to_media',
                'geo_submit_article_to_media',
            ]);

            const result = await client.callTool({
                name: 'geo_list_media_channels',
                arguments: { page: 1, per_page: 20, search: '科技' },
            });
            assert.equal(result.isError, undefined);
            assert.deepEqual(result.structuredContent, {
                result: {
                    path: '/api/v1/media/resources',
                },
            });
        } finally {
            await client.close();
            await server.close();
        }
    });

    void it('拒绝缺少 MCP 连接权限的普通 API Token', () => {
        assert.throws(
            () =>
                assertMcpContext({
                    ...context,
                    token: {
                        ...context.token,
                        scopes: ['tasks:read'],
                    },
                }),
            (error: unknown) => error instanceof GeoFlowApiError && error.code === 'mcp_scope_required',
        );
    });

    void it('拒绝未绑定站点的 MCP Key', () => {
        assert.throws(
            () => assertMcpContext({ ...context, site: null }),
            (error: unknown) => error instanceof GeoFlowApiError && error.code === 'mcp_site_required',
        );
    });
});
