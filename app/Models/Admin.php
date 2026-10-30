<?php

/**
 * 后台管理员（表 `admins`）。
 *
 * Blade 后台与 API 审计共用；会话登录使用 `admin` guard。密码 `hashed` cast；`name` 访问器供界面展示。
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable
{
    use HasApiTokens;
    use SoftDeletes;

    public const AUTH_VERSION_SESSION_KEY = 'admin_auth_version';

    protected $table = 'admins';

    protected $attributes = [
        'auth_version' => 1,
        'ai_config_access_version' => 1,
    ];

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
        'auth_version',
        'welcome_seen_version',
        'welcome_dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_login' => 'datetime',
            'welcome_dismissed_at' => 'datetime',
            'created_by' => 'integer',
            'auth_version' => 'integer',
            'shared_ai_config_owner_id' => 'integer',
            'ai_config_access_version' => 'integer',
            'password' => 'hashed',
            'deleted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $admin): void {
            if ($admin->isForceDeleting() || ! $admin->exists || ! $admin->getKey()) {
                return;
            }

            $admin->forceFill([
                'username' => $admin->releasedUsername(),
                'mobile' => null,
            ])->saveQuietly();
        });
    }

    private function releasedUsername(): string
    {
        $hash = substr(sha1((string) $this->getKey().'|'.(string) $this->getOriginal('username')), 0, 16);

        return 'deleted_'.$this->getKey().'_'.$hash;
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

    /**
     * @Name: scopeActive
     * @Description: 限定查询结果为状态正常且未软删除的管理员。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:13:33
     * @UpdateTime: 2026-10-29 12:13:33
     *
     * @Param: Builder $query 管理员查询构造器
     * @Return: Builder 管理员查询构造器
     * @Throws: 无
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * @Name: scopeSuperAdmins
     * @Description: 查询超级管理员并兼容历史角色值。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:13:33
     * @UpdateTime: 2026-10-29 12:13:33
     *
     * @Param: Builder $query 管理员查询构造器
     * @Return: Builder 管理员查询构造器
     * @Throws: 无
     */
    public function scopeSuperAdmins(Builder $query): Builder
    {
        return $query->whereIn('role', ['super_admin', 'superadmin']);
    }

    /**
     * @Name: canManageProtectedWorkflows
     * @Description: 判断当前管理员是否有权执行受保护的管理流程。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:13:33
     * @UpdateTime: 2026-10-29 12:13:33
     *
     * @Return: bool 是否允许管理
     * @Throws: 无
     */
    public function canManageProtectedWorkflows(): bool
    {
        return $this->isSuperAdmin();
    }

    /**
     * @Name: revokeAuthenticationCredentials
     * @Description: 锁定管理员记录、递增会话版本并撤销访问令牌，使现存会话失效。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:13:33
     * @UpdateTime: 2026-10-29 12:13:33
     *
     * @Return: void 无返回值
     * @Throws: \Illuminate\Database\Eloquent\ModelNotFoundException 管理员记录不存在
     */
    public function revokeAuthenticationCredentials(): void
    {
        DB::transaction(function (): void {
            /** @var self $admin */
            $admin = self::query()
                ->whereKey($this->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $admin->forceFill([
                'auth_version' => (int) $admin->auth_version + 1,
                'remember_token' => Str::random(60),
            ])->save();
            $admin->tokens()->delete();

            $this->setRawAttributes($admin->getAttributes(), true);
        });
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

    /**
     * @Name: sharedAiConfigOwner
     * @Description: 返回当前管理员使用的共享 AI 配置提供方。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:13:33
     * @UpdateTime: 2026-10-29 12:13:33
     *
     * @Return: BelongsTo AI 配置提供方关联
     * @Throws: 无
     */
    public function sharedAiConfigOwner(): BelongsTo
    {
        return $this->belongsTo(self::class, 'shared_ai_config_owner_id');
    }

    /**
     * @Name: aiConfigDependents
     * @Description: 返回依赖当前管理员共享 AI 配置的账号。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:13:33
     * @UpdateTime: 2026-10-29 12:13:33
     *
     * @Return: HasMany 依赖账号关联
     * @Throws: 无
     */
    public function aiConfigDependents(): HasMany
    {
        return $this->hasMany(self::class, 'shared_ai_config_owner_id');
    }

    /**
     * @Name: ownedAiModels
     * @Description: 返回当前管理员拥有的 AI 模型配置。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:13:33
     * @UpdateTime: 2026-10-29 12:13:33
     *
     * @Return: HasMany AI 模型关联
     * @Throws: 无
     */
    public function ownedAiModels(): HasMany
    {
        return $this->hasMany(AiModel::class, 'owner_admin_id');
    }

    /**
     * @Name: aiSettings
     * @Description: 返回当前管理员的默认 AI 模型设置。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:13:33
     * @UpdateTime: 2026-10-29 12:13:33
     *
     * @Return: HasOne AI 设置关联
     * @Throws: 无
     */
    public function aiSettings(): HasOne
    {
        return $this->hasOne(AdminAiSetting::class);
    }

    /**
     * @Name: updatedAiSettings
     * @Description: 返回由当前管理员最后修改的 AI 设置记录。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:13:33
     * @UpdateTime: 2026-10-29 12:13:33
     *
     * @Return: HasMany AI 设置修改记录
     * @Throws: 无
     */
    public function updatedAiSettings(): HasMany
    {
        return $this->hasMany(AdminAiSetting::class, 'updated_by_admin_id');
    }

    /**
     * @Name: ownedAiModelUsageEvents
     * @Description: 返回当前管理员名下模型产生的用量审计记录。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:13:33
     * @UpdateTime: 2026-10-29 12:13:33
     *
     * @Return: HasMany 模型用量记录
     * @Throws: 无
     */
    public function ownedAiModelUsageEvents(): HasMany
    {
        return $this->hasMany(AiModelUsageEvent::class, 'config_owner_admin_id');
    }

    /**
     * @Name: executedAiModelUsageEvents
     * @Description: 返回由当前管理员执行的 AI 模型用量审计记录。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:13:33
     * @UpdateTime: 2026-10-29 12:13:33
     *
     * @Return: HasMany 执行用量记录
     * @Throws: 无
     */
    public function executedAiModelUsageEvents(): HasMany
    {
        return $this->hasMany(AiModelUsageEvent::class, 'execution_admin_id');
    }

    /**
     * @Name: aiExecutionTasks
     * @Description: 返回使用当前管理员身份授权执行的任务。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:13:33
     * @UpdateTime: 2026-10-29 12:13:33
     *
     * @Return: HasMany 任务关联
     * @Throws: 无
     */
    public function aiExecutionTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'model_access_admin_id');
    }

    /**
     * @Name: aiExecutionTaskRuns
     * @Description: 返回使用当前管理员身份授权执行的任务运行记录。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:13:33
     * @UpdateTime: 2026-10-29 12:13:33
     *
     * @Return: HasMany 任务运行关联
     * @Throws: 无
     */
    public function aiExecutionTaskRuns(): HasMany
    {
        return $this->hasMany(TaskRun::class, 'model_access_admin_id');
    }

    /**
     * @Name: aiExecutionUrlImportJobs
     * @Description: 返回使用当前管理员身份授权执行的 URL 导入任务。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:13:33
     * @UpdateTime: 2026-10-29 12:13:33
     *
     * @Return: HasMany URL 导入任务关联
     * @Throws: 无
     */
    public function aiExecutionUrlImportJobs(): HasMany
    {
        return $this->hasMany(UrlImportJob::class, 'model_access_admin_id');
    }

    /**
     * @Name: aiWorkspaceRuns
     * @Description: 返回由当前管理员创建的 AI 工作台运行记录。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:13:33
     * @UpdateTime: 2026-10-29 12:13:33
     *
     * @Return: HasMany AI 工作台运行关联
     * @Throws: 无
     */
    public function aiWorkspaceRuns(): HasMany
    {
        return $this->hasMany(AiWorkspaceRun::class, 'admin_id');
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
