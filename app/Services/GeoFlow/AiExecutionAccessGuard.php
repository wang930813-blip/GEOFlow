<?php

namespace App\Services\GeoFlow;

use App\Data\Ai\AiExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Task;
use App\Models\TaskRun;
use App\Services\Admin\AdminAiModelAccessResolver;
use App\Support\AiConfigurationScope;
use App\Support\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class AiExecutionAccessGuard
{
    public function __construct(
        private readonly AdminAiModelAccessResolver $modelAccessResolver,
        private readonly AiExecutionContextFactory $contextFactory,
        private readonly AiConfigurationScope $aiConfigurationScope,
    ) {}

    public function identityRequired(): bool
    {
        return $this->contextFactory->identityRequired();
    }

    public function assertLegacyTaskModelCurrent(int $taskId, int $modelId): AiModel
    {
        if ($this->identityRequired()) {
            throw AiModelAccessException::configAccessRevokedForAdminId(0);
        }

        $task = Task::query()->whereKey($taskId)->first();
        if (! $task instanceof Task
            || ! in_array($modelId, [
                (int) ($task->ai_model_id ?? 0),
                (int) ($task->ai_image_model_id ?? 0),
            ], true)) {
            throw AiModelAccessException::configAccessRevokedForAdminId(
                (int) ($task?->model_access_admin_id ?? $task?->owner_admin_id ?? 0),
            );
        }

        $query = $this->legacyModelQueryForTask($task);
        $model = $query->whereKey($modelId)->first();
        if (! $model instanceof AiModel
            || (string) $model->status !== 'active'
            || $model->archived_at !== null) {
            throw AiModelAccessException::configAccessRevokedForAdminId(
                (int) ($task->model_access_admin_id ?? $task->owner_admin_id ?? 0),
            );
        }

        return $model;
    }

    /** @return list<AiModel> */
    public function resolveLegacyTaskModelCandidates(Task $task, string $modelType): array
    {
        if ($this->identityRequired()) {
            throw AiModelAccessException::configAccessRevokedForAdminId(
                (int) ($task->model_access_admin_id ?? $task->owner_admin_id ?? 0),
            );
        }

        return $this->legacyModelQueryForTask($task, $modelType)
            ->inFailoverOrder()
            ->get()
            ->all();
    }

    private function legacyModelQueryForTask(Task $task, ?string $modelType = null): Builder
    {
        $ownerAdminId = (int) ($task->owner_admin_id ?? 0);
        $query = AiModel::query()->withoutGlobalScope('current_site');
        if ($ownerAdminId > 0) {
            $admin = Admin::query()->whereKey($ownerAdminId)->first(['id', 'role', 'created_by']);
            if (! $admin instanceof Admin) {
                throw AiModelAccessException::executionAdminInactiveForId($ownerAdminId);
            }
            $query = $this->aiConfigurationScope->applyOwnerIdScope(
                $query,
                $this->aiConfigurationScope->ownerAdminIdForConsumer($admin),
                'ai_models.owner_admin_id',
            );
        } else {
            $query->whereNull('owner_admin_id');
        }

        return $query
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->when($modelType === 'chat', static fn (Builder $builder): Builder => $builder->where(static function (Builder $builder): void {
                $builder->whereNull('model_type')->orWhere('model_type', '')->orWhere('model_type', 'chat');
            }))
            ->when($modelType !== null && $modelType !== 'chat', static fn (Builder $builder): Builder => $builder->where('model_type', $modelType));
    }

    public function assertCurrent(AiExecutionContext $context, bool $validateRequestedModel = false): Admin
    {
        $this->assertExecutionLeaseCurrent($context);

        $admin = $this->lockWhenTransactional(
            Admin::query()->whereKey($context->modelAccessAdminId),
        )->first();
        if (! $admin instanceof Admin || (string) $admin->status !== 'active') {
            throw AiModelAccessException::executionAdminInactiveForId($context->modelAccessAdminId);
        }

        if ($this->contextFactory->normalizedRole($admin) !== $context->modelAccessAdminRole
            || max(1, (int) $admin->ai_config_access_version) !== $context->aiConfigAccessVersion
            || $context->resolverPolicyVersion !== AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION) {
            throw AiModelAccessException::configAccessRevoked($admin);
        }

        if ($validateRequestedModel && $context->requestedModelId !== null) {
            $this->assertModelCurrent($context, $context->requestedModelId, $admin);
        }

        return $admin;
    }

    /** @param array<string,mixed> $snapshot */
    public function assertPersistedAdminSnapshot(array $snapshot, ?int $taskId = null): Admin
    {
        $adminId = (int) ($snapshot['model_access_admin_id'] ?? 0);
        $admin = $this->lockWhenTransactional(Admin::query()->whereKey($adminId))->first();
        if (! $admin instanceof Admin || (string) $admin->status !== 'active') {
            throw AiModelAccessException::executionAdminInactiveForId($adminId);
        }
        if ((string) ($snapshot['model_access_admin_role'] ?? '') !== $this->contextFactory->normalizedRole($admin)
            || (int) ($snapshot['ai_config_access_version'] ?? 0) !== max(1, (int) $admin->ai_config_access_version)
            || (int) ($snapshot['resolver_policy_version'] ?? 0) !== AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION) {
            throw AiModelAccessException::configAccessRevoked($admin);
        }

        if ($taskId !== null && $taskId > 0) {
            $task = $this->lockWhenTransactional(Task::withTrashed()->whereKey($taskId))->first();
            if (! $task instanceof Task
                || $task->trashed()
                || (int) ($task->model_access_admin_id ?? 0) !== $adminId
                || (string) ($task->model_access_admin_role ?? '') !== (string) $snapshot['model_access_admin_role']
                || (int) ($task->model_access_policy_version ?? 0) !== AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION) {
                throw AiModelAccessException::configAccessRevoked($admin);
            }
        }

        return $admin;
    }

    /** @param array<string,mixed> $snapshot */
    public function assertModelForPersistedAdminSnapshot(
        array $snapshot,
        AiModel|int $model,
        ?int $taskId = null,
        ?Admin $currentAdmin = null,
    ): AiModel {
        $admin = $currentAdmin ?? $this->assertPersistedAdminSnapshot($snapshot, $taskId);
        $modelId = $model instanceof AiModel ? (int) $model->getKey() : $model;
        $currentModel = $this->lockWhenTransactional(
            $this->siteScopedModelQuery()->whereKey($modelId),
        )->first();
        if (! $currentModel instanceof AiModel) {
            throw AiModelAccessException::modelUnavailable($admin);
        }

        if (! $admin->isSuperAdmin()
            && (int) $currentModel->owner_admin_id !== (int) $admin->getKey()
            && (int) ($admin->shared_ai_config_owner_id ?? 0) === (int) $currentModel->owner_admin_id) {
            $provider = $this->lockWhenTransactional(
                Admin::query()->whereKey((int) $currentModel->owner_admin_id),
            )->first();
            if (! $provider instanceof Admin
                || (string) $provider->status !== 'active'
                || ! $provider->isSuperAdmin()) {
                throw AiModelAccessException::configOwnerInactive($admin, (int) $currentModel->owner_admin_id);
            }
        }

        $this->modelAccessResolver->assertLockedUsable($admin, $currentModel);

        return $currentModel;
    }

    public function assertModelCurrent(
        AiExecutionContext $context,
        AiModel|int $model,
        ?Admin $currentAdmin = null,
    ): AiModel {
        $admin = $currentAdmin ?? $this->assertCurrent($context);
        $modelId = $model instanceof AiModel ? (int) $model->getKey() : $model;
        $currentModel = $this->lockWhenTransactional(
            $this->siteScopedModelQuery()->whereKey($modelId),
        )->first();
        if (! $currentModel instanceof AiModel) {
            throw AiModelAccessException::modelUnavailable($admin);
        }

        if (! $admin->isSuperAdmin()
            && (int) $currentModel->owner_admin_id !== (int) $admin->getKey()
            && (int) ($admin->shared_ai_config_owner_id ?? 0) === (int) $currentModel->owner_admin_id) {
            $providerId = (int) $currentModel->owner_admin_id;
            $provider = $this->lockWhenTransactional(
                Admin::query()->whereKey($providerId),
            )->first();
            if (! $provider instanceof Admin
                || (string) $provider->status !== 'active'
                || ! $provider->isSuperAdmin()) {
                throw AiModelAccessException::configOwnerInactive($admin, $providerId);
            }
        }

        $this->modelAccessResolver->assertLockedUsable($admin, $currentModel);

        return $currentModel;
    }

    /** @return list<AiModel> */
    public function resolveModelCandidates(AiExecutionContext $context, string $modelType): array
    {
        $admin = $this->assertCurrent($context);

        return $this->modelAccessResolver
            ->resolveCandidates($admin, $modelType)
            ->all();
    }

    private function siteScopedModelQuery(): Builder
    {
        $query = AiModel::query()->withoutGlobalScope('current_site');
        $siteId = app(CurrentSite::class)->id();
        if ($siteId !== null && $siteId > 0) {
            $query->where(static function (Builder $siteQuery) use ($siteId): void {
                $siteQuery->where('ai_models.site_id', $siteId)
                    ->orWhereNull('ai_models.site_id');
            });
        }

        return $query;
    }

    public function recordResolvedModel(AiExecutionContext $context, AiModel $model): void
    {
        if ($context->taskRunId === null) {
            return;
        }

        $admin = $this->assertCurrent($context);
        $currentModel = $this->assertModelCurrent($context, $model, $admin);
        $source = (int) $currentModel->owner_admin_id === (int) $admin->getKey()
            ? 'personal'
            : 'shared';

        $this->writeResolvedModel($context, $currentModel, $source);
    }

    public function recordResolvedModelSnapshot(AiExecutionContext $context, AiModel $model): void
    {
        if ($context->taskRunId === null) {
            return;
        }

        $admin = Admin::query()->whereKey($context->modelAccessAdminId)->first();
        $currentModel = AiModel::query()->whereKey($model->getKey())->first();
        if (! $admin instanceof Admin || ! $currentModel instanceof AiModel) {
            return;
        }

        $source = match (true) {
            (string) $currentModel->access_scope === AiModel::ACCESS_SCOPE_SYSTEM_ONLY => 'system',
            (int) $currentModel->owner_admin_id === (int) $admin->getKey() => 'personal',
            ! $admin->isSuperAdmin()
                && (int) ($admin->shared_ai_config_owner_id ?? 0) === (int) $currentModel->owner_admin_id => 'shared',
            default => null,
        };
        if ($source === null) {
            return;
        }

        $this->writeResolvedModel($context, $currentModel, $source);
    }

    private function writeResolvedModel(
        AiExecutionContext $context,
        AiModel $model,
        string $source,
    ): void {

        $affected = TaskRun::query()
            ->whereKey($context->taskRunId)
            ->where('model_access_admin_id', $context->modelAccessAdminId)
            ->where('ai_config_access_version', $context->aiConfigAccessVersion)
            ->where('resolver_policy_version', $context->resolverPolicyVersion)
            ->where('execution_lease_token', $context->executionLeaseToken())
            ->where('status', 'running')
            ->whereNull('resolved_ai_model_id')
            ->update([
                'resolved_ai_model_id' => $model->getKey(),
                'resolved_model_source' => $source,
                'model_resolved_at' => now(),
            ]);
        if ($affected !== 1) {
            throw AiModelAccessException::configAccessRevokedForAdminId($context->modelAccessAdminId);
        }
    }

    private function assertExecutionLeaseCurrent(AiExecutionContext $context): void
    {
        if ($context->taskRunId === null) {
            throw AiModelAccessException::configAccessRevokedForAdminId($context->modelAccessAdminId);
        }

        $run = $this->lockWhenTransactional(TaskRun::query()->whereKey($context->taskRunId))->first();
        if (! $run instanceof TaskRun
            || (string) $run->status !== 'running'
            || (int) $run->model_access_admin_id !== $context->modelAccessAdminId
            || (string) $run->model_access_admin_role !== $context->modelAccessAdminRole
            || (int) $run->ai_config_access_version !== $context->aiConfigAccessVersion
            || (int) $run->resolver_policy_version !== $context->resolverPolicyVersion
            || ! hash_equals($context->executionLeaseToken(), (string) $run->execution_lease_token)) {
            throw AiModelAccessException::configAccessRevokedForAdminId($context->modelAccessAdminId);
        }
    }

    private function lockWhenTransactional(Builder $query): Builder
    {
        return DB::transactionLevel() > 0 ? $query->lockForUpdate() : $query;
    }
}
