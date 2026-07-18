/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-13
 * @Time: 16:38
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： geoflow-api-client.test.ts
 * @Description: 验证 GEO API 客户端的 Bearer 鉴权、错误映射和任务执行幂等请求契约。
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { GeoFlowApiClient, GeoFlowApiError } from '../src/geoflow-api-client.js';

const baseUrl = new URL('https://geo.example.com/api/v1/');

/**
 * @Name: jsonResponse
 * @Description: 构造带正确 Content-Type 的 GEO API JSON 响应。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-13 16:38:47
 * @UpdateTime: 2026-07-13 16:38:47
 *
 * @Param: unknown body 响应数据
 * @Param: number status HTTP 状态码
 * @Return: Response 标准 JSON 响应
 */
function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: {
            'Content-Type': 'application/json',
        },
    });
}

/**
 * @Name: requestUrl
 * @Description: 从 Fetch API 支持的输入类型中安全提取 URL，避免对象隐式字符串转换。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-13 16:38:47
 * @UpdateTime: 2026-07-13 16:38:47
 *
 * @Param: string | URL | Request input Fetch 请求输入
 * @Return: URL 标准 URL 对象
 */
function requestUrl(input: string | URL | Request): URL {
    if (input instanceof URL) {
        return input;
    }
    if (input instanceof Request) {
        return new URL(input.url);
    }

    return new URL(input);
}

