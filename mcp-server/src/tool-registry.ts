/**
 * Created by Codex.
 *
 * @Date: 2026-07-29
 * @Time: 15:41:12
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： tool-registry.ts
 * @Description: 按 MCP Key scope 动态注册 ceying-geo 品牌增长、诊断、素材、文章、视频、任务和媒体工具。
 */

import { createHash, randomUUID } from 'node:crypto';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import type { CallToolResult } from '@modelcontextprotocol/sdk/types.js';
import * as z from 'zod/v4';
import { GeoFlowApiClient, GeoFlowApiError, type GeoFlowAuthContext } from './geoflow-api-client.js';

const MCP_CONNECT_SCOPE = 'mcp:connect';
const articleInputSchema = {
    title: z.string().trim().min(1).max(255).describe('AI 已完成编写的文章标题'),
    content: z.string().trim().min(1).describe('AI 已完成编写的 Markdown 或 HTML 正文'),
    category_id: z.number().int().positive().describe('通过 geo_get_catalog 获取的分类编号'),
    author_id: z.number().int().positive().describe('通过 geo_get_catalog 获取的作者编号'),
    excerpt: z.string().trim().optional().describe('文章摘要，留空时由系统从正文提取'),
    keywords: z.array(z.string().trim().min(1)).optional().describe('文章关键词列表'),
    meta_description: z.string().trim().optional().describe('SEO 描述'),
};
const optionalIdempotencyKeySchema = z
    .string()
    .trim()
    .min(8)
    .max(96)
    .optional()
    .describe('可选幂等键，重试同一操作时保持一致');
const materialTypeSchema = z.enum([
    'categories',
    'authors',
    'keyword-libraries',
    'title-libraries',
    'image-libraries',
    'knowledge-bases',
]);
const materialItemTypeSchema = z.enum(['keyword-libraries', 'title-libraries', 'image-libraries', 'knowledge-bases']);
const writableMaterialItemTypeSchema = z.enum(['keyword-libraries', 'title-libraries', 'image-libraries']);
const materialDataSchema = z
    .record(z.string(), z.unknown())
    .describe(
        '素材字段。分类使用 name、slug、description、sort_order；作者使用 name、email、bio、avatar、website、social_links；普通素材库使用 name、description；知识库另需 content，可选 file_type、file_path。',
    );
const materialItemDataSchema = z
    .record(z.string(), z.unknown())
    .describe(
        '条目字段。关键词使用 keyword；标题使用 title，可选 keyword；图片使用 file_path，可选 filename、original_name、file_name、file_size、mime_type、width、height、tags。',
    );
const brandDiagnosisModelSchema = z.enum(['doubao', 'deepseek', 'qianwen', 'wenxin']);
const brandDiagnosisQuestionSchema = z.object({
    id: z.number().int().positive().describe('通过 geo_get_brand_diagnosis 获取的问题编号'),
    question: z.string().trim().max(240).describe('确认后的问题文本，空字符串表示删除该问题'),
});
const videoSourceSchema = z.enum(['pexels', 'pixabay', 'local']);
const videoAspectSchema = z.enum(['9:16', '16:9', '1:1']);
const videoInputSchema = {
    subject: z.string().trim().min(1).max(500).describe('视频主题，用于生成视频标题和任务主题'),
    script: z.string().trim().max(10000).optional().describe('视频脚本，留空时由上游视频服务按主题生成'),
    terms: z.string().trim().max(1000).optional().describe('视频素材关键词，多个关键词可用逗号或换行分隔'),
    negative_terms: z.string().trim().max(1000).optional().describe('排除关键词，避免生成不需要的视频素材'),
    video_source: videoSourceSchema.default('pexels').describe('视频素材来源'),
    video_aspect: videoAspectSchema.default('9:16').describe('视频比例，短视频默认使用 9:16'),
    video_count: z.number().int().min(1).max(5).default(1).describe('本次生成视频数量'),
    cover_image: z.string().trim().max(1000).optional().describe('可选封面图地址，只作为视频生成任务元数据'),
};

