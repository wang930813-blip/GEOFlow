/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-18
 * @Time: 15:37
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： rate-limit.ts
 * @Description: 为 MCP POST 请求提供按可信客户端 IP 和 Bearer Token 指纹隔离的内存限流。
 */

import { createHash } from 'node:crypto';
import type { Request, RequestHandler, Response } from 'express';
import { rateLimit } from 'express-rate-limit';
import type { RateLimitConfig } from './config.js';

/**
 * @Name: extractBearerTokenValue
 * @Description: 严格解析 Bearer 凭据，仅在当前请求内返回明文，调用方不得记录或持久化返回值。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-18 15:37:25
 * @UpdateTime: 2026-07-18 15:37:25
 *
 * @Param: Request request Express 请求
 * @Return: string | null Bearer Token 明文或无有效凭据
 */
export function extractBearerTokenValue(request: Request): string | null {
    const authorization = request.header('Authorization')?.trim() || '';
    const match = authorization.match(/^Bearer\s+(.+)$/iu);
    const token = match?.[1]?.trim() || '';

    return token === '' ? null : token;
}

/**
 * @Name: createBearerTokenFingerprint
 * @Description: 使用 SHA-256 生成不可逆固定长度指纹，限流存储和诊断过程不得保留明文 Key。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-18 15:37:25
 * @UpdateTime: 2026-07-18 15:37:25
 *
 * @Param: string token Bearer Token 明文
 * @Return: string SHA-256 十六进制指纹
 */
export function createBearerTokenFingerprint(token: string): string {
    return createHash('sha256').update(token, 'utf8').digest('hex');
}

/**
 * @Name: sendRateLimitError
 * @Description: 返回统一 JSON-RPC 限流错误，不暴露客户端 IP、Token 指纹、限流维度或内部计数。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-18 15:37:25
 * @UpdateTime: 2026-07-18 15:37:25
 *
 * @Param: Response response Express 响应
 * @Return: void
 */
function sendRateLimitError(response: Response): void {
    response.status(429).json({
        jsonrpc: '2.0',
        error: {
            code: -32029,
            message: 'MCP 请求过于频繁，请稍后重试',
            data: {
                code: 'rate_limit_exceeded',
            },
        },
        id: null,
    });
}

/**
 * @Name: createMcpRateLimiters
 * @Description: 创建独立的 IP 与 Token 指纹限流器；IP 由 Express 按显式可信代理配置解析，IPv6 默认按 /56 网段聚合。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-18 15:37:25
 * @UpdateTime: 2026-07-18 15:37:25
 *
 * @Param: RateLimitConfig config 限流窗口和各维度请求上限
 * @Return: readonly RequestHandler[] 按执行顺序排列的 Express 限流中间件
 */
export function createMcpRateLimiters(config: RateLimitConfig): readonly RequestHandler[] {
    const commonOptions = {
        windowMs: config.windowMs,
        standardHeaders: 'draft-8' as const,
        legacyHeaders: false,
        skipSuccessfulRequests: false,
        skipFailedRequests: false,
        passOnStoreError: false,
        handler: (_request: Request, response: Response) => {
            sendRateLimitError(response);
        },
    };

    const ipLimiter = rateLimit({
        ...commonOptions,
        limit: config.ipMaxRequests,
        identifier: 'mcp-ip',
        ipv6Subnet: 56,
    });
    const tokenLimiter = rateLimit({
        ...commonOptions,
        limit: config.tokenMaxRequests,
        identifier: 'mcp-token',
        skip: (request: Request) => extractBearerTokenValue(request) === null,
        keyGenerator: (request: Request) => {
            const token = extractBearerTokenValue(request);
            return createBearerTokenFingerprint(token || '');
        },
    });

    return [ipLimiter, tokenLimiter];
}
