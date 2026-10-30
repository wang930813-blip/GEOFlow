<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-10-26
 *
 * @Time: 19:59:30
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： WorkerExecutionService.php
 *
 * @Description: 执行 GEO 内容生成、配图和文章入库流程。
 */

namespace App\Services\GeoFlow;

use App\Data\Ai\AiExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Exceptions\ArticleAiQualityGateException;
use App\Exceptions\ArticleRiskGateException;
use App\Exceptions\PermanentAiProviderException;
use App\Exceptions\TaskTitleReadinessException;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\Author;
use App\Models\Category;
use App\Models\Image;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\PlatformPlan;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\Title;
use App\Services\Billing\AdminResourceQuotaService;
use App\Services\Site\UrlChangeInspector;
use App\Services\Topics\TopicTaskRunner;
use App\Support\AiConfigurationScope;
use App\Support\GeoFlow\AiExecutionErrorSanitizer;
use App\Support\GeoFlow\AiModelFailoverDecider;
use App\Support\GeoFlow\ArticleWorkflow;
use App\Support\GeoFlow\ImageUrlNormalizer;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use App\Support\Site\ArticleHtmlPresenter;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\FinishReason;
use RuntimeException;
use Throwable;

/**
 * Worker 浠诲姟鎵ц鍣細灏嗛槦鍒椾换鍔¤惤鍦颁负鏂囩珷璁板綍锛堝崰浣嶅疄鐜帮紝鍏堟墦閫?worker/闃熷垪閾捐矾锛夈€? */
class WorkerExecutionService
{
    private const IMAGE_CONTEXT_LIMIT = 40;

    /**
     * 澶嶇敤缁熶竴 API Key 瑙ｅ瘑缁勪欢锛岀‘淇?worker 涓庡悗鍙伴厤缃瑙ｅ瘑琛屼负涓€鑷淬€?     */
    public function __construct(
        private readonly KnowledgeRetrievalService $knowledgeRetrievalService,
        private readonly AiGeneratedArticleImageService $aiGeneratedArticleImageService,
        private readonly GeoArticleContextService $geoArticleContextService,
        private readonly ArticleContentPromptRenderer $articleContentPromptRenderer,
        private readonly MarkdownImagePolicy $markdownImagePolicy,
        private readonly ArticleAiQualityGate $articleAiQualityGate,
        private readonly ArticleAiQualityPolicyResolver $articleAiQualityPolicyResolver,
        private readonly ArticleAiQualityInspectionService $articleAiQualityInspectionService,
        private readonly ArticleRiskScanner $articleRiskScanner,
        private readonly TaskTitleReadinessService $taskTitleReadinessService,
        private readonly ArticleWorkflowTransitionService $articleWorkflowTransitionService,
        private readonly ArticlePublicationEligibilityService $publicationEligibility,
        private readonly AdminResourceQuotaService $quotaService,
        private readonly AiConfigurationScope $aiConfigurationScope,
        private readonly AiExecutionAccessGuard $aiExecutionAccessGuard,
        private readonly WorkerAiModelInvocationGateway $aiModelInvocationGateway,
        private readonly JobQueueService $jobQueueService,
        private readonly ArticleCitationMarkerCleaner $articleCitationMarkerCleaner,
        private readonly UrlChangeInspector $urlChangeInspector,
        private readonly AiExecutionErrorSanitizer $aiExecutionErrorSanitizer,
        private readonly AiModelFailoverDecider $aiModelFailoverDecider,
        private readonly TopicTaskRunner $topicTaskRunner,
    ) {}

