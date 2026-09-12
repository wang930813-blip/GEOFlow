<?php

namespace App\Services\ProductCases;

use App\Models\BrandDiagnosisBrandMention;
use App\Models\BrandDiagnosisQuestion;
use App\Models\BrandDiagnosisResult;
use App\Models\BrandDiagnosisRun;
use App\Models\BrandDiagnosisSource;
use App\Models\ProductCase;
use App\Services\BrandDiagnosis\BrandDiagnosisMetricsCalculator;
use Illuminate\Support\Facades\DB;

class ProductCaseDemoDataService
{
    public const BILLING_MODE = 'product_case_seed';

    private const QUESTION_COUNT = 6;

    /**
     * Generate one isolated, repeatable diagnosis run for a product case.
     *
     * @param  array{industry:string,brand_name:string,region:string,title:string,summary:string,brand_introduction:string}  $source
     */
    public function seed(ProductCase $case, array $source, string $sourcePath): BrandDiagnosisRun
    {
        $siteId = (int) $case->site_id;
        $ownerAdminId = (int) $case->owner_admin_id;
        $brandName = trim((string) $case->company_name);

        return DB::transaction(function () use ($case, $source, $sourcePath, $siteId, $ownerAdminId, $brandName): BrandDiagnosisRun {
            $this->removePreviousSeedRuns($siteId, $ownerAdminId, $brandName);

            $now = now();
            $seed = $this->seedNumber($brandName);
            $completedAt = $now->copy()->subMinutes(15 + ($seed % 180));
            $profile = trim((string) ($source['brand_introduction'] ?? ''));
            if ($profile === '') {
                $profile = trim((string) ($source['summary'] ?? ''));
            }

            $questions = $this->questions($brandName, (string) $case->industry, (string) $case->region);
            $platforms = $this->demoPlatforms();
            $run = BrandDiagnosisRun::query()
                ->withoutGlobalScopes(['current_site', 'admin_owner'])
                ->create([
                    'site_id' => $siteId,
                    'owner_admin_id' => $ownerAdminId,
                    'admin_id' => $ownerAdminId,
                    'brand_name' => $brandName,
                    'brand_profile' => $profile,
                    'brand_profile_source' => 'case_library',
                    'brand_profile_model' => 'seeded_demo',
                    'brand_profile_status' => 'completed',
                    'brand_profile_meta' => [
                        'source' => basename($sourcePath),
                        'industry' => (string) $case->industry,
                        'region' => (string) $case->region,
                        'case_slug' => (string) $case->slug,
                    ],
                    'platforms' => $platforms,
                    'status' => 'completed',
                    'total_questions' => count($questions),
                    'completed_questions' => count($questions),
                    'failed_questions' => 0,
                    'billing_mode' => self::BILLING_MODE,
                    'points_cost' => 0,
                    'limit_bypassed' => true,
                    'limit_bypass_reason' => 'product_case_library_seed',
                    'usage_date' => $now->toDateString(),
                    'started_at' => $completedAt->copy()->subMinutes(10 + ($seed % 35)),
                    'completed_at' => $completedAt,
                ]);

            foreach ($questions as $questionIndex => $questionData) {
                $question = BrandDiagnosisQuestion::query()
                    ->withoutGlobalScopes(['current_site', 'admin_owner'])
                    ->create([
                        'site_id' => $siteId,
                        'owner_admin_id' => $ownerAdminId,
                        'run_id' => (int) $run->id,
                        'question' => $questionData['question'],
                        'question_type' => $questionData['type'],
                        'sort_order' => $questionIndex + 1,
                        'status' => 'completed',
                    ]);

                foreach ($platforms as $platformIndex => $platform) {
                    $this->seedResult(
                        $run,
                        $question,
                        $case,
                        $source,
                        $questionIndex,
                        $platformIndex,
                        $platform
                    );
                }
            }

            $run->refresh();
            app(BrandDiagnosisMetricsCalculator::class)->refreshRun($run);

            return $run->fresh();
        });
    }

