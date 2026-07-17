/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-13
 * @Time: 16:38
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： tool-registry.ts
 * @Description: 按 MCP Key scope 动态注册 GEO 目录、任务、执行记录和文章工具。
 */

import { randomUUID } from 'node:crypto';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { CallToolResult } from '@modelcontextprotocol/sdk/types.js';
import * as z from 'zod/v4';
import { GeoFlowApiClient, GeoFlowApiError, type GeoFlowAuthContext } from './geoflow-api-client.js';

const MCP_CONNECT_SCOPE = 'mcp:connect';

/**
 * @Name: hasScope
 * @Description: 判断 MCP Key 是否拥有指定业务 scope，兼容项目现有通配权限。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-13 16:38:47
 * @UpdateTime: 2026-07-13 16:38:47
 *
 * @Param: GeoFlowAuthContext context 已验证 GEO 上下文
 * @Param: string scope 所需业务权限
 * @Return: boolean 是否已授权
 */
export function hasScope(context: GeoFlowAuthContext, scope: string): boolean {
    return context.token.scopes.includes('*') || context.token.scopes.includes(scope);
}

/**
 * @Name: assertMcpContext
 * @Description: 强制校验专用 MCP 连接权限和站点绑定，拒绝普通 API Token 或全局 Token 进入 MCP 工具层。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-13 16:38:47
 * @UpdateTime: 2026-07-13 16:38:47
 *
 * @Param: GeoFlowAuthContext context 已验证 GEO 上下文
 * @Return: void
 */
export function assertMcpContext(context: GeoFlowAuthContext): void {
    if (!hasScope(context, MCP_CONNECT_SCOPE)) {
        throw new GeoFlowApiError('当前凭证不是 MCP Key', 403, 'mcp_scope_required');
    }
    if (context.site === null || context.site.id <= 0) {
        throw new GeoFlowApiError('MCP Key 必须绑定有效站点', 403, 'mcp_site_required');
    }
}

/**
 * @Name: successResult
 * @Description: 同时返回可读文本和结构化结果，兼容只读取文本或支持 structuredContent 的 MCP 客户端。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-13 16:38:47
 * @UpdateTime: 2026-07-13 16:38:47
 *
 * @Param: unknown data GEO API 业务数据
 * @Return: CallToolResult MCP 工具成功结果
 */
function successResult(data: unknown): CallToolResult {
    return {
        content: [
            {
                type: 'text',
                text: JSON.stringify(data, null, 2),
            },
        ],
        structuredContent: {
            result: data,
        },
    };
}

/**
 * @Name: executeTool
 * @Description: 统一执行 GEO 工具并将上游业务错误转换为 MCP 可处理错误结果，不泄露调用栈和密钥。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-13 16:38:47
 * @UpdateTime: 2026-07-13 16:38:47
 *
 * @Param: () => Promise<unknown> operation GEO API 调用
 * @Return: Promise<CallToolResult> MCP 工具结果
 */
async function executeTool(operation: () => Promise<unknown>): Promise<CallToolResult> {
    try {
        return successResult(await operation());
    } catch (error) {
        const apiError =
            error instanceof GeoFlowApiError ? error : new GeoFlowApiError('GEO 工具执行失败', 500, 'geo_tool_error');

        return {
            isError: true,
            content: [
                {
                    type: 'text',
                    text: `${apiError.message}（${apiError.code}）`,
                },
            ],
        };
    }
}

/**
 * @Name: createGeoMcpServer
 * @Description: 根据已验证 Key 的 scopes 创建无状态 GEO MCP Server，仅注册当前凭证可调用的业务工具。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-13 16:38:47
 * @UpdateTime: 2026-07-13 16:38:47
 *
 * @Param: GeoFlowApiClient client 当前请求专用 GEO API 客户端
 * @Param: GeoFlowAuthContext context 已验证 GEO 账号和站点上下文
 * @Return: McpServer 已注册授权工具的 MCP Server
 */