    /**
     * @return array{article_id:int|null, title:string, message:string, meta:array<string,mixed>}
     */
    public function executeTask(int $taskId, ?AiExecutionContext $executionContext = null): array
    {
        $executionStartedAt = microtime(true);
        /** @var Task|null $task */
        $task = Task::query()->find($taskId);
        if (! $task) {
            throw new RuntimeException('Task not found');
        }

        if (($task->status ?? 'paused') !== 'active' || (int) ($task->schedule_enabled ?? 1) !== 1) {
            throw new RuntimeException('Task is not active');
        }

        if ($executionContext instanceof AiExecutionContext) {
            if ($executionContext->sourceId !== $taskId) {
                throw AiModelAccessException::configAccessRevokedForAdminId($executionContext->modelAccessAdminId);
            }
            $this->aiExecutionAccessGuard->assertCurrent($executionContext);
        }

        if ((string) ($task->content_type ?? '') === 'topic') {
            return $this->topicTaskRunner->execute($task, $executionContext);
        }

        $publishResult = $this->publishDueDraftArticle($task, $executionContext, $executionStartedAt);
        if ($publishResult !== null) {
            return $publishResult;
        }

        $generationBlockReason = $this->getGenerationBlockReason($task);
        if ($generationBlockReason !== null) {
            return [
                'article_id' => null,
                'title' => '',
                'message' => $generationBlockReason,
                'meta' => [
                    'task_id' => (int) $task->id,
                    'action' => 'noop',
                    'reason' => $generationBlockReason,
                ],
            ];
        }

        if (! $executionContext instanceof AiExecutionContext
            && $this->aiExecutionAccessGuard->identityRequired()) {
            throw AiModelAccessException::configAccessRevokedForAdminId(
                (int) ($task->model_access_admin_id ?? $task->owner_admin_id ?? 0),
            );
        }
        $quotaAdminId = $this->quotaAdminId($task);
        if ((int) ($task->site_id ?? 0) > 0 && $quotaAdminId !== null) {
            $this->quotaService->assertCanUse($quotaAdminId, (int) $task->site_id, PlatformPlan::RESOURCE_ARTICLE_GENERATIONS, 1);
        }

        $titleRow = $this->pickTitle($task);
        $author = $this->pickAuthor($task);
        $category = $this->pickCategory($task, $titleRow);
        $prompt = $task->prompt_id ? $this->aiConfigQueryForTask(
            Prompt::query()->withoutGlobalScope('current_site'),
            $task,
            'prompts.owner_admin_id'
        )->whereKey((int) $task->prompt_id)->first() : null;

        $keyword = (string) ($titleRow->keyword ?? '');
        $knowledgeBundle = $this->resolveKnowledgeContext($task, (string) $titleRow->title, $keyword, $executionContext);
        $knowledgeContext = $knowledgeBundle['context'];
        $generationEvidenceSnapshot = $this->generationEvidenceSnapshot($knowledgeBundle['evidence']);
        $contentPrompt = $this->buildContentPromptForTask(
            $task,
            (string) $titleRow->title,
            $keyword,
            $prompt?->content,
            $knowledgeContext,
        );
        $generation = $this->generateContentWithModelSelection($task, $contentPrompt, $executionContext);
        $aiModel = $generation['model'];
        $generatedContent = $generation['content'];
        $imageResult = $this->insertTaskImagesIntoContent(
            $task,
            $generatedContent,
            $aiModel,
            (string) $titleRow->title,
            $keyword,
            $executionContext,
        );
        $content = ArticleHtmlPresenter::normalizeMarkdownSyntax($imageResult['content']);
        $selectedImages = $imageResult['images'];
        $generatedImages = $imageResult['generated_images'] ?? [];
        $imageError = $imageResult['image_error'] ?? null;
        $excerpt = $this->buildExcerpt($content);
        $receipt = $generation['receipt'];
        $generatedKeywords = $this->generateArticleKeywords(
            $task,
            $aiModel,
            $content,
            (string) $titleRow->title,
            $keyword,
            $executionContext,
            $taskId,
            $receipt,
        );
        $generatedDescription = $this->generateArticleDescription(
            $task,
            $aiModel,
            $content,
            (string) $titleRow->title,
            $keyword,
            $excerpt,
            $executionContext,
            $taskId,
            $receipt,
        );
        $executionIdentity = $executionContext ?? $taskId;
        $articleCreation = $this->aiModelInvocationGateway->persistWithReceipt(
            $executionIdentity,
            $receipt,
            function (AiModel $verifiedModel) use ($task, $titleRow, $author, $category, $keyword, $generatedKeywords, $generatedDescription, $content, $excerpt, $selectedImages, $generatedImages, $imageError, $knowledgeContext, $generation, $executionContext, $executionIdentity, $receipt, $generationEvidenceSnapshot, $quotaAdminId, $executionStartedAt): array {
                return DB::transaction(function () use ($task, $titleRow, $author, $category, $keyword, $generatedKeywords, $generatedDescription, $content, $excerpt, $selectedImages, $generatedImages, $imageError, $knowledgeContext, $generation, $executionContext, $executionIdentity, $receipt, $generationEvidenceSnapshot, $quotaAdminId, $verifiedModel, $executionStartedAt): array {
                    $freshTask = Task::query()
                        ->whereKey((int) $task->id)
                        ->lockForUpdate()
                        ->first();
                    if (! $freshTask || ($freshTask->status ?? 'paused') !== 'active' || (int) ($freshTask->schedule_enabled ?? 1) !== 1) {
                        throw new RuntimeException('Task is not active');
                    }
                    if ($executionContext instanceof AiExecutionContext) {
                        $this->jobQueueService->lockRunningJobForWorker($executionContext, (int) $freshTask->getKey());
                        $this->aiExecutionAccessGuard->assertCurrent($executionContext);
                    }
                    $verifiedModel = $this->aiModelInvocationGateway->assertReceiptCurrent($executionIdentity, $receipt);
                    $generationBlockReason = $this->getGenerationBlockReason($freshTask, true);
                    if ($generationBlockReason !== null) {
                        throw new RuntimeException($generationBlockReason);
                    }
                    $qualityPolicy = $this->articleAiQualityPolicyResolver->fromTask($freshTask);
                    $qualityPolicySnapshot = $this->articleAiQualityPolicyResolver->snapshot($qualityPolicy);
                    $workflow = [
                        'status' => 'draft',
                        'review_status' => (int) ($freshTask->need_review ?? 1) === 1 ? 'pending' : 'auto_approved',
                        'published_at' => null,
                    ];
                    $siteId = (int) ($freshTask->site_id ?: $task->site_id);

                    $article = Article::query()->create([
                        'site_id' => $siteId > 0 ? $siteId : null,
                        'owner_admin_id' => (int) ($freshTask->owner_admin_id ?? 0) > 0 ? (int) $freshTask->owner_admin_id : null,
                        'title' => (string) $titleRow->title,
                        'slug' => ArticleWorkflow::generateUniqueSlug((string) $titleRow->title),
                        'excerpt' => $excerpt,
                        'content' => $content,
                        'category_id' => $category?->id,
                        'author_id' => $author?->id,
                        'task_id' => (int) $task->id,
                        'source_title_id' => (int) $titleRow->id,
                        'original_keyword' => $keyword,
                        'keywords' => (bool) $freshTask->auto_keywords ? $generatedKeywords : '',
                        'meta_description' => (bool) $freshTask->auto_description ? $generatedDescription : '',
                        'status' => $workflow['status'],
                        'review_status' => $workflow['review_status'],
                        'publication_intent' => 'scheduled',
                        'is_ai_generated' => 1,
                        'published_at' => $workflow['published_at'],
                        'view_count' => 0,
                        'ai_quality_required_at_creation' => (bool) ($qualityPolicy['required'] ?? false),
                        'ai_quality_policy_snapshot' => $qualityPolicySnapshot,
                        'generation_evidence_snapshot' => $generationEvidenceSnapshot,
                    ]);
                    $this->urlChangeInspector->assertArticleCompatible($article);
                    $this->articleRiskScanner->record($article, 'worker_generation');
                    if ($workflow['review_status'] === 'auto_approved') {
                        try {
                            $this->articleWorkflowTransitionService->transition(
                                $article,
                                $workflow,
                                'worker_generation',
                                null,
                                null,
                                false,
                                [
                                    'status' => 'draft',
                                    'review_status' => 'pending',
                                    'published_at' => null,
                                ],
                            );
                        } catch (ArticleRiskGateException|ArticleAiQualityGateException) {
                            // 风险扫描和待审状态随当前生成事务一并保留。
                        }
                    }
                    if ($selectedImages !== []) {
                        foreach ($selectedImages as $position => $image) {
                            ArticleImage::query()->create([
                                'site_id' => $siteId > 0 ? $siteId : null,
                                'article_id' => (int) $article->id,
                                'image_id' => (int) $image->id,
                                'position' => $position,
                            ]);
                            Image::query()->whereKey((int) $image->id)->update([
                                'used_count' => DB::raw('COALESCE(used_count,0)+1'),
                                'usage_count' => DB::raw('COALESCE(usage_count,0)+1'),
                            ]);
                        }
                    }

                    $qualityCheck = null;
                    if ($qualityPolicy['required'] ?? false) {
                        $qualityCheck = $this->articleAiQualityInspectionService->createOrReuse(
                            $article,
                            trigger: 'worker_generation',
                        );
                    }

                    // 保持既有统计行为：每次成功生成都会消耗标题并增加任务计数。
                    Title::query()->whereKey($titleRow->id)->increment('used_count');
                    Title::query()->whereKey($titleRow->id)->increment('usage_count');

                    $taskUpdate = [
                        'created_count' => DB::raw('COALESCE(created_count,0)+1'),
                        'loop_count' => DB::raw('COALESCE(loop_count,0)+1'),
                        'updated_at' => now(),
                    ];

                    if ($freshTask->next_publish_at === null) {
                        $taskUpdate['next_publish_at'] = now()->addSeconds($this->normalizePublishInterval($freshTask));
                    }
                    Task::query()->whereKey($task->id)->update($taskUpdate);

                    $articleId = (int) $article->id;
                    $quotaSiteId = (int) ($freshTask->site_id ?? 0);
                    if ($quotaSiteId > 0 && $quotaAdminId !== null) {
                        $this->quotaService->consume($quotaAdminId, $quotaSiteId, PlatformPlan::RESOURCE_ARTICLE_GENERATIONS, 1, [
                            'subject_type' => Article::class,
                            'subject_id' => $articleId,
                            'idempotency_key' => 'article-generation:'.$articleId,
                            'remark' => 'AI 文章生成消耗',
                        ]);
                    }

                    $result = [
                        'article_id' => (int) $article->id,
                        'ai_quality' => [
                            'required' => (bool) ($qualityPolicy['required'] ?? false),
                            'check_id' => $qualityCheck?->id,
                            'status' => $qualityCheck?->status,
                        ],
                        'meta' => [
                            'task_id' => (int) $freshTask->id,
                            'action' => 'generate_draft',
                            'title_id' => (int) $titleRow->id,
                            'author_id' => $author?->id,
                            'category_id' => $category?->id,
                            'knowledge_length' => mb_strlen($knowledgeContext, 'UTF-8'),
                            'knowledge_retrieval' => $generationEvidenceSnapshot,
                            'image_mode' => (string) ($freshTask->image_mode ?? 'library'),
                            'image_count' => count($selectedImages) + count($generatedImages),
                            'generated_images' => $generatedImages,
                            'image_error' => $imageError,
                            'model_selection_mode' => (string) ($freshTask->model_selection_mode ?? 'fixed'),
                            'used_model_id' => (int) $verifiedModel->id,
                            'used_model_name' => (string) $verifiedModel->name,
                            'model_attempts' => $generation['attempts'] ?? [],
                            'ai_quality' => [
                                'required' => (bool) ($qualityPolicy['required'] ?? false),
                                'check_id' => $qualityCheck?->id,
                                'status' => $qualityCheck?->status,
                            ],
                        ],
                    ];
                    $this->completePersistedExecution($freshTask, $result, $executionContext, $executionStartedAt);

                    return $result;
                });
            },
        );
        $articleId = (int) $articleCreation['article_id'];

        return [
            'article_id' => $articleId,
            'title' => (string) $titleRow->title,
            'message' => '鑽夌鐢熸垚鎴愬姛',
            'meta' => [
                'task_id' => (int) $task->id,
                'action' => 'generate_draft',
                'title_id' => (int) $titleRow->id,
                'author_id' => $author?->id,
                'category_id' => $category?->id,
                'knowledge_length' => mb_strlen($knowledgeContext, 'UTF-8'),
                'knowledge_retrieval' => $knowledgeBundle['retrieval_meta'],
                'image_mode' => (string) ($task->image_mode ?? 'library'),
                'image_count' => count($selectedImages) + count($generatedImages),
                'generated_images' => $generatedImages,
                'image_error' => $imageError,
                'model_selection_mode' => (string) ($task->model_selection_mode ?? 'fixed'),
                'used_model_id' => (int) $aiModel->id,
                'used_model_name' => (string) $aiModel->name,
                'model_attempts' => $generation['attempts'],
                'ai_quality' => $articleCreation['ai_quality'],
            ],
        ];
    }