/**
 * @Name: normalizeIdempotencyPayload
 * @Description: 递归排序对象键，生成稳定的幂等摘要输入；数组保持原顺序，避免改变确认问题和渠道列表等有序业务语义。
 *
 * @Author: cdkay
 * @CreateTime: 2026-08-06 10:55:22
 * @UpdateTime: 2026-08-06 10:55:22
 *
 * @Param: unknown value 待规范化的工具参数
 * @Return: unknown 规范化后的幂等摘要输入
 */
function normalizeIdempotencyPayload(value: unknown): unknown {
    if (Array.isArray(value)) {
        return value.map((item) => normalizeIdempotencyPayload(item));
    }
    if (typeof value !== 'object' || value === null) {
        return value;
    }

    const normalized: Record<string, unknown> = {};
    for (const key of Object.keys(value).sort()) {
        const item = (value as Record<string, unknown>)[key];
        if (item !== undefined) {
            normalized[key] = normalizeIdempotencyPayload(item);
        }
    }

    return normalized;
}

/**
 * @Name: stableIdempotencyKey
 * @Description: 在调用方未传幂等键时，根据工具名和业务参数生成稳定幂等键，避免 AI 客户端自动重试导致重复创建文章或投稿订单。
 *
 * @Author: cdkay
 * @CreateTime: 2026-08-06 10:55:22
 * @UpdateTime: 2026-08-06 10:55:22
 *
 * @Param: string prefix 工具场景前缀
 * @Param: unknown payload 工具业务参数
 * @Return: string 满足 GEO API 字符集要求的稳定幂等键
 */
function stableIdempotencyKey(prefix: string, payload: unknown): string {
    const normalizedPayload = JSON.stringify(normalizeIdempotencyPayload(payload));
    const digest = createHash('sha256').update(`${prefix}:${normalizedPayload}`).digest('hex').slice(0, 48);

    return `${prefix}-${digest}`;
}

/**
 * @Name: resolveIdempotencyKey
 * @Description: 优先使用客户端显式幂等键；缺省时使用稳定派生键，保证同一工具参数重复调用可以命中 GEO API 幂等缓存。
 *
 * @Author: cdkay
 * @CreateTime: 2026-08-06 10:55:22
 * @UpdateTime: 2026-08-06 10:55:22
 *
 * @Param: string | undefined idempotencyKey 客户端传入幂等键
 * @Param: string prefix 工具场景前缀
 * @Param: unknown payload 工具业务参数
 * @Return: string 最终幂等键
 */
function resolveIdempotencyKey(idempotencyKey: string | undefined, prefix: string, payload: unknown): string {
    const key = idempotencyKey?.trim();

    return key && key.length > 0 ? key : stableIdempotencyKey(prefix, payload);
}

/**
 * @Name: hasScope
 * @Description: 判断 MCP Key 是否拥有指定业务 scope，兼容项目现有通配权限。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-13 16:38:47
 * @UpdateTime: 2026-07-18 13:58:43
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
 * @Description: 统一执行 ceying-geo 工具并将上游业务错误转换为 MCP 可处理错误结果，不泄露调用栈和密钥。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-13 16:38:47
 * @UpdateTime: 2026-08-05 09:57:26
 *
 * @Param: () => Promise<unknown> operation GEO API 调用
 * @Return: Promise<CallToolResult> MCP 工具结果
 */
async function executeTool(operation: () => Promise<unknown>): Promise<CallToolResult> {
    try {
        return successResult(await operation());
    } catch (error) {
        const apiError =
            error instanceof GeoFlowApiError
                ? error
                : new GeoFlowApiError('ceying-geo 工具执行失败', 500, 'geo_tool_error');

        const errorPayload = {
            code: apiError.code,
            message: apiError.message,
        };

        return {
            isError: true,
            content: [
                {
                    type: 'text',
                    text: JSON.stringify(errorPayload, null, 2),
                },
            ],
            structuredContent: {
                error: errorPayload,
            },
        };
    }
}

