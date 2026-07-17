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
}

/**
 * @Name: readPositiveInteger
 * @Description: 读取正整数环境变量，非法值立即终止启动，避免运行时出现隐式配置降级。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-13 16:38:47
 * @UpdateTime: 2026-07-13 16:38:47
 *
 * @Param: string name 环境变量名称
 * @Param: number fallback 未配置时的默认值
 * @Return: number 正整数配置值
 */
function readPositiveInteger(name: string, fallback: number): number {
    const rawValue = process.env[name]?.trim();
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
 * @Name: readApiBaseUrl
 * @Description: 校验 GEOFlow 内部 API 基础地址，仅允许 HTTP 或 HTTPS，并统一保留 /api/v1 路径前缀。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-13 16:38:47
 * @UpdateTime: 2026-07-13 16:38:47
 *
 * @Return: URL GEOFlow API 基础地址
 */
function readApiBaseUrl(): URL {
    const url = new URL(process.env.GEOFLOW_API_BASE_URL?.trim() || 'http://app:8080/api/v1/');
    if (!['http:', 'https:'].includes(url.protocol)) {
        throw new Error('GEOFLOW_API_BASE_URL 仅支持 HTTP 或 HTTPS');
    }

    url.pathname = `${url.pathname.replace(/\/+$/u, '')}/`;
    url.search = '';
    url.hash = '';

    return url;
}

/**
 * @Name: readAllowedHosts
 * @Description: 解析 Host 白名单并去重，用于防止对公开监听地址进行 DNS 重绑定攻击。
 *
 * @Author: cdkay
 * @CreateTime: 2026-07-13 16:38:47
 * @UpdateTime: 2026-07-13 16:38:47
 *
 * @Return: string[] 允许访问 MCP 服务的主机名列表
 */
function readAllowedHosts(): string[] {
    const values = (process.env.MCP_ALLOWED_HOSTS || 'localhost,127.0.0.1')
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
 * @UpdateTime: 2026-07-13 16:38:47
 *
 * @Return: ServerConfig MCP Server 运行配置
 */
export function loadConfig(): ServerConfig {
    return {
        host: process.env.MCP_HOST?.trim() || '0.0.0.0',
        port: readPositiveInteger('MCP_PORT', 3000),
        geoFlowApiBaseUrl: readApiBaseUrl(),
        apiTimeoutMs: readPositiveInteger('MCP_API_TIMEOUT_MS', 30_000),
        allowedHosts: readAllowedHosts(),
    };
}