export function createGeoMcpServer(client: GeoFlowApiClient, context: GeoFlowAuthContext): McpServer {
    assertMcpContext(context);

    const server = new McpServer({
        name: 'geoflow-business-mcp-server',
        title: `GEOFlow - ${context.site?.name || 'GEO'}`,
        version: '1.0.0',
    });

    if (hasScope(context, 'catalog:read')) {
        server.registerTool(
            'geo_get_catalog',
            {
                title: '获取 GEO 目录',
                description: '获取当前站点可用的模型、提示词、标题库、知识库、作者和分类。',
                annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true },
            },
            async () => await executeTool(async () => await client.getCatalog()),
        );
    }

    if (hasScope(context, 'tasks:read')) {
        server.registerTool(
            'geo_list_tasks',
            {
                title: '查询 GEO 任务',
                description: '分页查询当前站点 GEO 任务，可按状态和名称筛选。',
                inputSchema: {
                    page: z.number().int().min(1).default(1).describe('页码'),
                    per_page: z.number().int().min(1).max(100).default(20).describe('每页数量'),
                    status: z.string().trim().min(1).max(32).optional().describe('任务状态'),
                    search: z.string().trim().min(1).max(120).optional().describe('任务名称关键词'),
                },
                annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true },
            },
            async ({ page, per_page, status, search }) =>
                await executeTool(async () => await client.listTasks({ page, per_page, status, search })),
        );

        server.registerTool(
            'geo_get_task',
            {
                title: '获取 GEO 任务详情',
                description: '查询当前站点单个任务的配置、业务进度和队列概况。',
                inputSchema: {
                    task_id: z.number().int().positive().describe('任务编号'),
                },
                annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true },
            },
            async ({ task_id }) => await executeTool(async () => await client.getTask(task_id)),
        );

        server.registerTool(
            'geo_list_task_runs',
            {
                title: '查询任务执行记录',
                description: '查询指定 GEO 任务最近的执行记录，可按状态筛选。',
                inputSchema: {
                    task_id: z.number().int().positive().describe('任务编号'),
                    status: z.string().trim().min(1).max(32).optional().describe('执行状态'),
                    limit: z.number().int().min(1).max(100).default(20).describe('返回数量'),
                },
                annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true },
            },
            async ({ task_id, status, limit }) =>
                await executeTool(async () => await client.listTaskRuns(task_id, { status, limit })),
        );
    }

    if (hasScope(context, 'tasks:write')) {
        server.registerTool(
            'geo_run_task',
            {
                title: '立即执行 GEO 任务',
                description: '向当前站点已有任务投递一次文章生成执行。成功生成文章后按现有套餐规则扣减额度。',
                inputSchema: {
                    task_id: z.number().int().positive().describe('任务编号'),
                    idempotency_key: z
                        .string()
                        .trim()
                        .min(8)
                        .max(128)
                        .optional()
                        .describe('可选幂等键，重试同一操作时保持一致'),
                },
                annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false },
            },
            async ({ task_id, idempotency_key }) =>
                await executeTool(async () => await client.runTask(task_id, idempotency_key || `mcp-${randomUUID()}`)),
        );
    }

    if (hasScope(context, 'jobs:read')) {
        server.registerTool(
            'geo_get_task_run',
            {
                title: '获取任务执行详情',
                description: '查询单次 GEO 任务执行的状态、结果摘要和错误信息。',
                inputSchema: {
                    run_id: z.number().int().positive().describe('执行记录编号'),
                },
                annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true },
            },
            async ({ run_id }) => await executeTool(async () => await client.getTaskRun(run_id)),
        );
    }

    if (hasScope(context, 'articles:read')) {
        server.registerTool(
            'geo_list_articles',
            {
                title: '查询 GEO 文章',
                description: '分页查询当前站点文章，可按任务、状态、审核状态、作者和关键词筛选。',
                inputSchema: {
                    page: z.number().int().min(1).default(1).describe('页码'),
                    per_page: z.number().int().min(1).max(100).default(20).describe('每页数量'),
                    task_id: z.number().int().positive().optional().describe('任务编号'),
                    status: z.string().trim().min(1).max(32).optional().describe('文章状态'),
                    review_status: z.string().trim().min(1).max(32).optional().describe('审核状态'),
                    author_id: z.number().int().positive().optional().describe('作者编号'),
                    search: z.string().trim().min(1).max(120).optional().describe('标题或正文关键词'),
                },
                annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true },
            },
            async ({ page, per_page, task_id, status, review_status, author_id, search }) =>
                await executeTool(
                    async () =>
                        await client.listArticles({
                            page,
                            per_page,
                            task_id,
                            status,
                            review_status,
                            author_id,
                            search,
                        }),
                ),
        );

        server.registerTool(
            'geo_get_article',
            {
                title: '获取 GEO 文章详情',
                description: '查询当前站点单篇文章的正文、元数据、作者、分类和配图。',
                inputSchema: {
                    article_id: z.number().int().positive().describe('文章编号'),
                },
                annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true },
            },
            async ({ article_id }) => await executeTool(async () => await client.getArticle(article_id)),
        );
    }

    return server;
}
