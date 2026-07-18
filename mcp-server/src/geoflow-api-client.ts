/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-13
 * @Time: 16:38
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： geoflow-api-client.ts
 * @Description: 封装 GEOFlow REST API 鉴权、超时、错误映射、素材管理、文章管理、媒体渠道和投稿调用。
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

export interface GeoFlowArticleInput {
    title: string;
    content: string;
    category_id: number;
    author_id: number;
    excerpt?: string;
    keywords?: string[];
    meta_description?: string;
}

export interface GeoFlowArticleRecord extends Record<string, unknown> {
    id: number;
}

export interface GeoFlowMediaSubmissionResult extends Record<string, unknown> {
    submissions: unknown[];
    errors: unknown[];
}

export type GeoFlowMaterialType =
    'categories' | 'authors' | 'keyword-libraries' | 'title-libraries' | 'image-libraries' | 'knowledge-bases';

export type GeoFlowWritableMaterialItemType = 'keyword-libraries' | 'title-libraries' | 'image-libraries';

export interface GeoFlowArticlePublicationResult {
    article: GeoFlowArticleRecord;
    submissions: unknown[];
    errors: unknown[];
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
     * @Name: getMaterialSummary
     * @Description: 查询当前账号和站点下六类 GEO 素材的数量摘要。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-18 18:30:00
     * @UpdateTime: 2026-07-18 18:30:00
     *
     * @Return: Promise<unknown> 素材类型和数量摘要
     * @Throws GeoFlowApiError 权限或上游 API 调用失败
     */
    public async getMaterialSummary(): Promise<unknown> {
        return await this.request<unknown>('GET', 'materials');
    }

    /**
     * @Name: listMaterials
     * @Description: 分页查询指定类型的 GEO 素材，可按名称或描述关键词搜索。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-18 18:30:00
     * @UpdateTime: 2026-07-18 18:30:00
     *
     * @Param: GeoFlowMaterialType type 素材类型
     * @Param: Record<string, string | number | undefined> query 分页和搜索参数
     * @Return: Promise<unknown> 素材分页结果
     * @Throws GeoFlowApiError 素材类型、权限或上游 API 调用失败
     */
    public async listMaterials(
        type: GeoFlowMaterialType,
        query: Record<string, string | number | undefined>,
    ): Promise<unknown> {
        return await this.request<unknown>('GET', `materials/${encodeURIComponent(type)}`, { query });
    }

    /**
     * @Name: getMaterial
     * @Description: 查询当前账号和站点下的单个 GEO 素材详情。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-18 18:30:00
     * @UpdateTime: 2026-07-18 18:30:00
     *
     * @Param: GeoFlowMaterialType type 素材类型
     * @Param: number materialId 素材编号
     * @Return: Promise<unknown> 素材详情
     * @Throws GeoFlowApiError 素材不存在、权限不足或上游 API 调用失败
     */
    public async getMaterial(type: GeoFlowMaterialType, materialId: number): Promise<unknown> {
        return await this.request<unknown>('GET', `materials/${encodeURIComponent(type)}/${materialId}`);
    }

    /**
     * @Name: createMaterial
     * @Description: 创建分类、作者、关键词库、标题库、图片库或知识库。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-18 18:30:00
     * @UpdateTime: 2026-07-18 18:30:00
     *
     * @Param: GeoFlowMaterialType type 素材类型
     * @Param: Record<string, unknown> data 对应素材类型的字段
     * @Param: string idempotencyKey 幂等键
     * @Return: Promise<unknown> 已创建素材
     * @Throws GeoFlowApiError 参数、权限或上游 API 调用失败
     */
    public async createMaterial(
        type: GeoFlowMaterialType,
        data: Record<string, unknown>,
        idempotencyKey: string,
    ): Promise<unknown> {
        return await this.request<unknown>('POST', `materials/${encodeURIComponent(type)}`, {
            body: data,
            idempotencyKey,
        });
    }

    /**
     * @Name: updateMaterial
     * @Description: 更新当前账号和站点下的指定 GEO 素材。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-18 18:30:00
     * @UpdateTime: 2026-07-18 18:30:00
     *
     * @Param: GeoFlowMaterialType type 素材类型
     * @Param: number materialId 素材编号
     * @Param: Record<string, unknown> data 待更新字段
     * @Param: string idempotencyKey 幂等键
     * @Return: Promise<unknown> 更新后的素材
     * @Throws GeoFlowApiError 参数、权限、占用关系或上游 API 调用失败
     */
    public async updateMaterial(
        type: GeoFlowMaterialType,
        materialId: number,
        data: Record<string, unknown>,
        idempotencyKey: string,
    ): Promise<unknown> {
        return await this.request<unknown>('PATCH', `materials/${encodeURIComponent(type)}/${materialId}`, {
            body: data,
            idempotencyKey,
        });
    }

