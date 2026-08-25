<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-08-05
 *
 * @Time: 23:05:06
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： UpdateMcpKeyScopesRequest.php
 *
 * @Description: 校验用户侧修改已有 GEO MCP Key 业务权限的请求参数。
 */

namespace App\Http\Requests\Admin;

use App\Services\Mcp\McpKeyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMcpKeyScopesRequest extends FormRequest
{
    /**
     * @Name: authorize
     *
     * @Description: 仅允许已登录后台管理员修改 MCP Key 权限，账号与站点归属由业务服务继续校验。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-08-05 23:05:06
     *
     * @UpdateTime: 2026-08-05 23:05:06
     *
     * @Return: bool 是否允许请求
     */
    public function authorize(): bool
    {
        return auth('admin')->check();
    }

    /**
     * @Name: rules
     *
     * @Description: 定义 MCP Key 权限修改参数规则，只允许提交已开放的 GEO 业务权限。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-08-05 23:05:06
     *
     * @UpdateTime: 2026-08-05 23:05:06
     *
     * @Return: array<string, mixed> Laravel 验证规则
     */
    public function rules(): array
    {
        return [
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', 'distinct', Rule::in(McpKeyService::BUSINESS_SCOPES)],
        ];
    }

    /**
     * @Name: messages
     *
     * @Description: 返回修改 MCP Key 权限时的中文验证提示，避免把内部 scope 实现直接暴露给用户。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-08-05 23:05:06
     *
     * @UpdateTime: 2026-08-05 23:05:06
     *
     * @Return: array<string, string> 自定义验证消息
     */
    public function messages(): array
    {
        return [
            'scopes.required' => '至少选择一项 GEO 业务权限',
            'scopes.min' => '至少选择一项 GEO 业务权限',
            'scopes.*.in' => '包含未开放的 GEO 业务权限',
        ];
    }
}
