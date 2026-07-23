<?php

namespace App\Services\BrandDiagnosis;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\BrandDiagnosisRun;

class BrandDiagnosisApiService
{
    public function __construct(
        private readonly BrandDiagnosisRunService $runs,
        private readonly BrandDiagnosisApiTaskKey $taskKeys,
    ) {}

    public function create(string $brandName, array $models): BrandDiagnosisRun
    {
        return $this->runs->createForApi($this->resolveOwnerAdmin(), $brandName, $models, $this->uniqueTaskKey());
    }

    public function findByTaskKey(string $taskKey): BrandDiagnosisRun
    {
        if (! $this->taskKeys->isValid($taskKey)) {
            throw new ApiException('diagnosis_not_found', '诊断任务不存在或任务 ID 格式不正确', 404);
        }

        $run = BrandDiagnosisRun::query()
            ->withoutGlobalScope('current_site')
            ->with([
                'questions' => fn ($query) => $query->orderBy('sort_order'),
                'questions.results.sources',
                'questions.results.brandMentions',
                'brandMentions',
                'sources',
            ])
            ->where('api_task_key', $taskKey)
            ->first();

        if (! $run instanceof BrandDiagnosisRun) {
            throw new ApiException('diagnosis_not_found', '诊断任务不存在或任务 ID 格式不正确', 404);
        }

        return $run;
    }

    private function uniqueTaskKey(): string
    {
        do {
            $taskKey = $this->taskKeys->generate(0);
        } while (BrandDiagnosisRun::query()->withoutGlobalScope('current_site')->where('api_task_key', $taskKey)->exists());

        return $taskKey;
    }

    private function resolveOwnerAdmin(): Admin
    {
        $configuredAdminId = (int) config('brand_diagnosis.open_api.admin_id', 0);
        $query = Admin::query()->where('status', 'active')->whereIn('role', ['super_admin', 'superadmin']);
        $admin = $configuredAdminId > 0
            ? (clone $query)->whereKey($configuredAdminId)->first()
            : $query->orderBy('id')->first();

        if (! $admin instanceof Admin) {
            throw new ApiException('open_api_admin_not_found', '品牌诊断开放 API 未配置可用超管账号', 500);
        }

        return $admin;
    }
}
