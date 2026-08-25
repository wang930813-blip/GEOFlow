<?php

/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-13
 *
 * @Time: 16:38
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： StoreMcpKeyRequest.php
 *
 * @Description: 校验用户侧创建 GEO MCP Key 的名称、有效期和业务权限。
 */

namespace App\Http\Requests\Admin;

use App\Services\Mcp\McpKeyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMcpKeyRequest extends FormRequest
{
    /**
     * @Name: authorize
     *
     * @Description: 仅允许已登录后台管理员提交 MCP Key 创建请求，站点授权由后台中间件和业务服务继续校验。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 16:38:47
     *
     * @UpdateTime: 2026-07-13 16:38:47
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
     * @Description: 定义 MCP Key 创建参数规则，禁止提交首版未开放的 GEO 业务权限。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 16:38:47
     *
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Return: array<string, mixed> Laravel 验证规则
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'never_expires' => ['nullable', 'boolean'],
            'expires_at' => [Rule::excludeIf($this->boolean('never_expires')), 'nullable', 'date', 'after:now'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', 'distinct', Rule::in(McpKeyService::BUSINESS_SCOPES)],
        ];
    }

    /**
     * @Name: messages
     *
     * @Description: 返回用户可直接理解的中文验证提示，避免暴露内部参数实现。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 16:38:47
     *
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Return: array<string, string> 自定义验证消息
     */
    public function messages(): array
    {
        return [
            'name.required' => '请输入 MCP Key 名称',
            'name.max' => 'MCP Key 名称不能超过 120 个字符',
            'expires_at.after' => '过期时间必须晚于当前时间',
            'scopes.required' => '至少选择一项 GEO 业务权限',
            'scopes.min' => '至少选择一项 GEO 业务权限',
            'scopes.*.in' => '包含未开放的 GEO 业务权限',
        ];
    }
}
