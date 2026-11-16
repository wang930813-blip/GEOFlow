<?php

/**
 * Created by Codex.
 * @Date: 2026-11-12
 * @Time: 16:04:59
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： WebsitePlugin.php
 * @Description: 保存站点级插件配置及启用状态，配置整体加密。
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class WebsitePlugin extends Model
{
    protected $fillable = ['site_id', 'plugin_id', 'settings', 'enabled'];

    protected $hidden = ['settings'];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'settings' => 'encrypted:array',
            'enabled' => 'boolean',
        ];
    }
}
