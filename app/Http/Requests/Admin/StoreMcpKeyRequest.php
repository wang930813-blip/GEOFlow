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
            'expires_at' => ['nullable', 'date', 'after:now'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', 'distinct', Rule::in(McpKeyService::BUSINESS_SCOPES)],
            'mcp_max_unit_price' => [Rule::requiredIf($this->hasMediaSubmitScope()), 'nullable', 'numeric', 'min:0.01', 'max:999999.99'],
            'mcp_max_total_price' => [Rule::requiredIf($this->hasMediaSubmitScope()), 'nullable', 'numeric', 'min:0.01', 'max:9999999.99'],
            'mcp_daily_spend_limit' => [Rule::requiredIf($this->hasMediaSubmitScope()), 'nullable', 'numeric', 'min:0.01', 'max:9999999.99'],
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
            'mcp_max_unit_price.required' => '授予媒体投稿权限时必须设置单渠道消费上限',
            'mcp_max_total_price.required' => '授予媒体投稿权限时必须设置单次消费上限',
            'mcp_daily_spend_limit.required' => '授予媒体投稿权限时必须设置每日消费上限',
        ];
    }

    /**
     * @Name: hasMediaSubmitScope
     *
     * @Description: 判断当前创建请求是否包含媒体投稿权限，用于强制启用 Key 级消费策略。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 17:25:00
     *
     * @UpdateTime: 2026-07-18 17:25:00
     *
     * @Return: bool 是否包含媒体投稿权限
     */
    private function hasMediaSubmitScope(): bool
    {
        return in_array('media:submit', (array) $this->input('scopes', []), true);
    }
}