    /**
     * @Name: deleteMaterial
     * @Description: 删除当前账号和站点下未被业务数据占用的指定 GEO 素材。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-18 18:30:00
     * @UpdateTime: 2026-07-18 18:30:00
     *
     * @Param: GeoFlowMaterialType type 素材类型
     * @Param: number materialId 素材编号
     * @Param: string idempotencyKey 幂等键
     * @Return: Promise<unknown> 删除结果
     * @Throws GeoFlowApiError 素材不存在、仍被占用或权限不足
     */
    public async deleteMaterial(
        type: GeoFlowMaterialType,
        materialId: number,
        idempotencyKey: string,
    ): Promise<unknown> {
        return await this.request<unknown>('DELETE', `materials/${encodeURIComponent(type)}/${materialId}`, {
            idempotencyKey,
        });
    }

    /**
     * @Name: listMaterialItems
     * @Description: 分页查询关键词、标题、图片条目或知识库自动切块。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-18 18:30:00
     * @UpdateTime: 2026-07-18 18:30:00
     *
     * @Param: GeoFlowMaterialType type 素材库类型
     * @Param: number materialId 素材库编号
     * @Param: Record<string, number | undefined> query 分页参数
     * @Return: Promise<unknown> 素材条目分页结果
     * @Throws GeoFlowApiError 素材类型、素材库或权限校验失败
     */
    public async listMaterialItems(
        type: GeoFlowMaterialType,
        materialId: number,
        query: Record<string, number | undefined>,
    ): Promise<unknown> {
        return await this.request<unknown>('GET', `materials/${encodeURIComponent(type)}/${materialId}/items`, {
            query,
        });
    }

    /**
     * @Name: createMaterialItem
     * @Description: 向关键词库、标题库或图片库新增一个条目。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-18 18:30:00
     * @UpdateTime: 2026-07-18 18:30:00
     *
     * @Param: GeoFlowWritableMaterialItemType type 可写素材库类型
     * @Param: number materialId 素材库编号
     * @Param: Record<string, unknown> data 条目字段
     * @Param: string idempotencyKey 幂等键
     * @Return: Promise<unknown> 已创建素材条目
     * @Throws GeoFlowApiError 参数、重复条目或权限校验失败
     */
    public async createMaterialItem(
        type: GeoFlowWritableMaterialItemType,
        materialId: number,
        data: Record<string, unknown>,
        idempotencyKey: string,
    ): Promise<unknown> {
        return await this.request<unknown>('POST', `materials/${encodeURIComponent(type)}/${materialId}/items`, {
            body: data,
            idempotencyKey,
        });
    }

    /**
     * @Name: deleteMaterialItems
     * @Description: 从关键词库、标题库或图片库批量删除指定条目。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-18 18:30:00
     * @UpdateTime: 2026-07-18 18:30:00
     *
     * @Param: GeoFlowWritableMaterialItemType type 可写素材库类型
     * @Param: number materialId 素材库编号
     * @Param: number[] itemIds 待删除条目编号
     * @Param: string idempotencyKey 幂等键
     * @Return: Promise<unknown> 删除数量
     * @Throws GeoFlowApiError 素材库、条目或权限校验失败
     */
    public async deleteMaterialItems(
        type: GeoFlowWritableMaterialItemType,
        materialId: number,
        itemIds: number[],
        idempotencyKey: string,
    ): Promise<unknown> {
        return await this.request<unknown>('DELETE', `materials/${encodeURIComponent(type)}/${materialId}/items`, {
            body: { ids: itemIds },
            idempotencyKey,
        });
    }

    /**
     * @Name: createArticle
     * @Description: 将外部 AI 应用生成的最终文章保存到当前 GEO 账号和站点，固定标记为待审核的 AI 内容。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-18 13:58:43
     * @UpdateTime: 2026-07-18 13:58:43
     *
     * @Param: GeoFlowArticleInput input 已完成生成的文章内容、作者和分类
     * @Param: string idempotencyKey 幂等键
     * @Return: Promise<GeoFlowArticleRecord> 已创建文章
     * @Throws GeoFlowApiError 文章参数、权限或上游 API 调用失败
     */
    public async createArticle(input: GeoFlowArticleInput, idempotencyKey: string): Promise<GeoFlowArticleRecord> {
        return await this.request<GeoFlowArticleRecord>('POST', 'articles', {
            body: {
                ...input,
                status: 'draft',
                review_status: 'pending',
                is_ai_generated: 1,
            },
            idempotencyKey,
        });
    }

    /**
     * @Name: listMediaChannels
     * @Description: 查询当前站点可用于文章投稿的媒体渠道和实际销售价格。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-18 13:58:43
     * @UpdateTime: 2026-07-18 13:58:43
     *
     * @Param: Record<string, string | number | undefined> query 渠道分页和筛选参数
     * @Return: Promise<unknown> 媒体渠道分页结果
     * @Throws GeoFlowApiError 权限或上游 API 调用失败
     */
    public async listMediaChannels(query: Record<string, string | number | undefined>): Promise<unknown> {
        return await this.request<unknown>('GET', 'media/resources', { query });
    }

