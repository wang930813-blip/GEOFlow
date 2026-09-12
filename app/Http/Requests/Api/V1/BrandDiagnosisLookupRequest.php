<?php

namespace App\Http\Requests\Api\V1;

use App\Exceptions\ApiException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class BrandDiagnosisLookupRequest extends FormRequest
{
    public const MODULES = [
        'profile',
        'questions',
        'performance',
        'rankings',
        'model_results',
        'sources',
        'snapshots',
        'competitors',
        'platform_analysis',
        'competitor_visibility',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'brand_word' => is_string($this->input('brand_word'))
                ? trim($this->input('brand_word'))
                : $this->input('brand_word'),
            'include' => is_string($this->input('include'))
                ? trim($this->input('include'))
                : $this->input('include'),
            'model' => is_string($this->input('model'))
                ? strtolower(trim($this->input('model')))
                : $this->input('model'),
        ]);
    }

    public function rules(): array
    {
        return [
            'brand_word' => ['required', 'string', 'max:120'],
            'include' => ['nullable', 'string', 'max:200'],
            'model' => ['nullable', 'string', 'max:40', 'in:all,doubao,deepseek,qianwen,wenxin'],
        ];
    }

    /**
     * @return list<string>
     */
    public function includedModules(): array
    {
        $raw = trim((string) $this->input('include', ''));
        if ($raw === '') {
            return self::MODULES;
        }

        $modules = collect(explode(',', $raw))
            ->map(static fn (string $module): string => trim($module))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($modules === [] || collect($modules)->diff(self::MODULES)->isNotEmpty()) {
            throw new ApiException('validation_failed', 'include 包含不支持的模块', 422, [
                'allowed' => self::MODULES,
            ]);
        }

        return $modules;
    }

    public function modelFilter(): ?string
    {
        $model = strtolower(trim((string) $this->input('model', '')));
        if ($model === '' || $model === 'all') {
            return null;
        }

        return $model;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new ApiException('validation_failed', '请求参数校验失败', 422, [
            'errors' => $validator->errors()->toArray(),
        ]);
    }
}
