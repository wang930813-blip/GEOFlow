<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-11-19
 *
 * @Time: 12:11:31
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： OpenPlatformAccessToken.php
 *
 * @Description: 保存不可逆摘要形式的开放平台短期访问令牌。
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OpenPlatformAccessToken extends Model
{
    protected $hidden = [
        'token_hash',
    ];

    protected $fillable = [
        'application_id',
        'token_hash',
        'expires_at',
        'revoked_at',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'application_id' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(OpenPlatformApplication::class, 'application_id');
    }
}
