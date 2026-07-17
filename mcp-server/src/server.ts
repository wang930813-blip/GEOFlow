/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-13
 * @Time: 16:38
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： server.ts
 * @Description: 启动独立 GEO MCP Streamable HTTP 服务并完成 Key 鉴权、工具注册和优雅退出。
 */

import type { Server as HttpServer } from 'node:http';
import { createMcpExpressApp } from '@modelcontextprotocol/sdk/server/express.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import type { Request, Response } from 'express';
import { loadConfig } from './config.js';
import { GeoFlowApiClient, GeoFlowApiError } from './geoflow-api-client.js';
import { assertMcpContext, createGeoMcpServer } from './tool-registry.js';

const config = loadConfig();
const app = createMcpExpressApp({
    host: config.host,
    allowedHosts: config.allowedHosts,
});

/**
 * @Name: extractBearerToken
 * @Description: 从标准 Authorization 请求头提取 Bearer MCP Key，拒绝空值和其他认证格式。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-13 16:38:47
 * @UpdateTime: 2026-07-13 16:38:47
 *
 * @Param: Request request Express 请求
 * @Return: string MCP Key 明文，仅在当前请求内存中使用
 */
function extractBearerToken(request: Request): string {
    const authorization = request.header('Authorization')?.trim() || '';
    const match = authorization.match(/^Bearer\s+(.+)$/iu);
    const token = match?.[1]?.trim() || '';
    if (token === '') {
        throw new GeoFlowApiError('缺少有效的 Authorization Bearer MCP Key', 401, 'mcp_unauthorized');
    }

    return token;
}

/**
 * @Name: sendJsonRpcError
 * @Description: 返回符合 JSON-RPC 2.0 的安全错误响应，不输出调用栈、内部 URL或凭证明文。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-13 16:38:47
 * @UpdateTime: 2026-07-13 16:38:47
 *
 * @Param: Response response Express 响应
 * @Param: GeoFlowApiError error 已归一化错误
 * @Return: void
 */
function sendJsonRpcError(response: Response, error: GeoFlowApiError): void {
    response.status(error.status).json({
        jsonrpc: '2.0',
        error: {
            code: error.status === 401 ? -32001 : error.status === 403 ? -32003 : -32603,
            message: error.message,
            data: {
                code: error.code,
            },
        },
        id: null,
    });
}

app.get('/health', (_request: Request, response: Response) => {
    response.json({
        status: 'ok',
        service: 'geoflow-business-mcp-server',
        protocol: 'streamable-http',
    });
});

app.post('/mcp', async (request: Request, response: Response) => {
    try {
        const token = extractBearerToken(request);
        const client = new GeoFlowApiClient(config.geoFlowApiBaseUrl, token, config.apiTimeoutMs);
        const context = await client.authenticate();
        assertMcpContext(context);

        const mcpServer = createGeoMcpServer(client, context);
        const transport = new StreamableHTTPServerTransport({
            sessionIdGenerator: undefined,
            enableJsonResponse: true,
        });

        response.on('close', () => {
            void transport.close();
            void mcpServer.close();
        });

        await mcpServer.connect(transport);
        await transport.handleRequest(request, response, request.body);
    } catch (error) {
        const normalized =
            error instanceof GeoFlowApiError
                ? error
                : new GeoFlowApiError('MCP 请求处理失败', 500, 'mcp_internal_error');

        if (!response.headersSent) {
            sendJsonRpcError(response, normalized);
        }
    }
});

app.get('/mcp', (_request: Request, response: Response) => {
    sendJsonRpcError(response, new GeoFlowApiError('无状态 MCP 服务不支持 GET 连接', 405, 'method_not_allowed'));
});

app.delete('/mcp', (_request: Request, response: Response) => {
    sendJsonRpcError(response, new GeoFlowApiError('无状态 MCP 服务不支持 DELETE 连接', 405, 'method_not_allowed'));
});

const httpServer: HttpServer = app.listen(config.port, config.host, () => {
    process.stdout.write(`GEOFlow Business MCP Server 已监听 ${config.host}:${config.port}\n`);
});

/**
 * @Name: shutdown
 * @Description: 停止接收新请求并等待现有 HTTP 连接关闭，超时由容器编排负责强制终止。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-13 16:38:47
 * @UpdateTime: 2026-07-13 16:38:47
 *
 * @Return: Promise<void> 关闭完成
 */
async function shutdown(): Promise<void> {
    await new Promise<void>((resolve, reject) => {
        httpServer.close((error) => {
            if (error) {
                reject(error);
                return;
            }

            resolve();
        });
    });
}

for (const signal of ['SIGINT', 'SIGTERM'] as const) {
    process.on(signal, () => {
        void shutdown()
            .then(() => process.exit(0))
            .catch(() => process.exit(1));
    });
}
