<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-11-23
 *
 * @Time: 10:03:04
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： BrandMonitoringPlanRun.php
 *
 * @Description: 关联监测计划与单次品牌诊断，记录调度、完成和失败状态。
 */

namespace App\Models;

use App\Models\Concerns\BelongsToAdminOwner;
use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandMonitoringPlanRun extends Model
{
    use BelongsToAdminOwner;
    use BelongsToSite;

    protected $fillable = [
        'plan_id',
        'site_id',
        'owner_admin_id',
        'admin_id',
        'diagnosis_run_id',
        'execution_key',
        'scheduled_for',
        'status',
        'started_at',
        'completed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'plan_id' => 'integer',
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
            'admin_id' => 'integer',
            'diagnosis_run_id' => 'integer',
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BrandMonitoringPlan::class, 'plan_id');
    }

    public function diagnosisRun(): BelongsTo
    {
        return $this->belongsTo(BrandDiagnosisRun::class, 'diagnosis_run_id');
    }
}
