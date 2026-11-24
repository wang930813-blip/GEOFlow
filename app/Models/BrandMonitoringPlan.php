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
 * @File： BrandMonitoringPlan.php
 *
 * @Description: 保存客户品牌、问题集、模型与周期配置，并隔离站点和账号数据。
 */

namespace App\Models;

use App\Models\Concerns\BelongsToAdminOwner;
use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BrandMonitoringPlan extends Model
{
    use BelongsToAdminOwner;
    use BelongsToSite;
    use SoftDeletes;

    protected $fillable = [
        'site_id',
        'owner_admin_id',
        'admin_id',
        'name',
        'brand_name',
        'website_url',
        'brand_profile',
        'brand_terms',
        'products',
        'competitors',
        'keywords',
        'questions',
        'platforms',
        'diagnosis_mode',
        'frequency',
        'frequency_interval',
        'run_time',
        'weekdays',
        'month_day',
        'period_mode',
        'starts_on',
        'ends_on',
        'status',
        'next_run_at',
        'last_run_at',
        'last_run_id',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
            'admin_id' => 'integer',
            'brand_terms' => 'array',
            'products' => 'array',
            'competitors' => 'array',
            'keywords' => 'array',
            'questions' => 'array',
            'platforms' => 'array',
            'frequency_interval' => 'integer',
            'weekdays' => 'array',
            'month_day' => 'integer',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
            'last_run_id' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    public function lastDiagnosisRun(): BelongsTo
    {
        return $this->belongsTo(BrandDiagnosisRun::class, 'last_run_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(BrandMonitoringPlanRun::class, 'plan_id')->orderByDesc('scheduled_for');
    }
}
