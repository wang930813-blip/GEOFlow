<?php

namespace App\Services\ProductCases;

use App\Models\BrandDiagnosisBrandMention;
use App\Models\BrandDiagnosisQuestion;
use App\Models\BrandDiagnosisResult;
use App\Models\BrandDiagnosisRun;
use App\Models\ProductCase;
use App\Services\BrandDiagnosis\BrandDiagnosisMetricsCalculator;
use App\Services\BrandDiagnosis\BrandDiagnosisPlatform;
use Illuminate\Support\Facades\DB;

class ProductCaseDemoDataService
{
    public const BILLING_MODE = 'product_case_seed';

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
            $profile = trim((string) ($source['brand_introduction'] ?? ''));
            if ($profile === '') {
                $profile = trim((string) ($source['summary'] ?? ''));
            }

            $questions = $this->questions($brandName, (string) $case->industry, (string) $case->region);
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
                    'platforms' => BrandDiagnosisPlatform::keys(),
                    'status' => 'completed',
                    'total_questions' => count($questions),
                    'completed_questions' => count($questions),
                    'failed_questions' => 0,
                    'billing_mode' => self::BILLING_MODE,
                    'points_cost' => 0,
                    'limit_bypassed' => true,
                    'limit_bypass_reason' => 'product_case_library_seed',
                    'usage_date' => $now->toDateString(),
                    'started_at' => $now->copy()->subMinutes(12),
                    'completed_at' => $now,
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

                foreach (BrandDiagnosisPlatform::keys() as $platformIndex => $platform) {
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

        return [
            ['question' => $brandName.'的品牌定位和主要服务是什么？', 'type' => 'brand_profile'],
            ['question' => $industry.'有哪些值得推荐的品牌？', 'type' => 'recommendation'],
            ['question' => $region.'如何选择可靠的'.$industry.'服务商？', 'type' => 'selection'],
            $this->questionData($brandName.'的产品或服务优势体现在哪些方面？', 'advantage'),
            $this->questionData($brandName.'与同行品牌相比有哪些特点？', 'comparison'),
            $this->questionData('如果需要'.$industry.'解决方案，'.$brandName.'是否值得考虑？', 'trust'),
        ];
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
        $seed = $this->seedNumber($brandName);
        $rank = (($seed + ($questionIndex * 3) + ($platformIndex * 5)) % 5) + 1;
        $mentionCount = (($seed + $questionIndex + $platformIndex) % 3) + 1;
        $sentiment = $this->sentiment($seed + $questionIndex + $platformIndex);
        $competitor = $this->competitorName($brandName, $platformIndex);
        $questionText = (string) $question->question;
        $summary = trim((string) ($source['summary'] ?? ''));
        $answer = sprintf(
            "这是关于%s的示例回答。%d. %s：%s %s在该问题中被提及并作为重点参考对象。%d. %s：可作为同类服务的比较对象。",
            $questionText,
            $rank,
            $brandName,
            $summary !== '' ? $summary : '提供面向客户的专业产品与服务。',
            $brandName,
            min(5, $rank + 1),
            $competitor
        );

        $result = BrandDiagnosisResult::query()
            ->withoutGlobalScopes(['current_site', 'admin_owner'])
            ->create([
                'site_id' => (int) $case->site_id,
                'owner_admin_id' => (int) $case->owner_admin_id,
                'run_id' => (int) $run->id,
                'question_id' => (int) $question->id,
                'platform' => $platform,
                'answer' => $answer,
                'brand_mentioned' => true,
                'mention_count' => $mentionCount,
                'mention_rank' => $rank,
                'sentiment' => $sentiment,
                'status' => 'success',
                'raw_response' => [
                    'source' => 'product_case_seed',
                    'platform' => $platform,
                    'question_index' => $questionIndex + 1,
                ],
                'meta' => [
                    'generated' => true,
                    'case_slug' => (string) $case->slug,
                    'seed' => $seed,
                ],
                'checked_at' => now()->subMinutes(($questionIndex * 4) + $platformIndex),
            ]);

        $sourceRows = [
            [
                'title' => $brandName.'品牌资料示例',
                'url' => 'https://case-library.example.com/brands/'.rawurlencode((string) $case->slug).'/'.$platform,
                'domain' => 'case-library.example.com',
                'source_type' => 'case_demo',
            ],
            [
                'title' => $brandName.'行业信息示例',
                'url' => 'https://industry-data.example.com/'.rawurlencode((string) $case->slug).'/'.$platform,
                'domain' => 'industry-data.example.com',
                'source_type' => 'case_demo',
            ],
        ];
        foreach ($sourceRows as $sourceRow) {
            $sourceModel = \App\Models\BrandDiagnosisSource::query()
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

            unset($sourceModel);
        }

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
                'source_count' => 2,
                'is_target_brand' => true,
                'evidence' => '回答示例中明确提及'.$brandName,
                'meta' => ['generated' => true],
            ]);

        BrandDiagnosisBrandMention::query()
            ->withoutGlobalScopes(['current_site', 'admin_owner'])
            ->create([
                'site_id' => (int) $case->site_id,
                'owner_admin_id' => (int) $case->owner_admin_id,
                'run_id' => (int) $run->id,
                'question_id' => (int) $question->id,
                'result_id' => (int) $result->id,
                'platform' => $platform,
                'brand_name' => $competitor,
                'mention_count' => max(1, $mentionCount - 1),
                'mention_rank' => min(5, $rank + 1),
                'sentiment' => 'neutral',
                'source_count' => 1,
                'is_target_brand' => false,
                'evidence' => '回答示例中的同类比较对象',
                'meta' => ['generated' => true],
            ]);
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
        return match ($seed % 6) {
            0 => 'negative',
            1, 2 => 'neutral',
            default => 'positive',
        };
    }

    private function competitorName(string $brandName, int $platformIndex): string
    {
        return $brandName.'行业竞品'.chr(65 + ($platformIndex % 3));
    }
}
