/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-13
 * @Time: 16:38
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： geoflow-api-client.ts
 * @Description: 封装 GEOFlow REST API 鉴权、超时、错误映射、查询和任务执行调用。
 */

import { randomUUID } from 'node:crypto';

export interface GeoFlowAuthContext {
    token: {
        id: number;
        name: string;
        scopes: string[];
        expires_at: string | null;
    };
    admin: {
        id: number;
        name: string;
        role: string;
    };
    site: {
        id: number;
        name: string;
    } | null;
}

interface GeoFlowSuccessEnvelope<T> {
    success: true;
    data: T;
    request_id?: string;
}

interface GeoFlowErrorEnvelope {
    success: false;
    error?: {
        code?: string;
        message?: string;
        details?: unknown;
    };
    request_id?: string;
}

interface RequestOptions {
    query?: Record<string, string | number | boolean | undefined>;
    body?: Record<string, unknown>;
    idempotencyKey?: string;
}

export class GeoFlowApiError extends Error {
    public constructor(
        message: string,
        public readonly status: number,
        public readonly code: string,
        public readonly details: unknown = null,
    ) {
        super(message);
        this.name = 'GeoFlowApiError';
    }
}

export class GeoFlowApiClient {
    public constructor(
        private readonly baseUrl: URL,
        private readonly token: string,
        private readonly timeoutMs: number,
        private readonly fetcher: typeof fetch = fetch,
    ) {}

    /**
     * @Name: authenticate
     * @Description: 调用 GEOFlow 机器凭证自检接口，获取账号、站点及 scope 上下文。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-13 16:38:47
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Return: Promise<GeoFlowAuthContext> 已验证业务上下文
     */
    public async authenticate(): Promise<GeoFlowAuthContext> {
        return await this.request<GeoFlowAuthContext>('GET', 'auth/me');
    }

    /**
     * @Name: getCatalog
     * @Description: 获取当前站点 GEO 任务目录数据。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-13 16:38:47
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Return: Promise<unknown> GEO 目录数据
     */
    public async getCatalog(): Promise<unknown> {
        return await this.request<unknown>('GET', 'catalog');
    }

    /**
     * @Name: listTasks
     * @Description: 分页查询当前站点任务并传递已验证的筛选参数。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-13 16:38:47
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Param: Record<string, string | number | undefined> query 任务查询参数
     * @Return: Promise<unknown> 任务分页数据
     */
    public async listTasks(query: Record<string, string | number | undefined>): Promise<unknown> {
        return await this.request<unknown>('GET', 'tasks', { query });
    }

    /**
     * @Name: getTask
     * @Description: 查询当前站点单个任务详情。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-13 16:38:47
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Param: number taskId 任务编号
     * @Return: Promise<unknown> 任务详情
     */
    public async getTask(taskId: number): Promise<unknown> {
        return await this.request<unknown>('GET', `tasks/${taskId}`);
    }

    /**
     * @Name: runTask
     * @Description: 通过既有 GEO API 为指定任务投递一次文章生成执行，费用由业务 Worker 按结果结算。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-13 16:38:47
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Param: number taskId 任务编号
     * @Param: string idempotencyKey 幂等键
     * @Return: Promise<unknown> 入队后的执行记录
     */
    public async runTask(taskId: number, idempotencyKey: string): Promise<unknown> {
        return await this.request<unknown>('POST', `tasks/${taskId}/enqueue`, {
            body: {
                job_type: 'generate_article',
                source: 'mcp',
            },
            idempotencyKey,
        });
    }

    /**
     * @Name: listTaskRuns
     * @Description: 查询指定任务的执行记录列表。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-13 16:38:47
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Param: number taskId 任务编号
     * @Param: Record<string, string | number | undefined> query 执行记录筛选参数
     * @Return: Promise<unknown> 执行记录列表
     */
    public async listTaskRuns(taskId: number, query: Record<string, string | number | undefined>): Promise<unknown> {
        return await this.request<unknown>('GET', `tasks/${taskId}/jobs`, { query });
    }

    /**
     * @Name: getTaskRun
     * @Description: 查询单次 GEO 任务执行的状态、结果和错误信息。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-13 16:38:47
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Param: number runId 执行记录编号
     * @Return: Promise<unknown> 单次执行详情
     */
    public async getTaskRun(runId: number): Promise<unknown> {
        return await this.request<unknown>('GET', `jobs/${runId}`);
    }

