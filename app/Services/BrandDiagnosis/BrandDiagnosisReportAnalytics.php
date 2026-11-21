<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-11-20
 *
 * @Time: 17:55:45
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： BrandDiagnosisReportAnalytics.php
 *
 * @Description: 按品牌诊断报告口径聚合推荐位次、问题维度、竞品和信源平台表现。
 */

namespace App\Services\BrandDiagnosis;

use App\Models\BrandDiagnosisResult;
use App\Models\BrandDiagnosisRun;
use Illuminate\Support\Collection;

final class BrandDiagnosisReportAnalytics
{
    private const SOURCE_PLATFORMS = [
        'zhihu.com' => ['知乎', 'UGC'],
        'xiaohongshu.com' => ['小红书', 'UGC'],
        'douyin.com' => ['抖音', 'UGC'],
        'bilibili.com' => ['B站', 'UGC'],
        'baijiahao.baidu.com' => ['百家号', '媒体'],
        'sohu.com' => ['搜狐号', '媒体'],
        '163.com' => ['网易号', '媒体'],
        'qq.com' => ['腾讯内容', '媒体'],
        'toutiao.com' => ['今日头条', '媒体'],
        'smzdm.com' => ['什么值得买', 'UGC'],
        'baike.baidu.com' => ['百度百科', '百科'],
        'baike.sogou.com' => ['搜狗百科', '百科'],
        'baike.com' => ['互动百科', '百科'],
        'wikipedia.org' => ['维基百科', '百科'],
        'gmw.cn' => ['光明网', '媒体'],
        'dahenews.cn' => ['大河网', '媒体'],
        'jd.com' => ['京东', '电商'],
        'taobao.com' => ['淘宝', '电商'],
        'tmall.com' => ['天猫', '电商'],
        'qcc.com' => ['企查查', '企业目录'],
        'tianyancha.com' => ['天眼查', '企业目录'],
        'qixin.com' => ['启信宝', '企业目录'],
        'jobui.com' => ['职友集', '招聘目录'],
        'zhipin.com' => ['BOSS直聘', '招聘目录'],
        'liepin.com' => ['猎聘', '招聘目录'],
        'maigoo.com' => ['买购网', '行业目录'],
    ];

    public function __construct(
        private readonly BrandEntityResolver $brandEntityResolver,
        private readonly BrandDiagnosisQuestionLabeler $questionLabeler,
    ) {}

