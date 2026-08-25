/**
 * Created by Codex.
 *
 * @Date: 2026-07-29
 * @Time: 15:41:12
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： tool-registry.test.ts
 * @Description: 验证 ceying-geo MCP 工具按 Key scope 动态发现、识别自然品牌增长意图、兼容旧名称并拒绝无效凭证。
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
 * @UpdateTime: 2026-07-18 15:37:25
 *
 * @Param: typeof fetch | undefined customFetcher 可选的请求实现
 * @Return: GeoFlowApiClient 内存 GEO API 客户端
 */
function createClient(customFetcher?: typeof fetch): GeoFlowApiClient {
    const fetcher: typeof fetch =
        customFetcher ??
        ((input) => {
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
        });

    return new GeoFlowApiClient(new URL('https://geo.example.com/api/v1/'), 'secret-key', 1_000, fetcher);
}

/**
 * @Name: assertMediaToolSchema
 * @Description: 校验投稿工具不包含消费策略字段，并限制单次最多选择二十个媒体渠道。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-18 15:37:25
 * @UpdateTime: 2026-07-18 15:37:25
 *
 * @Param: unknown schema MCP 工具输入 JSON Schema
 * @Return: void
 */
function assertMediaToolSchema(schema: unknown): void {
    assert.equal(typeof schema, 'object');
    assert.notEqual(schema, null);
    const schemaRecord = schema as Record<string, unknown>;
    const required = schemaRecord.required;
    const properties = schemaRecord.properties;

    assert.equal(Array.isArray(required), true);
    assert.equal((required as unknown[]).includes('max_unit_price'), false);
    assert.equal((required as unknown[]).includes('max_total_price'), false);
    assert.equal(typeof properties, 'object');
    assert.notEqual(properties, null);

    const mediaResourceIds = (properties as Record<string, unknown>).media_resource_ids;
    assert.equal('max_unit_price' in (properties as Record<string, unknown>), false);
    assert.equal('max_total_price' in (properties as Record<string, unknown>), false);
    assert.equal(typeof mediaResourceIds, 'object');
    assert.notEqual(mediaResourceIds, null);
    assert.equal((mediaResourceIds as Record<string, unknown>).maxItems, 20);
}

