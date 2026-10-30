<?php

/**
 * Created by Codex.
 * @Date: 2026-10-29
 * @Time: 12:10:42
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： AiModel.php
 * @Description: 管理站点模型、管理员归属、访问权限和 AI 模型运行状态。
 */

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class AiModel extends Model
{
    use BelongsToSite;

    public const ACCESS_SCOPE_USER_CONTENT = 'user_content';

    public const ACCESS_SCOPE_SYSTEM_ONLY = 'system_only';

    protected $table = 'ai_models';

    protected $attributes = [
        'access_scope' => self::ACCESS_SCOPE_USER_CONTENT,
        'failover_priority' => 100,
    ];

    protected $hidden = [
        'api_key',
    ];

    protected $fillable = [
        'name',
        'site_id',
        'version',
        'api_key',
        'model_id',
        'model_type',
        'api_url',
        'failover_priority',
        'daily_limit',
        'used_today',
        'usage_date',
        'total_used',
        'status',
        'max_tokens',
        'ai_workspace_structured_output_status',
        'ai_workspace_structured_output_verified_at',
        'ai_workspace_readiness_status',
        'ai_workspace_readiness_profile',
        'ai_workspace_readiness_checked_at',
        'ai_workspace_readiness_expires_at',
        'ai_workspace_readiness_failure_code',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
            'failover_priority' => 'integer',
            'daily_limit' => 'integer',
            'used_today' => 'integer',
            'usage_date' => 'date',
            'total_used' => 'integer',
            'max_tokens' => 'integer',
            'ai_workspace_structured_output_verified_at' => 'datetime',
            'ai_workspace_readiness_profile' => 'array',
            'ai_workspace_readiness_checked_at' => 'immutable_datetime',
            'ai_workspace_readiness_expires_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /**
     * @Name: booted
     * @Description: 模型凭据或能力配置变化时清除旧探测结果，并将 AI 工作台就绪状态标记为过期。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:10:42
     * @UpdateTime: 2026-10-29 12:10:42
     *
     * @Return: void
     * @Throws: 无
     */
    protected static function booted(): void
    {
        static::updating(function (AiModel $model): void {
            if (! $model->isDirty(['version', 'model_id', 'model_type', 'api_url', 'api_key', 'status', 'max_tokens'])) {
                return;
            }

            $model->ai_workspace_structured_output_status = null;
            $model->ai_workspace_structured_output_verified_at = null;

            if (Schema::hasColumn('ai_models', 'ai_workspace_readiness_status')) {
                $model->ai_workspace_readiness_status = 'stale';
                $model->ai_workspace_readiness_profile = null;
                $model->ai_workspace_readiness_checked_at = null;
                $model->ai_workspace_readiness_expires_at = null;
                $model->ai_workspace_readiness_failure_code = null;
            }
        });
    }

    /**
     * @Name: titleLibraries
     * @Description: 返回当前模型关联的标题库。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:10:42
     * @UpdateTime: 2026-10-29 12:10:42
     *
     * @Return: HasMany 标题库关联
     * @Throws: 无
     */
    public function titleLibraries(): HasMany
    {
        return $this->hasMany(TitleLibrary::class, 'ai_model_id');
    }

    /**
     * @Name: owner
     * @Description: 返回模型配置所属的管理员，供上游 AI 权限与治理流程使用。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:10:42
     * @UpdateTime: 2026-10-29 12:10:42
     *
     * @Return: BelongsTo 管理员关联
     * @Throws: 无
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'owner_admin_id');
    }

    /**
     * @Name: ownerAdmin
     * @Description: 返回国内版账号归属关系，兼容现有后台数据隔离逻辑。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:10:42
     * @UpdateTime: 2026-10-29 12:10:42
     *
     * @Return: BelongsTo 管理员关联
     * @Throws: 无
     */
    public function ownerAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'owner_admin_id');
    }

    /**
     * @Name: chatDefaultForAdmins
     * @Description: 返回将当前模型设为默认对话模型的管理员配置。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:10:42
     * @UpdateTime: 2026-10-29 12:10:42
     *
     * @Return: HasMany 管理员模型设置关联
     * @Throws: 无
     */
    public function chatDefaultForAdmins(): HasMany
    {
        return $this->hasMany(AdminAiSetting::class, 'default_chat_model_id');
    }

    /**
     * @Name: embeddingDefaultForAdmins
     * @Description: 返回将当前模型设为默认向量模型的管理员配置。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:10:42
     * @UpdateTime: 2026-10-29 12:10:42
     *
     * @Return: HasMany 管理员模型设置关联
     * @Throws: 无
     */
    public function embeddingDefaultForAdmins(): HasMany
    {
        return $this->hasMany(AdminAiSetting::class, 'default_embedding_model_id');
    }

    /**
     * @Name: usageEvents
     * @Description: 返回模型调用用量审计记录。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:10:42
     * @UpdateTime: 2026-10-29 12:10:42
     *
     * @Return: HasMany 用量审计关联
     * @Throws: 无
     */
    public function usageEvents(): HasMany
    {
        return $this->hasMany(AiModelUsageEvent::class, 'ai_model_id');
    }

    /**
     * @Name: tasks
     * @Description: 返回使用当前模型执行的常规任务。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:10:42
     * @UpdateTime: 2026-10-29 12:10:42
     *
     * @Return: HasMany 任务关联
     * @Throws: 无
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'ai_model_id');
    }

    /**
     * @Name: qualityTasks
     * @Description: 返回使用当前模型执行质量评估的任务。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:10:42
     * @UpdateTime: 2026-10-29 12:10:42
     *
     * @Return: HasMany 质量任务关联
     * @Throws: 无
     */
    public function qualityTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'ai_quality_model_id');
    }

    /**
     * @Name: visibilityRuns
     * @Description: 返回使用当前模型执行的 AI 可见性任务。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:10:42
     * @UpdateTime: 2026-10-29 12:10:42
     *
     * @Return: HasMany 可见性任务关联
     * @Throws: 无
     */
    public function visibilityRuns(): HasMany
    {
        return $this->hasMany(AiVisibilityRun::class, 'ai_model_id');
    }

    /**
     * @Name: scopeOwnedBy
     * @Description: 限定查询结果只包含指定管理员拥有的模型。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:10:42
     * @UpdateTime: 2026-10-29 12:10:42
     *
     * @Param: Admin|int $owner 管理员模型或主键
     * @Return: Builder 模型查询构造器
     * @Throws: 无
     */
    public function scopeOwnedBy(Builder $query, Admin|int $owner): Builder
    {
        return $query->where('owner_admin_id', $owner instanceof Admin ? $owner->getKey() : $owner);
    }

    /**
     * @Name: scopeUserContent
     * @Description: 限定查询结果为可用于用户内容任务的模型。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:10:42
     * @UpdateTime: 2026-10-29 12:10:42
     *
     * @Return: Builder 模型查询构造器
     * @Throws: 无
     */
    public function scopeUserContent(Builder $query): Builder
    {
        return $query->where('access_scope', self::ACCESS_SCOPE_USER_CONTENT);
    }

    /**
     * @Name: scopeSystemOnly
     * @Description: 限定查询结果为仅供系统内部使用的模型。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:10:42
     * @UpdateTime: 2026-10-29 12:10:42
     *
     * @Return: Builder 模型查询构造器
     * @Throws: 无
     */
    public function scopeSystemOnly(Builder $query): Builder
    {
        return $query->where('access_scope', self::ACCESS_SCOPE_SYSTEM_ONLY);
    }

    /**
     * @Name: scopeActive
     * @Description: 限定查询结果为状态为 active 的模型。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:10:42
     * @UpdateTime: 2026-10-29 12:10:42
     *
     * @Return: Builder 模型查询构造器
     * @Throws: 无
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * @Name: scopeUnarchived
     * @Description: 排除已归档的模型。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:10:42
     * @UpdateTime: 2026-10-29 12:10:42
     *
     * @Return: Builder 模型查询构造器
     * @Throws: 无
     */
    public function scopeUnarchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * @Name: scopeInFailoverOrder
     * @Description: 按故障切换优先级和主键稳定排列模型候选项。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:10:42
     * @UpdateTime: 2026-10-29 12:10:42
     *
     * @Return: Builder 模型查询构造器
     * @Throws: 无
     */
    public function scopeInFailoverOrder(Builder $query): Builder
    {
        return $query->orderBy('failover_priority')->orderBy('id');
    }

    /**
     * @Name: scopeActiveStatus
     * @Description: 兼容国内版历史状态值为空或带空格的模型记录。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:10:42
     * @UpdateTime: 2026-10-29 12:10:42
     *
     * @Return: Builder 模型查询构造器
     * @Throws: 无
     */
    public function scopeActiveStatus(Builder $query): Builder
    {
        return $query->whereRaw("LOWER(COALESCE(NULLIF(TRIM(status), ''), 'active')) = 'active'");
    }

    /**
     * @Name: scopeEmbeddingType
     * @Description: 兼容国内版历史模型类型为空或带空格的对话、向量模型记录。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:10:42
     * @UpdateTime: 2026-10-29 12:10:42
     *
     * @Return: Builder 模型查询构造器
     * @Throws: 无
     */
    public function scopeEmbeddingType(Builder $query): Builder
    {
        return $query->whereRaw("LOWER(COALESCE(NULLIF(TRIM(model_type), ''), 'chat')) = 'embedding'");
    }

    /**
     * @Name: currentUsage
     * @Description: 仅返回当前自然日内记录的模型调用次数，过期日期按零处理。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:10:42
     * @UpdateTime: 2026-10-29 12:10:42
     *
     * @Return: int 当前自然日调用次数
     * @Throws: 无
     */
    public function currentUsage(): int
    {
        return $this->usage_date?->toDateString() === now()->toDateString()
            ? max(0, (int) ($this->used_today ?? 0))
            : 0;
    }

    /**
     * @Name: scopeForCurrentUsageDay
     * @Description: 仅查询使用日期为当前自然日的模型记录。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 12:10:42
     * @UpdateTime: 2026-10-29 12:10:42
     *
     * @Return: Builder 模型查询构造器
     * @Throws: 无
     */
    public function scopeForCurrentUsageDay(Builder $query): Builder
    {
        return $query->whereDate('usage_date', now()->toDateString());
    }
}
