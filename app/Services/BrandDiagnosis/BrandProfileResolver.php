<?php

/**
 * Created by Codex.
 * @Date: 2026-11-23
 * @Time: 14:53:16
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： BrandProfileResolver.php
 * @Description: 联网核验品牌资料并提取品牌简介和官方网站地址。
 */

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
     * @Name: resolve
     *
     * @Description: 检索并整理品牌公开资料，将监测中心补充的品牌上下文作为检索线索。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-22 16:27:47
     *
     * @UpdateTime: 2026-11-23 14:53:16
     *
     * @Param: BrandDiagnosisRun $run 当前品牌诊断任务
     *
     * @Param: list<string> $platforms 本次可用于资料检索的平台
     *
     * @Return: array{profile:string,website_url:string,source:string,model:string,status:string,meta:array<string,mixed>} 品牌资料及检索元数据
     *
     * @Throws: RuntimeException 品牌名称为空
     */
    public function resolve(BrandDiagnosisRun $run, array $platforms = []): array
    {
        $brandName = trim((string) $run->brand_name);
        if ($brandName === '') {
            throw new RuntimeException('请输入品牌名称。');
        }

        try {
            $context = (array) data_get($run->brand_profile_meta, 'brand_context', []);

            return $this->resolveFromWebSearch($brandName, $platforms, $context);
        } catch (Throwable $exception) {
            return $this->unavailableProfile($brandName, $exception->getMessage());
        }
    }

    /**
     * @Name: resolveStrict
     *
     * @Description: 严格检索品牌资料并向调用方透传模型和验证异常，用于公开 API 错误映射。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-09-09 16:56:52
     *
     * @UpdateTime: 2026-11-23 14:53:16
     *
     * @Param: BrandDiagnosisRun $run 当前品牌诊断任务
     *
     * @Param: list<string> $platforms 本次可用于资料检索的平台
     *
     * @Return: array{profile:string,website_url:string,source:string,model:string,status:string,meta:array<string,mixed>} 品牌资料及检索元数据
     *
     * @Throws: BrandProfileNotFoundException 未检索到可用品牌资料
     */
    public function resolveStrict(BrandDiagnosisRun $run, array $platforms = []): array
    {
        $brandName = trim((string) $run->brand_name);
        if ($brandName === '') {
            throw new BrandProfileNotFoundException('品牌词不能为空');
        }

        $context = (array) data_get($run->brand_profile_meta, 'brand_context', []);

        return $this->resolveFromWebSearch($brandName, $platforms, $context);
    }

    /**
     * @Name: resolveFromWebSearch
     *
     * @Description: 逐个平台联网搜索品牌资料，并记录检索失败原因及公开来源。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-22 16:27:47
     *
     * @UpdateTime: 2026-11-23 14:53:16
     *
     * @Param: string $brandName 目标品牌名称
     *
     * @Param: list<string> $platforms 可用于联网检索的平台
     *
     * @Param: array<string,mixed> $context 用户补充的官网、简介、品牌词和竞品资料
     *
     * @Return: array{profile:string,website_url:string,source:string,model:string,status:string,meta:array<string,mixed>} 品牌资料及检索元数据
     *
     * @Throws: BrandProfileNotFoundException 所选平台均未能检索到可用品牌资料
     */
    private function resolveFromWebSearch(string $brandName, array $platforms, array $context = []): array
    {
        $attempts = [];

        foreach ($this->candidateSearchPlatforms($platforms) as $platform) {
            try {
                $response = $this->diagnosisClient->generateBrandProfileWithWebSearch($brandName, $platform, $context);
                $parsedProfile = $this->parseProfileText((string) ($response['text'] ?? ''));
                $profile = $parsedProfile['profile'];
                if (! $this->profileIsUsable($profile)) {
                    throw new BrandProfileNotFoundException('未检索到可用的品牌介绍。');
                }

                return [
                    'profile' => $profile,
                    'website_url' => $this->normalizeWebsiteUrl(
                        trim((string) ($context['website_url'] ?? '')) ?: $parsedProfile['website_url']
                    ),
                    'source' => 'web_search',
                    'model' => BrandDiagnosisPlatform::label($platform),
                    'status' => 'success',
                    'meta' => [
                        'platform' => $platform,
                        'sources' => (array) ($response['sources'] ?? []),
                        'raw_text' => mb_substr((string) ($response['text'] ?? ''), 0, 4000, 'UTF-8'),
                        'search_attempts' => $attempts,
                    ],
                ];
            } catch (Throwable $exception) {
                $attempts[] = [
                    'platform' => $platform,
                    'status' => 'failed',
                    'reason' => mb_substr(trim($exception->getMessage()), 0, 500, 'UTF-8'),
                ];
            }
        }

        $reasons = collect($attempts)
            ->map(fn (array $attempt): string => BrandDiagnosisPlatform::label((string) $attempt['platform']).'：'.(string) $attempt['reason'])
            ->implode('；');

        throw new BrandProfileNotFoundException($reasons !== '' ? '所选模型均未能检索品牌资料：'.$reasons : '未检索到可用的品牌介绍。');
    }

    /**
     * @param  list<string>  $platforms
     * @return list<string>
     */
    private function candidateSearchPlatforms(array $platforms): array
    {
        $selectedPlatforms = collect($platforms)
            ->map(static fn (string $platform): string => BrandDiagnosisPlatform::normalize($platform))
            ->filter(static fn (string $platform): bool => BrandDiagnosisPlatform::isSupported($platform))
            ->unique()
            ->values();

        return ($selectedPlatforms->isNotEmpty() ? $selectedPlatforms : collect([BrandDiagnosisPlatform::DOUBAO]))
            ->all();
    }

    /**
     * @return array{profile:string,website_url:string,source:string,model:string,status:string,meta:array<string,mixed>}
     */
    private function unavailableProfile(string $brandName, string $reason): array
    {
        return [
            'profile' => '未检索到可用品牌介绍。目标品牌：'.$brandName,
            'website_url' => '',
            'source' => 'unavailable',
            'model' => '',
            'status' => 'fallback',
            'meta' => [
                'reason' => $reason,
            ],
        ];
    }

    /**
     * @Name: parseProfileText
     * @Description: 解析联网模型返回的 JSON，清理品牌简介并提取经过格式校验的官方网站地址。
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-23 14:53:16
     * @UpdateTime: 2026-11-23 14:53:16
     *
     * @Param: string $text 模型返回的品牌资料文本
     * @Return: array{profile:string,website_url:string} 清理后的品牌简介和官方网站地址
     * @Throws: 无 非 JSON 文本按简介处理，无法校验的网址置空
     */
    private function parseProfileText(string $text): array
    {
        $text = $this->stripCodeFence($text);
        if ($text === '') {
            return ['profile' => '', 'website_url' => ''];
        }

        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            return [
                'profile' => $this->profileIsUsable($text) ? mb_substr($this->normalizeText($text), 0, 1600, 'UTF-8') : '',
                'website_url' => '',
            ];
        }

        $found = Arr::get($decoded, 'found', true);
        $summary = $this->normalizeText((string) (Arr::get($decoded, 'summary', Arr::get($decoded, 'profile', Arr::get($decoded, 'introduction', '')))));
        if ($found === false && $summary === '') {
            return ['profile' => '', 'website_url' => ''];
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

        return [
            'profile' => $this->profileIsUsable($profile) ? mb_substr($profile, 0, 1600, 'UTF-8') : '',
            'website_url' => $this->normalizeWebsiteUrl((string) Arr::get($decoded, 'official_website', Arr::get($decoded, 'website_url', ''))),
        ];
    }

    /**
     * @Name: normalizeWebsiteUrl
     * @Description: 仅允许绝对 HTTP 或 HTTPS 网站地址，拒绝凭证、脚本协议和无效主机名。
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-23 14:53:16
     * @UpdateTime: 2026-11-23 14:53:16
     *
     * @Param: string $websiteUrl 模型返回或用户提供的网站地址
     * @Return: string 可安全展示的规范化网站地址，无效时返回空字符串
     * @Throws: 无
     */
    private function normalizeWebsiteUrl(string $websiteUrl): string
    {
        $websiteUrl = trim(rtrim($websiteUrl, '.,;:)]}'));
        if ($websiteUrl === '') {
            return '';
        }

        if (! preg_match('~^https?://~i', $websiteUrl)) {
            $websiteUrl = 'https://'.$websiteUrl;
        }

        if (filter_var($websiteUrl, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        $parts = parse_url($websiteUrl);
        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return '';
        }

        return $websiteUrl;
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