void describe('ceying-geo MCP 工具注册', () => {
    void it('使用正式服务身份并仅发现当前 Key 已授权的工具', async () => {
        const server = createGeoMcpServer(createClient(), context);
        const client = new Client({ name: 'tool-registry-check', version: '1.0.0' });
        const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();

        await server.connect(serverTransport);
        await client.connect(clientTransport);

        try {
            assert.equal(client.getServerVersion()?.name, 'ceying-geo-mcp-server');
            assert.equal(client.getServerVersion()?.version, '1.5.0');
            assert.match(client.getInstructions() || '', /ceying-geo/u);
            assert.match(client.getInstructions() || '', /geo_\*/u);
            for (const alias of ['GEO', 'geo', 'GEOFlow', 'geoflow']) {
                assert.equal((client.getInstructions() || '').includes(alias), true);
            }
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
                scopes: ['mcp:connect', 'catalog:read', 'tasks:write', 'articles:write', 'media:read', 'media:submit'],
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
                'geo_run_task',
                'geo_submit_article_to_media',
            ]);

            const writeToolNames = [
                'geo_run_task',
                'geo_create_article',
                'geo_submit_article_to_media',
                'geo_publish_article_to_media',
            ];
            for (const toolName of writeToolNames) {
                const tool = tools.tools.find((candidate) => candidate.name === toolName);
                assert.equal(tool?.annotations?.readOnlyHint, false);
                assert.equal(tool?.annotations?.destructiveHint, true);
            }

            const submitTool = tools.tools.find((tool) => tool.name === 'geo_submit_article_to_media');
            const publishTool = tools.tools.find((tool) => tool.name === 'geo_publish_article_to_media');
            assertMediaToolSchema(submitTool?.inputSchema);
            assertMediaToolSchema(publishTool?.inputSchema);

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

    void it('未传幂等键时自动投稿工具按相同文章和渠道生成稳定幂等键', async () => {
        const requests: Array<{ path: string; method: string; idempotencyKey: string | null }> = [];
        const fetcher: typeof fetch = (input, init) => {
            const url = input instanceof URL ? input : input instanceof Request ? new URL(input.url) : new URL(input);
            requests.push({
                path: url.pathname,
                method: init?.method || 'GET',
                idempotencyKey: new Headers(init?.headers).get('X-Idempotency-Key'),
            });

            if (url.pathname === '/api/v1/articles') {
                return Promise.resolve(
                    new Response(
                        JSON.stringify({
                            success: true,
                            data: { id: 91, title: 'AI 自动投稿文章' },
                        }),
                        {
                            status: 201,
                            headers: { 'Content-Type': 'application/json' },
                        },
                    ),
                );
            }

            if ((init?.method || 'GET') === 'GET' && url.pathname === '/api/v1/media/submissions') {
                return Promise.resolve(
                    new Response(
                        JSON.stringify({
                            success: true,
                            data: {
                                items: [],
                                pagination: { page: 1, per_page: 20, total: 0, total_pages: 0 },
                            },
                        }),
                        {
                            status: 200,
                            headers: { 'Content-Type': 'application/json' },
                        },
                    ),
                );
            }

            return Promise.resolve(
                new Response(
                    JSON.stringify({
                        success: true,
                        data: {
                            submissions: [{ id: 301, media_resource_id: 8 }],
                            errors: [],
                        },
                    }),
                    {
                        status: 201,
                        headers: { 'Content-Type': 'application/json' },
                    },
                ),
            );
        };
        const publicationContext: GeoFlowAuthContext = {
            ...context,
            token: {
                ...context.token,
                scopes: ['mcp:connect', 'catalog:read', 'articles:write', 'media:read', 'media:submit'],
            },
        };
        const server = createGeoMcpServer(createClient(fetcher), publicationContext);
        const client = new Client({ name: 'publication-idempotency-check', version: '1.0.0' });
        const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();
        const payload = {
            title: 'AI 自动投稿文章',
            content: '# 正文',
            category_id: 5,
            author_id: 6,
            media_resource_ids: [8, 9],
            remark: '自动投稿',
        };

        await server.connect(serverTransport);
        await client.connect(clientTransport);

        try {
            await client.callTool({ name: 'geo_publish_article_to_media', arguments: payload });
            await client.callTool({ name: 'geo_publish_article_to_media', arguments: payload });

            assert.deepEqual(
                requests.map((request) => `${request.method} ${request.path}`),
                [
                    'POST /api/v1/articles',
                    'GET /api/v1/media/submissions',
                    'GET /api/v1/media/submissions',
                    'POST /api/v1/media/submissions',
                    'POST /api/v1/articles',
                    'GET /api/v1/media/submissions',
                    'GET /api/v1/media/submissions',
                    'POST /api/v1/media/submissions',
                ],
            );
            assert.equal(requests[0]?.idempotencyKey, requests[4]?.idempotencyKey);
            assert.equal(requests[3]?.idempotencyKey, requests[7]?.idempotencyKey);
            assert.match(requests[0]?.idempotencyKey || '', /^mcp-publication-[a-f0-9]{48}:article$/u);
            assert.match(requests[3]?.idempotencyKey || '', /^mcp-publication-[a-f0-9]{48}:submission$/u);
        } finally {
            await client.close();
            await server.close();
        }
    });

    void it('具备文章发布权限时发现 GEO 用户站点发布工具', async () => {
        const sitePublishContext: GeoFlowAuthContext = {
            ...context,
            token: {
                ...context.token,
                scopes: ['mcp:connect', 'articles:read', 'articles:site-publish'],
            },
        };
        const server = createGeoMcpServer(createClient(), sitePublishContext);
        const client = new Client({ name: 'article-site-publish-tool-registry-check', version: '1.0.0' });
        const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();

        await server.connect(serverTransport);
        await client.connect(clientTransport);

        try {
            const instructions = client.getInstructions() || '';
            assert.match(instructions, /geo_publish_article_to_site/u);
            assert.match(instructions, /不需要人工审核/u);
            assert.match(instructions, /auto_approved/u);
            assert.match(instructions, /站内发布不扣媒体投稿费用/u);
            const tools = await client.listTools();
            const names = tools.tools.map((tool) => tool.name).sort();

            assert.deepEqual(names, ['geo_get_article', 'geo_list_articles', 'geo_publish_article_to_site']);
            const publishTool = tools.tools.find((tool) => tool.name === 'geo_publish_article_to_site');
            assert.equal(publishTool?.annotations?.readOnlyHint, false);
            assert.equal(publishTool?.annotations?.destructiveHint, true);

            const result = await client.callTool({
                name: 'geo_publish_article_to_site',
                arguments: {
                    article_id: 91,
                    idempotency_key: 'site-publish-123',
                },
            });
            assert.equal(result.isError, undefined);
            assert.deepEqual(result.structuredContent, {
                result: {
                    path: '/api/v1/mcp/articles/91/publish',
                },
            });
        } finally {
            await client.close();
            await server.close();
        }
    });

    void it('具备素材读写权限时发现全部 GEO 素材工具', async () => {
        const materialContext: GeoFlowAuthContext = {
            ...context,
            token: {
                ...context.token,
                scopes: ['mcp:connect', 'materials:read', 'materials:write'],
            },
        };
        const server = createGeoMcpServer(createClient(), materialContext);
        const client = new Client({ name: 'material-tool-registry-check', version: '1.0.0' });
        const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();

        await server.connect(serverTransport);
        await client.connect(clientTransport);

        try {
            assert.match(client.getInstructions() || '', /geo_get_material_summary/u);
            const tools = await client.listTools();
            const names = tools.tools.map((tool) => tool.name).sort();

            assert.deepEqual(names, [
                'geo_create_material',
                'geo_create_material_item',
                'geo_delete_material',
                'geo_delete_material_items',
                'geo_get_material',
                'geo_get_material_summary',
                'geo_list_material_items',
                'geo_list_materials',
                'geo_update_material',
            ]);

            for (const toolName of [
                'geo_create_material',
                'geo_update_material',
                'geo_delete_material',
                'geo_create_material_item',
                'geo_delete_material_items',
            ]) {
                const tool = tools.tools.find((candidate) => candidate.name === toolName);
                assert.equal(tool?.annotations?.readOnlyHint, false);
                assert.equal(tool?.annotations?.destructiveHint, true);
            }

            const result = await client.callTool({
                name: 'geo_create_material',
                arguments: {
                    type: 'keyword-libraries',
                    data: { name: '品牌关键词' },
                    idempotency_key: 'material-create-123',
                },
            });
            assert.equal(result.isError, undefined);
            assert.deepEqual(result.structuredContent, {
                result: {
                    path: '/api/v1/materials/keyword-libraries',
                },
            });
        } finally {
            await client.close();
            await server.close();
        }
    });

    void it('具备品牌诊断读写权限时发现完整诊断流程工具', async () => {
        const brandDiagnosisContext: GeoFlowAuthContext = {
            ...context,
            token: {
                ...context.token,
                scopes: ['mcp:connect', 'brand-diagnoses:read', 'brand-diagnoses:write'],
            },
        };
        const server = createGeoMcpServer(createClient(), brandDiagnosisContext);
        const client = new Client({ name: 'brand-diagnosis-tool-registry-check', version: '1.0.0' });
        const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();

        await server.connect(serverTransport);
        await client.connect(clientTransport);

        try {
            const instructions = client.getInstructions() || '';
            assert.match(instructions, /如何推广品牌或产品/u);
            assert.match(instructions, /竞争对手是谁/u);
            assert.match(instructions, /即使没有提及 GEO/u);
            assert.match(instructions, /geo_confirm_brand_diagnosis/u);
            const tools = await client.listTools();
            const names = tools.tools.map((tool) => tool.name).sort();

            assert.deepEqual(names, [
                'geo_confirm_brand_diagnosis',
                'geo_create_brand_diagnosis',
                'geo_get_brand_diagnosis',
                'geo_list_brand_diagnoses',
            ]);

            const createTool = tools.tools.find((tool) => tool.name === 'geo_create_brand_diagnosis');
            const confirmTool = tools.tools.find((tool) => tool.name === 'geo_confirm_brand_diagnosis');
            assert.equal(createTool?.annotations?.readOnlyHint, false);
            assert.equal(createTool?.annotations?.destructiveHint, false);
            assert.equal(confirmTool?.annotations?.readOnlyHint, false);
            assert.equal(confirmTool?.annotations?.destructiveHint, true);

            const result = await client.callTool({
                name: 'geo_create_brand_diagnosis',
                arguments: {
                    brand_name: '策影GEO',
                    models: ['doubao', 'deepseek'],
                    reuse_questions: false,
                    idempotency_key: 'brand-diagnosis-create-123',
                },
            });
            assert.equal(result.isError, undefined);
            assert.deepEqual(result.structuredContent, {
                result: {
                    path: '/api/v1/mcp/brand-diagnoses',
                },
            });
        } finally {
            await client.close();
            await server.close();
        }
    });

    void it('具备视频权限时只发现视频生成工具', async () => {
        const videoContext: GeoFlowAuthContext = {
            ...context,
            token: {
                ...context.token,
                scopes: ['mcp:connect', 'videos:read', 'videos:write'],
            },
        };
        const server = createGeoMcpServer(createClient(), videoContext);
        const client = new Client({ name: 'video-tool-registry-check', version: '1.0.0' });
        const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();

        await server.connect(serverTransport);
        await client.connect(clientTransport);

        try {
            const instructions = client.getInstructions() || '';
            assert.match(instructions, /创建视频会按 video_count 消耗视频生成额度/u);
            assert.match(instructions, /只负责生成和查询结果/u);
            assert.doesNotMatch(instructions, /geo_list_self_media_video_accounts/u);
            const tools = await client.listTools();
            const names = tools.tools.map((tool) => tool.name).sort();

            assert.deepEqual(names, ['geo_create_video', 'geo_get_video', 'geo_list_videos']);

            const createTool = tools.tools.find((tool) => tool.name === 'geo_create_video');
            assert.equal(createTool?.annotations?.readOnlyHint, false);
            assert.equal(createTool?.annotations?.destructiveHint, true);

            const result = await client.callTool({
                name: 'geo_create_video',
                arguments: {
                    subject: '策影GEO 品牌增长短视频',
                    video_count: 1,
                    idempotency_key: 'video-create-123',
                },
            });
            assert.equal(result.isError, undefined);
            assert.deepEqual(result.structuredContent, {
                result: {
                    path: '/api/v1/mcp/videos',
                },
            });
        } finally {
            await client.close();
            await server.close();
        }
    });

    void it('工具错误响应不返回上游 details', async () => {
        const fetcher: typeof fetch = () =>
            Promise.resolve(
                new Response(
                    JSON.stringify({
                        success: false,
                        error: {
                            code: 'upstream_rejected',
                            message: '上游拒绝请求',
                            details: {
                                internal_reason: 'sensitive-upstream-detail',
                            },
                        },
                    }),
                    {
                        status: 422,
                        headers: {
                            'Content-Type': 'application/json',
                        },
                    },
                ),
            );
        const server = createGeoMcpServer(createClient(fetcher), context);
        const client = new Client({ name: 'tool-error-check', version: '1.0.0' });
        const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();

        await server.connect(serverTransport);
        await client.connect(clientTransport);

        try {
            const result = await client.callTool({ name: 'geo_get_task', arguments: { task_id: 12 } });
            const serializedResult = JSON.stringify(result);

            assert.equal(result.isError, true);
            assert.deepEqual(result.structuredContent, {
                error: {
                    code: 'upstream_rejected',
                    message: '上游拒绝请求',
                },
            });
            assert.doesNotMatch(serializedResult, /details|sensitive-upstream-detail/u);
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