    /**
     * @return list<array{question:string,type:string}>
     */
    private function questions(string $brandName, string $industry, string $region): array
    {
        $industry = trim($industry) !== '' ? trim($industry) : '所在行业';
        $region = trim($region) !== '' ? trim($region) : '目标地区';

        $templates = [
            ['question' => $brandName.'的品牌定位和主要服务是什么？', 'type' => 'brand_profile'],
            ['question' => $industry.'有哪些值得推荐的品牌？', 'type' => 'recommendation'],
            ['question' => $region.'如何选择可靠的'.$industry.'服务商？', 'type' => 'selection'],
            $this->questionData($brandName.'的产品或服务优势体现在哪些方面？', 'advantage'),
            $this->questionData($brandName.'与同行品牌相比有哪些特点？', 'comparison'),
            $this->questionData('如果需要'.$industry.'解决方案，'.$brandName.'是否值得考虑？', 'trust'),
            $this->questionData($brandName.'适合哪些客户或使用场景？', 'audience'),
            $this->questionData('选择'.$industry.'服务时应该重点关注哪些指标？', 'evaluation'),
            $this->questionData($region.'有哪些'.$industry.'服务趋势值得关注？', 'trend'),
            $this->questionData($industry.'品牌如何提升 AI 搜索推荐率？', 'ai_visibility'),
            $this->questionData($brandName.'在'.$region.'市场的交付优势是什么？', 'delivery'),
            $this->questionData('采购'.$industry.'方案时如何比较品牌实力？', 'procurement'),
            $this->questionData($brandName.'有哪些可验证的客户价值？', 'value'),
            $this->questionData($industry.'企业做 GEO 增长应优先优化哪些内容？', 'geo_growth'),
            $this->questionData($brandName.'与主流竞品相比有哪些差异化卖点？', 'differentiation'),
            $this->questionData($region.$industry.'服务商有哪些口碑表现？', 'reputation'),
            $this->questionData('AI 平台如何评价'.$brandName.'的产品能力？', 'ai_evaluation'),
        ];

        return array_slice($templates, 0, self::QUESTION_COUNT);
    }

    /**
     * @return array{question:string,type:string}
     */
    private function questionData(string $question, string $type): array
    {
        return ['question' => $question, 'type' => $type];
    }

    /**
     * @param  array{industry:string,brand_name:string,region:string,title:string,summary:string,brand_introduction:string}  $source
     */
    private function seedResult(
        BrandDiagnosisRun $run,
        BrandDiagnosisQuestion $question,
        ProductCase $case,
        array $source,
        int $questionIndex,
        int $platformIndex,
        string $platform
    ): void {
        $brandName = (string) $case->company_name;
        $seed = $this->variantSeed($brandName, $questionIndex, $platformIndex);
        $mentionRate = 60 + ($this->seedNumber($brandName) % 31);
        $mentioned = $questionIndex === 0
            || (
                ! ($questionIndex === 1 && $platformIndex === count($this->demoPlatforms()) - 1)
                && ($seed % 100) < $mentionRate
            );
        $rank = $mentioned
            ? $this->bounded($this->variantSeed($brandName, $questionIndex, $platformIndex, 1), 1, 5)
            : 0;
        $mentionCount = $mentioned
            ? $this->bounded($this->variantSeed($brandName, $questionIndex, $platformIndex, 2), 1, 4)
            : 0;
        $sentiment = $this->sentiment($this->variantSeed($brandName, $questionIndex, $platformIndex, 3));
        $sourceRows = $this->sourceRows($case, $questionIndex, $platformIndex);
        $answer = $this->answer($case, $source, $mentioned, $rank, $sentiment);

        $result = BrandDiagnosisResult::query()
            ->withoutGlobalScopes(['current_site', 'admin_owner'])
            ->create([
                'site_id' => (int) $case->site_id,
                'owner_admin_id' => (int) $case->owner_admin_id,
                'run_id' => (int) $run->id,
                'question_id' => (int) $question->id,
                'platform' => $platform,
                'answer' => $answer,
                'brand_mentioned' => $mentioned,
                'mention_count' => $mentionCount,
                'mention_rank' => $rank,
                'sentiment' => $sentiment,
                'status' => 'success',
                'raw_response' => [
                    'source' => 'product_case_seed',
                    'platform' => $platform,
                    'question_index' => $questionIndex + 1,
                    'brand_mentioned' => $mentioned,
                ],
                'meta' => [
                    'generated' => true,
                    'case_slug' => (string) $case->slug,
                    'seed' => $seed,
                ],
                'checked_at' => ($run->completed_at?->copy() ?? now())
                    ->subMinutes(($questionIndex * 4) + $platformIndex + ($seed % 8)),
            ]);

        foreach ($sourceRows as $sourceRow) {
            BrandDiagnosisSource::query()
                ->withoutGlobalScopes(['current_site', 'admin_owner'])
                ->create([
                    'site_id' => (int) $case->site_id,
                    'owner_admin_id' => (int) $case->owner_admin_id,
                    'run_id' => (int) $run->id,
                    'question_id' => (int) $question->id,
                    'result_id' => (int) $result->id,
                    'platform' => $platform,
                    'title' => $sourceRow['title'],
                    'url' => $sourceRow['url'],
                    'domain' => $sourceRow['domain'],
                    'source_type' => $sourceRow['source_type'],
                    'meta' => ['generated' => true],
                ]);
        }

        if ($mentioned) {
            BrandDiagnosisBrandMention::query()
                ->withoutGlobalScopes(['current_site', 'admin_owner'])
                ->create([
                    'site_id' => (int) $case->site_id,
                    'owner_admin_id' => (int) $case->owner_admin_id,
                    'run_id' => (int) $run->id,
                    'question_id' => (int) $question->id,
                    'result_id' => (int) $result->id,
                    'platform' => $platform,
                    'brand_name' => $brandName,
                    'mention_count' => $mentionCount,
                    'mention_rank' => $rank,
                    'sentiment' => $sentiment,
                    'source_count' => count($sourceRows),
                    'is_target_brand' => true,
                    'evidence' => '回答示例中明确提及'.$brandName,
                    'meta' => ['generated' => true],
                ]);
        }
    }

