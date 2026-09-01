<?php

namespace App\Http\Requests\Api\V1;

use App\Services\BrandDiagnosis\BrandDiagnosisPlatform;
use Illuminate\Foundation\Http\FormRequest;

class StoreBrandDiagnosisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'brand_name' => ['required', 'string', 'max:120'],
            'models' => ['required', 'array', 'min:1', 'max:4'],
            'models.*' => ['string', BrandDiagnosisPlatform::publicValidationRule()],
        ];
    }

    public function messages(): array
    {
        return [
            'brand_name.required' => '品牌词不能为空',
            'models.required' => '请选择至少一个诊断模型',
            'models.*.in' => '诊断模型仅支持 ChatGPT、Grok、Gemini',
        ];
    }
}