    /**
     * 鍙戝竷涓€涓凡瀹℃牳鑽夌銆傜敓鎴愪笌鍙戝竷瑙ｈ€﹀悗锛學orker 姣忔鎵ц浼樺厛閲婃斁鍒版湡鑽夌銆?     *
     *
     * @return array{article_id:int, title:string, message:string, meta:array<string,mixed>}|null
     */
    private function publishDueDraftArticle(
        Task $task,
        ?AiExecutionContext $executionContext = null,
        ?float $executionStartedAt = null,
    ): ?array {
        if ($task->next_publish_at !== null && $task->next_publish_at->greaterThan(now())) {
            return null;
        }

        foreach (Article::query()->scheduledCandidates($task)->orderBy('id')->lazyById(100, 'id') as $candidate) {
            $candidateArticleId = (int) $candidate->id;
            $result = DB::transaction(function () use ($task, $candidateArticleId, $executionContext, $executionStartedAt): ?array {
                $freshTask = Task::query()
                    ->whereKey((int) $task->id)
                    ->lockForUpdate()
                    ->first();
                if (! $freshTask || ($freshTask->status ?? 'paused') !== 'active' || (int) ($freshTask->schedule_enabled ?? 1) !== 1) {
                    throw new RuntimeException('Task is not active');
                }

                if ($freshTask->next_publish_at !== null && $freshTask->next_publish_at->greaterThan(now())) {
                    return null;
                }

                if ($executionContext instanceof AiExecutionContext) {
                    $this->jobQueueService->lockRunningJobForWorker($executionContext, (int) $freshTask->getKey());
                    $this->aiExecutionAccessGuard->assertCurrent($executionContext);
                }

                /** @var Article|null $article */
                $article = Article::query()
                    ->whereKey($candidateArticleId)
                    ->where('task_id', (int) $freshTask->id)
                    ->where('publication_intent', 'scheduled')
                    ->where('status', 'draft')
                    ->whereIn('review_status', $freshTask->need_review ? ['approved'] : ['approved', 'auto_approved', 'pending'])
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->first();
                if (! $article) {
                    return null;
                }

                $qualityModelId = $this->articleAiQualityGate->modelIdThatWouldBeDispatched($article);
                if ($qualityModelId !== null) {
                    if (! $executionContext instanceof AiExecutionContext) {
                        throw AiModelAccessException::configAccessRevokedForAdminId(
                            (int) ($freshTask->model_access_admin_id ?? 0),
                        );
                    }
                    $executionAdmin = $this->aiExecutionAccessGuard->assertCurrent($executionContext);
                    $this->aiExecutionAccessGuard->assertModelCurrent($executionContext, $qualityModelId, $executionAdmin);
                }

                $publishScope = (string) ($freshTask->publish_scope ?? 'local_and_distribution');
                $targetStatus = $publishScope === 'distribution_only' ? 'private' : 'published';
                $workflow = ArticleWorkflow::normalizeState(
                    $targetStatus,
                    $this->publicationEligibility->reviewStatus($article),
                );
                if ($workflow['status'] === 'published') {
                    $workflow['published_at'] = now()->toDateTimeString();
                }
                $fallbackWorkflow = ArticleWorkflow::normalizeState('draft', 'pending');

                try {
                    $article = $this->articleWorkflowTransitionService->transition(
                        $article,
                        $workflow,
                        'worker_publish',
                        null,
                        null,
                        true,
                        $fallbackWorkflow,
                    );
                } catch (ArticleRiskGateException) {
                    return null;
                } catch (ArticleAiQualityGateException $exception) {
                    if ($qualityModelId === null || $exception->getCheck() === null) {
                        return null;
                    }

                    return [
                        'article_id' => null,
                        'title' => (string) $article->title,
                        'message' => '草稿等待 AI 质检',
                        'meta' => [
                            'task_id' => (int) $freshTask->id,
                            'action' => 'await_ai_quality',
                            'ai_quality_check_id' => (int) $exception->getCheck()->id,
                            'ai_quality_error_code' => $exception->getErrorCode(),
                        ],
                    ];
                }

                $publishInterval = $this->normalizePublishInterval($freshTask);
                Task::query()->whereKey((int) $freshTask->id)->update([
                    'published_count' => DB::raw('COALESCE(published_count,0)+'.($targetStatus === 'published' ? '1' : '0')),
                    'next_publish_at' => now()->addSeconds($publishInterval),
                    'updated_at' => now(),
                ]);

                $article->loadMissing('task');

                $result = [
                    'article_id' => (int) $article->id,
                    'title' => (string) $article->title,
                    'message' => '草稿发布成功',
                    'meta' => [
                        'task_id' => (int) $freshTask->id,
                        'action' => 'publish_draft',
                        'publish_interval' => $publishInterval,
                        'workflow_fence' => $this->publicationEligibility->fence($article),
                        'local_published' => $targetStatus === 'published',
                        'distribution_status' => $publishScope === 'local_only' ? 'not_requested' : 'pending',
                    ],
                ];
                $this->completePersistedExecution(
                    $freshTask,
                    $result,
                    $executionContext,
                    $executionStartedAt ?? microtime(true),
                );

                return $result;
            });

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * 鍒ゆ柇鏄惁鍏佽缁х画鐢熸垚鑽夌銆?     */
    private function getGenerationBlockReason(Task $task, bool $lock = false): ?string
    {
        $articleLimit = max(1, (int) ($task->article_limit ?? $task->draft_limit ?? 10));
        if ((int) ($task->created_count ?? 0) >= $articleLimit) {
            return '宸茶揪鍒版枃绔犳€绘暟涓婇檺';
        }

        $draftLimit = max(1, (int) ($task->draft_limit ?? 10));
        $draftQuery = Article::query()
            ->where('task_id', (int) $task->id)
            ->where('status', 'draft')
            ->whereNull('deleted_at');
        // PostgreSQL 涓嶅厑璁稿湪 count(*) 鑱氬悎鏌ヨ涓婅拷鍔?FOR UPDATE銆?        // 杩欓噷鐨勫苟鍙戜繚鎶ょ敱浠诲姟琛岄攣鍜?task_runs 鐨勫崟浠诲姟涓茶闃熷垪淇濊瘉锛岃崏绋胯鏁颁笉闇€瑕佸啀鍗曠嫭鍔犻攣銆?
        if ($draftQuery->count() >= $draftLimit) {
            return '鑽夌姹犲凡婊★紝绛夊緟瀹℃牳鎴栨寜闂撮殧鍙戝竷';
        }

        return null;
    }

    private function normalizePublishInterval(Task $task): int
    {
        return max(60, (int) ($task->publish_interval ?? 3600));
    }

    /**
     * 瑙ｆ瀽骞舵牎楠屼换鍔＄粦瀹氱殑 AI 妯″瀷锛堝繀椤绘槸 active + chat锛夈€?     */
    private function resolveConfiguredAiModel(Task $task, ?AiExecutionContext $executionContext): AiModel
    {
        $aiModelId = $executionContext instanceof AiExecutionContext
            ? (int) ($executionContext->requestedModelId ?? 0)
            : (int) ($task->ai_model_id ?? 0);
        if ($aiModelId <= 0) {
            throw new RuntimeException('Task AI model is not configured');
        }
        if ((int) ($task->ai_model_id ?? 0) !== $aiModelId) {
            throw AiModelAccessException::configAccessRevokedForAdminId(
                (int) ($task->model_access_admin_id ?? $task->owner_admin_id ?? 0),
            );
        }

        return $executionContext instanceof AiExecutionContext
            ? $this->aiExecutionAccessGuard->assertModelCurrent($executionContext, $aiModelId)
            : $this->aiExecutionAccessGuard->assertLegacyTaskModelCurrent((int) $task->getKey(), $aiModelId);
    }

    /**
     * 鍥哄畾妯″瀷鍙皾璇曚富妯″瀷锛涙櫤鑳藉垏鎹㈡寜 failover_priority 渚濇灏濊瘯鍏跺畠 active chat 妯″瀷銆?     *
     *
     * @return array{content:string,model:AiModel,attempts:list<array{model_id:int,model_name:string,status:string,reason:?string}>}
     */
    private function generateContentWithModelSelection(
        Task $task,
        string $contentPrompt,
        ?AiExecutionContext $executionContext = null,
    ): array {
        $mode = (string) ($task->model_selection_mode ?? 'fixed');
        $attempts = [];
        $lastMessage = '';

        foreach ($this->resolveAiModelCandidates($task, $executionContext) as $candidate) {
            if ($executionContext instanceof AiExecutionContext) {
                $candidate = $this->aiExecutionAccessGuard->assertModelCurrent($executionContext, $candidate);
                $this->aiExecutionAccessGuard->recordResolvedModel($executionContext, $candidate);
            } else {
                $candidate = $this->aiExecutionAccessGuard->assertLegacyTaskModelCurrent(
                    (int) $task->getKey(),
                    (int) $candidate->getKey(),
                );
            }

            $unavailableReason = $this->getAiModelUnavailableReason($candidate);
            if ($unavailableReason !== null) {
                $attempts[] = $this->buildModelAttempt($candidate, 'skipped', $unavailableReason);
                $lastMessage = $unavailableReason;
                if ($mode !== 'smart_failover') {
                    throw new RuntimeException($unavailableReason);
                }

                continue;
            }

            try {
                $generated = $this->generateContent(
                    $executionContext,
                    $candidate,
                    $contentPrompt,
                    static fn (array $generation): array => $generation,
                    (int) $task->getKey(),
                );
                $attempts[] = $this->buildModelAttempt($candidate, 'success', null);

                return [
                    'content' => $generated['content'],
                    'model' => $candidate,
                    'receipt' => $generated['receipt'],
                    'attempts' => $attempts,
                ];
            } catch (AiModelAccessException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                $lastMessage = $this->aiExecutionErrorSanitizer->sanitize($exception);
                $attempts[] = $this->buildModelAttempt($candidate, 'failed', $lastMessage);

                if ($this->aiModelFailoverDecider->isPermanentProviderFailure($exception)) {
                    throw PermanentAiProviderException::fromProviderFailure($exception);
                }

                if ($mode !== 'smart_failover' || ! $this->aiModelFailoverDecider->shouldFailover($exception)) {
                    throw $exception;
                }
            }
        }

        if ($mode === 'smart_failover' && $attempts !== []) {
            throw new RuntimeException($this->buildFailoverErrorMessage($attempts, $lastMessage));
        }

        throw new RuntimeException('AI妯″瀷涓嶅彲鐢ㄦ垨宸茶揪姣忔棩闄愬埗');
    }

    /**
     * @return list<AiModel>
     */
    private function resolveAiModelCandidates(Task $task, ?AiExecutionContext $executionContext): array
    {
        $primaryModel = $this->resolveConfiguredAiModel($task, $executionContext);
        if (($task->model_selection_mode ?? 'fixed') !== 'smart_failover') {
            return [$primaryModel];
        }

        $candidates = $executionContext instanceof AiExecutionContext
            ? $this->aiExecutionAccessGuard->resolveModelCandidates($executionContext, 'chat')
            : $this->aiExecutionAccessGuard->resolveLegacyTaskModelCandidates($task, 'chat');
        $primaryModelId = (int) $primaryModel->getKey();
        $fallbackModels = array_values(array_filter(
            $candidates,
            static fn (AiModel $candidate): bool => (int) $candidate->getKey() !== $primaryModelId,
        ));

        return array_values(array_merge([$primaryModel], $fallbackModels));
    }

    private function getAiModelUnavailableReason(AiModel $aiModel): ?string
    {
        if (($aiModel->status ?? 'inactive') !== 'active') {
            return 'AI妯″瀷涓嶅彲鐢ㄦ垨宸茶揪姣忔棩闄愬埗';
        }

        $dailyLimit = (int) ($aiModel->daily_limit ?? 0);
        $usedToday = (int) ($aiModel->used_today ?? 0);
        if ($dailyLimit > 0 && $usedToday >= $dailyLimit) {
            return 'AI妯″瀷涓嶅彲鐢ㄦ垨宸茶揪姣忔棩闄愬埗';
        }

        return null;
    }

    /**
     * @return array{model_id:int,model_name:string,status:string,reason:?string}
     */
    private function buildModelAttempt(AiModel $aiModel, string $status, ?string $reason): array
    {
        return [
            'model_id' => (int) $aiModel->id,
            'model_name' => (string) $aiModel->name,
            'status' => $status,
            'reason' => $reason === null ? null : $this->aiExecutionErrorSanitizer->sanitize($reason, ''),
        ];
    }

    /**
     * 在文章业务事务内完成队列执行记录，避免文章已入库而执行记录仍处于运行状态。
     *
     * @param  array{article_id:int|null,title:string,message:string,meta:array<string,mixed>}  $result
     */
    private function completePersistedExecution(
        Task $task,
        array $result,
        ?AiExecutionContext $executionContext,
        float $executionStartedAt,
    ): void {
        if (! $executionContext instanceof AiExecutionContext || $executionContext->taskRunId === null) {
            return;
        }

        $this->jobQueueService->completeJob(
            jobId: $executionContext->taskRunId,
            taskId: (int) $task->getKey(),
            articleId: $result['article_id'],
            durationMs: (int) round((microtime(true) - $executionStartedAt) * 1000),
            meta: $result['meta'],
            executionContext: $executionContext,
            executionLeaseToken: $executionContext->executionLeaseToken(),
            rejectStaleExecution: true,
        );
    }

    /**
     * @param  list<array{model_id:int,model_name:string,status:string,reason:?string}>  $attempts
     */
    private function buildFailoverErrorMessage(array $attempts, string $lastMessage): string
    {
        $summaries = [];
        foreach ($attempts as $attempt) {
            $reason = trim((string) ($attempt['reason'] ?? ''));
            $summaries[] = (string) $attempt['model_name'].($reason !== '' ? ' ('.$reason.')' : '');
        }

        return 'Model failover attempted: '.implode(', ', $summaries).'. Final failure: '.$lastMessage;
    }

    private function pickTitle(Task $task): Title
    {
        $libraryId = (int) ($task->title_library_id ?? 0);
        if ($libraryId <= 0) {
            throw new TaskTitleReadinessException($this->taskTitleReadinessService->inspectTask($task), 409);
        }

        $fixedTitleId = (int) ($task->fixed_title_id ?? 0);
        if ($fixedTitleId > 0) {
            /** @var Title|null $fixedTitle */
            $fixedTitle = Title::query()
                ->whereKey($fixedTitleId)
                ->where('library_id', $libraryId)
                ->first();
            if ($fixedTitle) {
                return $fixedTitle;
            }
        }

        $titleCount = Title::query()
            ->where('library_id', $libraryId)
            ->count();
        if ($titleCount <= 0) {
            throw new TaskTitleReadinessException($this->taskTitleReadinessService->inspectTask($task), 409);
        }

        $createdCount = max(0, (int) ($task->created_count ?? 0));
        $isLoop = (int) ($task->is_loop ?? 0) === 1;
        if (! $isLoop && Title::query()
            ->where('library_id', $libraryId)
            ->where(fn ($query) => $query->whereNull('used_count')->orWhere('used_count', '<=', 0))
            ->doesntExist()) {
            throw new TaskTitleReadinessException($this->taskTitleReadinessService->inspectTask($task), 409);
        }
        if (! $isLoop && $createdCount >= $titleCount) {
            throw new TaskTitleReadinessException($this->taskTitleReadinessService->inspectTask($task), 409);
        }

        // Auto mode follows this task's own generation order; used_count is only global statistics.
        $offset = $isLoop ? ($createdCount % $titleCount) : $createdCount;

        /** @var Title|null $title */
        $title = Title::query()
            ->where('library_id', $libraryId)
            ->orderBy('id')
            ->offset($offset)
            ->limit(1)
            ->first();

        if (! $title) {
            throw new TaskTitleReadinessException($this->taskTitleReadinessService->inspectTask($task), 409);
        }

        return $title;
    }

    private function pickAuthor(Task $task): Author
    {
        $siteId = (int) ($task->site_id ?? 0);
        $ownerAdminId = (int) ($task->owner_admin_id ?? 0);
        $authorQuery = Author::query()->withoutGlobalScope('current_site');
        if ($siteId > 0) {
            $authorQuery->where('site_id', $siteId);
        } else {
            $authorQuery->whereNull('site_id');
            if ($ownerAdminId > 0) {
                $authorQuery->where('owner_admin_id', $ownerAdminId);
            } else {
                $authorQuery->whereNull('owner_admin_id');
            }
        }

        $authorId = (int) ($task->custom_author_id ?: $task->author_id);
        if ($authorId > 0) {
            $author = (clone $authorQuery)->whereKey($authorId)->first();
            if ($author) {
                return $author;
            }
        }

        $author = (clone $authorQuery)->inRandomOrder()->first();
        if ($author) {
            return $author;
        }

        return Author::query()->withoutGlobalScope('current_site')->firstOrCreate(
            [
                'site_id' => $siteId > 0 ? $siteId : null,
                'owner_admin_id' => $ownerAdminId > 0 ? $ownerAdminId : null,
                'name' => 'GEOFlow',
            ],
            ['bio' => 'Default GEOFlow author for automated content generation.']
        );
    }

    private function pickCategory(Task $task, ?Title $title = null): ?Category
    {
        $siteId = (int) ($task->site_id ?? 0);

        if (($task->category_mode ?? 'smart') === 'fixed' && (int) ($task->fixed_category_id ?? 0) > 0) {
            $fixedQuery = Category::withoutGlobalScope('current_site')
                ->whereKey((int) $task->fixed_category_id);
            if ($siteId > 0) {
                $fixedQuery->where('site_id', $siteId);
            }

            $category = $fixedQuery->first();
            if ($category instanceof Category) {
                return $category;
            }
        }

        $query = Category::withoutGlobalScope('current_site');
        if ($siteId > 0) {
            $query->where('site_id', $siteId);
        }

        $categories = $query->orderBy('sort_order')->orderBy('id')->get();
        if ($categories->isEmpty()) {
            return null;
        }

        if (($task->category_mode ?? 'smart') === 'smart') {
            $smartCategory = $this->pickSmartCategory($categories, $title);
            if ($smartCategory instanceof Category) {
                return $smartCategory;
            }
        }

        return $categories->first();
    }

    /**
     * @param  iterable<int, Category>  $categories
     */
    private function pickSmartCategory(iterable $categories, ?Title $title): ?Category
    {
        $context = $this->normalizeSmartCategoryText(trim((string) ($title?->title ?? '').' '.(string) ($title?->keyword ?? '')));
        if ($context === '') {
            return null;
        }

        $bestCategory = null;
        $bestScore = 0;
        foreach ($categories as $category) {
            $score = $this->scoreSmartCategory($context, $category);
            if ($score > $bestScore) {
                $bestCategory = $category;
                $bestScore = $score;
            }
        }

        return $bestScore > 0 ? $bestCategory : null;
    }

    private function scoreSmartCategory(string $context, Category $category): int
    {
        $score = 0;
        $nameTokens = $this->smartCategoryTokens((string) $category->name);
        foreach ($nameTokens as $token) {
            if (mb_strpos($context, $token, 0, 'UTF-8') !== false) {
                $score += mb_strlen($token, 'UTF-8') * 10;
            }
        }

        $descriptionTokens = $this->smartCategoryTokens((string) ($category->description ?? ''));
        foreach ($descriptionTokens as $token) {
            if (mb_strpos($context, $token, 0, 'UTF-8') !== false) {
                $score += mb_strlen($token, 'UTF-8') * 4;
            }
        }

        return $score;
    }

    /**
     * @return list<string>
     */
    private function smartCategoryTokens(string $value): array
    {
        $normalized = $this->normalizeSmartCategoryText($value);
        if ($normalized === '') {
            return [];
        }

        $parts = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if (mb_strlen($part, 'UTF-8') >= 2) {
                $tokens[] = $part;
            }

            $stripped = $this->stripSmartCategorySuffix($part);
            if ($stripped !== $part && mb_strlen($stripped, 'UTF-8') >= 2) {
                $tokens[] = $stripped;
            }
        }

        return array_values(array_unique($tokens));
    }

    private function stripSmartCategorySuffix(string $value): string
    {
        $suffixes = ['新闻资讯', '行业资讯', '资讯', '新闻', '信息', '动态', '专栏', '栏目', '百科', '知识', '分类'];
        foreach ($suffixes as $suffix) {
            $suffixLength = mb_strlen($suffix, 'UTF-8');
            if (mb_strlen($value, 'UTF-8') > $suffixLength && mb_substr($value, -$suffixLength, null, 'UTF-8') === $suffix) {
                return mb_substr($value, 0, -$suffixLength, 'UTF-8');
            }
        }

        return $value;
    }

    private function normalizeSmartCategoryText(string $value): string
    {
        $normalized = mb_strtolower($value, 'UTF-8');
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);
    }