    /**
     * @Name: getMediaChannel
     * @Description: 查询单个媒体渠道的来源、类型、状态和当前站点实际销售价格。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-18 13:58:43
     * @UpdateTime: 2026-07-18 13:58:43
     *
     * @Param: number mediaResourceId 媒体资源编号
     * @Return: Promise<unknown> 媒体渠道详情
     * @Throws GeoFlowApiError 渠道不存在、权限不足或上游 API 调用失败
     */
    public async getMediaChannel(mediaResourceId: number): Promise<unknown> {
        return await this.request<unknown>('GET', `media/resources/${mediaResourceId}`);
    }

    /**
     * @Name: submitArticleToMedia
     * @Description: 将当前账号已有文章投递到一个或多个指定媒体渠道，费用由既有投稿服务逐渠道结算。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-18 13:58:43
     * @UpdateTime: 2026-07-18 15:37:25
     *
     * @Param: number articleId 当前账号文章编号
     * @Param: number[] mediaResourceIds 目标媒体资源编号
     * @Param: string remark 投稿备注
     * @Param: string idempotencyKey 幂等键
     * @Return: Promise<GeoFlowMediaSubmissionResult> 投稿订单和逐渠道错误
     * @Throws GeoFlowApiError 文章、渠道、余额、订阅或上游媒体接口校验失败
     */
    public async submitArticleToMedia(
        articleId: number,
        mediaResourceIds: number[],
        remark: string,
        idempotencyKey: string,
    ): Promise<GeoFlowMediaSubmissionResult> {
        return await this.request<GeoFlowMediaSubmissionResult>('POST', 'media/submissions', {
            body: {
                article_ids: [articleId],
                media_resource_ids: mediaResourceIds,
                remark,
            },
            idempotencyKey,
        });
    }

    /**
     * @Name: publishArticleToMedia
     * @Description: 先幂等创建外部 AI 编写的文章，再将同一文章投递到指定媒体渠道；投稿失败时保留文章供后续修正重投。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-18 13:58:43
     * @UpdateTime: 2026-07-18 15:37:25
     *
     * @Param: GeoFlowArticleInput input 已完成生成的文章内容、作者和分类
     * @Param: number[] mediaResourceIds 目标媒体资源编号
     * @Param: string remark 投稿备注
     * @Param: string idempotencyKey 整体操作幂等键
     * @Return: Promise<GeoFlowArticlePublicationResult> 文章、投稿订单和逐渠道错误
     * @Throws GeoFlowApiError 文章创建或媒体投稿失败
     */
    public async publishArticleToMedia(
        input: GeoFlowArticleInput,
        mediaResourceIds: number[],
        remark: string,
        idempotencyKey: string,
    ): Promise<GeoFlowArticlePublicationResult> {
        const article = await this.createArticle(input, `${idempotencyKey}:article`);

        try {
            const publication = await this.submitArticleToMedia(
                article.id,
                mediaResourceIds,
                remark,
                `${idempotencyKey}:submission`,
            );

            return {
                article,
                submissions: publication.submissions,
                errors: publication.errors,
            };
        } catch (error) {
            if (error instanceof GeoFlowApiError) {
                throw new GeoFlowApiError(error.message, error.status, error.code, {
                    article,
                    submission_error: error.details,
                });
            }

            throw error;
        }
    }

    /**
     * @Name: listMediaSubmissions
     * @Description: 查询当前账号在当前站点的媒体投稿订单，并触发可见订单状态同步。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-18 13:58:43
     * @UpdateTime: 2026-07-18 13:58:43
     *
     * @Param: Record<string, string | number | undefined> query 投稿分页和筛选参数
     * @Return: Promise<unknown> 媒体投稿分页结果
     * @Throws GeoFlowApiError 权限或上游 API 调用失败
     */
    public async listMediaSubmissions(query: Record<string, string | number | undefined>): Promise<unknown> {
        return await this.request<unknown>('GET', 'media/submissions', { query });
    }

    /**
     * @Name: getMediaSubmission
     * @Description: 查询单个媒体投稿订单并同步最新发布状态和发布链接。
     *
     * @Author: cdkay
     * @CreateTime: 2026-07-18 13:58:43
     * @UpdateTime: 2026-07-18 13:58:43
     *
     * @Param: number submissionId 媒体投稿订单编号
     * @Return: Promise<unknown> 投稿订单详情
     * @Throws GeoFlowApiError 订单不存在、权限不足或上游 API 调用失败
     */
    public async getMediaSubmission(submissionId: number): Promise<unknown> {
        return await this.request<unknown>('GET', `media/submissions/${submissionId}`);
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