    private function removePreviousSeedRuns(int $siteId, int $ownerAdminId, string $brandName): void
    {
        $runs = BrandDiagnosisRun::query()
            ->withoutGlobalScopes(['current_site', 'admin_owner'])
            ->where('site_id', $siteId)
            ->where('owner_admin_id', $ownerAdminId)
            ->where('brand_name', $brandName)
            ->where('billing_mode', self::BILLING_MODE)
            ->get();

        foreach ($runs as $run) {
            $run->forceDelete();
        }
    }

    private function seedNumber(string $brandName): int
    {
        return (int) hexdec(substr(hash('sha256', $brandName), 0, 8));
    }

    private function sentiment(int $seed): string
    {
        return match ($seed % 100) {
            0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11 => 'negative',
            12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29 => 'neutral',
            default => 'positive',
        };
    }

    /**
     * @return list<array{title:string,url:string,domain:string,source_type:string}>
     */
    private function sourceRows(ProductCase $case, int $questionIndex, int $platformIndex): array
    {
        $brandName = (string) $case->company_name;
        $catalog = [
            ['title' => $brandName.'品牌资料', 'domain' => 'brand-profile.example.com'],
            ['title' => $brandName.'产品信息', 'domain' => 'product-guides.example.com'],
            ['title' => $brandName.'服务介绍', 'domain' => 'service-directory.example.com'],
            ['title' => $brandName.'客户案例', 'domain' => 'customer-stories.example.com'],
            ['title' => $brandName.'公开信息', 'domain' => 'public-info.example.com'],
            ['title' => $brandName.'市场资料', 'domain' => 'market-research.example.com'],
            ['title' => $brandName.'行业榜单', 'domain' => 'industry-ranking.example.com'],
            ['title' => $brandName.'采购指南', 'domain' => 'buying-guide.example.com'],
            ['title' => $brandName.'口碑评价', 'domain' => 'reviews.example.com'],
            ['title' => $brandName.'技术资料', 'domain' => 'technical-docs.example.com'],
        ];
        $poolSize = 6 + ($this->seedNumber($brandName) % 5);
        $catalog = array_slice($catalog, 0, $poolSize);
        $rowSeed = $this->variantSeed($brandName, $questionIndex, $platformIndex, 20);
        $count = 2 + ($rowSeed % min(5, $poolSize));
        $start = $this->variantSeed($brandName, $questionIndex, $platformIndex, 21) % $poolSize;
        $rows = [];

        for ($index = 0; $index < $count; $index++) {
            $item = $catalog[($start + $index) % $poolSize];
            $rows[] = [
                'title' => (string) $item['title'],
                'url' => 'https://'.$item['domain'].'/cases/'.rawurlencode((string) $case->slug).'/'.$questionIndex.'/'.$platformIndex.'/'.$index,
                'domain' => (string) $item['domain'],
                'source_type' => 'case_demo',
            ];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function demoPlatforms(): array
    {
        return [
            'chatgpt',
            'gemini',
            'claude',
            'grok',
            'deepseek',
            'doubao',
            'qianwen',
            'wenxin',
            'yuanbao',
        ];
    }

    /**
     * @param  array{summary:string}  $source
     */
    private function answer(
        ProductCase $case,
        array $source,
        bool $mentioned,
        int $rank,
        string $sentiment
    ): string {
        if (! $mentioned) {
            $industry = trim((string) $case->industry) ?: '相关行业';
            $region = trim((string) $case->region);
            $focus = $region !== '' ? $region.'的'.$industry : $industry;

            return '该模型回答聚焦于'.$focus.'的通用信息与选择建议，当前未将具体品牌列为重点推荐对象。';
        }

        $brandName = (string) $case->company_name;
        $summary = trim((string) ($source['summary'] ?? ''));
        $summary = $summary !== ''
            ? $summary
            : '品牌在产品定位、服务体验和客户交付方面形成了较清晰的价值表达';

        return sprintf(
            '这是关于%s的示例回答。%s 在该问题中，%s被模型提及并作为重点参考对象，品牌表现排名第%d位，整体呈%s倾向。',
            $brandName,
            $summary,
            $brandName,
            $rank,
            $this->sentimentLabel($sentiment)
        );
    }

    private function sentimentLabel(string $sentiment): string
    {
        return match ($sentiment) {
            'positive' => '正向',
            'negative' => '谨慎',
            default => '中性',
        };
    }

    private function variantSeed(string $brandName, int $questionIndex, int $platformIndex, int $salt = 0): int
    {
        return (int) hexdec(substr(
            hash('sha256', $brandName.'|'.$questionIndex.'|'.$platformIndex.'|'.$salt),
            0,
            8
        ));
    }

    private function bounded(int $seed, int $min, int $max): int
    {
        return $min + ($seed % ($max - $min + 1));
    }
}
