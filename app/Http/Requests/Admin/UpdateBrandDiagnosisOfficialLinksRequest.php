<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use App\Models\BrandDiagnosisResult;
use App\Services\BrandDiagnosis\BrandDiagnosisPlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateBrandDiagnosisOfficialLinksRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = $this->user('admin');

        return $admin instanceof Admin && $admin->isSuperAdmin();
    }

    /**
     * @return array<string,list<string>>
     */
    public function rules(): array
    {
        return [
            'official_links' => ['required', 'array', 'max:100'],
            'official_links.*' => ['nullable', 'string', 'max:2048', 'url:http,https'],
        ];
    }

    /**
     * @return array<string,string>
     */
    public function messages(): array
    {
        return [
            'official_links.required' => '请提交需要保存的官方链接。',
            'official_links.*.url' => '官方链接必须是有效的 HTTP 或 HTTPS 地址。',
            'official_links.*.max' => '官方链接不能超过 2048 个字符。',
        ];
    }

    /**
     * @return array<int,callable(Validator):void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $links = (array) $this->input('official_links', []);
            $resultIds = collect(array_keys($links))
                ->filter(static fn ($resultId): bool => ctype_digit((string) $resultId))
                ->map(static fn ($resultId): int => (int) $resultId)
                ->values();

            $results = BrandDiagnosisResult::query()
                ->withoutGlobalScopes()
                ->where('run_id', (int) $this->route('run'))
                ->whereIn('id', $resultIds)
                ->get(['id', 'platform'])
                ->keyBy('id');

            foreach ($links as $resultId => $url) {
                $url = trim((string) ($url ?? ''));
                $result = $results->get((int) $resultId);
                if ($url === '' || ! $result instanceof BrandDiagnosisResult) {
                    continue;
                }

                if (! BrandDiagnosisPlatform::isOfficialShareUrl((string) $result->platform, $url)) {
                    $domains = implode('、', BrandDiagnosisPlatform::officialShareDomains((string) $result->platform));
                    $validator->errors()->add(
                        'official_links.'.$resultId,
                        '该链接不是对应平台的官方域名，允许域名：'.$domains.'。'
                    );
                }
            }
        }];
    }
}