/**
 * @Name: createGeoMcpServer
 * @Description: 根据已验证 Key 的 scopes 创建无状态 ceying-geo MCP Server，仅注册当前凭证可调用的业务工具。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-13 16:38:47
 * @UpdateTime: 2026-08-06 12:09:02
 *
 * @Param: GeoFlowApiClient client 当前请求专用 GEO API 客户端
 * @Param: GeoFlowAuthContext context 已验证 GEO 账号和站点上下文
 * @Return: McpServer 已注册授权工具的 MCP Server
 */
export function createGeoMcpServer(client: GeoFlowApiClient, context: GeoFlowAuthContext): McpServer {
    assertMcpContext(context);
    const canSubmitToMedia = hasScope(context, 'media:read') && hasScope(context, 'media:submit');
    const canPublishToMedia =
        hasScope(context, 'catalog:read') && hasScope(context, 'articles:write') && canSubmitToMedia;
    const instructions = [
        '本服务正式名称为 ceying-geo，配套 Skill 角色为策影GEO品牌增长智能体。用户询问如何推广品牌或产品、竞争对手是谁、品牌定位是否有竞争力、为什么缺少曝光或 AI 推荐、应该建设哪些内容资产、如何选择传播渠道、如何提升行业影响力或评估推广效果时，即使没有提及 GEO、ceying-geo、策影 GEO、GEOFlow、geoflow 或 MCP，也应识别为可由本服务辅助的品牌增长需求。仅在存在明确品牌、产品、企业、市场或传播目标时进入该流程；纯学术营销问题、广告账户代投和无品牌目标的通用写作不自动执行本服务。保留 geo_* 工具名以兼容现有客户端，只调用当前 MCP Key 已授权并实际发现的工具，不要尝试调用工具列表中不存在的能力。先区分平台事实、用户事实、分析推断和行动建议，再在获得必要授权后执行。',
    ];
    if (hasScope(context, 'materials:read')) {
        instructions.push(
            '管理 ceying-geo 素材时先调用 geo_get_material_summary 确认可用类型，再按类型查询或操作；知识库条目由正文自动切块，仅允许读取。',
        );
    }
    if (canPublishToMedia) {
        instructions.push(
            '处理 AI 文章媒体投递时，先调用 geo_get_catalog 获取有效作者和分类，再调用 geo_list_media_channels 查询渠道编号与当前售价。由当前 AI 应用完成最终标题和正文后，优先调用 geo_publish_article_to_media 一次创建并投递。未获得明确媒体资源编号时禁止投稿；投稿工具会先查询同文章同渠道已有进行中或已生效订单，已有订单不重复提交；多渠道部分成功时只重投失败渠道；同一操作重试必须保持 idempotency_key 不变。',
        );
    }
    if (hasScope(context, 'articles:site-publish')) {
        instructions.push(
            '处理文章站内发布时，先调用 geo_get_article 核对文章属于当前站点且内容正确；用户确认后调用 geo_publish_article_to_site 发布文章。MCP 站内发布不需要人工审核，会自动将未被拒绝的文章标记为 auto_approved 并发布；站内发布不扣媒体投稿费用，不调用审核接口，不覆盖已被人工拒绝的文章。',
        );
    }
    if (hasScope(context, 'brand-diagnoses:read') || hasScope(context, 'brand-diagnoses:write')) {
        instructions.push(
            '处理品牌诊断时先创建任务，再查询任务直到 raw_status 为 questions_ready；当前只支持豆包、DeepSeek、千问和文心一言，不得声称已完成 ChatGPT 实测。确认问题会消耗一次品牌诊断额度，必须在用户确认后调用 geo_confirm_brand_diagnosis；启动后继续查询直到 completed 或 failed。AI 答案中的占位品牌不自动等同于完整市场竞争对手，评分、来源、排名和竞品数据只能依据工具实际返回结果。',
        );
    }
    if (hasScope(context, 'videos:read') || hasScope(context, 'videos:write')) {
        instructions.push(
            '处理视频增长内容时，先确认用户目标、主题、脚本和视频比例。创建视频会按 video_count 消耗视频生成额度；本 MCP 只负责生成和查询结果，不提供视频自媒体发布工具，不得声称已把视频发布到外部平台。',
        );
    }

    const server = new McpServer(
        {
            name: 'ceying-geo-mcp-server',
            title: `ceying-geo - ${context.site?.name || '当前站点'}`,
            version: '1.5.0',
        },
        {
            instructions: instructions.join(''),
        },
    );

    if (hasScope(context, 'catalog:read')) {
        server.registerTool(
            'geo_get_catalog',
            {
                title: '获取 ceying-geo 目录',
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
                title: '查询 ceying-geo 任务',
                description: '分页查询当前站点 ceying-geo 任务，可按状态和名称筛选。',
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
                title: '获取 ceying-geo 任务详情',
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
                description: '查询指定 ceying-geo 任务最近的执行记录，可按状态筛选。',
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
                title: '立即执行 ceying-geo 任务',
                description: '向当前站点已有任务投递一次文章生成执行。成功生成文章后按现有套餐规则扣减额度。',
                inputSchema: {
                    task_id: z.number().int().positive().describe('任务编号'),
                    idempotency_key: z
                        .string()
                        .trim()
                        .min(8)
                        .max(120)
                        .optional()
                        .describe('可选幂等键，重试同一操作时保持一致'),
                },
                annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false },
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
                description: '查询单次 ceying-geo 任务执行的状态、结果摘要和错误信息。',
                inputSchema: {
                    run_id: z.number().int().positive().describe('执行记录编号'),
                },
                annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true },
            },
            async ({ run_id }) => await executeTool(async () => await client.getTaskRun(run_id)),
        );
    }

    if (hasScope(context, 'brand-diagnoses:read')) {
        server.registerTool(
            'geo_list_brand_diagnoses',
            {
                title: '查询品牌诊断任务',
                description: '分页查询当前 MCP Key 所属账号和站点的品牌诊断任务，可按状态和品牌词筛选。',
                inputSchema: {
                    page: z.number().int().min(1).default(1).describe('页码'),
                    per_page: z.number().int().min(1).max(100).default(20).describe('每页数量'),
                    status: z.string().trim().min(1).max(32).optional().describe('任务原始状态'),
                    search: z.string().trim().min(1).max(120).optional().describe('品牌词关键词'),
                },
                annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true },
            },
            async ({ page, per_page, status, search }) =>
                await executeTool(async () => await client.listBrandDiagnoses({ page, per_page, status, search })),
        );

        server.registerTool(
            'geo_get_brand_diagnosis',
            {
                title: '获取品牌诊断详情',
                description: '查询单次品牌诊断的状态、待确认问题、模型回答、引用来源、品牌表现和排名。',
                inputSchema: {
                    run_id: z.number().int().positive().describe('品牌诊断任务编号'),
                },
                annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true },
            },
            async ({ run_id }) => await executeTool(async () => await client.getBrandDiagnosis(run_id)),
        );
    }

    if (hasScope(context, 'brand-diagnoses:write')) {
        server.registerTool(
            'geo_create_brand_diagnosis',
            {
                title: '创建品牌诊断任务',
                description: '创建品牌诊断问题生成任务。此阶段不扣额度，生成问题后需查询详情并由用户确认。',
                inputSchema: {
                    brand_name: z.string().trim().min(1).max(120).describe('需要诊断的品牌词'),
                    models: z
                        .array(brandDiagnosisModelSchema)
                        .min(1)
                        .max(4)
                        .describe('诊断模型列表，支持豆包、DeepSeek、千问和文心一言'),
                    reuse_questions: z.boolean().default(false).describe('是否复用当前账号同站点最近生成的问题'),
                    idempotency_key: optionalIdempotencyKeySchema,
                },
                annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false },
            },
            async ({ brand_name, models, reuse_questions, idempotency_key }) =>
                await executeTool(
                    async () =>
                        await client.createBrandDiagnosis(
                            { brand_name, models, reuse_questions },
                            idempotency_key || `mcp-brand-diagnosis-${randomUUID()}`,
                        ),
                ),
        );

        server.registerTool(
            'geo_confirm_brand_diagnosis',
            {
                title: '确认并启动品牌诊断',
                description:
                    '确认系统生成的问题并启动正式诊断。调用后按现有套餐规则扣减一次品牌诊断额度；省略问题列表表示全部保留。',
                inputSchema: {
                    run_id: z.number().int().positive().describe('处于 questions_ready 状态的品牌诊断任务编号'),
                    questions: z
                        .array(brandDiagnosisQuestionSchema)
                        .min(1)
                        .max(50)
                        .optional()
                        .describe('可选问题确认列表，省略时保留全部系统生成问题'),
                    idempotency_key: optionalIdempotencyKeySchema,
                },
                annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false },
            },
            async ({ run_id, questions, idempotency_key }) =>
                await executeTool(
                    async () =>
                        await client.confirmBrandDiagnosis(
                            run_id,
                            questions,
                            idempotency_key || `mcp-brand-diagnosis-confirm-${randomUUID()}`,
                        ),
                ),
        );
    }

    if (hasScope(context, 'videos:read')) {
        server.registerTool(
            'geo_list_videos',
            {
                title: '查询视频生成任务',
                description: '分页查询当前账号和站点的视频生成任务，可按状态、标题或主题筛选。',
                inputSchema: {
                    page: z.number().int().min(1).default(1).describe('页码'),
                    per_page: z.number().int().min(1).max(100).default(20).describe('每页数量'),
                    status: z.string().trim().min(1).max(32).optional().describe('视频生成任务状态'),
                    search: z.string().trim().min(1).max(120).optional().describe('视频标题或主题关键词'),
                },
                annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true },
            },
            async ({ page, per_page, status, search }) =>
                await executeTool(async () => await client.listVideos({ page, per_page, status, search })),
        );

        server.registerTool(
            'geo_get_video',
            {
                title: '获取视频生成详情',
                description: '查询单个视频生成任务的状态、进度、封面图和生成结果地址。',
                inputSchema: {
                    video_id: z.number().int().positive().describe('视频生成任务编号'),
                },
                annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true },
            },
            async ({ video_id }) => await executeTool(async () => await client.getVideo(video_id)),
        );
    }

    if (hasScope(context, 'videos:write')) {
        server.registerTool(
            'geo_create_video',
            {
                title: '创建视频生成任务',
                description:
                    '创建视频生成任务并投递异步生成队列。调用后按 video_count 扣减视频生成额度，需继续查询状态直到 success 或 failed。',
                inputSchema: {
                    ...videoInputSchema,
                    idempotency_key: optionalIdempotencyKeySchema,
                },
                annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false },
            },
            async ({ idempotency_key, ...video }) =>
                await executeTool(
                    async () => await client.createVideo(video, idempotency_key || `mcp-video-${randomUUID()}`),
                ),
        );
    }

    if (hasScope(context, 'materials:read')) {
        server.registerTool(
            'geo_get_material_summary',
            {
                title: '获取 ceying-geo 素材摘要',
                description: '查询分类、作者、关键词库、标题库、图片库、知识库六类素材的数量。',
                annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true },
            },
            async () => await executeTool(async () => await client.getMaterialSummary()),
        );

        server.registerTool(
            'geo_list_materials',
            {
                title: '查询 ceying-geo 素材',
                description: '分页查询指定类型素材，可按名称、描述、作者信息或分类字段搜索。',
                inputSchema: {
                    type: materialTypeSchema.describe('素材类型'),
                    page: z.number().int().min(1).default(1).describe('页码'),
                    per_page: z.number().int().min(1).max(100).default(20).describe('每页数量'),
                    search: z.string().trim().min(1).max(120).optional().describe('搜索关键词'),
                },
                annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true },
            },
            async ({ type, page, per_page, search }) =>
                await executeTool(async () => await client.listMaterials(type, { page, per_page, search })),
        );

        server.registerTool(
            'geo_get_material',
            {
                title: '获取 ceying-geo 素材详情',
                description: '查询当前站点指定类型和编号的素材详情。',
                inputSchema: {
                    type: materialTypeSchema.describe('素材类型'),
                    material_id: z.number().int().positive().describe('素材编号'),
                },
                annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true },
            },
            async ({ type, material_id }) => await executeTool(async () => await client.getMaterial(type, material_id)),
        );

        server.registerTool(
            'geo_list_material_items',
            {
                title: '查询 ceying-geo 素材条目',
                description: '分页查询关键词、标题、图片条目或知识库自动切块。分类和作者没有条目接口。',
                inputSchema: {
                    type: materialItemTypeSchema.describe('支持条目的素材库类型'),
                    material_id: z.number().int().positive().describe('素材库编号'),
                    page: z.number().int().min(1).default(1).describe('页码'),
                    per_page: z.number().int().min(1).max(100).default(20).describe('每页数量'),
                },
                annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true },
            },
            async ({ type, material_id, page, per_page }) =>
                await executeTool(async () => await client.listMaterialItems(type, material_id, { page, per_page })),
        );
    }

    if (hasScope(context, 'materials:write')) {
        server.registerTool(
            'geo_create_material',
            {
                title: '创建 ceying-geo 素材',
                description: '创建分类、作者、关键词库、标题库、图片库或知识库。知识库正文保存后会自动切块。',
                inputSchema: {
                    type: materialTypeSchema.describe('素材类型'),
                    data: materialDataSchema,
                    idempotency_key: optionalIdempotencyKeySchema,
                },
                annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false },
            },
            async ({ type, data, idempotency_key }) =>
                await executeTool(
                    async () =>
                        await client.createMaterial(type, data, idempotency_key || `mcp-material-${randomUUID()}`),
                ),
        );

        server.registerTool(
            'geo_update_material',
            {
                title: '更新 ceying-geo 素材',
                description: '更新指定素材的可写字段；知识库正文变化后会重新生成切块。',
                inputSchema: {
                    type: materialTypeSchema.describe('素材类型'),
                    material_id: z.number().int().positive().describe('素材编号'),
                    data: materialDataSchema,
                    idempotency_key: optionalIdempotencyKeySchema,
                },
                annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false },
            },
            async ({ type, material_id, data, idempotency_key }) =>
                await executeTool(
                    async () =>
                        await client.updateMaterial(
                            type,
                            material_id,
                            data,
                            idempotency_key || `mcp-material-update-${randomUUID()}`,
                        ),
                ),
        );

        server.registerTool(
            'geo_delete_material',
            {
                title: '删除 ceying-geo 素材',
                description: '删除指定素材；仍被文章、任务或其他素材引用时由 ceying-geo 拒绝删除。',
                inputSchema: {
                    type: materialTypeSchema.describe('素材类型'),
                    material_id: z.number().int().positive().describe('素材编号'),
                    idempotency_key: optionalIdempotencyKeySchema,
                },
                annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false },
            },
            async ({ type, material_id, idempotency_key }) =>
                await executeTool(
                    async () =>
                        await client.deleteMaterial(
                            type,
                            material_id,
                            idempotency_key || `mcp-material-delete-${randomUUID()}`,
                        ),
                ),
        );

        server.registerTool(
            'geo_create_material_item',
            {
                title: '新增 ceying-geo 素材条目',
                description: '向关键词库、标题库或图片库新增条目。知识库切块由正文自动生成，不能直接新增。',
                inputSchema: {
                    type: writableMaterialItemTypeSchema.describe('可写素材库类型'),
                    material_id: z.number().int().positive().describe('素材库编号'),
                    data: materialItemDataSchema,
                    idempotency_key: optionalIdempotencyKeySchema,
                },
                annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false },
            },
            async ({ type, material_id, data, idempotency_key }) =>
                await executeTool(
                    async () =>
                        await client.createMaterialItem(
                            type,
                            material_id,
                            data,
                            idempotency_key || `mcp-material-item-${randomUUID()}`,
                        ),
                ),
        );

        server.registerTool(
            'geo_delete_material_items',
            {
                title: '批量删除 ceying-geo 素材条目',
                description: '从关键词库、标题库或图片库批量删除指定条目。',
                inputSchema: {
                    type: writableMaterialItemTypeSchema.describe('可写素材库类型'),
                    material_id: z.number().int().positive().describe('素材库编号'),
                    item_ids: z.array(z.number().int().positive()).min(1).max(1000).describe('待删除条目编号列表'),
                    idempotency_key: optionalIdempotencyKeySchema,
                },
                annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false },
            },
            async ({ type, material_id, item_ids, idempotency_key }) =>
                await executeTool(
                    async () =>
                        await client.deleteMaterialItems(
                            type,
                            material_id,
                            item_ids,
                            idempotency_key || `mcp-material-items-delete-${randomUUID()}`,
                        ),
                ),
        );
    }

    if (hasScope(context, 'articles:read')) {
        server.registerTool(
            'geo_list_articles',
            {
                title: '查询 ceying-geo 文章',
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
                title: '获取 ceying-geo 文章详情',
                description: '查询当前站点单篇文章的正文、元数据、作者、分类和配图。',
                inputSchema: {
                    article_id: z.number().int().positive().describe('文章编号'),
                },
                annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true },
            },
            async ({ article_id }) => await executeTool(async () => await client.getArticle(article_id)),
        );
    }

    if (hasScope(context, 'articles:write')) {
        server.registerTool(
            'geo_create_article',
            {
                title: '保存 AI 编写的文章',
                description:
                    '保存外部 AI 应用已经完成编写的文章。MCP 上传文章会自动通过审核但不会公开发布；调用前必须先用 geo_get_catalog 取得当前站点有效的作者和分类编号。',
                inputSchema: {
                    ...articleInputSchema,
                    idempotency_key: optionalIdempotencyKeySchema,
                },
                annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false },
            },
            async ({ idempotency_key, ...article }) =>
                await executeTool(
                    async () =>
                        await client.createArticle(
                            article,
                            resolveIdempotencyKey(idempotency_key, 'mcp-article', article),
                        ),
                ),
        );
    }

    if (hasScope(context, 'articles:site-publish')) {
        server.registerTool(
            'geo_publish_article_to_site',
            {
                title: '发布文章到 GEO 用户站点',
                description:
                    '将当前账号未被拒绝的文章发布到绑定的 GEO 用户站点。MCP 站内发布会自动标记为通过，不需要人工审核，不执行媒体投稿。',
                inputSchema: {
                    article_id: z.number().int().positive().describe('当前账号未被拒绝的文章编号'),
                    idempotency_key: optionalIdempotencyKeySchema,
                },
                annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false },
            },
            async ({ article_id, idempotency_key }) =>
                await executeTool(
                    async () =>
                        await client.publishArticleToSite(
                            article_id,
                            resolveIdempotencyKey(idempotency_key, 'mcp-article-site-publish', { article_id }),
                        ),
                ),
        );
    }

    if (hasScope(context, 'media:read')) {
        server.registerTool(
            'geo_list_media_channels',
            {
                title: '查询文章投递渠道',
                description:
                    '分页查询可投稿媒体渠道，返回渠道编号、媒体名称、媒体类型、来源平台和当前站点实际售价。可按媒体名称或分类搜索。',
                inputSchema: {
                    page: z.number().int().min(1).default(1).describe('页码'),
                    per_page: z.number().int().min(1).max(100).default(50).describe('每页数量'),
                    platform_id: z.number().int().positive().optional().describe('来源平台编号'),
                    source_type: z.string().trim().min(1).max(32).optional().describe('媒体类型'),
                    search: z.string().trim().min(1).max(120).optional().describe('媒体名称、分类或备注关键词'),
                },
                annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true },
            },
            async ({ page, per_page, platform_id, source_type, search }) =>
                await executeTool(
                    async () => await client.listMediaChannels({ page, per_page, platform_id, source_type, search }),
                ),
        );

        server.registerTool(
            'geo_get_media_channel',
            {
                title: '获取文章投递渠道详情',
                description: '查询单个媒体渠道的状态、分类、来源平台、案例链接和当前站点实际售价。',
                inputSchema: {
                    media_resource_id: z.number().int().positive().describe('媒体资源编号'),
                },
                annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true },
            },
            async ({ media_resource_id }) =>
                await executeTool(async () => await client.getMediaChannel(media_resource_id)),
        );

        server.registerTool(
            'geo_list_media_submissions',
            {
                title: '查询媒体投稿记录',
                description: '分页查询当前账号的媒体投稿记录，并同步可见订单的最新状态和发布链接。',
                inputSchema: {
                    page: z.number().int().min(1).default(1).describe('页码'),
                    per_page: z.number().int().min(1).max(100).default(20).describe('每页数量'),
                    article_id: z.number().int().positive().optional().describe('文章编号'),
                    media_resource_id: z.number().int().positive().optional().describe('媒体资源编号'),
                    status: z.string().trim().min(1).max(32).optional().describe('投稿状态'),
                },
                annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true },
            },
            async ({ page, per_page, article_id, media_resource_id, status }) =>
                await executeTool(
                    async () =>
                        await client.listMediaSubmissions({
                            page,
                            per_page,
                            article_id,
                            media_resource_id,
                            status,
                        }),
                ),
        );

        server.registerTool(
            'geo_get_media_submission',
            {
                title: '获取媒体投稿详情',
                description: '查询单个媒体投稿订单，并同步最新状态、发布链接和失败原因。',
                inputSchema: {
                    submission_id: z.number().int().positive().describe('媒体投稿订单编号'),
                },
                annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true },
            },
            async ({ submission_id }) => await executeTool(async () => await client.getMediaSubmission(submission_id)),
        );
    }

    if (canSubmitToMedia) {
        server.registerTool(
            'geo_submit_article_to_media',
            {
                title: '将已有文章投递到媒体',
                description:
                    '将当前账号已有文章投递到一个或多个指定媒体渠道。投稿前自动查询同文章同渠道已有订单，已有进行中或已生效订单时不重复提交；每个新提交成功的渠道按查询结果中的实际售价扣费，提交失败自动退款。',
                inputSchema: {
                    article_id: z.number().int().positive().describe('当前账号已有文章编号'),
                    media_resource_ids: z
                        .array(z.number().int().positive())
                        .min(1)
                        .max(20)
                        .describe('目标媒体资源编号列表'),
                    remark: z.string().trim().max(1000).default('').describe('投稿备注'),
                    idempotency_key: optionalIdempotencyKeySchema,
                },
                annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false },
            },
            async ({ article_id, media_resource_ids, remark, idempotency_key }) =>
                await executeTool(
                    async () =>
                        await client.submitArticleToMedia(
                            article_id,
                            media_resource_ids,
                            remark,
                            resolveIdempotencyKey(idempotency_key, 'mcp-submission', {
                                article_id,
                                media_resource_ids,
                                remark,
                            }),
                        ),
                ),
        );
    }

    if (hasScope(context, 'articles:write') && canSubmitToMedia) {
        server.registerTool(
            'geo_publish_article_to_media',
            {
                title: '发布 AI 文章到指定媒体',
                description:
                    '面向 AI Agent 的完整发布工具：保存已经生成完成的文章，并立即投递到一个或多个指定媒体渠道。文章与投稿分别幂等，投稿前自动查询同文章同渠道已有订单，投稿失败时保留文章供修正后重投。',
                inputSchema: {
                    ...articleInputSchema,
                    media_resource_ids: z
                        .array(z.number().int().positive())
                        .min(1)
                        .max(20)
                        .describe('通过 geo_list_media_channels 选定的媒体资源编号列表'),
                    remark: z.string().trim().max(1000).default('').describe('投稿备注'),
                    idempotency_key: optionalIdempotencyKeySchema,
                },
                annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false },
            },
            async ({ media_resource_ids, remark, idempotency_key, ...article }) =>
                await executeTool(
                    async () =>
                        await client.publishArticleToMedia(
                            article,
                            media_resource_ids,
                            remark,
                            resolveIdempotencyKey(idempotency_key, 'mcp-publication', {
                                article,
                                media_resource_ids,
                                remark,
                            }),
                        ),
                ),
        );
    }

    return server;
}
