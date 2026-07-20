/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-13
 * @Time: 16:38
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： server.ts
 * @Description: 启动独立 GEO MCP Streamable HTTP 服务并完成请求限流、Key 鉴权、工具注册和优雅退出。
 */

import type { Server as HttpServer } from 'node:http';
import { createMcpExpressApp } from '@modelcontextprotocol/sdk/server/express.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import type { NextFunction, Request, Response } from 'express';
import { loadConfig } from './config.js';
import { GeoFlowApiClient, GeoFlowApiError } from './geoflow-api-client.js';
import { createMcpRateLimiters, extractBearerTokenValue } from './rate-limit.js';
import { assertMcpContext, createGeoMcpServer } from './tool-registry.js';

const config = loadConfig();
const app = createMcpExpressApp({
    host: config.host,
    allowedHosts: config.allowedHosts,
});
app.set('trust proxy', config.trustedProxies.length > 0 ? config.trustedProxies : false);
const mcpRateLimiters = createMcpRateLimiters(config.rateLimit);

/**
 * @Name: extractBearerToken
 * @Description: 从标准 Authorization 请求头提取 Bearer MCP Key，拒绝空值和其他认证格式。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-13 16:38:47
 * @UpdateTime: 2026-07-18 15:37:25
 *
 * @Param: Request request Express 请求
 * @Return: string MCP Key 明文，仅在当前请求内存中使用
 */
function extractBearerToken(request: Request): string {
    const token = extractBearerTokenValue(request);
    if (token === null) {
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

/**
 * @Name: enforceHttps
 * @Description: 生产配置启用时拒绝非 HTTPS MCP 请求，防止 Bearer Key 通过公网明文传输。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-18 17:15:00
 * @UpdateTime: 2026-07-18 17:15:00
 *
 * @Param: Request request Express 请求
 * @Param: Response response Express 响应
 * @Param: NextFunction next 后续请求处理器
 * @Return: void
 */
function enforceHttps(request: Request, response: Response, next: NextFunction): void {
    if (!config.requireHttps || request.secure) {
        next();
        return;
    }

    sendJsonRpcError(response, new GeoFlowApiError('MCP Server 仅允许 HTTPS 连接', 426, 'https_required'));
}

app.get('/health', (_request: Request, response: Response) => {
    response.json({
        status: 'ok',
        service: 'geoflow-business-mcp-server',
        protocol: 'streamable-http',
    });
});

/**
 * 处理 MCP Streamable HTTP 请求
 * 对请求执行 IP 与 Token 指纹限流、Bearer 鉴权、站点上下文校验和 MCP 工具调用。
 * @Url [POST] [/mcp]
 *      登录 [是]
 *      Authorization string 必选 Bearer MCP Key 请求头
 *      body JSON-RPC 2.0 object 必选 MCP 协议请求体
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-13 16:38:47
 * @UpdateTime: 2026-07-18 15:37:25
 *
 * @Return JSON-RPC 2.0 object MCP 协议结果或安全错误响应
 * @Throws GeoFlowApiError 凭据无效、权限不足、触发限流或内部 API 调用失败
 */
app.post('/mcp', enforceHttps, ...mcpRateLimiters, async (request: Request, response: Response) => {
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
