<?php

namespace App\Services\GeoFlow;

use App\Data\Ai\AiExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use App\Services\Admin\AiModelUsageAttempt;
use App\Services\Admin\AiModelUsageAttemptFactory;
use App\Services\AiWorkspace\AiModelInvocationLock;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Closure;
use Laravel\Ai\Image as AiImage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use RuntimeException;

final readonly class WorkerAiModelInvocationGateway
{
    public const PERSISTENCE_MARGIN_SECONDS = 60;

    public function __construct(
        private AiExecutionAccessGuard $accessGuard,
        private AiModelInvocationLock $invocationLocks,
        private ArticleContentGenerationService $generationService,
        private AiModelUsageAttemptFactory $usageAttempts,
        private ApiKeyCrypto $apiKeyCrypto,
    ) {}

    /**
     * @Name: generate
     *
     * @Description: 在模型访问校验和调用锁保护下生成内容，复核结果凭据后执行回调。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-30 05:18:24
     *
     * @UpdateTime: 2026-10-30 05:18:24
     *
     * @Param: AiExecutionContext|int $executionIdentity 已授权执行身份或兼容任务主键。
     *
     * @Param: AiModel|int $model 当前选用的模型或模型主键。
     *
     * @Param: string $prompt 本次模型调用提示词。
     *
     * @Param: Closure $persistResponse 模型回执校验后的结果处理回调。
     *
     * @Param: string $operation 用量事件操作类型。
     *
     * @Param: string $businessSource 用量事件业务来源。
     *
     * @Param: bool $stream 是否使用流式模型响应。
     *
     * @Return: TResult 回调返回的处理结果。
     *
     * @Throws: AiModelAccessException 模型配置失效或执行访问被撤销时抛出。
     *
     * @template TResult
     *
     * @param  Closure(array{model:AiModel,response:AgentResponse|StreamableAgentResponse,receipt:array{model_id:int,request_id:string,configuration_digest:string}}): TResult  $persistResponse
     * @return TResult
     */
    public function generate(
        AiExecutionContext|int $executionIdentity,
        AiModel|int $model,
        string $prompt,
        Closure $persistResponse,
        string $operation = 'article.generate',
        string $businessSource = 'worker_article_generation',
        bool $stream = false,
    ): mixed {
        $modelId = $model instanceof AiModel ? (int) $model->getKey() : $model;
        if ($modelId <= 0) {
            throw AiModelAccessException::configAccessRevokedForAdminId($this->adminId($executionIdentity));
        }

        $invocationLock = $this->invocationLocks->acquireForInvocation(
            $modelId,
            $this->generationService->providerTimeoutSeconds() + self::PERSISTENCE_MARGIN_SECONDS,
        );
        $usageAttempt = null;
        $providerReturned = false;
        $response = null;

        try {
            $currentModel = $this->assertModelCurrent($executionIdentity, $modelId);
            $receipt = $this->receiptForIdentity($executionIdentity, $currentModel);
            $usageRequestId = $executionIdentity instanceof AiExecutionContext
                ? $this->usageAttempts->requestId()
                : null;
            $response = $this->generationService->generate(
                $currentModel,
                $prompt,
                function (AiModel $providerModel) use (&$usageAttempt, $executionIdentity, $usageRequestId, $prompt, $operation, $businessSource): void {
                    if (! $executionIdentity instanceof AiExecutionContext || ! is_string($usageRequestId)) {
                        return;
                    }

                    $usageAttempt = $this->usageAttempts->beginForAdmin(
                        model: $providerModel,
                        executionAdminId: $executionIdentity->modelAccessAdminId,
                        accessVersion: $executionIdentity->aiConfigAccessVersion,
                        executionScope: AiModelUsageEvent::EXECUTION_SCOPE_PERSISTED_ADMIN,
                        modelSource: $this->usageAttempts->sourceFor(
                            $providerModel,
                            $executionIdentity->modelAccessAdminId,
                        ),
                        requestId: $usageRequestId,
                        requestPayload: $prompt,
                        callKey: 'candidate-1',
                        operation: $operation,
                        businessSource: $businessSource,
                        sourceType: $executionIdentity->sourceType,
                        sourceId: $executionIdentity->sourceId,
                    );
                },
                stream: $stream,
            );
            $providerReturned = true;
            $currentModel = $this->assertReceiptCurrent($executionIdentity, $receipt);

            $result = $persistResponse([
                'model' => $currentModel,
                'response' => $response,
                'receipt' => $receipt,
            ]);
            $usageAttempt?->succeeded($response->usage ?? null);

            return $result;
        } catch (AiModelAccessException $exception) {
            $usageAttempt?->revoked($exception->getErrorCode(), $response?->usage ?? null);

            throw $exception;
        } catch (\Throwable $exception) {
            if ($usageAttempt instanceof AiModelUsageAttempt) {
                $providerReturned
                    ? $usageAttempt->discarded('ai_result_persistence_failed', $response?->usage ?? null)
                    : $usageAttempt->failed('ai_provider_request_failed');
            }

            throw $exception;
        } finally {
            $usageAttempt?->discarded('ai_result_not_committed', $response?->usage ?? null);
            $this->invocationLocks->release($invocationLock);
        }
    }

    /**
     * @param  array{model_id:int,request_id:string,configuration_digest:string}  $receipt
     */
    public function persistWithReceipt(
        AiExecutionContext|int $executionIdentity,
        array $receipt,
        Closure $persistResponse,
    ): mixed {
        $modelId = (int) ($receipt['model_id'] ?? 0);
        if ($modelId <= 0) {
            throw AiModelAccessException::configAccessRevokedForAdminId($this->adminId($executionIdentity));
        }

        $invocationLock = $this->invocationLocks->acquireForInvocation(
            $modelId,
            self::PERSISTENCE_MARGIN_SECONDS,
        );

        try {
            if ($executionIdentity instanceof AiExecutionContext) {
                $this->accessGuard->assertCurrent($executionIdentity);
            }
            $currentModel = $this->assertReceiptCurrent($executionIdentity, $receipt);

            return $persistResponse($currentModel);
        } finally {
            $this->invocationLocks->release($invocationLock);
        }
    }

    /**
     * @return array{content:string,mime:string}
     */
    public function generateImage(
        AiExecutionContext|int $executionIdentity,
        AiModel|int $model,
        string $prompt,
    ): array {
        $modelId = $model instanceof AiModel ? (int) $model->getKey() : $model;
        if ($modelId <= 0 || trim($prompt) === '') {
            throw new RuntimeException('AI 图片模型或提示词无效');
        }

        $invocationLock = $this->invocationLocks->acquireForInvocation(
            $modelId,
            120 + self::PERSISTENCE_MARGIN_SECONDS,
        );
        $usageAttempt = null;
        $providerReturned = false;
        $response = null;

        try {
            $currentModel = $this->assertModelCurrent($executionIdentity, $modelId);
            if ((string) $currentModel->model_type !== 'image' || (string) $currentModel->status !== 'active') {
                throw AiModelAccessException::modelUnavailable(
                    $executionIdentity instanceof AiExecutionContext
                        ? $this->accessGuard->assertCurrent($executionIdentity)
                        : throw AiModelAccessException::configAccessRevokedForAdminId($this->adminId($executionIdentity)),
                    $currentModel,
                );
            }

            $receipt = $this->receiptForIdentity($executionIdentity, $currentModel);
            $providerUrl = OpenAiRuntimeProvider::resolveImageBaseUrl((string) ($currentModel->api_url ?? ''));
            $apiKey = $this->apiKeyCrypto->decrypt((string) ($currentModel->getRawOriginal('api_key') ?? ''));
            if ($providerUrl === '' || $apiKey === '') {
                throw new RuntimeException('AI 图片模型未配置 API 地址或密钥');
            }

            $driver = OpenAiRuntimeProvider::resolveImageDriver($providerUrl, (string) ($currentModel->model_id ?? ''));
            $providerName = OpenAiRuntimeProvider::registerProvider('worker_image', $driver, $providerUrl, $apiKey);
            if ($executionIdentity instanceof AiExecutionContext) {
                $usageAttempt = $this->usageAttempts->beginForAdmin(
                    model: $currentModel,
                    executionAdminId: $executionIdentity->modelAccessAdminId,
                    accessVersion: $executionIdentity->aiConfigAccessVersion,
                    executionScope: AiModelUsageEvent::EXECUTION_SCOPE_PERSISTED_ADMIN,
                    modelSource: $this->usageAttempts->sourceFor($currentModel, $executionIdentity->modelAccessAdminId),
                    requestId: $this->usageAttempts->requestId(),
                    requestPayload: $prompt,
                    callKey: 'image-1',
                    operation: 'article.image.generate',
                    businessSource: 'worker_article_image',
                    sourceType: $executionIdentity->sourceType,
                    sourceId: $executionIdentity->sourceId,
                );
            }

            $response = AiImage::of($prompt)
                ->landscape()
                ->quality('medium')
                ->timeout(120)
                ->generate($providerName, (string) ($currentModel->model_id ?? ''));
            $providerReturned = true;
            $currentModel = $this->assertReceiptCurrent($executionIdentity, $receipt);

            $image = $response->firstImage();
            $content = $image->content();
            if ($content === '') {
                throw new RuntimeException('AI 图片服务未返回图片内容');
            }

            $usageAttempt?->succeeded(null);
            $currentModel->newQuery()
                ->whereKey((int) $currentModel->getKey())
                ->incrementEach(['used_today' => 1, 'total_used' => 1]);

            return [
                'content' => $content,
                'mime' => $image->mime ?: 'image/png',
            ];
        } catch (AiModelAccessException $exception) {
            $usageAttempt?->revoked($exception->getErrorCode(), $response?->usage ?? null);

            throw $exception;
        } catch (\Throwable $exception) {
            if ($usageAttempt instanceof AiModelUsageAttempt) {
                $providerReturned
                    ? $usageAttempt->discarded('ai_image_persistence_failed', $response?->usage ?? null)
                    : $usageAttempt->failed('ai_image_provider_request_failed');
            }

            throw $exception;
        } finally {
            $usageAttempt?->discarded('ai_image_result_not_committed', $response?->usage ?? null);
            $this->invocationLocks->release($invocationLock);
        }
    }

    /**
     * @param  array{model_id:int,request_id:string,configuration_digest:string}  $receipt
     */
    public function assertReceiptCurrent(
        AiExecutionContext|int $executionIdentity,
        array $receipt,
    ): AiModel {
        if ($executionIdentity instanceof AiExecutionContext
            && ! hash_equals($executionIdentity->requestId, (string) ($receipt['request_id'] ?? ''))) {
            throw AiModelAccessException::configAccessRevokedForAdminId($executionIdentity->modelAccessAdminId);
        }

        $currentModel = $this->assertModelCurrent($executionIdentity, (int) ($receipt['model_id'] ?? 0));
        if (! hash_equals(
            $this->configurationDigest($currentModel),
            (string) ($receipt['configuration_digest'] ?? ''),
        )) {
            throw AiModelAccessException::configAccessRevokedForAdminId($this->adminId($executionIdentity));
        }

        return $currentModel;
    }

    public function maxTokens(AiModel $model): int
    {
        return $this->generationService->maxTokens($model);
    }

    /** @return array{model_id:int,request_id:string,configuration_digest:string} */
    private function receiptForIdentity(AiExecutionContext|int $executionIdentity, AiModel $model): array
    {
        return [
            'model_id' => (int) $model->getKey(),
            'request_id' => $executionIdentity instanceof AiExecutionContext
                ? $executionIdentity->requestId
                : bin2hex(random_bytes(16)),
            'configuration_digest' => $this->configurationDigest($model),
        ];
    }

    private function assertModelCurrent(AiExecutionContext|int $executionIdentity, int $modelId): AiModel
    {
        if ($executionIdentity instanceof AiExecutionContext) {
            return $this->accessGuard->assertModelCurrent($executionIdentity, $modelId);
        }

        if ($this->accessGuard->identityRequired()) {
            throw AiModelAccessException::configAccessRevokedForAdminId(0);
        }

        return $this->accessGuard->assertLegacyTaskModelCurrent($executionIdentity, $modelId);
    }

    private function adminId(AiExecutionContext|int $executionIdentity): int
    {
        if ($executionIdentity instanceof AiExecutionContext) {
            return $executionIdentity->modelAccessAdminId;
        }

        return 0;
    }

    private function configurationDigest(AiModel $model): string
    {
        return hash('sha256', json_encode([
            'owner_admin_id' => (int) $model->owner_admin_id,
            'access_scope' => (string) $model->access_scope,
            'version' => trim((string) $model->version),
            'model_id' => trim((string) $model->model_id),
            'model_type' => trim((string) $model->model_type),
            'api_url' => trim((string) $model->api_url),
            'api_key' => (string) $model->getRawOriginal('api_key'),
            'status' => trim((string) $model->status),
            'archived_at' => $model->archived_at?->toISOString(),
            'max_tokens' => $model->max_tokens,
        ], JSON_THROW_ON_ERROR));
    }
}