    /**
     * 鏋勯€犳鏂囨彁绀鸿瘝锛氫紭鍏堢簿纭浛鎹㈠彉閲忥紱鏃犲彉閲忕殑鑷畾涔夋彁绀鸿瘝鑷姩琛ラ綈浠诲姟涓婁笅鏂囥€?     */
    private function buildContentPrompt(
        string $title,
        string $keyword,
        ?string $promptContent,
        string $knowledgeContext,
        string $imageContext = '',
    ): string {
        return $this->buildContentPromptWithGeoContext($title, $keyword, $promptContent, $knowledgeContext, '', $imageContext);
    }

    private function buildContentPromptForTask(Task $task, string $title, string $keyword, ?string $promptContent, string $knowledgeContext): string
    {
        $geoContext = $this->geoArticleContextService->buildForTask($task, $keyword);
        $imageContext = $this->buildImageContext($task);

        return $this->buildContentPromptWithGeoContext(
            $title,
            $keyword,
            $promptContent,
            $knowledgeContext,
            $geoContext,
            $imageContext,
        );
    }

    private function buildContentPromptWithGeoContext(
        string $title,
        string $keyword,
        ?string $promptContent,
        string $knowledgeContext,
        string $geoContext,
        string $imageContext,
    ): string {
        $knowledgeWithGeoContext = trim($knowledgeContext);
        if (trim($geoContext) !== '') {
            $knowledgeWithGeoContext = trim($knowledgeWithGeoContext."\n\n".trim($geoContext));
        }

        return $this->articleContentPromptRenderer->renderForWorker(
            $title,
            $keyword,
            $promptContent,
            $knowledgeWithGeoContext,
            $imageContext,
        );
    }

