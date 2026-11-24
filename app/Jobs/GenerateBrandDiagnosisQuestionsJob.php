<?php

/**
 * Created by Codex.
 * @Date: 2026-11-23
 * @Time: 14:53:16
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： GenerateBrandDiagnosisQuestionsJob.php
 * @Description: 异步检索品牌资料并生成诊断问题草稿。
 */

namespace App\Jobs;

use App\Models\BrandDiagnosisRun;
use App\Services\BrandDiagnosis\BrandDiagnosisPlatform;
use App\Services\BrandDiagnosis\BrandProfileResolver;
use App\Services\BrandDiagnosis\DoubaoBrandDiagnosisClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateBrandDiagnosisQuestionsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $runId)
    {
        $this->timeout = max(180, min(600, (int) config('brand_diagnosis.job_timeout', 1200)));
    }

    /**
     * @return array<int,string>
     */
    public function tags(): array
    {
        return [
            'brand-diagnosis',
            'brand-diagnosis-questions',
            'brand-diagnosis-run:'.$this->runId,
        ];
    }

    /**
     * @Name: handle
     *
     * @Description: 检索并解析品牌资料，结合用户提供的官网、品牌词和竞品上下文生成可编辑诊断问题。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 11:21:41
     *
     * @UpdateTime: 2026-11-23 14:53:16
     *
     * @Param: 无
     *
     * @Return: void 更新任务状态并保存生成的问题
     *
     * @Throws: 无 所有异常均转换为诊断任务失败状态
     */
    public function handle(): void
    {
        $run = BrandDiagnosisRun::query()
            ->withoutGlobalScope('current_site')
            ->whereKey($this->runId)
            ->first();

        if (! $run) {
            return;
        }

        $storedProfileMeta = (array) $run->brand_profile_meta;
        $diagnosisOptions = (array) ($storedProfileMeta['diagnosis_options'] ?? []);
        $brandContext = (array) ($storedProfileMeta['brand_context'] ?? []);
        $questionCount = min(30, max(1, (int) ($diagnosisOptions['question_count'] ?? config('brand_diagnosis.question_count', 10))));
        $brandProfileMeta = [];
        if ($diagnosisOptions !== []) {
            $brandProfileMeta['diagnosis_options'] = $diagnosisOptions;
        }
        if ($brandContext !== []) {
            $brandProfileMeta['brand_context'] = $brandContext;
        }

        $run->update([
            'status' => 'brand_profile_resolving',
            'brand_profile' => null,
            'brand_profile_source' => '',
            'brand_profile_model' => '',
            'brand_profile_status' => '',
            'brand_profile_meta' => $brandProfileMeta !== [] ? $brandProfileMeta : null,
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);

        try {
            $brandProfile = app(BrandProfileResolver::class)->resolve($run, (array) $run->platforms);
            if ((string) ($brandProfile['status'] ?? '') !== 'success') {
                $reason = trim((string) data_get($brandProfile, 'meta.reason', ''));
                throw new \RuntimeException($reason !== ''
                    ? '品牌资料检索失败：'.$reason
                    : '未检索到可用品牌介绍，请更换更明确的品牌词后重试。');
            }
            $diagnosisClient = app(DoubaoBrandDiagnosisClient::class);
            $brandProfileMeta = array_merge($brandProfileMeta, (array) ($brandProfile['meta'] ?? []));
            $brandContext['website_url'] = trim((string) ($brandContext['website_url'] ?? ''))
                ?: trim((string) ($brandProfile['website_url'] ?? ''));
            $brandProfileMeta['brand_context'] = $brandContext;
            $run->update([
                'status' => 'questions_generating',
                'brand_profile' => $brandProfile['profile'],
                'brand_profile_source' => $brandProfile['source'],
                'brand_profile_model' => $brandProfile['model'],
                'brand_profile_status' => $brandProfile['status'],
                'brand_profile_meta' => $brandProfileMeta,
            ]);
            $planningPlatform = BrandDiagnosisPlatform::normalize(
                (string) ($brandProfileMeta['platform'] ?? ((array) $run->platforms)[0] ?? BrandDiagnosisPlatform::DOUBAO)
            );
            $questionProfile = $this->questionProfile((string) $brandProfile['profile'], $brandContext);
            $coreTerms = collect((array) ($brandContext['brand_terms'] ?? []))
                ->map(static fn (mixed $term): string => trim((string) $term))
                ->filter(static fn (string $term): bool => $term !== '')
                ->unique()
                ->take(5)
                ->values()
                ->all();
            if ($coreTerms === []) {
                $coreTerms = $diagnosisClient->extractBrandCoreTerms(
                    (string) $run->brand_name,
                    $questionProfile,
                    5,
                    $planningPlatform
                );
            }
            if ((array) ($brandContext['brand_terms'] ?? []) === []) {
                $brandContext['brand_terms'] = $coreTerms;
            }
            $brandProfileMeta['brand_context'] = $brandContext;
            $brandProfileMeta['diagnosis_options'] = array_merge(
                (array) ($brandProfileMeta['diagnosis_options'] ?? []),
                ['question_count' => $questionCount]
            );
            $brandProfileMeta['core_terms'] = $coreTerms;
            $brandProfileMeta['core_terms_model'] = $planningPlatform;
            $run->update(['brand_profile_meta' => $brandProfileMeta]);

            $questions = $diagnosisClient->generateQuestionsFromCoreTerms(
                (string) $run->brand_name,
                $questionCount,
                $questionProfile,
                $coreTerms,
                $planningPlatform
            );

            $run->questions()->delete();
            foreach ($questions as $index => $question) {
                $run->questions()->create([
                    'site_id' => $run->site_id !== null ? (int) $run->site_id : null,
                    'owner_admin_id' => (int) ($run->owner_admin_id ?? 0) ?: null,
                    'question' => (string) $question['question'],
                    'question_type' => (string) $question['type'],
                    'core_term' => (string) ($question['core_term'] ?? ''),
                    'sort_order' => $index + 1,
                    'status' => 'pending',
                ]);
            }

            $run->update([
                'status' => 'questions_ready',
                'total_questions' => count($questions),
                'completed_questions' => 0,
                'failed_questions' => 0,
                'brand_score' => 0,
                'mention_rate' => 0,
                'average_rank' => 0,
                'mention_count' => 0,
                'sentiment_rate' => 0,
                'error_message' => null,
            ]);

            if (trim((string) $run->api_task_key) !== '') {
                AutoConfirmBrandDiagnosisRunJob::dispatch((int) $run->id)->onQueue('geoflow');
            }
        } catch (Throwable $exception) {
            $this->markFailed($run, $exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = BrandDiagnosisRun::query()
            ->withoutGlobalScope('current_site')
            ->whereKey($this->runId)
            ->first();

        if (! $run) {
            return;
        }

        $this->markFailed($run, $exception);
    }

    private function markFailed(BrandDiagnosisRun $run, ?Throwable $exception): void
    {
        $message = $exception?->getMessage();
        if (! is_string($message) || trim($message) === '') {
            $message = $exception ? class_basename($exception) : '品牌诊断问题生成失败';
        }

        $run->update([
            'status' => 'failed',
            'error_message' => mb_strimwidth($message, 0, 1000, '...', 'UTF-8'),
            'completed_at' => now(),
        ]);
    }

    /**
     * @Name: questionProfile
     *
     * @Description: 将手动维护的品牌简介、官网、品牌词和竞品附加到已检索资料，作为问题生成上下文。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 11:21:41
     *
     * @UpdateTime: 2026-11-23 12:02:31
     *
     * @Param: string $profile 在线检索得到的品牌资料
     *
     * @Param: array<string,mixed> $context 用户维护的品牌上下文
     *
     * @Return: string 用于提取品牌词和生成问题的资料文本
     *
     * @Throws: 无
     */
    private function questionProfile(string $profile, array $context): string
    {
        $lines = [];
        $websiteUrl = trim((string) ($context['website_url'] ?? ''));
        $description = trim((string) ($context['brand_profile'] ?? ''));
        $brandTerms = collect((array) ($context['brand_terms'] ?? []))
            ->map(static fn (mixed $term): string => trim((string) $term))
            ->filter(static fn (string $term): bool => $term !== '')
            ->values()
            ->all();
        $competitors = collect((array) ($context['competitors'] ?? []))
            ->map(static function (mixed $competitor): string {
                if (! is_array($competitor)) {
                    return '';
                }

                $name = trim((string) ($competitor['name'] ?? ''));
                $terms = collect((array) ($competitor['terms'] ?? []))
                    ->map(static fn (mixed $term): string => trim((string) $term))
                    ->filter(static fn (string $term): bool => $term !== '')
                    ->values()
                    ->all();
                if ($name === '') {
                    return '';
                }

                return $name.($terms !== [] ? '（相关词：'.implode('、', $terms).'）' : '');
            })
            ->filter(static fn (string $competitor): bool => $competitor !== '')
            ->values()
            ->all();

        if ($websiteUrl !== '') {
            $lines[] = '品牌官网：'.$websiteUrl;
        }
        if ($description !== '') {
            $lines[] = '用户补充品牌简介：'.$description;
        }
        if ($brandTerms !== []) {
            $lines[] = '用户指定品牌核心词：'.implode('、', $brandTerms);
        }
        if ($competitors !== []) {
            $lines[] = '竞品参考：'.implode('；', $competitors);
        }

        return $lines === [] ? $profile : $profile."\n\n用户补充的品牌诊断上下文：\n".implode("\n", $lines);
    }
}
