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
 * @File： StoreMcpBrandDiagnosisRequest.php
 *
 * @Description: 校验 MCP 创建用户侧品牌诊断任务所需的品牌、模型和问题复用参数。
 */

namespace App\Http\Requests\Api\V1;

use App\Services\BrandDiagnosis\BrandDiagnosisPlatform;
use Illuminate\Foundation\Http\FormRequest;

class StoreMcpBrandDiagnosisRequest extends FormRequest
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
     * @Description: 限制品牌词长度、诊断模型数量和问题复用开关，模型范围与现有品牌诊断模块保持一致。
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
            'brand_name' => ['required', 'string', 'max:120'],
            'models' => ['required', 'array', 'min:1', 'max:4'],
            'models.*' => ['required', 'string', 'distinct', BrandDiagnosisPlatform::validationRule()],
            'reuse_questions' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @Name: messages
     *
     * @Description: 返回适合 MCP 客户端直接展示的中文参数错误信息。
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
            'brand_name.required' => '品牌词不能为空',
            'models.required' => '请选择至少一个诊断模型',
            'models.min' => '请选择至少一个诊断模型',
            'models.max' => '单次最多选择四个诊断模型',
            'models.*.distinct' => '诊断模型不能重复',
            'models.*.in' => '诊断模型仅支持 doubao、deepseek、qianwen、wenxin',
        ];
    }
}