    private function promptHasKnownContextVariables(string $prompt): bool
    {
        return preg_match('/\{\{\s*(title|keyword|knowledge)\s*\}\}/iu', $prompt) === 1
            || preg_match('/\{\{#if\s+(title|keyword|knowledge)\s*\}\}/iu', $prompt) === 1;
    }

    /**
     * 娓叉煋浠诲姟涓婁笅鏂囧彉閲忥紝鍏煎 {{Knowledge}} 涓?{{knowledge}} 绛夊ぇ灏忓啓鍐欐硶銆?     *
     *
     * @param  array{title:string, keyword:string, knowledge:string}  $context
     */
    private function renderPromptTemplate(string $prompt, array $context): string
    {
        $renderedPrompt = preg_replace_callback('/\{\{#if\s+([A-Za-z_][A-Za-z0-9_]*)\s*\}\}(.*?)\{\{\/if\}\}/su', function (array $matches) use ($context): string {
            $name = (string) ($matches[1] ?? '');
            if (! $this->isKnownPromptContextName($name)) {
                return (string) ($matches[0] ?? '');
            }

            $value = $this->promptContextValue($name, $context);

            return trim($value) !== '' ? (string) ($matches[2] ?? '') : '';
        }, $prompt) ?? $prompt;

        return preg_replace_callback('/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/u', function (array $matches) use ($context): string {
            $name = (string) ($matches[1] ?? '');
            $value = $this->promptContextValue($name, $context);

            return $value !== '' || $this->isKnownPromptContextName($name) ? $value : (string) ($matches[0] ?? '');
        }, $renderedPrompt) ?? $renderedPrompt;
    }

    /**
     * @param  array{title:string, keyword:string, knowledge:string}  $context
     */
    private function promptContextValue(string $name, array $context): string
    {
        return match (mb_strtolower($name, 'UTF-8')) {
            'title' => $context['title'],
            'keyword' => $context['keyword'],
            'knowledge' => $context['knowledge'],
            default => '',
        };
    }

    private function isKnownPromptContextName(string $name): bool
    {
        return in_array(mb_strtolower($name, 'UTF-8'), ['title', 'keyword', 'knowledge'], true);
    }

    private function appendSmartPromptContext(string $prompt, string $title, string $keyword, string $knowledgeContext): string
    {
        if (! $this->isLikelyEnglishPrompt($prompt)) {
            $lines = [
                '【任务上下文】',
                '- 文章标题：'.$title,
            ];

            if (trim($keyword) !== '') {
                $lines[] = '- 核心关键词：'.$keyword;
            }

            if (trim($knowledgeContext) !== '') {
                $lines[] = '- 参考知识：';
                $lines[] = $knowledgeContext;
            }

            return trim($prompt)."\n\n".implode("\n", $lines);
        }

        $lines = [
            'Task context:',
            '- Article title: '.$title,
        ];

        if (trim($keyword) !== '') {
            $lines[] = '- Core keyword: '.$keyword;
        }

        if (trim($knowledgeContext) !== '') {
            $lines[] = '- Reference knowledge:';
            $lines[] = $knowledgeContext;
        }

        return trim($prompt)."\n\n".implode("\n", $lines);
    }

    private function finalPromptInstruction(string $prompt, string $knowledgeContext = ''): string
    {
        if (! $this->isLikelyEnglishPrompt($prompt)) {
            $instructions = [
                '请直接输出最终文章正文（Markdown），不要重复提示词、不要输出占位符。',
                '必须写成完整文章，包含开头导语、清晰小节和结尾总结；不要只输出检查清单、提纲或提示词内容。',
            ];
            //            if (trim($knowledgeContext) !== '') {
            //                $instructions[] = '如果提供了参考知识或来源资料，必须在正文中吸收关键事实，并在文末增加“参考依据”小节，列出可验证的来源、事实或引用依据；不要编造来源。';
            //            }

            return implode("\n", $instructions);
        }

        $instructions = [
            'Please output only the final article body in Markdown. Do not repeat the prompt or output placeholders.',
            'Write a complete article with an introduction, clear sections, and a conclusion. Do not output only a checklist, outline, or prompt instructions.',
        ];
        //        if (trim($knowledgeContext) !== '') {
        //            $instructions[] = 'When reference knowledge or source material is provided, incorporate the key facts into the article and include a "References" section with verifiable sources, facts, or citation basis. Do not invent sources.';
        //        }

        return implode("\n", $instructions);
    }

    private function isLikelyEnglishPrompt(string $prompt): bool
    {
        preg_match_all('/\p{Han}/u', $prompt, $cjkMatches);
        preg_match_all('/[A-Za-z]/', $prompt, $latinMatches);

        return count($latinMatches[0] ?? []) > 20 && count($cjkMatches[0] ?? []) <= 3;
    }

    /**
     * @Name: resolveKnowledgeContext
     *
     * @Description: 按任务绑定顺序检索知识库，返回实际使用的上下文、来源证据和检索统计；存在知识片段但未检索到可用片段时不回退整库内容。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-26 19:45:37
     *
     * @UpdateTime: 2026-10-26 19:45:37
     *
     * @Param: Task $task 当前文章任务。
     *
     * @Param: string $title 当前标题。
     *
     * @Param: string $keyword 当前标题关键词。
     *
     * @Param: AiExecutionContext|null $executionContext 当前 AI 执行身份。
     *
     * @Return array{context:string,evidence:list<array<string,mixed>>,retrieval_meta:array<string,mixed>} 知识上下文和来源快照。
     */
    private function resolveKnowledgeContext(
        Task $task,
        string $title,
        string $keyword,
        ?AiExecutionContext $executionContext,
    ): array {
        $knowledgeBaseIds = $task->knowledgeBases()
            ->limit(5)
            ->pluck('knowledge_bases.id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        if ($knowledgeBaseIds === [] && (int) ($task->knowledge_base_id ?? 0) > 0) {
            $knowledgeBaseIds = [(int) $task->knowledge_base_id];
        }
        if ($knowledgeBaseIds === []) {
            return ['context' => '', 'evidence' => [], 'retrieval_meta' => []];
        }

        $knowledgeBases = KnowledgeBase::query()
            ->whereIn('id', $knowledgeBaseIds)
            ->get(['id', 'content'])
            ->keyBy('id');
        if ($knowledgeBases->isEmpty()) {
            return ['context' => '', 'evidence' => [], 'retrieval_meta' => []];
        }

        $fallbackContents = [];
        foreach ($knowledgeBaseIds as $knowledgeBaseId) {
            $content = trim((string) ($knowledgeBases->get($knowledgeBaseId)?->content ?? ''));
            if ($content !== '') {
                $fallbackContents[$knowledgeBaseId] = $content;
            }
        }

        $identity = $executionContext;
        if (! $identity instanceof AiExecutionContext) {
            $adminId = (int) ($task->owner_admin_id ?? 0);
            $identity = $adminId > 0 ? Admin::query()->whereKey($adminId)->first() : null;
        }

        $query = trim($title."\n".$keyword);
        $bundle = $this->knowledgeRetrievalService->retrieveContextBundleFromMany(
            $knowledgeBaseIds,
            $query,
            5,
            3200,
            $identity,
        );
        if ($bundle['context'] !== '') {
            return $bundle;
        }

        if (KnowledgeChunk::query()->whereIn('knowledge_base_id', $knowledgeBaseIds)->exists()) {
            return ['context' => '', 'evidence' => [], 'retrieval_meta' => $bundle['retrieval_meta']];
        }

        return [
            'context' => $this->fallbackKnowledgeContext($fallbackContents, 2400),
            'evidence' => [],
            'retrieval_meta' => $bundle['retrieval_meta'],
        ];
    }

