<?php

/**
 * Created by Codex.
 * @Date: 2026-09-21
 * @Time: 16:06:48
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File：ProductCaseImport.php
 * @Description: 产品案例异步导入任务模型
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductCaseImport extends Model
{
    protected $table = 'product_case_imports';

    protected $fillable = [
        'site_id',
        'owner_admin_id',
        'created_by_admin_id',
        'original_filename',
        'stored_path',
        'file_sha256',
        'status',
        'total_rows',
        'processed_rows',
        'created_count',
        'updated_count',
        'images_uploaded',
        'images_reused',
        'diagnosis_runs',
        'failed_count',
        'options_json',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
            'created_by_admin_id' => 'integer',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'images_uploaded' => 'integer',
            'images_reused' => 'integer',
            'diagnosis_runs' => 'integer',
            'failed_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * 获取导入任务所属站点。
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 16:06:48
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * 获取案例归属管理员。
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 16:06:48
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'owner_admin_id');
    }

    /**
     * 获取实际发起导入的管理员。
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 16:06:48
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    /**
     * 获取导入逐行结果。
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 16:06:48
     */
    public function items(): HasMany
    {
        return $this->hasMany(ProductCaseImportItem::class, 'product_case_import_id');
    }
}
