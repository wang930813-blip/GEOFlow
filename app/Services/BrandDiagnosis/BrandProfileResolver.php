<?php

namespace App\Services\BrandDiagnosis;

use App\Models\BrandDiagnosisRun;
use Illuminate\Support\Arr;
use RuntimeException;
use Throwable;

class BrandProfileResolver
{
    public function __construct(
        private readonly DoubaoBrandDiagnosisClient $diagnosisClient,
    ) {}

    /**
     * @param  list<string>  $platforms
     * @return array{profile:string,source:string,model:string,status:string,meta:array<string,mixed>}
     */
    public function resolve(BrandDiagnosisRun $run, array $platforms = []): array
    {
        $brandName = trim((string) $run->brand_name);
        if ($brandName === '') {
            throw new RuntimeException('请输入品牌名称。');
        }

        try {
            return $this->resolveFromWebSearch($brandName, $platforms);
        } catch (Throwable $exception) {
            return $this->unavailableProfile($brandName, $exception->getMessage());
        }
    }

    /**
     * @param  list<string>  $platforms
     * @return array{profile:string,source:string,model:string,status:string,meta:array<string,mixed>}
     */
    private function resolveFromWebSearch(string $brandName, array $platforms): array
    {
        $platform = $this->brandProfileSearchPlatform($platforms);
        $response = $this->diagnosisClient->generateBrandProfileWithWebSearch($brandName, $platform);
        $profile = $this->parseProfileText((string) ($response['text'] ?? ''));
        if (! $this->profileIsUsable($profile)) {
            throw new RuntimeException('未检索到可用的品牌介绍。');
        }

        return [
            'profile' => $profile,
            'source' => 'web_search',
            'model' => BrandDiagnosisPlatform::publicIsSupported($platform)
                ? BrandDiagnosisPlatform::publicLabel($platform)
                : BrandDiagnosisPlatform::label($platform),
            'status' => 'success',
            'meta' => [
                'platform' => $platform,
                'sources' => (array) ($response['sources'] ?? []),
                'raw_text' => mb_substr((string) ($response['text'] ?? ''), 0, 4000, 'UTF-8'),
            ],
        ];
    }

    private function brandProfileSearchPlatform(array $platforms): string
    {
        foreach ($platforms as $platform) {
            $platform = BrandDiagnosisPlatform::publicNormalize((string) $platform, '');
            if ($platform === '') {
                continue;
            }

            if ($this->platformEnabled($platform)) {
                return $platform;
            }
        }

        $defaultPlatform = BrandDiagnosisPlatform::publicNormalize(
            (string) config('brand_diagnosis.public_default_platform', BrandDiagnosisPlatform::CHATGPT),
            BrandDiagnosisPlatform::CHATGPT
        );

        if ($this->platformEnabled($defaultPlatform)) {
            return $defaultPlatform;
        }

        return BrandDiagnosisPlatform::DOUBAO;
    }

    private function platformEnabled(string $platform): bool
    {
        $platform = BrandDiagnosisPlatform::publicNormalize($platform, '');
        if ($platform === '') {
            return false;
        }

        return filter_var(config('brand_diagnosis.public_platforms.'.$platform.'.enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array{profile:string,source:string,model:string,status:string,meta:array<string,mixed>}
     */
    private function unavailableProfile(string $brandName, string $reason): array
    {
        return [
            'profile' => '未检索到可用品牌介绍。目标品牌：'.$brandName,
            'source' => 'unavailable',
            'model' => '',
            'status' => 'fallback',
            'meta' => [
                'reason' => $reason,
            ],
        ];
    }

    private function parseProfileText(string $text): string
    {
        $text = $this->stripCodeFence($text);
        if ($text === '') {
            return '';
        }

        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            return $this->profileIsUsable($text) ? mb_substr($this->normalizeText($text), 0, 1600, 'UTF-8') : '';
        }

        $found = Arr::get($decoded, 'found', true);
        $summary = $this->normalizeText((string) (Arr::get($decoded, 'summary', Arr::get($decoded, 'profile', Arr::get($decoded, 'introduction', '')))));
        if ($found === false && $summary === '') {
            return '';
        }

        $lines = [];
        if ($summary !== '') {
            $lines[] = $summary;
        }

        foreach ([
            'industry' => '行业',
            'brand_type' => '品牌类型',
            'audience' => '服务对象',
            'region' => '地域',
            'business' => '核心业务',
            'scenarios' => '典型场景',
            'competitors' => '竞品方向',
        ] as $key => $label) {
            $value = $this->stringifyProfileValue(Arr::get($decoded, $key));
            if ($value !== '') {
                $lines[] = $label.'：'.$value;
            }
        }

        $profile = $this->normalizeText(implode("\n", $lines));

        return $this->profileIsUsable($profile) ? mb_substr($profile, 0, 1600, 'UTF-8') : '';
    }

    private function stripCodeFence(string $text): string
    {
        $text = trim($text);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/su', $text, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }

        return $text;
    }

    private function stringifyProfileValue(mixed $value): string
    {
        if (is_array($value)) {
            $parts = collect($value)
                ->map(fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '')
                ->filter(static fn (string $item): bool => $item !== '')
                ->values()
                ->all();

            return implode('、', $parts);
        }

        return is_scalar($value) ? $this->normalizeText((string) $value) : '';
    }

    private function profileIsUsable(string $profile): bool
    {
        $profile = $this->normalizeText($profile);
        if (mb_strlen($profile, 'UTF-8') < 20) {
            return false;
        }

        return preg_match('/^(?:无法|不能|未能|没有|暂无|不清楚|不知道|未检索到|检索不到|无法判断)/u', $profile) !== 1;
    }

    private function normalizeText(string $text): string
    {
        $text = trim($text);
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