    /**
     * @Name: generationEvidenceSnapshot
     *
     * @Description: 将本次实际召回的知识片段压缩为不可变文章来源快照，供后续质检和溯源校验使用。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-26 19:45:37
     *
     * @UpdateTime: 2026-10-26 19:45:37
     *
     * @Param: array $evidence 知识检索服务返回的证据片段。
     *
     * @Return array 仅包含稳定标识、内容与来源哈希的证据快照。
     */
    private function generationEvidenceSnapshot(array $evidence): array
    {
        return array_values(array_map(static function (array $item): array {
            $content = trim((string) ($item['content'] ?? ''));
            $contentHash = (string) ($item['content_hash'] ?? hash('sha256', $content));

            return [
                'stable_key' => (int) ($item['knowledge_base_id'] ?? 0).':'.(int) ($item['chunk_id'] ?? 0).':'.$contentHash,
                'knowledge_base_id' => (int) ($item['knowledge_base_id'] ?? 0),
                'chunk_id' => (int) ($item['chunk_id'] ?? 0),
                'chunk_index' => (int) ($item['chunk_index'] ?? 0),
                'content_hash' => $contentHash,
                'source_hash' => (string) ($item['source_hash'] ?? ''),
                'snippet' => mb_substr($content, 0, 500, 'UTF-8'),
            ];
        }, $evidence));
    }

