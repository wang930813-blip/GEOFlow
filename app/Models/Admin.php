<?php

/**
 * 后台管理员（表 `admins`）。
 *
 * Blade 后台与 API 审计共用；会话登录使用 `admin` guard。密码 `hashed` cast；`name` 访问器供界面展示。
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable
{
    use HasApiTokens;
    use SoftDeletes;

    protected $table = 'admins';

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $fillable = [
        'username',
        'password',
        'email',
        'mobile',
        'display_name',
        'role',
        'status',
        'created_by',
        'last_login',
        'welcome_seen_version',
        'welcome_dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_login' => 'datetime',
            'welcome_dismissed_at' => 'datetime',
            'created_by' => 'integer',
            'password' => 'hashed',
            'deleted_at' => 'datetime',
        ];
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    /**
     * 顶栏等使用 `name` 展示。
     */
    public function getNameAttribute(): string
    {
        $display = trim((string) $this->display_name);

        return $display !== '' ? $display : (string) $this->username;
    }

    /**
     * 统一判断超级管理员角色，兼容历史脏值 superadmin。
     */
    public function isSuperAdmin(): bool
    {
        $role = trim(strtolower((string) ($this->role ?? '')));

        return in_array($role, ['super_admin', 'superadmin'], true);
    }

    public function isAgentAdmin(): bool
    {
        return trim(strtolower((string) ($this->role ?? ''))) === 'agent_admin';
    }

    public function isDirectAdmin(): bool
    {
        return trim(strtolower((string) ($this->role ?? ''))) === 'direct_admin';
    }

    public function isSiteUser(): bool
    {
        return trim(strtolower((string) ($this->role ?? ''))) === 'site_user';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(AdminActivityLog::class, 'admin_id');
    }

    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'site_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function articleReviews(): HasMany
    {
        return $this->hasMany(ArticleReview::class, 'admin_id');
    }

    public function accountPlanSubscriptions(): HasMany
    {
        return $this->hasMany(AdminPlanSubscription::class, 'admin_id');
    }

    public function creditAccount(): HasOne
    {
        return $this->hasOne(AdminCreditAccount::class, 'admin_id');
    }
}