void describe('GeoFlowApiClient', () => {
    void it('使用 Bearer MCP Key 获取当前 GEO 上下文', async () => {
        const fetcher: typeof fetch = (input, init) => {
            const url = requestUrl(input);
            assert.equal(url.toString(), 'https://geo.example.com/api/v1/auth/me');
            assert.equal(new Headers(init?.headers).get('Authorization'), 'Bearer secret-key');

            return Promise.resolve(
                jsonResponse({
                    success: true,
                    data: {
                        token: { id: 1, name: '运营助手', scopes: ['mcp:connect', 'tasks:read'], expires_at: null },
                        admin: { id: 2, name: '运营人员', role: 'site_user' },
                        site: { id: 3, name: '企业站点' },
                    },
                }),
            );
        };
        const client = new GeoFlowApiClient(baseUrl, 'secret-key', 1_000, fetcher);

        const context = await client.authenticate();

        assert.equal(context.site?.id, 3);
        assert.deepEqual(context.token.scopes, ['mcp:connect', 'tasks:read']);
    });

    void it('保留 GEO API 的业务错误码和状态码', async () => {
        const fetcher: typeof fetch = () =>
            Promise.resolve(
                jsonResponse(
                    {
                        success: false,
                        error: {
                            code: 'quota_exceeded',
                            message: '当前账号规格额度不足',
                        },
                    },
                    422,
                ),
            );
        const client = new GeoFlowApiClient(baseUrl, 'secret-key', 1_000, fetcher);

        await assert.rejects(
            async () => await client.runTask(10, 'idempotency-123'),
            (error: unknown) =>
                error instanceof GeoFlowApiError && error.status === 422 && error.code === 'quota_exceeded',
        );
    });

    void it('执行任务时发送固定任务类型和幂等键', async () => {
        const fetcher: typeof fetch = (input, init) => {
            const url = requestUrl(input);
            const headers = new Headers(init?.headers);
            assert.equal(url.pathname, '/api/v1/tasks/9/enqueue');
            assert.equal(init?.method, 'POST');
            assert.equal(headers.get('X-Idempotency-Key'), 'run-once-123');
            if (typeof init?.body !== 'string') {
                throw new Error('任务执行请求体必须是 JSON 字符串');
            }
            assert.deepEqual(JSON.parse(init.body), {
                job_type: 'generate_article',
                source: 'mcp',
            });

            return Promise.resolve(jsonResponse({ success: true, data: { id: 88, status: 'pending' } }, 201));
        };
        const client = new GeoFlowApiClient(baseUrl, 'secret-key', 1_000, fetcher);

        const result = await client.runTask(9, 'run-once-123');

        assert.deepEqual(result, { id: 88, status: 'pending' });
    });

    void it('完整映射 GEO 素材库和条目接口', async () => {
        const requests: Array<{
            path: string;
            query: string;
            method: string;
            idempotencyKey: string | null;
            body: unknown;
        }> = [];
        const fetcher: typeof fetch = (input, init) => {
            const url = requestUrl(input);
            requests.push({
                path: url.pathname,
                query: url.search,
                method: init?.method || 'GET',
                idempotencyKey: new Headers(init?.headers).get('X-Idempotency-Key'),
                body: typeof init?.body === 'string' ? (JSON.parse(init.body) as unknown) : null,
            });

            return Promise.resolve(jsonResponse({ success: true, data: { ok: true } }));
        };
        const client = new GeoFlowApiClient(baseUrl, 'secret-key', 1_000, fetcher);

        await client.getMaterialSummary();
        await client.listMaterials('categories', { page: 2, per_page: 30, search: '科技' });
        await client.getMaterial('authors', 7);
        await client.createMaterial('keyword-libraries', { name: '品牌词库' }, 'material-create-123');
        await client.updateMaterial('knowledge-bases', 8, { content: '更新正文' }, 'material-update-123');
        await client.deleteMaterial('categories', 9, 'material-delete-123');
        await client.listMaterialItems('knowledge-bases', 10, { page: 1, per_page: 50 });
        await client.createMaterialItem('title-libraries', 11, { title: 'GEO 标题' }, 'material-item-create-123');
        await client.deleteMaterialItems('image-libraries', 12, [21, 22], 'material-items-delete-123');

        assert.deepEqual(requests, [
            { path: '/api/v1/materials', query: '', method: 'GET', idempotencyKey: null, body: null },
            {
                path: '/api/v1/materials/categories',
                query: '?page=2&per_page=30&search=%E7%A7%91%E6%8A%80',
                method: 'GET',
                idempotencyKey: null,
                body: null,
            },
            {
                path: '/api/v1/materials/authors/7',
                query: '',
                method: 'GET',
                idempotencyKey: null,
                body: null,
            },
            {
                path: '/api/v1/materials/keyword-libraries',
                query: '',
                method: 'POST',
                idempotencyKey: 'material-create-123',
                body: { name: '品牌词库' },
            },
            {
                path: '/api/v1/materials/knowledge-bases/8',
                query: '',
                method: 'PATCH',
                idempotencyKey: 'material-update-123',
                body: { content: '更新正文' },
            },
            {
                path: '/api/v1/materials/categories/9',
                query: '',
                method: 'DELETE',
                idempotencyKey: 'material-delete-123',
                body: null,
            },
            {
                path: '/api/v1/materials/knowledge-bases/10/items',
                query: '?page=1&per_page=50',
                method: 'GET',
                idempotencyKey: null,
                body: null,
            },
            {
                path: '/api/v1/materials/title-libraries/11/items',
                query: '',
                method: 'POST',
                idempotencyKey: 'material-item-create-123',
                body: { title: 'GEO 标题' },
            },
            {
                path: '/api/v1/materials/image-libraries/12/items',
                query: '',
                method: 'DELETE',
                idempotencyKey: 'material-items-delete-123',
                body: { ids: [21, 22] },
            },
        ]);
    });

    void it('使用派生幂等键创建 AI 文章并投递指定媒体渠道', async () => {
        const requests: Array<{ path: string; method: string; idempotencyKey: string | null; body: unknown }> = [];
        const fetcher: typeof fetch = (input, init) => {
            const url = requestUrl(input);
            const headers = new Headers(init?.headers);
            const body = typeof init?.body === 'string' ? (JSON.parse(init.body) as unknown) : null;
            requests.push({
                path: url.pathname,
                method: init?.method || 'GET',
                idempotencyKey: headers.get('X-Idempotency-Key'),
                body,
            });

            if (url.pathname === '/api/v1/articles') {
                return Promise.resolve(
                    jsonResponse({ success: true, data: { id: 91, title: 'AI 渠道文章', status: 'draft' } }, 201),
                );
            }

            return Promise.resolve(
                jsonResponse(
                    {
                        success: true,
                        data: {
                            submissions: [{ id: 301, media_resource_id: 8, points_amount: '12.00' }],
                            errors: [],
                        },
                    },
                    201,
                ),
            );
        };
        const client = new GeoFlowApiClient(baseUrl, 'secret-key', 1_000, fetcher);

        const result = await client.publishArticleToMedia(
            {
                title: 'AI 渠道文章',
                content: '# 正文',
                category_id: 5,
                author_id: 6,
                keywords: ['GEO', '媒体投递'],
            },
            [8, 9],
            '自动投稿',
            'publication-123',
        );

        assert.equal(result.article.id, 91);
        assert.equal(result.submissions.length, 1);
        assert.deepEqual(requests, [
            {
                path: '/api/v1/articles',
                method: 'POST',
                idempotencyKey: 'publication-123:article',
                body: {
                    title: 'AI 渠道文章',
                    content: '# 正文',
                    category_id: 5,
                    author_id: 6,
                    keywords: ['GEO', '媒体投递'],
                    status: 'draft',
                    review_status: 'pending',
                    is_ai_generated: 1,
                },
            },
            {
                path: '/api/v1/media/submissions',
                method: 'POST',
                idempotencyKey: 'publication-123:submission',
                body: {
                    article_ids: [91],
                    media_resource_ids: [8, 9],
                    remark: '自动投稿',
                },
            },
        ]);
    });
});
