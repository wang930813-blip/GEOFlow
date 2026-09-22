<?php

/**
 * Created by Codex.
 * @Date: 2026-09-21
 * @Time: 16:06:48
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File：ProductCaseImportItem.php
 * @Description: 产品案例异步导入任务逐行结果模型
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCaseImportItem extends Model
{
    protected $table = 'product_case_import_items';

    protected $fillable = [
        'product_case_import_id',
        'row_number',
        'brand_name',
        'title',
        'status',
        'action',
        'product_case_id',
        'result_json',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'product_case_import_id' => 'integer',
            'row_number' => 'integer',
            'product_case_id' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * 获取所属导入任务。
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 16:06:48
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(ProductCaseImport::class, 'product_case_import_id');
    }

    /**
     * 获取导入后的案例。
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 16:06:48
     */
    public function productCase(): BelongsTo
    {
        return $this->belongsTo(ProductCase::class, 'product_case_id');
    }
}