    /**
     * @Name: fallbackKnowledgeContext
     *
     * @Description: 仅在绑定知识库没有切片数据时，按绑定顺序拼接并截断原始知识内容。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-26 19:45:37
     *
     * @UpdateTime: 2026-10-26 19:45:37
     *
     * @Param: array<int,string> $contents 按知识库 ID 排序的原文内容。
     *
     * @Param: int $maxChars 拼接后的最大字符数。
     *
     * @Return string 可注入生成提示词的知识上下文。
     */
    private function fallbackKnowledgeContext(array $contents, int $maxChars): string
    {
        $parts = [];
        $remaining = max(0, $maxChars);
        foreach ($contents as $knowledgeBaseId => $content) {
            $content = trim((string) $content);
            if ($content === '' || $remaining <= 0) {
                continue;
            }

            $part = mb_substr($content, 0, $remaining, 'UTF-8');
            $parts[] = '[Knowledge base '.(int) $knowledgeBaseId."]\n".$part;
            $remaining -= mb_strlen($part, 'UTF-8');
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * 鎸変换鍔″浘鐗囬厤缃彃鍏?Markdown 閰嶅浘骞惰繑鍥炶閫変腑鐨勫浘鐗囧垪琛ㄣ€?     *
     *
     * @return array{content:string,images:list<Image>,generated_images?:list<array<string,mixed>>,image_error?:string|null}
     */
    private function buildImageContext(Task $task): string
    {
        $libraryId = (int) ($task->image_library_id ?? 0);
        if ($libraryId <= 0 || (int) ($task->image_count ?? 0) <= 0) {
            return '';
        }

        $images = Image::query()
            ->where('library_id', $libraryId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::IMAGE_CONTEXT_LIMIT)
            ->get(['id', 'file_path', 'original_name', 'tags']);

        $lines = [];
        foreach ($images as $image) {
            $url = ImageUrlNormalizer::toPublicUrl(trim((string) ($image->file_path ?? '')));
            if ($url === '') {
                continue;
            }

            $remark = preg_replace('/[\r\n\t|]+/u', ' ', trim((string) ($image->tags ?? ''))) ?? '';
            if ($remark === '') {
                $remark = ImageUrlNormalizer::readableAlt((string) ($image->original_name ?? ''));
            }
            $lines[] = '- '.($remark !== '' ? $remark.' | ' : '').$url;
        }

        return $lines === []
            ? ''
            : 'Maximum images / 配图数量上限：'.min(5, (int) $task->image_count)."\n".implode("\n", $lines);
    }

    private function insertTaskImagesIntoContent(
        Task $task,
        string $content,
        ?AiModel $chatModel = null,
        string $title = '',
        string $keyword = '',
        ?AiExecutionContext $executionContext = null,
    ): array {
        $imageMode = (string) ($task->image_mode ?? 'library');
        if ($imageMode === 'none') {
            $content = $this->markdownImagePolicy->filter($content, static fn (string $url): bool => false);

            return ['content' => $content, 'images' => []];
        }

        if ($imageMode === 'ai') {
            try {
                if (! $chatModel) {
                    throw new RuntimeException('AI image generation requires a chat model context');
                }
                $content = $this->markdownImagePolicy->filter($content, static fn (string $url): bool => false);
                $result = $this->aiGeneratedArticleImageService->generateAndInsert(
                    $task,
                    $chatModel,
                    $content,
                    $title,
                    $keyword,
                    $executionContext ?? (int) $task->getKey(),
                );

                return [
                    'content' => $result['content'],
                    'images' => [],
                    'generated_images' => $result['blocks'],
                    'image_error' => null,
                ];
            } catch (Throwable $exception) {
                return [
                    'content' => $content,
                    'images' => [],
                    'generated_images' => [],
                    'image_error' => $exception->getMessage(),
                ];
            }
        }

        $libraryId = (int) ($task->image_library_id ?? 0);
        $imageCount = min(5, max(0, (int) ($task->image_count ?? 0)));
        if ($libraryId <= 0 || $imageCount <= 0) {
            $content = $this->markdownImagePolicy->filter($content, static fn (string $url): bool => false);

            return ['content' => $content, 'images' => []];
        }

        $libraryImages = Image::query()
            ->where('library_id', $libraryId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::IMAGE_CONTEXT_LIMIT)
            ->get(['id', 'file_path', 'original_name', 'tags']);
        if ($libraryImages->isEmpty()) {
            $content = $this->markdownImagePolicy->filter($content, static fn (string $url): bool => false);

            return ['content' => $content, 'images' => []];
        }

        $allowed = $libraryImages->mapWithKeys(function (Image $image): array {
            $path = trim((string) ($image->file_path ?? ''));
            $url = ImageUrlNormalizer::toPublicUrl($path);

            return $url === '' ? [] : [$url => $image];
        });
        $selectedImages = [];
        $selectImage = function (string $url) use ($allowed, $imageCount, &$selectedImages): bool {
            $image = $allowed->get($url);
            if (! $image instanceof Image || count($selectedImages) >= $imageCount || isset($selectedImages[$image->id])) {
                return false;
            }

            $selectedImages[$image->id] = $image;

            return true;
        };
        $content = $this->markdownImagePolicy->filter($content, $selectImage);
        $selectedImages = array_values($selectedImages);
        if ($selectedImages !== []) {
            return ['content' => $content, 'images' => $selectedImages];
        }

        $images = $libraryImages->shuffle()->take($imageCount)->values()->all();
        $markdownBlocks = [];
        foreach ($images as $image) {
            $path = trim((string) ($image->file_path ?? ''));
            if ($path === '') {
                continue;
            }
            $path = ImageUrlNormalizer::toPublicUrl($path);
            $alt = ImageUrlNormalizer::readableAlt((string) ($image->original_name ?? ''));
            $markdownBlocks[] = '!['.($alt !== '' ? $alt : 'image').']('.$path.')';
        }
        if ($markdownBlocks !== []) {
            $content = $this->insertImagesByParagraphInterval($content, $markdownBlocks);
        }

        $selectedImages = [];
        $content = $this->markdownImagePolicy->filter($content, $selectImage);

        return ['content' => $content, 'images' => array_values($selectedImages)];
    }

    /**
     * @Name: insertImagesByParagraphInterval
     *
     * @Description: 按段落间隔插入候选图片，保留缩进代码块及 Markdown 段落的原始前导空格。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-26 18:58:50
     *
     * @UpdateTime: 2026-10-26 18:58:50
     *
     * @Param: string $content 文章 Markdown 正文。
     *
     * @Param: list<string> $markdownBlocks 需要插入的 Markdown 图片块。
     *
     * @Return: string 插图后的 Markdown 正文。
     */
    private function insertImagesByParagraphInterval(string $content, array $markdownBlocks): string
    {
        $trimmed = trim($content);
        if ($trimmed === '' || $markdownBlocks === []) {
            return $content;
        }

        $paragraphs = preg_split("/\n{2,}/u", $trimmed, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($paragraphs === []) {
            return $trimmed."\n\n".implode("\n\n", $markdownBlocks);
        }

        $paragraphCount = count($paragraphs);
        $imageCount = count($markdownBlocks);
        $interval = max(1, (int) floor($paragraphCount / ($imageCount + 1)));

        $parts = [];
        $imageIndex = 0;
        foreach ($paragraphs as $index => $paragraph) {
            $parts[] = (string) $paragraph;
            $nextParagraphPosition = $index + 1;

            if (
                $imageIndex < $imageCount
                && $nextParagraphPosition % $interval === 0
                && $nextParagraphPosition < $paragraphCount
            ) {
                $parts[] = $markdownBlocks[$imageIndex];
                $imageIndex++;
            }
        }

        while ($imageIndex < $imageCount) {
            $parts[] = $markdownBlocks[$imageIndex];
            $imageIndex++;
        }

        return implode("\n\n", array_values(array_filter($parts, static fn (string $part): bool => trim($part) !== '')));
    }

    /**
     * 璋冪敤浠诲姟閰嶇疆妯″瀷鐢熸垚姝ｆ枃銆?     */
    private function generateArticleKeywords(
        Task $task,
        AiModel $aiModel,
        string $content,
        string $title,
        string $keyword,
        ?AiExecutionContext $executionContext,
        int $taskId,
        ?array &$receipt,
    ): string {
        $fallback = trim($keyword);
        if ((int) ($task->auto_keywords ?? 1) !== 1) {
            return '';
        }

        $prompt = $this->latestSpecialPromptContent($task, 'keyword');
        if ($prompt === '') {
            return $fallback;
        }

        try {
            $generated = $this->generateContent(
                $executionContext,
                $aiModel,
                $this->renderSpecialPromptTemplate($prompt, $content, $title, $keyword),
                static fn (array $generation): array => $generation,
                $taskId,
                false,
            );
            $receipt = $generated['receipt'];

            return $this->normalizeGeneratedKeywords($generated['content'], $fallback);
        } catch (AiModelAccessException $exception) {
            throw $exception;
        } catch (Throwable) {
            return $fallback;
        }
    }

    private function generateArticleDescription(
        Task $task,
        AiModel $aiModel,
        string $content,
        string $title,
        string $keyword,
        string $excerpt,
        ?AiExecutionContext $executionContext,
        int $taskId,
        ?array &$receipt,
    ): string {
        $fallback = mb_substr(trim($excerpt), 0, 120);
        if ((int) ($task->auto_description ?? 1) !== 1) {
            return '';
        }

        $prompt = $this->latestSpecialPromptContent($task, 'description');
        if ($prompt === '') {
            return $fallback;
        }

        try {
            $generated = $this->generateContent(
                $executionContext,
                $aiModel,
                $this->renderSpecialPromptTemplate($prompt, $content, $title, $keyword),
                static fn (array $generation): array => $generation,
                $taskId,
                false,
            );
            $receipt = $generated['receipt'];

            return $this->normalizeGeneratedDescription($generated['content'], $fallback);
        } catch (AiModelAccessException $exception) {
            throw $exception;
        } catch (Throwable) {
            return $fallback;
        }
    }

    private function latestSpecialPromptContent(Task $task, string $type): string
    {
        $ownerPrompt = $this->aiConfigQueryForTask(
            Prompt::withoutGlobalScope('current_site'),
            $task,
            'prompts.owner_admin_id'
        )
            ->select(['id', 'content'])
            ->where('type', $type)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        return $ownerPrompt ? trim((string) $ownerPrompt->content) : '';
    }

    private function renderSpecialPromptTemplate(string $prompt, string $content, string $title, string $keyword): string
    {
        $context = [
            'content' => $content,
            'title' => $title,
            'keyword' => $keyword,
        ];

        $renderedPrompt = preg_replace_callback('/\{\{#if\s+([A-Za-z_][A-Za-z0-9_]*)\s*\}\}(.*?)\{\{\/if\}\}/su', function (array $matches) use ($context): string {
            $name = (string) ($matches[1] ?? '');
            if (! $this->isKnownSpecialPromptContextName($name)) {
                return (string) ($matches[0] ?? '');
            }

            $value = $this->specialPromptContextValue($name, $context);

            return trim($value) !== '' ? (string) ($matches[2] ?? '') : '';
        }, $prompt) ?? $prompt;

        return preg_replace_callback('/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/u', function (array $matches) use ($context): string {
            $name = (string) ($matches[1] ?? '');
            $value = $this->specialPromptContextValue($name, $context);

            return $value !== '' || $this->isKnownSpecialPromptContextName($name) ? $value : (string) ($matches[0] ?? '');
        }, $renderedPrompt) ?? $renderedPrompt;
    }

    /**
     * @param  array{content:string,title:string,keyword:string}  $context
     */
    private function specialPromptContextValue(string $name, array $context): string
    {
        return match (mb_strtolower($name, 'UTF-8')) {
            'content' => $context['content'],
            'title' => $context['title'],
            'keyword' => $context['keyword'],
            default => '',
        };
    }

    private function isKnownSpecialPromptContextName(string $name): bool
    {
        return in_array(mb_strtolower($name, 'UTF-8'), ['content', 'title', 'keyword'], true);
    }

    private function normalizeGeneratedKeywords(string $generated, string $fallback): string
    {
        $keywords = $this->extractKeywordList($this->stripCodeFence($generated));
        if ($keywords === []) {
            return $fallback;
        }

        return mb_substr(implode("\u{3001}", array_slice($keywords, 0, 10)), 0, 500);
    }

    /**
     * @return list<string>
     */
    private function extractKeywordList(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (is_array($decoded) && array_is_list($decoded)) {
                return $this->cleanKeywordParts($decoded);
            }

            if (is_array($decoded)) {
                foreach (['keywords', 'keyword', 'tags', 'meta_keywords'] as $key) {
                    if (array_key_exists($key, $decoded)) {
                        $value = $decoded[$key];

                        return $this->cleanKeywordParts(is_array($value) ? $value : [(string) $value]);
                    }
                }
            }
        }

        $parts = preg_split('/[\r\n,;\|\x{FF0C}\x{3001}\x{FF1B}]+/u', $text) ?: [];

        return $this->cleanKeywordParts($parts);
    }

    /**
     * @param  iterable<mixed>  $parts
     * @return list<string>
     */
    private function cleanKeywordParts(iterable $parts): array
    {
        $keywords = [];
        $seen = [];
        foreach ($parts as $part) {
            if (is_array($part)) {
                $part = implode(' ', array_filter($part, static fn (mixed $value): bool => is_scalar($value)));
            }

            if (! is_scalar($part)) {
                continue;
            }

            $keyword = trim((string) $part);
            $keyword = preg_replace('/^\s*(?:[-*]|\d+[\.)\x{3001}]|\x{2022})\s*/u', '', $keyword) ?? $keyword;
            $keyword = preg_replace('/^\s*(?:keywords?|tags?|meta\s*keywords?|关键词|关键字|标签)\s*[:：]\s*/iu', '', $keyword) ?? $keyword;
            $keyword = preg_replace('/^[\s"\'`“”‘’\[\]【】()（）<>《》:：,，;；.。!！?？|｜\/\\\\]+|[\s"\'`“”‘’\[\]【】()（）<>《》:：,，;；.。!！?？|｜\/\\\\]+$/u', '', $keyword) ?? $keyword;
            $keyword = preg_replace('/\s+/u', ' ', trim($keyword)) ?? $keyword;

            if ($keyword === '') {
                continue;
            }

            $key = mb_strtolower($keyword, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $keywords[] = mb_substr($keyword, 0, 80);
        }

        return $keywords;
    }

    private function normalizeGeneratedDescription(string $generated, string $fallback): string
    {
        $text = $this->stripCodeFence($generated);
        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            foreach (['description', 'meta_description', 'summary', 'excerpt'] as $key) {
                if (isset($decoded[$key]) && is_scalar($decoded[$key])) {
                    $text = (string) $decoded[$key];
                    break;
                }
            }
        }

        $text = trim($text);
        $text = preg_replace('/^\s*(?:description|meta\s*description|summary|excerpt|摘要|描述)\s*[:：]\s*/iu', '', $text) ?? $text;
        $text = preg_replace('/^[\s"\'`“”‘’]+|[\s"\'`“”‘’]+$/u', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? $text;

        return $text !== '' ? mb_substr($text, 0, 500) : $fallback;
    }

    private function stripCodeFence(string $text): string
    {
        $text = trim($text);
        if (preg_match('/^```(?:[A-Za-z0-9_-]+)?\s*(.*?)\s*```$/su', $text, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }

        return $text;
    }

    /**
     * Generate content with the selected task model.
     */
    /**
     * @template TResult
     *
     * @param  Closure(array{content:string,model:AiModel,receipt:array{model_id:int,request_id:string,configuration_digest:string}}): TResult  $persistGeneratedContent
     * @return TResult
     */
    private function generateContent(
        ?AiExecutionContext $executionContext,
        AiModel $aiModel,
        string $contentPrompt,
        Closure $persistGeneratedContent,
        ?int $legacyTaskId = null,
        bool $streamLegacyResponse = true,
    ): mixed {
        $executionIdentity = $executionContext ?? $legacyTaskId;
        if (! $executionIdentity instanceof AiExecutionContext
            && (! is_int($executionIdentity) || $executionIdentity <= 0 || $this->aiExecutionAccessGuard->identityRequired())) {
            throw AiModelAccessException::configAccessRevokedForAdminId(
                (int) ($aiModel->owner_admin_id ?? 0),
            );
        }

        return $this->aiModelInvocationGateway->generate(
            $executionIdentity,
            $aiModel,
            $contentPrompt,
            function (array $invocation) use ($persistGeneratedContent): mixed {
                $rawContent = (string) ($invocation['response']->text ?? '');
                $content = $this->articleCitationMarkerCleaner->cleanContent(
                    OpenAiRuntimeProvider::normalizeGeneratedText($rawContent),
                );
                if ($content === '') {
                    if (OpenAiRuntimeProvider::looksLikeSseCompletionPayload($rawContent)) {
                        throw new RuntimeException('AI 返回空流式响应，未生成正文内容，请重试或检查模型流式输出兼容性');
                    }

                    throw new RuntimeException('AI返回空正文');
                }
                $currentModel = $invocation['model'];
                $this->warnIfContentLooksTruncated($content, $currentModel, $invocation['response']);
                $persistedContent = $this->persistGeneratedContent($content, $invocation['receipt']);

                return $persistGeneratedContent([
                    'content' => $persistedContent['content'],
                    'model' => $currentModel,
                    'receipt' => $persistedContent['receipt'],
                ]);
            },
            operation: 'article.generate',
            businessSource: 'worker_article_generation',
            stream: $streamLegacyResponse,
        );
    }

    /** @param array{model_id:int,request_id:string,configuration_digest:string} $receipt
     * @return array{content:string,receipt:array{model_id:int,request_id:string,configuration_digest:string}}
     */
    private function persistGeneratedContent(string $content, array $receipt): array
    {
        return ['content' => $content, 'receipt' => $receipt];
    }

    private function resolveMaxTokens(AiModel $aiModel): int
    {
        return $this->aiModelInvocationGateway->maxTokens($aiModel);
    }

    private function warnIfContentLooksTruncated(string $content, AiModel $aiModel, object $response): void
    {
        $trimmed = rtrim($content);
        if ($trimmed === '') {
            return;
        }

        $maxTokens = $this->resolveMaxTokens($aiModel);
        $completionTokens = (int) ($response->usage->completionTokens ?? 0);
        $nearTokenLimit = $completionTokens > 0 && $completionTokens >= (int) floor($maxTokens * 0.92);
        $lengthFinishReason = collect($response->steps ?? [])->contains(function (mixed $step): bool {
            $finishReason = is_object($step) ? ($step->finishReason ?? null) : null;

            return $finishReason === FinishReason::Length
                || (is_string($finishReason) && $finishReason === FinishReason::Length->value)
                || (is_object($finishReason)
                    && property_exists($finishReason, 'value')
                    && $finishReason->value === FinishReason::Length->value);
        });
        $unclosedFence = substr_count($trimmed, '```') % 2 === 1;
        $lastChar = mb_substr($trimmed, -1);
        $allowedEndings = ['。', '！', '？', '.', '!', '?', '”', '"', '）', ')', '》', '`', '】', ']', '…', ':', '：', ';', '；', '-', '—'];
        $hasAbruptTrailingText = $nearTokenLimit
            && ! in_array($lastChar, $allowedEndings, true)
            && preg_match('/[\p{L}\p{N}]$/u', $trimmed) === 1;

        if (! $lengthFinishReason && ! $unclosedFence && ! $hasAbruptTrailingText) {
            return;
        }

        Log::warning('GeoFlow 正文疑似被截断，建议调大该模型的 max_tokens', [
            'ai_model_id' => (int) $aiModel->id,
            'model_id' => (string) ($aiModel->model_id ?? ''),
            'max_tokens' => $maxTokens,
            'completion_tokens' => $completionTokens,
            'content_length' => mb_strlen($trimmed),
            'finish_reason_length' => $lengthFinishReason,
            'unclosed_code_fence' => $unclosedFence,
            'has_abrupt_trailing_text' => $hasAbruptTrailingText,
        ]);
    }

    private function quotaAdminId(Task $task): ?int
    {
        $adminId = (int) ($task->owner_admin_id ?? 0);

        return $adminId > 0 ? $adminId : null;
    }

    private function aiConfigOwnerIdForTask(Task $task): ?int
    {
        $adminId = (int) ($task->owner_admin_id ?? 0);
        if ($adminId <= 0) {
            return null;
        }

        $admin = Admin::query()->whereKey($adminId)->first(['id', 'role', 'created_by']);

        return $admin instanceof Admin ? $this->aiConfigurationScope->ownerAdminIdForConsumer($admin) : null;
    }

    private function aiConfigQueryForTask($query, Task $task, string $column)
    {
        return $this->aiConfigurationScope->applyOwnerIdScope($query, $this->aiConfigOwnerIdForTask($task), $column);
    }

    /**
     * @Name: buildExcerpt
     *
     * @Description: 优先提取文章核心摘要段落，否则回退到首个正文段落，保持既有摘要展示规则。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-26 18:58:50
     *
     * @UpdateTime: 2026-10-30 05:17:20
     *
     * @Param: string $content 已完成风险清理和配图过滤的文章正文。
     *
     * @Return: string 用于文章摘要及元描述的纯文本。
     */
    private function buildExcerpt(string $content): string
    {
        $excerpt = ArticleHtmlPresenter::excerptFromMarkdown($content, 0);
        if ($excerpt === '') {
            return 'AI 生成内容摘要';
        }

        return $excerpt;
    }

    /**
     * @return array<string,int>
     */
    private function termFrequencies(string $text): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower(trim($text), 'UTF-8')) ?: [];
        $frequencies = [];
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '' || mb_strlen($token, 'UTF-8') <= 1) {
                continue;
            }
            $frequencies[$token] = (int) ($frequencies[$token] ?? 0) + 1;
        }

        return $frequencies;
    }