    /**
     * @Name: listArticles
     * @Description: 分页查询当前站点文章并传递已验证的筛选参数。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-13 16:38:47
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Param: Record<string, string | number | undefined> query 文章查询参数
     * @Return: Promise<unknown> 文章分页数据
     */
    public async listArticles(query: Record<string, string | number | undefined>): Promise<unknown> {
        return await this.request<unknown>('GET', 'articles', { query });
    }

    /**
     * @Name: getArticle
     * @Description: 查询当前站点单篇文章完整内容。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-13 16:38:47
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Param: number articleId 文章编号
     * @Return: Promise<unknown> 文章详情
     */
    public async getArticle(articleId: number): Promise<unknown> {
        return await this.request<unknown>('GET', `articles/${articleId}`);
    }

    /**
     * @Name: request
     * @Description: 统一发送 GEO API 请求，负责 Bearer 鉴权、请求编号、超时、幂等头和标准错误信封解析。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-13 16:38:47
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Param: string method HTTP 请求方法
     * @Param: string path API 相对路径
     * @Param: RequestOptions options 查询、请求体和幂等配置
     * @Return: Promise<T> GEO API data 字段
     */
    private async request<T>(method: string, path: string, options: RequestOptions = {}): Promise<T> {
        const url = new URL(path.replace(/^\/+/, ''), this.baseUrl);
        for (const [key, value] of Object.entries(options.query ?? {})) {
            if (value !== undefined) {
                url.searchParams.set(key, String(value));
            }
        }

        const headers = new Headers({
            Accept: 'application/json',
            Authorization: `Bearer ${this.token}`,
            'X-Request-Id': randomUUID(),
            'User-Agent': 'GEOFlow-Business-MCP/1.0',
        });
        if (options.body !== undefined) {
            headers.set('Content-Type', 'application/json');
        }
        if (options.idempotencyKey) {
            headers.set('X-Idempotency-Key', options.idempotencyKey);
        }

        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), this.timeoutMs);

        try {
            const response = await this.fetcher(url, {
                method,
                headers,
                body: options.body === undefined ? undefined : JSON.stringify(options.body),
                signal: controller.signal,
            });
            const payload = await this.parseEnvelope<T>(response);
            if (!response.ok || payload.success !== true) {
                const errorPayload = payload as GeoFlowErrorEnvelope;
                throw new GeoFlowApiError(
                    errorPayload.error?.message || `GEOFlow API 请求失败，HTTP ${response.status}`,
                    response.status,
                    errorPayload.error?.code || 'geoflow_api_error',
                    errorPayload.error?.details,
                );
            }

            return payload.data;
        } catch (error) {
            if (error instanceof GeoFlowApiError) {
                throw error;
            }
            if (error instanceof Error && error.name === 'AbortError') {
                throw new GeoFlowApiError('GEOFlow API 请求超时', 504, 'geoflow_api_timeout');
            }

            throw new GeoFlowApiError('无法连接 GEOFlow API', 502, 'geoflow_api_unavailable');
        } finally {
            clearTimeout(timeout);
        }
    }

    /**
     * @Name: parseEnvelope
     * @Description: 使用 JSON 解析响应信封，拒绝空响应和非 JSON 响应，避免把上游页面内容返回给 MCP 客户端。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-13 16:38:47
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Param: Response response GEOFlow API 响应
     * @Return: Promise<GeoFlowSuccessEnvelope<T> | GeoFlowErrorEnvelope> 标准响应信封
     */
    private async parseEnvelope<T>(response: Response): Promise<GeoFlowSuccessEnvelope<T> | GeoFlowErrorEnvelope> {
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.toLowerCase().includes('application/json')) {
            throw new GeoFlowApiError('GEOFlow API 返回了非 JSON 响应', 502, 'invalid_geoflow_response');
        }

        const payload: unknown = await response.json();
        if (typeof payload !== 'object' || payload === null || !('success' in payload)) {
            throw new GeoFlowApiError('GEOFlow API 响应格式无效', 502, 'invalid_geoflow_response');
        }

        return payload as GeoFlowSuccessEnvelope<T> | GeoFlowErrorEnvelope;
    }
}
