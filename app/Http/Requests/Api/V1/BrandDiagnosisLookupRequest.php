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
        'model_results',
        'sources',
        'snapshots',
        'competitors',
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
        ]);
    }

    public function rules(): array
    {
        return [
            'brand_word' => ['required', 'string', 'max:120'],
            'include' => ['nullable', 'string', 'max:200'],
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

    protected function failedValidation(Validator $validator): void
    {
        throw new ApiException('validation_failed', '请求参数校验失败', 422, [
            'errors' => $validator->errors()->toArray(),
        ]);
    }
}
