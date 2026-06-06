<?php

namespace App\Services\BrandDiagnosis;

use App\Jobs\ProcessBrandDiagnosisJob;
use App\Models\Admin;
use App\Models\BrandDiagnosisRun;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BrandDiagnosisRunService
{
    public function __construct(
        private readonly BrandDiagnosisUsagePolicy $usagePolicy,
        private readonly CurrentSite $currentSite,
    ) {}

    /**
     * @param  list<string>  $platforms
     */
    public function create(Admin $admin, string $brandName, array $platforms): BrandDiagnosisRun
    {
        $brandName = trim($brandName);
        if ($brandName === '') {
            throw new RuntimeException('请输入品牌名称。');
        }

        $platforms = $this->normalizePlatforms($platforms);
        $siteId = (int) ($this->currentSite->id() ?? 0);
        if ($siteId <= 0) {
            throw new RuntimeException('当前站点未初始化，请刷新后台后重试。');
        }

        $decision = $this->usagePolicy->reserve($admin, $siteId);

        $run = DB::transaction(function () use ($admin, $brandName, $platforms, $siteId, $decision): BrandDiagnosisRun {
            return BrandDiagnosisRun::query()->create([
                'site_id' => $siteId,
                'admin_id' => (int) $admin->id,
                'brand_name' => $brandName,
                'platforms' => $platforms,
                'status' => 'pending',
                'total_questions' => 0,
                'completed_questions' => 0,
                'failed_questions' => 0,
                'billing_mode' => $decision->billingMode,
                'points_cost' => 0,
                'points_transaction_id' => null,
                'limit_bypassed' => $decision->limitBypassed,
                'limit_bypass_reason' => $decision->limitBypassReason,
                'usage_date' => now()->toDateString(),
            ]);
        });

        ProcessBrandDiagnosisJob::dispatch((int) $run->id)->onQueue('geoflow');

        return $run;
    }

    /**
     * @param  list<string>  $platforms
     * @return list<string>
     */
    private function normalizePlatforms(array $platforms): array
    {
        $allowed = ['doubao'];
        $normalized = collect($platforms)
            ->map(static fn (mixed $platform): string => strtolower(trim((string) $platform)))
            ->filter(static fn (string $platform): bool => in_array($platform, $allowed, true))
            ->unique()
            ->values()
            ->all();

        return $normalized !== [] ? $normalized : ['doubao'];
    }
}
