<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-11-23
 *
 * @Time: 10:47:47
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： BrandMonitoringPlanService.php
 *
 * @Description: 管理租户隔离的品牌监测计划，并复用现有诊断额度与队列执行链路。
 */

namespace App\Services\BrandDiagnosis;

use App\Models\Admin;
use App\Models\BrandDiagnosisRun;
use App\Models\BrandMonitoringPlan;
use App\Models\BrandMonitoringPlanRun;
use App\Models\Site;
use App\Support\CurrentSite;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class BrandMonitoringPlanService
{
    public function __construct(
        private readonly BrandDiagnosisRunService $diagnosisRuns,
        private readonly BrandDiagnosisModelSettings $modelSettings,
        private readonly BrandMonitoringScheduleCalculator $scheduleCalculator,
        private readonly CurrentSite $currentSite,
        private readonly BrandProfileResolver $brandProfileResolver,
        private readonly DoubaoBrandDiagnosisClient $diagnosisClient,
    ) {}

    /**
     * @Name: list
     *
     * @Description: 查询当前账号和站点的品牌监测计划，并附带最近执行进度与周期统计。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:03:04
     *
     * @UpdateTime: 2026-11-23 10:50:42
     *
     * @Param: Site $site 当前登录账号绑定的站点
     *
     * @Return array<string,mixed> 计划列表、状态计数和可用模型
     *
     * @Throws: RuntimeException 当前账号站点不可用
     */
    public function list(Site $site): array
    {
        $this->synchronizeExecutionStates((int) $site->id);
        $plans = BrandMonitoringPlan::query()
            ->where('site_id', (int) $site->id)
            ->where('owner_admin_id', (int) $site->owner_admin_id)
            ->with(['executions' => fn ($query) => $query->limit(10)->with('diagnosisRun')])
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();

        $items = $plans->map(fn (BrandMonitoringPlan $plan): array => $this->presentPlan($plan))->values()->all();

        return [
            'items' => $items,
            'timezone' => (string) config('app.timezone', 'UTC'),
            'summary' => [
                'active_plans' => $plans->where('status', 'active')->count(),
                'running_plans' => $plans->filter(fn (BrandMonitoringPlan $plan): bool => $this->hasInFlightExecution($plan))->count(),
                'question_count' => $plans->sum(fn (BrandMonitoringPlan $plan): int => count((array) $plan->questions)),
            ],
            'platforms' => $this->platformOptions(),
        ];
    }

    /**
     * @Name: suggest
     *
     * @Description: 复用品牌诊断的资料检索、核心词抽取和问题生成逻辑，为监测计划提供可编辑的问题草稿。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:17:20
     *
     * @UpdateTime: 2026-11-23 17:34:12
     *
     * @Param: Admin $admin 当前监测中心账号
     *
     * @Param: Site $site 当前账号站点
     *
     * @Param: array<string,mixed> $data 已验证的品牌与模型配置
     *
     * @Return array<string,mixed> 品牌资料、核心词和建议问题
     *
     * @Throws: RuntimeException 模型不可用、品牌资料检索失败或问题生成失败
     */
    public function suggest(Admin $admin, Site $site, array $data): array
    {
        $platform = BrandDiagnosisPlatform::normalize((string) $data['platform']);
        $this->assertPlatformsAvailable([$platform]);

        $run = new BrandDiagnosisRun([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) ($site->owner_admin_id ?: $admin->id),
            'admin_id' => (int) $admin->id,
            'brand_name' => trim((string) $data['brand_name']),
        ]);
        $profile = trim((string) ($data['brand_profile'] ?? ''));
        $profileSource = $profile !== '' ? 'manual' : 'web_search';
        if ($profile === '') {
            $resolvedProfile = $this->brandProfileResolver->resolveStrict($run, [$platform]);
            $profile = (string) $resolvedProfile['profile'];
            $profileSource = (string) $resolvedProfile['source'];
        }

        $extraContext = collect([
            '品牌相关词：'.implode('、', $this->normalizeTerms((array) ($data['brand_terms'] ?? []))),
            '产品或服务：'.implode('、', $this->normalizeTerms((array) ($data['products'] ?? []))),
            '竞品：'.implode('、', $this->normalizeTerms((array) ($data['competitors'] ?? []))),
            '搜索关键词：'.implode('、', $this->normalizeTerms((array) ($data['keywords'] ?? []))),
        ])->filter(static fn (string $line): bool => ! str_ends_with($line, '：'))->implode("\n");
        $questionProfile = $extraContext !== '' ? $profile."\n\n".$extraContext : $profile;
        $coreTerms = $this->normalizeTerms((array) ($data['brand_terms'] ?? []));
        if ($coreTerms === []) {
            $coreTerms = $this->normalizeTerms($this->diagnosisClient->extractBrandCoreTerms(
                (string) $data['brand_name'],
                $questionProfile,
                5,
                $platform
            ));
        }
        if ($coreTerms === []) {
            $coreTerms = [trim((string) $data['brand_name'])];
        }

        $questionCount = ($data['diagnosis_mode'] ?? 'standard') === 'deep' ? 30 : 5;
        $questions = $this->diagnosisClient->generateQuestionsFromCoreTerms(
            (string) $data['brand_name'],
            $questionCount,
            $questionProfile,
            $coreTerms,
            $platform
        );

        return [
            'brand_profile' => $profile,
            'brand_profile_source' => $profileSource,
            'core_terms' => $coreTerms,
            'questions' => $this->normalizeQuestions($questions),
        ];
    }

    /**
     * @Name: find
     *
     * @Description: 按当前站点读取计划及其最近执行记录，避免接受跨租户计划编号。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:03:04
     *
     * @UpdateTime: 2026-11-23 10:50:42
     *
     * @Param: Site $site 当前站点
     *
     * @Param: int $planId 监测计划编号
     *
     * @Return array<string,mixed> 监测计划与执行历史
     *
     * @Throws: RuntimeException 计划不存在或不属于当前站点
     */
    public function find(Site $site, int $planId): array
    {
        $this->synchronizeExecutionStates((int) $site->id, $planId);
        $plan = BrandMonitoringPlan::query()
            ->where('site_id', (int) $site->id)
            ->where('owner_admin_id', (int) $site->owner_admin_id)
            ->with(['executions' => fn ($query) => $query->limit(50)->with('diagnosisRun')])
            ->find($planId);

        if (! $plan instanceof BrandMonitoringPlan) {
            throw new RuntimeException('监测计划不存在或无权访问。');
        }

        return $this->presentPlan($plan, true);
    }

    /**
     * @Name: create
     *
     * @Description: 在当前账号和站点范围内保存监测计划，计算首个未来执行时间并按请求决定是否立即执行。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:03:04
     *
     * @UpdateTime: 2026-11-23 10:50:42
     *
     * @Param: Admin $admin 当前监测中心账号
     *
     * @Param: Site $site 当前账号站点
     *
     * @Param: array<string,mixed> $data 已验证的监测计划配置
     *
     * @Param: bool $startImmediately 是否立即执行一轮诊断
     *
     * @Return array<string,mixed> 已保存计划与首轮执行结果
     *
     * @Throws: RuntimeException 当前模型关闭、周期配置无效或执行额度不足
     */
    public function create(Admin $admin, Site $site, array $data, bool $startImmediately): array
    {
        $this->assertPlatformsAvailable((array) $data['platforms']);
        $this->assertQuestionCount((string) $data['diagnosis_mode'], (array) $data['questions']);

        $plan = new BrandMonitoringPlan($this->planAttributes($admin, $site, $data));
        $nextRun = $this->scheduleCalculator->nextOccurrence($plan);
        if (! $nextRun instanceof CarbonImmutable) {
            throw new RuntimeException('所选监测周期内没有可执行时间，请调整开始日期、结束日期或执行时间。');
        }
        $plan->next_run_at = $nextRun;
        $plan->save();

        $execution = null;
        if ($startImmediately) {
            try {
                $execution = $this->startRun($plan, $admin, true, CarbonImmutable::now((string) config('app.timezone')));
            } catch (BrandDiagnosisLimitExceededException $exception) {
                $message = mb_strimwidth(trim($exception->getMessage()), 0, 1000, '...', 'UTF-8');
                $plan->update(['status' => 'paused', 'next_run_at' => null, 'last_error' => $message]);
                $execution = $plan->executions()->with('diagnosisRun')->orderByDesc('id')->first();
            }
            $plan->refresh();
        }

        return [
            'plan' => $this->presentPlan($plan->load(['executions.diagnosisRun']), true),
            'execution' => $execution instanceof BrandMonitoringPlanRun
                ? $this->presentExecution($execution->refresh()->load('diagnosisRun'))
                : null,
        ];
    }

    /**
     * @Name: update
     *
     * @Description: 更新计划未来使用的品牌资料、固定问题集和执行周期；有效周期内恢复已结束计划，不修改已开始的诊断记录。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:03:04
     *
     * @UpdateTime: 2026-11-23 17:34:12
     *
     * @Param: Admin $admin 当前监测中心账号
     *
     * @Param: Site $site 当前站点
     *
     * @Param: int $planId 监测计划编号
     *
     * @Param: array<string,mixed> $data 已验证的完整计划配置
     *
     * @Return array<string,mixed> 更新后的监测计划
     *
     * @Throws: RuntimeException 计划不存在、周期配置无效或模型已关闭
     */
    public function update(Admin $admin, Site $site, int $planId, array $data): array
    {
        $plan = $this->planForSite($site, $planId);
        $this->assertPlatformsAvailable((array) $data['platforms']);
        $questions = $this->normalizeQuestions((array) $data['questions']);
        $isUnchangedLegacyQuestionCount = $plan->diagnosis_mode === 'standard'
            && (string) $data['diagnosis_mode'] === 'standard'
            && count($this->normalizeQuestions((array) $plan->questions)) === 10
            && count($questions) === 10;
        $this->assertQuestionCount((string) $data['diagnosis_mode'], $questions, $isUnchangedLegacyQuestionCount);

        $attributes = $this->planAttributes($admin, $site, $data);
        unset($attributes['site_id'], $attributes['owner_admin_id'], $attributes['admin_id'], $attributes['status']);
        $plan->fill($attributes);
        $plan->last_error = null;
        $canSchedule = in_array($plan->status, ['active', 'completed'], true);
        $nextRun = $canSchedule ? $this->scheduleCalculator->nextOccurrence($plan) : null;
        if ($plan->status === 'active' && ! ($nextRun instanceof CarbonImmutable)) {
            $plan->status = 'completed';
        } elseif ($plan->status === 'completed' && $nextRun instanceof CarbonImmutable) {
            $plan->status = 'active';
        }
        $plan->next_run_at = $nextRun;
        $plan->save();

        return $this->presentPlan($plan->refresh()->load(['executions.diagnosisRun']), true);
    }

    /**
     * @Name: pause
     *
     * @Description: 暂停后续自动执行，并保留已入队的诊断任务及完整执行记录。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:03:04
     *
     * @UpdateTime: 2026-11-23 10:31:31
     *
     * @Param: Site $site 当前站点
     *
     * @Param: int $planId 监测计划编号
     *
     * @Return array<string,mixed> 暂停后的计划
     *
     * @Throws: RuntimeException 计划不存在
     */
    public function pause(Site $site, int $planId): array
    {
        $plan = $this->planForSite($site, $planId);
        $plan->update(['status' => 'paused', 'next_run_at' => null]);

        return $this->presentPlan($plan->refresh()->load(['executions.diagnosisRun']), true);
    }

    /**
     * @Name: resume
     *
     * @Description: 恢复计划并按当前时间重新计算下次执行时间；周期已结束的计划不能恢复。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:03:04
     *
     * @UpdateTime: 2026-11-23 10:03:04
     *
     * @Param: Site $site 当前站点
     *
     * @Param: int $planId 监测计划编号
     *
     * @Return array<string,mixed> 恢复后的计划
     *
     * @Throws: RuntimeException 计划不存在、模型已关闭或周期已结束
     */
    public function resume(Site $site, int $planId): array
    {
        $plan = $this->planForSite($site, $planId);
        $this->assertPlatformsAvailable((array) $plan->platforms);
        $nextRun = $this->scheduleCalculator->nextOccurrence($plan);
        if (! $nextRun instanceof CarbonImmutable) {
            $plan->update(['status' => 'completed', 'next_run_at' => null]);

            throw new RuntimeException('该计划的执行周期已结束，请修改结束日期后再恢复。');
        }

        $plan->update(['status' => 'active', 'next_run_at' => $nextRun, 'last_error' => null]);

        return $this->presentPlan($plan->refresh()->load(['executions.diagnosisRun']), true);
    }

    /**
     * @Name: duplicate
     *
     * @Description: 复制计划配置为暂停状态的新计划，不复制历史记录或自动启动诊断。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:03:04
     *
     * @UpdateTime: 2026-11-23 17:40:21
     *
     * @Param: Admin $admin 当前监测中心账号
     *
     * @Param: Site $site 当前站点
     *
     * @Param: int $planId 源监测计划编号
     *
     * @Return array<string,mixed> 新建的暂停计划
     *
     * @Throws: RuntimeException 源计划不存在
     */
    public function duplicate(Admin $admin, Site $site, int $planId): array
    {
        $source = $this->planForSite($site, $planId);
        $attributes = $source->only([
            'brand_name', 'website_url', 'brand_profile', 'brand_terms', 'products', 'competitors',
            'keywords', 'questions', 'platforms', 'diagnosis_mode', 'frequency', 'frequency_interval',
            'run_time', 'weekdays', 'month_day', 'period_mode', 'starts_on', 'ends_on',
        ]);
        if ($source->diagnosis_mode === 'standard') {
            $attributes['questions'] = array_slice($this->normalizeQuestions((array) $source->questions), 0, 5);
        }
        $attributes['name'] = mb_strimwidth((string) $source->name.' 副本', 0, 120, '', 'UTF-8');
        $attributes['site_id'] = (int) $site->id;
        $attributes['owner_admin_id'] = (int) ($site->owner_admin_id ?: $admin->id);
        $attributes['admin_id'] = (int) $admin->id;
        $attributes['status'] = 'paused';
        $attributes['next_run_at'] = null;

        $copy = BrandMonitoringPlan::query()->create($attributes);

        return $this->presentPlan($copy, true);
    }

    /**
     * @Name: delete
     *
     * @Description: 软删除指定站点的监测计划，同时保留品牌诊断记录供历史报告继续使用。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:03:04
     *
     * @UpdateTime: 2026-11-23 10:03:04
     *
     * @Param: Site $site 当前站点
     *
     * @Param: int $planId 监测计划编号
     *
     * @Return void 删除完成
     *
     * @Throws: RuntimeException 计划不存在
     */
    public function delete(Site $site, int $planId): void
    {
        $plan = $this->planForSite($site, $planId);
        $plan->delete();
    }

    /**
     * @Name: runNow
     *
     * @Description: 手动为当前计划创建一轮全新的品牌诊断，不改变自动计划的下次执行时间。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:03:04
     *
     * @UpdateTime: 2026-11-23 10:03:04
     *
     * @Param: Admin $admin 当前监测中心账号
     *
     * @Param: Site $site 当前站点
     *
     * @Param: int $planId 监测计划编号
     *
     * @Return array<string,mixed> 新建执行记录与品牌诊断进度
     *
     * @Throws: RuntimeException 计划未配置完成、模型不可用、已有诊断运行中或额度不足
     */
    public function runNow(Admin $admin, Site $site, int $planId): array
    {
        $plan = $this->planForSite($site, $planId);
        $execution = $this->startRun($plan, $admin, true, CarbonImmutable::now((string) config('app.timezone')));

        return $execution->load('diagnosisRun') instanceof BrandMonitoringPlanRun
            ? $this->presentExecution($execution->refresh()->load('diagnosisRun'))
            : [];
    }

    /**
     * @Name: runDuePlans
     *
     * @Description: 每次调度扫描最多启动指定数量的到期计划；每个计划单独锁定并处理，避免重复扣减额度。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:03:04
     *
     * @UpdateTime: 2026-11-23 10:50:42
     *
     * @Param: int $limit 本次扫描计划上限
     *
     * @Return int 本次启动或因在途任务而顺延的计划数
     *
     * @Throws: 无 单个计划错误会保存状态并由调用方记录日志
     */
    public function runDuePlans(int $limit = 50): int
    {
        $this->synchronizeExecutionStates();
        $now = CarbonImmutable::now((string) config('app.timezone'));
        $plans = BrandMonitoringPlan::query()
            ->where('status', 'active')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            ->orderBy('next_run_at')
            ->limit(max(1, min(200, $limit)))
            ->get();
        $processed = 0;

        foreach ($plans as $plan) {
            try {
                $site = Site::query()->find((int) $plan->site_id);
                $ownerAdminId = (int) ($plan->owner_admin_id ?? 0);
                $admin = $ownerAdminId > 0 ? Admin::query()->find($ownerAdminId) : null;
                if (
                    ! $site instanceof Site
                    || ! $admin instanceof Admin
                    || (int) $admin->status !== 1
                    || (string) $site->status !== 'active'
                    || (int) $site->owner_admin_id !== $ownerAdminId
                ) {
                    $plan->update(['status' => 'paused', 'next_run_at' => null, 'last_error' => '计划所属账号或站点不可用。']);

                    continue;
                }

                $previousSite = $this->currentSite->get();
                $guard = auth('admin');
                $previousAdmin = $guard->user();
                $this->currentSite->set($site);
                $guard->setUser($admin);

                try {
                    $this->startRun($plan, $admin, false, CarbonImmutable::parse((string) $plan->next_run_at));
                    $processed++;
                } finally {
                    $this->currentSite->set($previousSite);
                    if ($previousAdmin instanceof Admin) {
                        $guard->setUser($previousAdmin);
                    } else {
                        $guard->forgetUser();
                    }
                }
            } catch (Throwable $exception) {
                $message = mb_strimwidth(trim($exception->getMessage()) ?: '品牌监测计划启动失败。', 0, 1000, '...', 'UTF-8');
                BrandMonitoringPlan::query()
                    ->whereKey((int) $plan->id)
                    ->where('status', 'active')
                    ->update([
                        'status' => 'paused',
                        'next_run_at' => null,
                        'last_error' => $message,
                        'updated_at' => now(),
                    ]);
                report($exception);
            }
        }

        return $processed;
    }

    /**
     * @Name: platformOptions
     *
     * @Description: 返回现有品牌诊断支持且同时满足后台开关和接口配置的平台。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:03:04
     *
     * @UpdateTime: 2026-11-23 10:03:04
     *
     * @Return list<array<string,mixed>> 模型选项及可用状态
     *
     * @Throws: 无
     */
    public function platformOptions(): array
    {
        return collect(BrandDiagnosisPlatform::keys())
            ->map(fn (string $platform): array => [
                'key' => $platform,
                'label' => BrandDiagnosisPlatform::label($platform),
                'icon' => BrandDiagnosisPlatform::icon($platform),
                'logo_url' => BrandDiagnosisPlatform::logoUrl($platform),
                'enabled' => $this->modelSettings->isEnabled($platform) && $this->modelSettings->isConfigured($platform),
            ])
            ->values()
            ->all();
    }

    /**
     * @Name: planAttributes
     *
     * @Description: 统一归一化计划 JSON、周期、租户所有者和初始状态，保证 API 与调度任务使用一致配置。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:03:04
     *
     * @UpdateTime: 2026-11-23 10:03:04
     *
     * @Param: Admin $admin 当前账号
     *
     * @Param: Site $site 当前站点
     *
     * @Param: array<string,mixed> $data 验证后的请求字段
     *
     * @Return array<string,mixed> 可写入计划模型的字段
     *
     * @Throws: 无
     */
    private function planAttributes(Admin $admin, Site $site, array $data): array
    {
        return [
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) ($site->owner_admin_id ?: $admin->id),
            'admin_id' => (int) $admin->id,
            'name' => trim((string) $data['name']),
            'brand_name' => trim((string) $data['brand_name']),
            'website_url' => trim((string) ($data['website_url'] ?? '')) ?: null,
            'brand_profile' => trim((string) ($data['brand_profile'] ?? '')),
            'brand_terms' => $this->normalizeTerms((array) $data['brand_terms']),
            'products' => $this->normalizeTerms((array) ($data['products'] ?? [])),
            'competitors' => $this->normalizeTerms((array) ($data['competitors'] ?? [])),
            'keywords' => $this->normalizeTerms((array) ($data['keywords'] ?? [])),
            'questions' => $this->normalizeQuestions((array) $data['questions']),
            'platforms' => array_values(array_unique((array) $data['platforms'])),
            'diagnosis_mode' => (string) $data['diagnosis_mode'],
            'frequency' => (string) $data['frequency'],
            'frequency_interval' => (int) ($data['frequency_interval'] ?? 1),
            'run_time' => (string) $data['run_time'],
            'weekdays' => array_values(array_unique(array_map('intval', (array) ($data['weekdays'] ?? [])))),
            'month_day' => isset($data['month_day']) ? (int) $data['month_day'] : null,
            'period_mode' => (string) $data['period_mode'],
            'starts_on' => (string) $data['starts_on'],
            'ends_on' => ($data['period_mode'] ?? '') === 'custom' ? (string) ($data['ends_on'] ?? '') : null,
            'status' => 'active',
        ];
    }

    /**
     * @Name: startRun
     *
     * @Description: 以固定问题和平台配置创建品牌诊断，再通过现有确认服务扣减一次额度并分发到诊断队列。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:03:04
     *
     * @UpdateTime: 2026-11-23 17:34:12
     *
     * @Param: BrandMonitoringPlan $plan 监测计划
     *
     * @Param: Admin $admin 执行计划的账号
     *
     * @Param: bool $manual 是否由用户立即触发
     *
     * @Param: CarbonInterface $scheduledFor 本轮计划时间或手动触发时间
     *
     * @Return BrandMonitoringPlanRun 新一轮诊断执行记录
     *
     * @Throws: RuntimeException 模型不可用、计划暂停、诊断正在运行或额度不足
     */
    private function startRun(BrandMonitoringPlan $plan, Admin $admin, bool $manual, CarbonInterface $scheduledFor): BrandMonitoringPlanRun
    {
        $execution = DB::transaction(function () use ($plan, $admin, $manual, $scheduledFor): BrandMonitoringPlanRun {
            $lockedPlan = BrandMonitoringPlan::query()->lockForUpdate()->find((int) $plan->id);
            if (! $lockedPlan instanceof BrandMonitoringPlan) {
                throw new RuntimeException('监测计划不存在或已删除。');
            }
            if (! $manual && $lockedPlan->status !== 'active') {
                throw new RuntimeException('监测计划已暂停。');
            }
            if ($this->hasInFlightExecution($lockedPlan)) {
                if (! $manual) {
                    $lockedPlan->update(['next_run_at' => $this->scheduleCalculator->nextOccurrence($lockedPlan)]);

                    return new BrandMonitoringPlanRun(['status' => 'skipped']);
                }

                throw new RuntimeException('当前计划已有诊断任务在运行，请完成后再启动新一轮。');
            }

            $this->assertPlatformsAvailable((array) $lockedPlan->platforms);
            $questions = $this->normalizeQuestions((array) $lockedPlan->questions);
            $this->assertQuestionCount((string) $lockedPlan->diagnosis_mode, $questions, true);
            $scheduledAt = CarbonImmutable::instance($scheduledFor)->setTimezone((string) config('app.timezone'));
            $executionKey = $manual
                ? 'manual-'.(string) Str::ulid()
                : 'scheduled-'.$scheduledAt->format('YmdHi');
            $existing = $lockedPlan->executions()->where('execution_key', $executionKey)->first();
            if ($existing instanceof BrandMonitoringPlanRun) {
                return $existing;
            }

            $questionCount = count($questions);
            $run = BrandDiagnosisRun::query()->create([
                'site_id' => (int) $lockedPlan->site_id,
                'owner_admin_id' => (int) ($lockedPlan->owner_admin_id ?: $admin->id),
                'admin_id' => (int) $admin->id,
                'brand_name' => (string) $lockedPlan->brand_name,
                'brand_profile' => (string) $lockedPlan->brand_profile,
                'brand_profile_source' => 'monitor_plan',
                'brand_profile_model' => '',
                'brand_profile_status' => trim((string) $lockedPlan->brand_profile) !== '' ? 'success' : 'fallback',
                'brand_profile_meta' => [
                    'diagnosis_options' => [
                        'mode' => (string) $lockedPlan->diagnosis_mode,
                        'question_count' => $questionCount,
                    ],
                    'monitoring_plan_id' => (int) $lockedPlan->id,
                    'brand_terms' => (array) $lockedPlan->brand_terms,
                    'products' => (array) $lockedPlan->products,
                    'competitors' => (array) $lockedPlan->competitors,
                    'keywords' => (array) $lockedPlan->keywords,
                ],
                'platforms' => (array) $lockedPlan->platforms,
                'status' => 'questions_ready',
                'total_questions' => $questionCount,
                'completed_questions' => 0,
                'failed_questions' => 0,
                'brand_score' => 0,
                'mention_rate' => 0,
                'average_rank' => 0,
                'mention_count' => 0,
                'sentiment_rate' => 0,
                'billing_mode' => 'pending_confirmation',
                'points_cost' => 0,
                'limit_bypassed' => false,
                'limit_bypass_reason' => '',
            ]);

            foreach ($questions as $index => $question) {
                $run->questions()->create([
                    'site_id' => (int) $lockedPlan->site_id,
                    'owner_admin_id' => (int) ($lockedPlan->owner_admin_id ?: $admin->id),
                    'question' => $question['question'],
                    'question_type' => $question['type'],
                    'core_term' => $question['core_term'],
                    'sort_order' => $index + 1,
                    'status' => 'pending',
                ]);
            }

            return BrandMonitoringPlanRun::query()->create([
                'plan_id' => (int) $lockedPlan->id,
                'site_id' => (int) $lockedPlan->site_id,
                'owner_admin_id' => (int) ($lockedPlan->owner_admin_id ?: $admin->id),
                'admin_id' => (int) $admin->id,
                'diagnosis_run_id' => (int) $run->id,
                'execution_key' => $executionKey,
                'scheduled_for' => $scheduledAt,
                'status' => 'queued',
            ]);
        });

        if ($execution->status === 'skipped' || ! $execution->exists) {
            return $execution;
        }

        $run = $execution->diagnosisRun()->first();
        if (! $run instanceof BrandDiagnosisRun) {
            return $execution;
        }

        try {
            $questionMap = $run->questions()->orderBy('sort_order')->pluck('question', 'id')->all();
            $startedRun = $this->diagnosisRuns->confirm($admin, $run, $questionMap, (array) $plan->platforms);
            $execution->update([
                'status' => 'running',
                'started_at' => $startedRun->started_at ?? now(),
                'error_message' => null,
            ]);
            $plan->refresh()->update([
                'last_run_at' => $startedRun->started_at ?? now(),
                'last_run_id' => (int) $startedRun->id,
                'last_error' => null,
                'next_run_at' => ! $manual && $plan->status === 'active'
                    ? $this->scheduleCalculator->nextOccurrence($plan->refresh(), CarbonImmutable::now((string) config('app.timezone')))
                    : $plan->next_run_at,
            ]);
            if (! $manual && $plan->next_run_at === null) {
                $plan->update(['status' => 'completed']);
            }
        } catch (Throwable $exception) {
            $message = mb_strimwidth(trim($exception->getMessage()) ?: '品牌监测启动失败。', 0, 1000, '...', 'UTF-8');
            $run->update([
                'status' => 'failed',
                'error_message' => $message,
                'completed_at' => now(),
            ]);
            $execution->update(['status' => 'failed', 'completed_at' => now(), 'error_message' => $message]);
            $plan->refresh()->update([
                'last_error' => $message,
                'status' => ! $manual && $plan->status === 'active' ? 'paused' : $plan->status,
                'next_run_at' => ! $manual ? null : $plan->next_run_at,
            ]);

            throw $exception;
        }

        return $execution->refresh()->load('diagnosisRun');
    }

    /**
     * @Name: planForSite
     *
     * @Description: 使用站点编号再次限定计划查询，避免依赖全局作用域作为唯一授权边界。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:03:04
     *
     * @UpdateTime: 2026-11-23 10:50:42
     *
     * @Param: Site $site 当前站点
     *
     * @Param: int $planId 监测计划编号
     *
     * @Return BrandMonitoringPlan 通过当前站点校验的计划
     *
     * @Throws: RuntimeException 计划不存在或跨站点
     */
    private function planForSite(Site $site, int $planId): BrandMonitoringPlan
    {
        $plan = BrandMonitoringPlan::query()
            ->where('site_id', (int) $site->id)
            ->where('owner_admin_id', (int) $site->owner_admin_id)
            ->find($planId);
        if (! $plan instanceof BrandMonitoringPlan) {
            throw new RuntimeException('监测计划不存在或无权访问。');
        }

        return $plan;
    }

    /**
     * @Name: assertPlatformsAvailable
     *
     * @Description: 校验计划只选择受支持且仍启用、配置完整的平台，防止定时执行调用被运维关闭的模型。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:03:04
     *
     * @UpdateTime: 2026-11-23 10:03:04
     *
     * @Param: list<string> $platforms 模型标识列表
     *
     * @Return void 校验通过
     *
     * @Throws: RuntimeException 模型为空、不受支持或已关闭
     */
    private function assertPlatformsAvailable(array $platforms): void
    {
        $platforms = collect($platforms)
            ->map(static fn (mixed $platform): string => strtolower(trim((string) $platform)))
            ->filter(static fn (string $platform): bool => BrandDiagnosisPlatform::isSupported($platform))
            ->unique()
            ->values();
        if ($platforms->isEmpty()) {
            throw new RuntimeException('请至少选择一个可用 AI 模型。');
        }

        foreach ($platforms as $platform) {
            if (! $this->modelSettings->isEnabled($platform) || ! $this->modelSettings->isConfigured($platform)) {
                throw new RuntimeException(BrandDiagnosisPlatform::label($platform).'当前未启用或接口配置不完整。');
            }
        }
    }

    /**
     * @Name: assertQuestionCount
     *
     * @Description: 新快速模式限定为 5 道题、深度模式限定为 30 道题，并可兼容已有的 10 道快速计划。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:03:04
     *
     * @UpdateTime: 2026-11-23 17:37:54
     *
     * @Param: string $mode 诊断模式
     *
     * @Param: list<array<string,mixed>> $questions 计划固定问题
     *
     * @Param: bool $allowLegacyQuickTen 是否允许兼容已有的 10 道快速计划
     *
     * @Return void 校验通过
     *
     * @Throws: RuntimeException 问题数与所选诊断模式不匹配
     */
    private function assertQuestionCount(string $mode, array $questions, bool $allowLegacyQuickTen = false): void
    {
        $actual = count($this->normalizeQuestions($questions));
        $expected = $mode === 'deep' ? 30 : 5;
        if ($actual !== $expected && ! ($mode !== 'deep' && $allowLegacyQuickTen && $actual === 10)) {
            throw new RuntimeException($mode === 'deep' ? '深度思考模式需要 30 道监测问题。' : '快速模式需要 5 道监测问题。');
        }
    }

    /**
     * @Name: normalizeTerms
     *
     * @Description: 去除空项并按 UTF-8 文本去重，限制一个配置列表最多保留 30 个词条。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:03:04
     *
     * @UpdateTime: 2026-11-23 10:03:04
     *
     * @Param: list<string> $terms 用户提交的文本标签
     *
     * @Return list<string> 规范化标签
     *
     * @Throws: 无
     */
    private function normalizeTerms(array $terms): array
    {
        return collect($terms)
            ->map(static fn (mixed $term): string => mb_strimwidth(trim((string) $term), 0, 80, '', 'UTF-8'))
            ->filter(static fn (string $term): bool => $term !== '')
            ->unique()
            ->take(30)
            ->values()
            ->all();
    }

    /**
     * @Name: normalizeQuestions
     *
     * @Description: 清理计划问题字段、移除重复问题并限制最多 30 道，确保每轮任务使用同一组固定题目。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:03:04
     *
     * @UpdateTime: 2026-11-23 10:03:04
     *
     * @Param: list<array<string,mixed>> $questions 原始问题配置
     *
     * @Return list<array{question:string,type:string,core_term:string}> 规范化问题列表
     *
     * @Throws: 无
     */
    private function normalizeQuestions(array $questions): array
    {
        return collect($questions)
            ->map(static fn (mixed $question): array => is_array($question) ? [
                'question' => mb_strimwidth(trim((string) ($question['question'] ?? '')), 0, 500, '', 'UTF-8'),
                'type' => mb_strimwidth(trim((string) ($question['type'] ?? $question['question_type'] ?? '')), 0, 80, '', 'UTF-8'),
                'core_term' => mb_strimwidth(trim((string) ($question['core_term'] ?? '')), 0, 80, '', 'UTF-8'),
            ] : ['question' => '', 'type' => '', 'core_term' => ''])
            ->filter(static fn (array $question): bool => $question['question'] !== '')
            ->unique(static fn (array $question): string => mb_strtolower($question['question'], 'UTF-8'))
            ->take(30)
            ->values()
            ->all();
    }

    /**
     * @Name: hasInFlightExecution
     *
     * @Description: 依据关联诊断真实状态判断当前计划是否仍有一轮待确认或正在处理的任务。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:03:04
     *
     * @UpdateTime: 2026-11-23 10:03:04
     *
     * @Param: BrandMonitoringPlan $plan 需要判断的计划
     *
     * @Return bool 是否存在进行中的诊断
     *
     * @Throws: 无
     */
    private function hasInFlightExecution(BrandMonitoringPlan $plan): bool
    {
        $inFlightStatuses = ['queued', 'questions_generating', 'questions_ready', 'awaiting_confirmation', 'pending', 'running'];

        return $plan->executions()
            ->where(function (Builder $query) use ($inFlightStatuses): void {
                $query->whereHas('diagnosisRun', fn (Builder $runQuery): Builder => $runQuery->whereIn('status', $inFlightStatuses))
                    ->orWhere(function (Builder $pendingQuery): void {
                        $pendingQuery->whereNull('diagnosis_run_id')->where('status', 'queued');
                    });
            })
            ->exists();
    }

    /**
     * @Name: synchronizeExecutionStates
     *
     * @Description: 将诊断队列最终状态同步到计划执行记录，避免已完成诊断永久占用计划运行锁。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:17:20
     *
     * @UpdateTime: 2026-11-23 10:17:20
     *
     * @Param: int $siteId 当前站点编号；零值表示调度器扫描全部站点
     *
     * @Param: int|null $planId 可选的单个计划编号
     *
     * @Return int 同步的执行记录数量
     *
     * @Throws: 无
     */
    private function synchronizeExecutionStates(int $siteId = 0, ?int $planId = null): int
    {
        $executions = BrandMonitoringPlanRun::query()
            ->whereIn('status', ['queued', 'running'])
            ->whereHas('diagnosisRun', fn (Builder $query): Builder => $query->whereIn('status', ['completed', 'failed']))
            ->with('diagnosisRun:id,status,completed_at,error_message')
            ->when($siteId > 0, fn (Builder $query): Builder => $query->where('site_id', $siteId))
            ->when($planId !== null, fn (Builder $query): Builder => $query->where('plan_id', $planId))
            ->limit(500)
            ->get();

        foreach ($executions as $execution) {
            $run = $execution->diagnosisRun;
            if (! $run instanceof BrandDiagnosisRun) {
                continue;
            }

            $status = (string) $run->status;
            $message = $status === 'failed' ? trim((string) $run->error_message) : '';
            $execution->update([
                'status' => $status,
                'completed_at' => $run->completed_at ?? now(),
                'error_message' => $message !== '' ? mb_strimwidth($message, 0, 1000, '...', 'UTF-8') : null,
            ]);
            if ($status === 'failed') {
                $execution->plan()->update(['last_error' => $message !== '' ? $message : '本轮品牌诊断执行失败。']);
            }
        }

        return $executions->count();
    }

    /**
     * @Name: presentPlan
     *
     * @Description: 将计划和执行历史映射为监测中心安全展示字段，不返回 AI 原始响应或账户敏感数据。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:03:04
     *
     * @UpdateTime: 2026-11-23 10:03:04
     *
     * @Param: BrandMonitoringPlan $plan 监测计划
     *
     * @Param: bool $includeExecutions 是否包含执行历史
     *
     * @Return array<string,mixed> 前端计划结构
     *
     * @Throws: 无
     */
    private function presentPlan(BrandMonitoringPlan $plan, bool $includeExecutions = false): array
    {
        $executions = $plan->relationLoaded('executions') ? $plan->executions : collect();

        return [
            'id' => (int) $plan->id,
            'name' => (string) $plan->name,
            'brand_name' => (string) $plan->brand_name,
            'website_url' => (string) ($plan->website_url ?? ''),
            'brand_profile' => (string) ($plan->brand_profile ?? ''),
            'brand_terms' => (array) $plan->brand_terms,
            'products' => (array) $plan->products,
            'competitors' => (array) $plan->competitors,
            'keywords' => (array) $plan->keywords,
            'questions' => (array) $plan->questions,
            'question_count' => count((array) $plan->questions),
            'platforms' => (array) $plan->platforms,
            'diagnosis_mode' => (string) $plan->diagnosis_mode,
            'frequency' => (string) $plan->frequency,
            'frequency_interval' => (int) $plan->frequency_interval,
            'run_time' => (string) $plan->run_time,
            'weekdays' => (array) $plan->weekdays,
            'month_day' => $plan->month_day !== null ? (int) $plan->month_day : null,
            'period_mode' => (string) $plan->period_mode,
            'starts_on' => (string) $plan->starts_on,
            'ends_on' => (string) ($plan->ends_on ?? ''),
            'status' => (string) $plan->status,
            'next_run_at' => $plan->next_run_at?->toIso8601String(),
            'last_run_at' => $plan->last_run_at?->toIso8601String(),
            'last_run_id' => $plan->last_run_id !== null ? (int) $plan->last_run_id : null,
            'last_error' => (string) ($plan->last_error ?? ''),
            'latest_execution' => $executions->first() instanceof BrandMonitoringPlanRun
                ? $this->presentExecution($executions->first())
                : null,
            'executions' => $includeExecutions
                ? $executions->map(fn (BrandMonitoringPlanRun $execution): array => $this->presentExecution($execution))->values()->all()
                : [],
            'created_at' => $plan->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @Name: presentExecution
     *
     * @Description: 返回单轮诊断进度和可查看报告的记录编号，并优先读取诊断任务的实时状态。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:03:04
     *
     * @UpdateTime: 2026-11-23 10:31:31
     *
     * @Param: BrandMonitoringPlanRun $execution 单轮执行记录
     *
     * @Return array<string,mixed> 执行状态、进度和报告编号
     *
     * @Throws: 无
     */
    private function presentExecution(BrandMonitoringPlanRun $execution): array
    {
        $run = $execution->relationLoaded('diagnosisRun') ? $execution->diagnosisRun : $execution->diagnosisRun()->first();
        $runStatus = (string) ($run?->status ?? $execution->status);
        $status = match ($runStatus) {
            'completed' => 'completed',
            'failed' => 'failed',
            'queued', 'pending', 'questions_generating', 'questions_ready', 'awaiting_confirmation', 'running' => 'running',
            default => (string) $execution->status,
        };

        return [
            'id' => (int) $execution->id,
            'diagnosis_run_id' => $run?->id !== null ? (int) $run->id : null,
            'scheduled_for' => $execution->scheduled_for?->toIso8601String(),
            'status' => $status,
            'total_questions' => (int) ($run?->total_questions ?? 0),
            'completed_questions' => (int) ($run?->completed_questions ?? 0),
            'failed_questions' => (int) ($run?->failed_questions ?? 0),
            'started_at' => $run?->started_at?->toIso8601String() ?? $execution->started_at?->toIso8601String(),
            'completed_at' => $run?->completed_at?->toIso8601String() ?? $execution->completed_at?->toIso8601String(),
            'error_message' => (string) ($run?->error_message ?? $execution->error_message ?? ''),
        ];
    }
}
