<?php

/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-24
 *
 * @Time: 12:01
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： ConfirmMcpBrandDiagnosisRequest.php
 *
 * @Description: 校验 MCP 确认品牌诊断问题并启动正式诊断时提交的问题列表。
 */

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmMcpBrandDiagnosisRequest extends FormRequest
{
    /**
     * @Name: authorize
     *
     * @Description: 业务授权由 Bearer Token、MCP 连接权限及品牌诊断写权限中间件统一完成。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-24 12:01:13
     *
     * @UpdateTime: 2026-07-24 12:01:13
     *
     * @Return: bool 始终允许进入参数校验流程
     *
     * @Throws: 无
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @Name: rules
     *
     * @Description: 允许省略问题列表以确认全部系统生成问题；传入列表时按问题编号更新、清空文本则删除该问题。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-24 12:01:13
     *
     * @UpdateTime: 2026-07-24 12:01:13
     *
     * @Return: array<string, array<int, mixed>> Laravel 参数校验规则
     *
     * @Throws: 无
     */
    public function rules(): array
    {
        return [
            'questions' => ['sometimes', 'array', 'min:1', 'max:50'],
            'questions.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'questions.*.question' => ['present', 'nullable', 'string', 'max:240'],
        ];
    }

    /**
     * @Name: messages
     *
     * @Description: 返回适合 MCP 客户端直接展示的中文问题确认错误信息。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-24 12:01:13
     *
     * @UpdateTime: 2026-07-24 12:01:13
     *
     * @Return: array<string, string> 中文参数错误信息
     *
     * @Throws: 无
     */
    public function messages(): array
    {
        return [
            'questions.min' => '问题列表不能为空',
            'questions.max' => '单次最多确认五十个问题',
            'questions.*.id.required' => '问题编号不能为空',
            'questions.*.id.distinct' => '问题编号不能重复',
            'questions.*.question.present' => '问题内容字段不能为空',
            'questions.*.question.max' => '单个问题不能超过 240 个字符',
        ];
    }
}
