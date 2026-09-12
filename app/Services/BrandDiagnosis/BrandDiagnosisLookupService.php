<?php

namespace App\Services\BrandDiagnosis;

use App\Exceptions\ApiException;
use App\Jobs\GenerateBrandDiagnosisLookupJob;
use App\Models\BrandDiagnosisLookupJob;
use App\Models\BrandDiagnosisRun;
use Illuminate\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

final class BrandDiagnosisLookupService
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

    public function __construct(
        private readonly BrandProfileResolver $profileResolver,
        private readonly DoubaoBrandDiagnosisClient $diagnosisClient,
        private readonly BrandEntityResolver $entityResolver,
    ) {}

    /**
     * @param  list<string>  $includes
     * @return list<string>
     */
    public function normalizeIncludes(array $includes): array
    {
        if ($includes === []) {
            return self::MODULES;
        }

        return collect($includes)
            ->map(static fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $includes
     * @return array{brand_word:string,data_source:string,match_type:string,run:?BrandDiagnosisRun,generated:?array<string,mixed>}
     */
    public function lookup(string $brandWord, array $includes = []): array
    {
        $brandWord = trim($brandWord);
        $includes = $this->normalizeIncludes($includes);
        $this->assertSchemaReady();

        $runMatch = $this->findStoredRun($brandWord, $includes);
        if ($runMatch !== null) {
            return [
                'brand_word' => $brandWord,
                'data_source' => 'stored',
                'match_type' => $runMatch['match_type'],
                'run' => $runMatch['run'],
                'generated' => null,
            ];
        }

        return [
            'brand_word' => $brandWord,
            'data_source' => 'generated_not_stock',
            'match_type' => 'none',
            'run' => null,
            'generated' => $this->generatePreview($brandWord),
        ];
    }

    /**
     * Return the stored lookup result without starting a generated preview.
     *
     * @param  list<string>  $includes
     * @return array{brand_word:string,data_source:string,match_type:string,run:BrandDiagnosisRun,generated:null}|null
     */
    public function findStoredLookup(string $brandWord, array $includes = []): ?array
    {
        $brandWord = trim($brandWord);
        $includes = $this->normalizeIncludes($includes);
        $this->assertSchemaReady();

        $runMatch = $this->findStoredRun($brandWord, $includes);
        if ($runMatch === null) {
            return null;
        }

        return [
            'brand_word' => $brandWord,
            'data_source' => 'stored',
            'match_type' => $runMatch['match_type'],
            'run' => $runMatch['run'],
            'generated' => null,
        ];
    }

    /**
     * Create or reuse a non-stock preview job. The generated result is kept on
     * the lookup record only and never creates a diagnosis run.
     *
     * @param  list<string>  $includes
     */
    public function queueAsyncLookup(string $brandWord, array $includes = []): BrandDiagnosisLookupJob
    {
        $brandWord = trim($brandWord);
        $includes = $this->normalizeIncludes($includes);
        $this->assertSchemaReady();
        $this->assertAsyncSchemaReady();
        $canonicalKey = $this->canonicalLookupKey($brandWord);

        try {
            return Cache::lock('brand-diagnosis-lookup:async:'.$canonicalKey, 15)->block(5, function () use ($brandWord, $includes, $canonicalKey): BrandDiagnosisLookupJob {
                $active = BrandDiagnosisLookupJob::query()
                    ->where('canonical_key', $canonicalKey)
                    ->whereIn('status', ['pending', 'processing'])
                    ->where(function ($query): void {
                        $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    })
                    ->latest('id')
                    ->first();
                if ($active instanceof BrandDiagnosisLookupJob) {
                    $this->mergeAsyncIncludes($active, $includes);

                    return $active;
                }

                $completed = BrandDiagnosisLookupJob::query()
                    ->where('canonical_key', $canonicalKey)
                    ->where('status', 'completed')
                    ->whereNotNull('result')
                    ->where(function ($query): void {
                        $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    })
                    ->latest('id')
                    ->first();
                if ($completed instanceof BrandDiagnosisLookupJob) {
                    $this->mergeAsyncIncludes($completed, $includes);

                    return $completed;
                }

                $lookup = BrandDiagnosisLookupJob::query()->create([
                    'lookup_id' => 'bdl_'.Str::lower(Str::random(32)),
                    'brand_word' => $brandWord,
                    'canonical_key' => $canonicalKey,
                    'includes' => $includes,
                    'status' => 'pending',
                    'expires_at' => now()->addSeconds(max(300, (int) config('brand_diagnosis.lookup_api.async_result_ttl', 1800))),
                ]);

                GenerateBrandDiagnosisLookupJob::dispatch((int) $lookup->id)->onQueue('geoflow');

                return $lookup;
            });
        } catch (LockTimeoutException $exception) {
            throw new ApiException('brand_diagnosis_lookup_busy', '查询任务正在创建，请稍后重试', 503);
        }
    }

    /**
     * @return array{profile:array<string,mixed>,questions:list<array<string,mixed>>}
     */
    public function generatePreviewForLookup(string $brandWord): array
    {
        return $this->generatePreview(trim($brandWord));
    }

    /**
     * @param  list<string>  $includes
     * @return array{run:BrandDiagnosisRun,match_type:string}|null
     */
    private function findStoredRun(string $brandWord, array $includes): ?array
    {
        $exactQuery = BrandDiagnosisRun::query()
            ->withoutGlobalScopes(['current_site', 'admin_owner'])
            ->select([
                'id', 'site_id', 'brand_name', 'brand_profile', 'brand_profile_source',
                'brand_profile_model', 'brand_profile_status', 'brand_profile_meta', 'platforms',
                'status', 'total_questions', 'completed_questions', 'failed_questions',
                'brand_score', 'mention_rate', 'average_rank', 'mention_count', 'sentiment_rate',
                'error_message', 'started_at', 'completed_at', 'created_at', 'updated_at',
            ])
            ->whereRaw('LOWER(brand_name) = LOWER(?)', [$brandWord])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $this->eagerLoad($exactQuery, $includes);
        $exact = $exactQuery->first();
        if ($exact instanceof BrandDiagnosisRun && $this->matchType($brandWord, (string) $exact->brand_name) === 'exact') {
            return ['run' => $exact, 'match_type' => 'exact'];
        }

        $query = BrandDiagnosisRun::query()
            ->withoutGlobalScopes(['current_site', 'admin_owner'])
            ->select([
                'id', 'site_id', 'brand_name', 'brand_profile', 'brand_profile_source',
                'brand_profile_model', 'brand_profile_status', 'brand_profile_meta', 'platforms',
                'status', 'total_questions', 'completed_questions', 'failed_questions',
                'brand_score', 'mention_rate', 'average_rank', 'mention_count', 'sentiment_rate',
                'error_message', 'started_at', 'completed_at', 'created_at', 'updated_at',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit((int) config('brand_diagnosis.lookup_api.candidate_limit', 100));

        $aliases = collect($this->entityResolver->aliases($brandWord))
            ->push($this->entityResolver->canonicalKey($brandWord))
            ->filter(static fn (mixed $alias): bool => is_string($alias) && trim($alias) !== '')
            ->unique()
            ->values()
            ->all();
        if ($aliases === []) {
            return null;
        }
        $query->where(function ($query) use ($aliases): void {
            foreach ($aliases as $alias) {
                $query->orWhereRaw('LOWER(brand_name) LIKE LOWER(?)', ['%'.addcslashes($alias, '%_\\').'%']);
            }
        });

        $this->eagerLoad($query, $includes);
        $runs = $query->get();

        $best = null;
        foreach ($runs as $run) {
            $matchType = $this->matchType($brandWord, (string) $run->brand_name);
            if ($matchType === null) {
                continue;
            }

            $rank = array_search($matchType, ['exact', 'canonical', 'prefix', 'contains'], true);
            $candidate = [$rank === false ? 99 : $rank, $run->created_at?->getTimestamp() ?? 0, (int) $run->id];
            if ($best === null || $candidate[0] < $best['sort'][0]
                || ($candidate[0] === $best['sort'][0] && ($candidate[1] > $best['sort'][1]
                    || ($candidate[1] === $best['sort'][1] && $candidate[2] > $best['sort'][2])))) {
                $best = ['run' => $run, 'match_type' => $matchType, 'sort' => $candidate];
            }
        }

        return $best === null ? null : [
            'run' => $best['run'],
            'match_type' => $best['match_type'],
        ];
    }

    private function eagerLoad($query, array $includes): void
    {
        $needsRunResults = in_array('performance', $includes, true)
            || in_array('rankings', $includes, true)
            || in_array('platform_analysis', $includes, true)
            || in_array('competitor_visibility', $includes, true);
        $needsRunSources = in_array('sources', $includes, true)
            || in_array('snapshots', $includes, true)
            || in_array('platform_analysis', $includes, true);
        $needsBrandMentions = in_array('competitors', $includes, true)
            || in_array('rankings', $includes, true)
            || in_array('performance', $includes, true)
            || in_array('platform_analysis', $includes, true)
            || in_array('competitor_visibility', $includes, true);

        if (in_array('questions', $includes, true) || in_array('model_results', $includes, true) || in_array('snapshots', $includes, true)) {
            $query->with(['questions' => function ($relation): void {
                $relation->withoutGlobalScopes(['current_site', 'admin_owner'])
                    ->select(['id', 'run_id', 'question', 'question_type', 'core_term', 'sort_order', 'status'])
                    ->orderBy('sort_order');
            }]);
        }
        if ($needsRunResults) {
            $query->with(['results' => function ($relation): void {
                $relation->withoutGlobalScopes(['current_site', 'admin_owner'])
                    ->select([
                        'id', 'run_id', 'question_id', 'platform', 'brand_mentioned',
                        'mention_count', 'mention_rank', 'sentiment', 'status', 'checked_at',
                    ])
                    ->orderBy('id');
            }]);
        }
        if (in_array('model_results', $includes, true) || in_array('snapshots', $includes, true)) {
            $query->with(['questions.results' => function ($relation): void {
                $columns = ['id', 'run_id', 'question_id', 'platform', 'answer', 'brand_mentioned', 'mention_count', 'mention_rank', 'sentiment', 'status', 'error_message', 'checked_at'];
                if (Schema::hasColumn('brand_diagnosis_results', 'snapshot_payload')) {
                    $columns[] = 'snapshot_payload';
                }
                $relation->withoutGlobalScopes(['current_site', 'admin_owner'])->select($columns)->orderBy('id');
            }]);
        }
        if ($needsRunSources) {
            $query->with(['sources' => function ($relation): void {
                $relation->withoutGlobalScopes(['current_site', 'admin_owner'])
                    ->select(['id', 'run_id', 'question_id', 'result_id', 'platform', 'title', 'url', 'domain', 'source_type'])
                    ->orderBy('id');
            }]);
        }
        if ($needsBrandMentions) {
            $query->with(['brandMentions' => function ($relation): void {
                $relation->withoutGlobalScopes(['current_site', 'admin_owner'])
                    ->select([
                        'id', 'run_id', 'question_id', 'result_id', 'platform', 'brand_name',
                        'mention_count', 'mention_rank', 'sentiment', 'source_count', 'is_target_brand', 'meta',
                    ])
                    ->orderByDesc('mention_count')
                    ->orderBy('id');
            }]);
        }
    }

    private function assertSchemaReady(): void
    {
        if (! Schema::hasTable('brand_diagnosis_runs')
            || ! Schema::hasColumn('brand_diagnosis_runs', 'brand_name')
            || ! Schema::hasColumn('brand_diagnosis_runs', 'created_at')) {
            throw new ApiException('brand_diagnosis_lookup_not_ready', '品牌诊断查询服务尚未准备完成', 503);
        }
    }

    private function assertAsyncSchemaReady(): void
    {
        if (! Schema::hasTable('brand_diagnosis_lookup_jobs')) {
            throw new ApiException('brand_diagnosis_lookup_not_ready', '品牌诊断查询服务尚未准备完成', 503);
        }
    }

    private function canonicalLookupKey(string $brandWord): string
    {
        return $this->entityResolver->canonicalKey($brandWord) ?: $this->normalize($brandWord);
    }

    /**
     * @param  list<string>  $includes
     */
    private function mergeAsyncIncludes(BrandDiagnosisLookupJob $lookup, array $includes): void
    {
        $lookupIncludes = $this->normalizeIncludes((array) $lookup->includes);
        $merged = $this->normalizeIncludes(array_merge($lookupIncludes, $includes));
        if ($merged !== $lookupIncludes) {
            $lookup->forceFill(['includes' => $merged])->save();
        }
    }

    private function matchType(string $requested, string $stored): ?string
    {
        $requested = $this->normalize($requested);
        $stored = $this->normalize($stored);
        if ($requested === '' || $stored === '') {
            return null;
        }
        if ($requested === $stored) {
            return 'exact';
        }
        if ($this->entityResolver->canonicalKey($requested) !== ''
            && $this->entityResolver->canonicalKey($requested) === $this->entityResolver->canonicalKey($stored)) {
            return 'canonical';
        }
        if (str_starts_with($stored, $requested) || str_starts_with($requested, $stored)) {
            return 'prefix';
        }
        if (str_contains($stored, $requested) || str_contains($requested, $stored)) {
            return 'contains';
        }

        return null;
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)), 'UTF-8');
    }

    /**
     * @return array{profile:array<string,mixed>,questions:list<array<string,mixed>>}
     */
    private function generatePreview(string $brandWord): array
    {
        $cacheKey = 'brand-diagnosis-lookup:'.sha1($this->entityResolver->canonicalKey($brandWord) ?: $this->normalize($brandWord));
        $ttl = (int) config('brand_diagnosis.lookup_api.cache_ttl', 21600);

        $callback = function () use ($brandWord): array {
            $run = new BrandDiagnosisRun([
                'brand_name' => $brandWord,
                'platforms' => [BrandDiagnosisPlatform::DOUBAO],
            ]);
            try {
                $profile = $this->profileResolver->resolveStrict($run, [BrandDiagnosisPlatform::DOUBAO]);
            } catch (BrandProfileNotFoundException $exception) {
                throw new ApiException('brand_profile_not_found', '未检索到可用的品牌介绍', 422);
            } catch (Throwable $exception) {
                throw new ApiException('brand_profile_provider_failed', '品牌介绍核实服务暂不可用', 502);
            }

            try {
                $questions = $this->diagnosisClient->generateQuestionPool(
                    $brandWord,
                    max(1, (int) config('brand_diagnosis.question_count', 6)),
                    [BrandDiagnosisPlatform::DOUBAO],
                    (string) $profile['profile']
                );
            } catch (Throwable $exception) {
                throw new ApiException('brand_questions_generation_failed', 'AI 问题生成失败，请稍后重试', 502);
            }

            return [
                'profile' => $profile,
                'questions' => collect($questions)->values()->all(),
            ];
        };

        if ($ttl <= 0) {
            return $callback();
        }

        try {
            return Cache::lock($cacheKey.':lock', 30)->block(10, fn (): array => Cache::remember($cacheKey, $ttl, $callback));
        } catch (LockTimeoutException $exception) {
            throw new ApiException('brand_profile_provider_failed', '品牌介绍核实服务暂不可用', 502);
        }
    }
}
