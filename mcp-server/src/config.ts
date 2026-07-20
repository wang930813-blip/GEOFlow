/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-13
 * @Time: 16:38
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： config.ts
 * @Description: 解析并校验 GEO MCP Server 的监听地址、内部 API 地址和安全配置。
 */

export interface ServerConfig {
    host: string;
    port: number;
    geoFlowApiBaseUrl: URL;
    apiTimeoutMs: number;
    allowedHosts: string[];
    trustedProxies: string[];
    requireHttps: boolean;
    rateLimit: RateLimitConfig;
}

export interface RateLimitConfig {
    windowMs: number;
    ipMaxRequests: number;
    tokenMaxRequests: number;
}

/**
 * @Name: readPositiveInteger
 * @Description: 读取正整数环境变量，非法值立即终止启动，避免运行时出现隐式配置降级。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-13 16:38:47
 * @UpdateTime: 2026-07-18 15:37:25
 *
 * @Param: NodeJS.ProcessEnv environment 环境变量集合
 * @Param: string name 环境变量名称
 * @Param: number fallback 未配置时的默认值
 * @Return: number 正整数配置值
 */
function readPositiveInteger(environment: NodeJS.ProcessEnv, name: string, fallback: number): number {
    const rawValue = environment[name]?.trim();
    if (!rawValue) {
        return fallback;
    }

    const value = Number(rawValue);
    if (!Number.isInteger(value) || value <= 0) {
        throw new Error(`${name} 必须是正整数`);
    }

    return value;
}

/**
 * @Name: readBoolean
 * @Description: 严格读取布尔环境变量，仅接受 true 或 false，防止生产安全开关被拼写错误静默关闭。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-18 17:15:00
 * @UpdateTime: 2026-07-18 17:15:00
 *
 * @Param: NodeJS.ProcessEnv environment 环境变量集合
 * @Param: string name 环境变量名称
 * @Param: boolean fallback 未配置时的默认值
 * @Return: boolean 布尔配置值
 */
function readBoolean(environment: NodeJS.ProcessEnv, name: string, fallback: boolean): boolean {
    const rawValue = environment[name]?.trim().toLowerCase();
    if (!rawValue) {
        return fallback;
    }
    if (rawValue !== 'true' && rawValue !== 'false') {
        throw new Error(`${name} 必须是 true 或 false`);
    }

    return rawValue === 'true';
}

/**
 * @Name: readApiBaseUrl
 * @Description: 校验 GEOFlow 内部 API 基础地址，仅允许 HTTP 或 HTTPS，并统一保留 /api/v1 路径前缀。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-13 16:38:47
 * @UpdateTime: 2026-07-18 15:37:25
 *
 * @Param: NodeJS.ProcessEnv environment 环境变量集合
 * @Return: URL GEOFlow API 基础地址
 */
function readApiBaseUrl(environment: NodeJS.ProcessEnv): URL {
    const url = new URL(environment.GEOFLOW_API_BASE_URL?.trim() || 'http://app:8080/api/v1/');
    if (!['http:', 'https:'].includes(url.protocol)) {
        throw new Error('GEOFLOW_API_BASE_URL 仅支持 HTTP 或 HTTPS');
    }

    url.pathname = `${url.pathname.replace(/\/+$/u, '')}/`;
    url.search = '';
    url.hash = '';

    return url;
}

/**
 * @Name: readCommaSeparatedValues
 * @Description: 解析逗号分隔配置，统一去除空值、转换小写并去重，供 Host 白名单和可信代理配置复用。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-13 16:38:47
 * @UpdateTime: 2026-07-18 15:37:25
 *
 * @Param: string rawValue 逗号分隔原始值
 * @Return: string[] 已清洗并去重的配置列表
 */
function readCommaSeparatedValues(rawValue: string): string[] {
    const values = rawValue
        .split(',')
        .map((value) => value.trim().toLowerCase())
        .filter((value) => value !== '');

    return [...new Set(values)];
}

/**
 * @Name: loadConfig
 * @Description: 组装 MCP Server 运行配置，确保启动前完成全部环境校验。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-13 16:38:47
 * @UpdateTime: 2026-07-18 15:37:25
 *
 * @Param: NodeJS.ProcessEnv environment 环境变量集合
 * @Return: ServerConfig MCP Server 运行配置
 */
export function loadConfig(environment: NodeJS.ProcessEnv = process.env): ServerConfig {
    return {
        host: environment.MCP_HOST?.trim() || '0.0.0.0',
        port: readPositiveInteger(environment, 'MCP_PORT', 3000),
        geoFlowApiBaseUrl: readApiBaseUrl(environment),
        apiTimeoutMs: readPositiveInteger(environment, 'MCP_API_TIMEOUT_MS', 30_000),
        allowedHosts: readCommaSeparatedValues(environment.MCP_ALLOWED_HOSTS || 'localhost,127.0.0.1'),
        trustedProxies: readCommaSeparatedValues(environment.MCP_TRUSTED_PROXIES || ''),
        requireHttps: readBoolean(environment, 'MCP_REQUIRE_HTTPS', false),
        rateLimit: {
            windowMs: readPositiveInteger(environment, 'MCP_RATE_LIMIT_WINDOW_MS', 60_000),
            ipMaxRequests: readPositiveInteger(environment, 'MCP_RATE_LIMIT_IP_MAX', 120),
            tokenMaxRequests: readPositiveInteger(environment, 'MCP_RATE_LIMIT_TOKEN_MAX', 60),
        },
    };
}
