<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-11-19
 *
 * @Time: 12:11:31
 *
 * @UpdateTime: 2026-11-20 10:41:07
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： OpenPlatformApplication.php
 *
 * @Description: 保存绑定到账号和站点的开放平台应用及其访问范围。
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class OpenPlatformApplication extends Model
{
    use SoftDeletes;

    public const AVAILABLE_SCOPES = [
        'reports:read',
        'diagnosis-records:read',
        'platforms:read',
        'questions:read',
        'mentions:read',
        'sentiment:read',
        'sources:read',
        'conversations:read',
        'citations:read',
    ];

    protected $hidden = [
        'client_secret_hash',
    ];

    protected $fillable = [
        'site_id',
        'owner_admin_id',
        'created_by_admin_id',
        'name',
        'description',
        'client_id',
        'client_secret_hash',
        'scopes',
        'is_active',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
            'created_by_admin_id' => 'integer',
            'scopes' => 'array',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function accessTokens(): HasMany
    {
        return $this->hasMany(OpenPlatformAccessToken::class, 'application_id');
    }
}
