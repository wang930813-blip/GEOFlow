<?php

namespace App\Services\BrandDiagnosis;

use App\Models\Admin;
use App\Models\BrandDiagnosisRun;
use App\Models\BrandDiagnosisUsageLimit;
use App\Models\PlatformPlan;
use App\Services\Billing\ResourceQuotaService;
use Illuminate\Support\Facades\DB;

class BrandDiagnosisUsagePolicy
{
    public function __construct(
        private readonly ResourceQuotaService $quotaService
    ) {}

    public function reserve(Admin $admin, int $siteId): BrandDiagnosisUsageDecision
    {
        if ($admin->isSuperAdmin()) {
            return new BrandDiagnosisUsageDecision(
                billingMode: 'admin_unlimited',
                limitBypassed: true,
                limitBypassReason: 'super_admin',
            );
        }

        $this->quotaService->consume($siteId, PlatformPlan::RESOURCE_BRAND_DIAGNOSES, 1, [
            'actor_admin_id' => (int) $admin->id,
            'idempotency_key' => 'brand-diagnosis:'.(int) $admin->id.':'.$siteId.':'.now()->timestamp.':'.str()->random(8),
            'remark' => '品牌诊断确认消耗',
        ]);

        return new BrandDiagnosisUsageDecision('plan_quota', false, '');
    }

    public function reserveDailyFree(Admin $admin, int $siteId): BrandDiagnosisUsageDecision
    {
        $today = now()->toDateString();
        $limit = max(1, (int) config('brand_diagnosis.daily_free_limit', 1));

        return DB::transaction(function () use ($admin, $siteId, $today, $limit): BrandDiagnosisUsageDecision {
            $existingRunCount = BrandDiagnosisRun::query()
                ->withoutGlobalScope('current_site')
                ->where('site_id', $siteId)
                ->where('admin_id', (int) $admin->id)
                ->whereDate('usage_date', $today)
                ->whereNotIn('billing_mode', ['pending_confirmation'])
                ->count();

            $usage = BrandDiagnosisUsageLimit::query()
                ->withoutGlobalScope('current_site')
                ->where('site_id', $siteId)
                ->where('admin_id', (int) $admin->id)
                ->whereDate('usage_date', $today)
                ->lockForUpdate()
                ->first();

            if (! $usage) {
                if ($existingRunCount >= $limit) {
                    throw new BrandDiagnosisLimitExceededException('普通用户每天只能免费诊断一次，后续可通过积分抵扣增加诊断次数。');
                }

                BrandDiagnosisUsageLimit::query()->create([
                    'site_id' => $siteId,
                    'admin_id' => (int) $admin->id,
                    'usage_date' => $today,
                    'free_runs_used' => $existingRunCount + 1,
                ]);

                return new BrandDiagnosisUsageDecision('daily_free', false, '');
            }

            if (max((int) $usage->free_runs_used, $existingRunCount) >= $limit) {
                throw new BrandDiagnosisLimitExceededException('普通用户每天只能免费诊断一次，后续可通过积分抵扣增加诊断次数。');
            }

            $usage->increment('free_runs_used');

            return new BrandDiagnosisUsageDecision('daily_free', false, '');
        });
    }
}