    /**
     * @param  array<string,int>  $queryTerms
     * @param  array<string,int>  $chunkTerms
     */
    private function lexicalScore(array $queryTerms, array $chunkTerms): float
    {
        if ($queryTerms === [] || $chunkTerms === []) {
            return 0.0;
        }

        $matched = 0;
        $total = 0;
        foreach ($queryTerms as $term => $count) {
            $total += $count;
            if (isset($chunkTerms[$term])) {
                $matched += min($count, (int) $chunkTerms[$term]);
            }
        }

        return $total > 0 ? ($matched / $total) : 0.0;
    }

    /**
     * @return list<float>
     */
    private function decodeVector(string $json): array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded) || $decoded === []) {
            return [];
        }

        $vector = [];
        foreach ($decoded as $value) {
            if (is_numeric($value)) {
                $vector[] = (float) $value;
            }
        }

        return $vector;
    }

    /**
     * @param  list<float>  $left
     * @param  list<float>  $right
     */
    private function dotProduct(array $left, array $right): float
    {
        if ($left === [] || $right === []) {
            return 0.0;
        }
        $sum = 0.0;
        $limit = min(count($left), count($right));
        for ($i = 0; $i < $limit; $i++) {
            $sum += ((float) $left[$i]) * ((float) $right[$i]);
        }

        return $sum;
    }

    /**
     * @return list<float>
     */
    private function buildFallbackVector(string $text, int $dimensions): array
    {
        $dimensions = max(1, $dimensions);
        $vector = array_fill(0, $dimensions, 0.0);
        foreach ($this->termFrequencies($text) as $token => $count) {
            $indexSeed = abs((int) crc32('i:'.$token));
            $signSeed = abs((int) crc32('s:'.$token));
            $index = $indexSeed % $dimensions;
            $sign = ($signSeed % 2 === 0) ? 1.0 : -1.0;
            $tokenLength = max(1, mb_strlen($token, 'UTF-8'));
            $weight = (1.0 + log(1 + $count)) * min(2.0, 0.8 + ($tokenLength / 4));
            $vector[$index] += $sign * $weight;
        }

        $norm = 0.0;
        foreach ($vector as $value) {
            $norm += $value * $value;
        }
        if ($norm > 0.0) {
            $norm = sqrt($norm);
            foreach ($vector as $index => $value) {
                $vector[$index] = $value / $norm;
            }
        }

        return $vector;
    }

    /**
     * 浼樺厛浣跨敤 pgvector 鎵ц鏁版嵁搴撳悜閲忔绱紝鍛戒腑鍒欒繑鍥炲€欓€夊潡銆?     *
     *
     * @return list<array{chunk_index:int,content:string,score:float}>
     */
    private function fetchKnowledgeChunksByPgvector(
        KnowledgeBase $knowledgeBase,
        string $query,
        int $candidateLimit,
        Admin|AiExecutionContext|null $identity,
    ): array {
        $bundle = $this->knowledgeRetrievalService->retrieveContextBundleFromMany(
            [(int) $knowledgeBase->getKey()],
            $query,
            max(1, $candidateLimit),
            3200,
            $identity,
        );

        return array_values(array_map(static fn (array $item): array => [
            'chunk_index' => (int) ($item['chunk_index'] ?? 0),
            'content' => (string) ($item['content'] ?? ''),
            'score' => (float) ($item['score'] ?? 0),
        ], $bundle['evidence'] ?? []));
    }

    /**
     * 浠庡€欓€夊潡鎷艰鐭ヨ瘑涓婁笅鏂囷紝鎸夌墖娈甸『搴忚緭鍑恒€?     *
     *
     * @param  list<array{chunk_index:int,content:string,score:float}>  $scored
     */
    private function composeKnowledgeContext(array $scored, int $limit, int $maxChars): string
    {
        if ($scored === []) {
            return '';
        }

        $selected = array_slice($scored, 0, max(1, $limit));
        usort($selected, static fn (array $a, array $b): int => $a['chunk_index'] <=> $b['chunk_index']);

        $parts = [];
        $charCount = 0;
        foreach ($selected as $index => $chunk) {
            $content = trim((string) ($chunk['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $nextLength = $charCount + mb_strlen($content, 'UTF-8');
            if ($parts !== [] && $nextLength > $maxChars) {
                continue;
            }
            $parts[] = '[Knowledge chunk '.($index + 1).']'."\n".$content;
            $charCount = $nextLength;
        }

        return trim(implode("\n\n", $parts));
    }
}