    /**
     * @Name: summarize
     *
     * @Description: 对成功回答按诊断模型筛选，并计算总体、问题维度、竞品及信源平台指标；所有百分比均以成功回答或信源出现次数为分母。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-20 17:55:45
     *
     * @UpdateTime: 2026-11-21 09:27:35
     *
     * @Param: BrandDiagnosisRun $run 已预载问题、结果、品牌提及和引用来源的诊断记录
     *
     * @Param: string|null $platform 指定诊断模型；为空时统计全部模型
     *
     * @Param: int|null $questionId 指定诊断问题；为空时统计全部问题
     *
     * @Return: array<string,mixed> 报告分析指标和分组明细
     *
     * @Throws: 无
     */
    public function summarize(BrandDiagnosisRun $run, ?string $platform = null, ?int $questionId = null): array
    {
        $platform = $platform !== null ? $this->platformKey($platform) : null;
        $samples = $run->questions
            ->when($questionId !== null, fn (Collection $questions): Collection => $questions
                ->filter(fn ($question): bool => (int) $question->id === $questionId))
            ->flatMap(function ($question) use ($platform): Collection {
                return $question->results
                    ->filter(static fn (BrandDiagnosisResult $result): bool => (string) $result->status === 'success')
                    ->when($platform !== null, fn (Collection $results): Collection => $results
                        ->filter(fn (BrandDiagnosisResult $result): bool => $this->platformKey((string) $result->platform) === $platform))
                    ->map(fn (BrandDiagnosisResult $result): array => $this->sample($question, $result));
            })
            ->values();

        return [
            'metrics' => $this->sampleMetrics($samples),
            'dimensions' => $this->dimensionRows($samples),
            'competitors' => $this->competitorRows($samples),
            'source_platforms' => $this->sourcePlatformRows($samples),
            'source_occurrences' => $samples->sum(static fn (array $sample): int => count($sample['source_domains'])),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sample(object $question, BrandDiagnosisResult $result): array
    {
        $targetMentions = $result->brandMentions->where('is_target_brand', true);
        $targetRanks = $targetMentions
            ->pluck('mention_rank')
            ->map(static fn (mixed $rank): int => (int) $rank)
            ->filter(static fn (int $rank): bool => $rank > 0);
        $resultRank = (int) $result->mention_rank;
        $rank = $targetRanks->isNotEmpty() ? (int) $targetRanks->min() : ($resultRank > 0 ? $resultRank : null);
        $sourceDomains = $result->sources
            ->map(fn ($source): string => $this->sourceDomain((string) $source->domain, (string) $source->url))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'result_id' => (int) $result->id,
            'question_id' => (int) $question->id,
            'dimension' => $this->questionLabeler->questionType(
                (string) $question->question,
                (string) $question->question_type
            ) ?: '未分类',
            'platform' => (string) $result->platform,
            'target_mentioned' => $targetMentions->isNotEmpty() || (bool) $result->brand_mentioned,
            'target_rank' => $rank,
            'competitors' => $this->sampleCompetitors($result),
            'source_domains' => $sourceDomains,
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $samples
     * @return array<string,int|float>
     */
    private function sampleMetrics(Collection $samples): array
    {
        $answers = $samples->count();
        $mentioned = $samples->where('target_mentioned', true)->count();
        $ranked = $samples->filter(static fn (array $sample): bool => (int) ($sample['target_rank'] ?? 0) > 0);
        $top1 = $samples->filter(static fn (array $sample): bool => (int) ($sample['target_rank'] ?? 0) === 1)->count();
        $top3 = $samples->filter(static fn (array $sample): bool => (int) ($sample['target_rank'] ?? 0) > 0 && (int) $sample['target_rank'] <= 3)->count();
        $top5 = $samples->filter(static fn (array $sample): bool => (int) ($sample['target_rank'] ?? 0) > 0 && (int) $sample['target_rank'] <= 5)->count();

        return [
            'answer_count' => $answers,
            'mention_count' => $mentioned,
            'mention_rate' => $this->rate($mentioned, $answers),
            'recommendation_count' => $ranked->count(),
            'recommendation_rate' => $this->rate($ranked->count(), $answers),
            'top1_count' => $top1,
            'top1_rate' => $this->rate($top1, $answers),
            'top3_count' => $top3,
            'top3_rate' => $this->rate($top3, $answers),
            'top5_count' => $top5,
            'top5_rate' => $this->rate($top5, $answers),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $samples
     * @return list<array<string,int|float|string|null>>
     */
    private function dimensionRows(Collection $samples): array
    {
        return $samples
            ->groupBy('dimension')
            ->map(function (Collection $dimensionSamples, string $dimension): array {
                $metrics = $this->sampleMetrics($dimensionSamples);
                $ranked = $dimensionSamples
                    ->pluck('target_rank')
                    ->map(static fn (mixed $rank): int => (int) $rank)
                    ->filter(static fn (int $rank): bool => $rank > 0);
                $sourceOccurrences = $dimensionSamples->sum(static fn (array $sample): int => count($sample['source_domains']));
                $targetSourceOccurrences = $dimensionSamples
                    ->filter(static fn (array $sample): bool => (bool) $sample['target_mentioned'])
                    ->sum(static fn (array $sample): int => count($sample['source_domains']));

                return [
                    'dimension' => $dimension,
                    'question_count' => $dimensionSamples->pluck('question_id')->unique()->count(),
                    ...$metrics,
                    'average_rank' => $ranked->isNotEmpty() ? round($ranked->avg(), 1) : null,
                    'target_source_rate' => $this->rate($targetSourceOccurrences, $sourceOccurrences),
                ];
            })
            ->sortBy('mention_rate')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $samples
     * @return list<array<string,int|float|string|null>>
     */
    private function competitorRows(Collection $samples): array
    {
        $answers = max(1, $samples->count());
        $totalSourceOccurrences = $samples->sum(static fn (array $sample): int => count($sample['source_domains']));
        $rows = [];

        foreach ($samples as $sample) {
            foreach ($sample['competitors'] as $competitor) {
                $key = $competitor['key'];
                $rows[$key] ??= [
                    'brand' => $competitor['brand'],
                    'answer_count' => 0,
                    'recommendation_count' => 0,
                    'top1_count' => 0,
                    'top3_count' => 0,
                    'top5_count' => 0,
                    'mention_count' => 0,
                    'rank_sum' => 0,
                    'rank_count' => 0,
                    'source_occurrences' => 0,
                ];
                $rank = (int) $competitor['rank'];
                $rows[$key]['answer_count']++;
                $rows[$key]['mention_count'] += (int) $competitor['mention_count'];
                $rows[$key]['source_occurrences'] += count($sample['source_domains']);
                if ($rank > 0) {
                    $rows[$key]['recommendation_count']++;
                    $rows[$key]['rank_sum'] += $rank;
                    $rows[$key]['rank_count']++;
                    if ($rank === 1) {
                        $rows[$key]['top1_count']++;
                    }
                    if ($rank <= 3) {
                        $rows[$key]['top3_count']++;
                    }
                    if ($rank <= 5) {
                        $rows[$key]['top5_count']++;
                    }
                }
            }
        }

        return collect($rows)
            ->map(function (array $row) use ($answers, $totalSourceOccurrences): array {
                return [
                    ...$row,
                    'mention_rate' => $this->rate($row['answer_count'], $answers),
                    'recommendation_rate' => $this->rate($row['recommendation_count'], $answers),
                    'top1_rate' => $this->rate($row['top1_count'], $answers),
                    'top3_rate' => $this->rate($row['top3_count'], $answers),
                    'top5_rate' => $this->rate($row['top5_count'], $answers),
                    'average_rank' => $row['rank_count'] > 0 ? round($row['rank_sum'] / $row['rank_count'], 1) : null,
                    'source_share' => $this->rate($row['source_occurrences'], $totalSourceOccurrences),
                ];
            })
            ->sort(fn (array $left, array $right): int => ($right['answer_count'] <=> $left['answer_count']) ?: (($left['average_rank'] ?? 99) <=> ($right['average_rank'] ?? 99)))
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $samples
     * @return list<array<string,int|float|string|list<string>>>
     */
    private function sourcePlatformRows(Collection $samples): array
    {
        $rows = [];
        $totalOccurrences = 0;

        foreach ($samples as $sample) {
            foreach ($sample['source_domains'] as $domain) {
                [$name, $type] = $this->sourcePlatformLabel((string) $domain);
                $rows[$name] ??= [
                    'platform' => $name,
                    'type' => $type,
                    'count' => 0,
                    'target_count' => 0,
                    'domains' => [],
                    'models' => [],
                ];
                $rows[$name]['count']++;
                $rows[$name]['domains'][$domain] = true;
                $rows[$name]['models'][$sample['platform']] = true;
                if ($sample['target_mentioned']) {
                    $rows[$name]['target_count']++;
                }
                $totalOccurrences++;
            }
        }

        return collect($rows)
            ->map(function (array $row) use ($totalOccurrences): array {
                return [
                    'platform' => $row['platform'],
                    'type' => $row['type'],
                    'count' => $row['count'],
                    'share' => $this->rate($row['count'], $totalOccurrences),
                    'target_rate' => $this->rate($row['target_count'], $row['count']),
                    'domain_count' => count($row['domains']),
                    'models' => collect(array_keys($row['models']))
                        ->map(static fn (string $platform): string => BrandDiagnosisPlatform::label($platform))
                        ->sort()
                        ->values()
                        ->all(),
                ];
            })
            ->sort(function (array $left, array $right): int {
                if ($left['type'] === '其他' && $right['type'] !== '其他') {
                    return 1;
                }
                if ($right['type'] === '其他' && $left['type'] !== '其他') {
                    return -1;
                }

                return $right['count'] <=> $left['count'];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{key:string,brand:string,rank:int,mention_count:int}>
     */
    private function sampleCompetitors(BrandDiagnosisResult $result): array
    {
        return $result->brandMentions
            ->reject(static fn ($mention): bool => (bool) $mention->is_target_brand)
            ->filter(static fn ($mention): bool => trim((string) $mention->brand_name) !== '')
            ->groupBy(function ($mention): string {
                $meta = (array) $mention->meta;
                $key = trim((string) ($meta['canonical_key'] ?? ''));

                return mb_strtolower($key !== '' ? $key : $this->brandEntityResolver->canonicalKey((string) $mention->brand_name), 'UTF-8');
            })
            ->map(function (Collection $mentions, string $key): array {
                $first = $mentions->first();
                $rank = $mentions
                    ->pluck('mention_rank')
                    ->map(static fn (mixed $value): int => (int) $value)
                    ->filter(static fn (int $value): bool => $value > 0)
                    ->min();

                return [
                    'key' => $key,
                    'brand' => (string) data_get($first?->meta, 'canonical_name', $first?->brand_name ?? ''),
                    'rank' => (int) ($rank ?? 0),
                    'mention_count' => (int) $mentions->sum('mention_count'),
                ];
            })
            ->filter(static fn (array $competitor): bool => $competitor['key'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return array{0:string,1:string}
     */
    private function sourcePlatformLabel(string $domain): array
    {
        $domain = strtolower(ltrim(rtrim(trim($domain), '.'), '.'));
        if (str_starts_with($domain, 'www.')) {
            $domain = substr($domain, 4);
        }

        foreach (self::SOURCE_PLATFORMS as $suffix => $label) {
            if ($domain === $suffix || str_ends_with($domain, '.'.$suffix)) {
                return $label;
            }
        }

        return ['其他站点', '其他'];
    }

    private function platformKey(string $platform): string
    {
        return match (strtolower(trim($platform))) {
            'tencent_yuanbao' => 'yuanbao',
            'ernie' => 'wenxin',
            'tongyi' => 'qianwen',
            default => strtolower(trim($platform)),
        };
    }

    private function sourceDomain(string $domain, string $url): string
    {
        $domain = strtolower(trim($domain));
        if ($domain !== '') {
            return rtrim(str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain, '.');
        }

        $host = parse_url(trim($url), PHP_URL_HOST);
        if (! is_string($host)) {
            return '';
        }

        $host = strtolower($host);

        return rtrim(str_starts_with($host, 'www.') ? substr($host, 4) : $host, '.');
    }

    private function rate(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 1) : 0.0;
    }
}
