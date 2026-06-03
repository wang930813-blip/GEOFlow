<?php

namespace App\Services\GeoFlow;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\Author;
use App\Models\Category;
use App\Models\Image;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\Title;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\ArticleWorkflow;
use App\Support\GeoFlow\ImageUrlNormalizer;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Worker 浠诲姟鎵ц鍣細灏嗛槦鍒椾换鍔¤惤鍦颁负鏂囩珷璁板綍锛堝崰浣嶅疄鐜帮紝鍏堟墦閫?worker/闃熷垪閾捐矾锛夈€? */
class WorkerExecutionService
{
    /**
     * 澶嶇敤缁熶竴 API Key 瑙ｅ瘑缁勪欢锛岀‘淇?worker 涓庡悗鍙伴厤缃瑙ｅ瘑琛屼负涓€鑷淬€?     */
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly KnowledgeChunkSyncService $knowledgeChunkSyncService,
        private readonly AiGeneratedArticleImageService $aiGeneratedArticleImageService,
        private readonly GeoArticleContextService $geoArticleContextService,
        private readonly DistributionOrchestrator $distributionOrchestrator
    ) {}

    /**
     * @return array{article_id:int|null, title:string, message:string, meta:array<string,mixed>}
     */
    public function executeTask(int $taskId): array
    {
        /** @var Task|null $task */
        $task = Task::query()->find($taskId);
        if (! $task) {
            throw new RuntimeException('Task not found');
        }

        if (($task->status ?? 'paused') !== 'active' || (int) ($task->schedule_enabled ?? 1) !== 1) {
            throw new RuntimeException('Task is not active');
        }

        $publishResult = $this->publishDueDraftArticle($task);
        if ($publishResult !== null) {
            $this->distributionOrchestrator->enqueueForArticle((int) $publishResult['article_id']);

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

        $titleRow = $this->pickTitle($task);
        $author = $this->pickAuthor($task);
        $category = $this->pickCategory($task);
        $prompt = $task->prompt_id ? Prompt::query()->find((int) $task->prompt_id) : null;

        $keyword = (string) ($titleRow->keyword ?? '');
        $knowledgeContext = $this->resolveKnowledgeContext($task, (string) $titleRow->title, $keyword);
        $contentPrompt = $this->buildContentPromptForTask($task, (string) $titleRow->title, $keyword, $prompt?->content, $knowledgeContext);
        $generation = $this->generateContentWithModelSelection($task, $contentPrompt);
        $aiModel = $generation['model'];
        $generatedContent = $generation['content'];
        $imageResult = $this->insertTaskImagesIntoContent($task, $generatedContent, $aiModel, (string) $titleRow->title, $keyword);
        $content = $imageResult['content'];
        $selectedImages = $imageResult['images'];
        $generatedImages = $imageResult['generated_images'] ?? [];
        $imageError = $imageResult['image_error'] ?? null;
        $excerpt = $this->buildExcerpt($content);
        $generatedKeywords = $this->generateArticleKeywords($task, $aiModel, $content, (string) $titleRow->title, $keyword);
        $generatedDescription = $this->generateArticleDescription($task, $aiModel, $content, (string) $titleRow->title, $keyword, $excerpt);
        $workflow = [
            'status' => 'draft',
            'review_status' => (int) ($task->need_review ?? 1) === 1 ? 'pending' : 'approved',
            'published_at' => null,
        ];

        $articleId = DB::transaction(function () use ($task, $titleRow, $author, $category, $keyword, $generatedKeywords, $generatedDescription, $content, $excerpt, $workflow, $selectedImages): int {
            $freshTask = Task::query()
                ->whereKey((int) $task->id)
                ->lockForUpdate()
                ->first(['id', 'site_id', 'status', 'schedule_enabled', 'created_count', 'draft_limit', 'article_limit', 'publish_interval', 'next_publish_at']);
            if (! $freshTask || ($freshTask->status ?? 'paused') !== 'active' || (int) ($freshTask->schedule_enabled ?? 1) !== 1) {
                throw new RuntimeException('Task is not active');
            }
            $generationBlockReason = $this->getGenerationBlockReason($freshTask, true);
            if ($generationBlockReason !== null) {
                throw new RuntimeException($generationBlockReason);
            }
            $siteId = (int) ($freshTask->site_id ?: $task->site_id);

            $article = Article::query()->create([
                'site_id' => $siteId > 0 ? $siteId : null,
                'title' => (string) $titleRow->title,
                'slug' => ArticleWorkflow::generateUniqueSlug((string) $titleRow->title),
                'excerpt' => $excerpt,
                'content' => $content,
                'category_id' => $category?->id,
                'author_id' => $author?->id,
                'task_id' => (int) $task->id,
                'original_keyword' => $keyword,
                'keywords' => $generatedKeywords,
                'meta_description' => $generatedDescription,
                'status' => $workflow['status'],
                'review_status' => $workflow['review_status'],
                'is_ai_generated' => 1,
                'published_at' => $workflow['published_at'],
                'view_count' => 0,
            ]);
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

            // 淇濇寔涓庢棫閫昏緫涓€鑷达細姣忔浠诲姟鎵ц浼氭秷鑰楁爣棰樺苟绱姞浠诲姟璁℃暟銆?            Title::query()->whereKey($titleRow->id)->increment('used_count');
            Title::query()->whereKey($titleRow->id)->increment('usage_count');

            $articleLimit = max(1, (int) ($freshTask->article_limit ?? $freshTask->draft_limit ?? 10));
            $nextCreatedCount = (int) ($freshTask->created_count ?? 0) + 1;
            $taskUpdate = [
                'created_count' => DB::raw('COALESCE(created_count,0)+1'),
                'loop_count' => DB::raw('COALESCE(loop_count,0)+1'),
                'updated_at' => now(),
            ];

            if ($nextCreatedCount >= $articleLimit) {
                $taskUpdate['status'] = 'paused';
                $taskUpdate['schedule_enabled'] = 0;
                $taskUpdate['next_run_at'] = null;
            } elseif ($freshTask->next_publish_at === null || ! $freshTask->next_publish_at->greaterThan(now())) {
                $taskUpdate['next_publish_at'] = now()->addSeconds($this->normalizePublishInterval($freshTask));
            }
            Task::query()->whereKey($task->id)->update($taskUpdate);

            return (int) $article->id;
        });

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
                'image_mode' => (string) ($task->image_mode ?? 'library'),
                'image_count' => count($selectedImages) + count($generatedImages),
                'generated_images' => $generatedImages,
                'image_error' => $imageError,
                'model_selection_mode' => (string) ($task->model_selection_mode ?? 'fixed'),
                'used_model_id' => (int) $aiModel->id,
                'used_model_name' => (string) $aiModel->name,
                'model_attempts' => $generation['attempts'],
            ],
        ];
    }

    /**
     * 鍙戝竷涓€涓凡瀹℃牳鑽夌銆傜敓鎴愪笌鍙戝竷瑙ｈ€﹀悗锛學orker 姣忔鎵ц浼樺厛閲婃斁鍒版湡鑽夌銆?     *
     *
     * @return array{article_id:int, title:string, message:string, meta:array<string,mixed>}|null
     */
    private function publishDueDraftArticle(Task $task): ?array
    {
        if ($task->next_publish_at !== null && $task->next_publish_at->greaterThan(now())) {
            return null;
        }

        return DB::transaction(function () use ($task): ?array {
            $freshTask = Task::query()
                ->whereKey((int) $task->id)
                ->lockForUpdate()
                ->first(['id', 'site_id', 'status', 'schedule_enabled', 'publish_interval', 'next_publish_at', 'publish_scope']);
            if (! $freshTask || ($freshTask->status ?? 'paused') !== 'active' || (int) ($freshTask->schedule_enabled ?? 1) !== 1) {
                throw new RuntimeException('Task is not active');
            }

            if ($freshTask->next_publish_at !== null && $freshTask->next_publish_at->greaterThan(now())) {
                return null;
            }

            /** @var Article|null $article */
            $article = Article::query()
                ->where('task_id', (int) $freshTask->id)
                ->where('status', 'draft')
                ->whereIn('review_status', ['approved', 'auto_approved'])
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first(['id', 'title', 'review_status']);
            if (! $article) {
                return null;
            }

            $publishScope = (string) ($freshTask->publish_scope ?? 'local_and_distribution');
            $targetStatus = $publishScope === 'distribution_only' ? 'private' : 'published';
            $workflow = ArticleWorkflow::normalizeState($targetStatus, (string) ($article->review_status ?: 'approved'));
            Article::query()->whereKey((int) $article->id)->update([
                'status' => $workflow['status'],
                'review_status' => $workflow['review_status'],
                'published_at' => $workflow['published_at'],
                'updated_at' => now(),
            ]);

            $publishInterval = $this->normalizePublishInterval($freshTask);
            Task::query()->whereKey((int) $freshTask->id)->update([
                'published_count' => DB::raw('COALESCE(published_count,0)+1'),
                'next_publish_at' => now()->addSeconds($publishInterval),
                'updated_at' => now(),
            ]);

            return [
                'article_id' => (int) $article->id,
                'title' => (string) $article->title,
                'message' => '鑽夌鍙戝竷鎴愬姛',
                'meta' => [
                    'task_id' => (int) $freshTask->id,
                    'action' => 'publish_draft',
                    'publish_interval' => $publishInterval,
                ],
            ];
        });
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
    private function resolveAiModel(Task $task): AiModel
    {
        $aiModelId = (int) ($task->ai_model_id ?? 0);
        if ($aiModelId <= 0) {
            throw new RuntimeException('Task AI model is not configured');
        }

        $aiModel = AiModel::query()
            ->whereKey($aiModelId)
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->first();

        if (! $aiModel) {
            throw new RuntimeException('Task AI model is unavailable');
        }

        return $aiModel;
    }

    /**
     * 鍥哄畾妯″瀷鍙皾璇曚富妯″瀷锛涙櫤鑳藉垏鎹㈡寜 failover_priority 渚濇灏濊瘯鍏跺畠 active chat 妯″瀷銆?     *
     *
     * @return array{content:string,model:AiModel,attempts:list<array{model_id:int,model_name:string,status:string,reason:?string}>}
     */
    private function generateContentWithModelSelection(Task $task, string $contentPrompt): array
    {
        $mode = (string) ($task->model_selection_mode ?? 'fixed');
        $attempts = [];
        $lastMessage = '';

        foreach ($this->resolveAiModelCandidates($task) as $candidate) {
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
                $content = $this->generateContent($candidate, $contentPrompt);
                $attempts[] = $this->buildModelAttempt($candidate, 'success', null);

                return [
                    'content' => $content,
                    'model' => $candidate,
                    'attempts' => $attempts,
                ];
            } catch (Throwable $exception) {
                $lastMessage = trim($exception->getMessage());
                $attempts[] = $this->buildModelAttempt($candidate, 'failed', $lastMessage);

                if ($mode !== 'smart_failover') {
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
    private function resolveAiModelCandidates(Task $task): array
    {
        $primaryModel = $this->resolveAiModel($task);
        if (($task->model_selection_mode ?? 'fixed') !== 'smart_failover') {
            return [$primaryModel];
        }

        $fallbackModels = AiModel::query()
            ->whereKeyNot((int) $primaryModel->id)
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->get()
            ->all();

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
            'reason' => $reason,
        ];
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
            throw new RuntimeException('Task title library is not configured');
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

        $query = Title::query()->where('library_id', $libraryId);
        if ((int) ($task->is_loop ?? 0) !== 1) {
            $query->where(function ($builder): void {
                $builder->whereNull('used_count')->orWhere('used_count', '<=', 0);
            });
        }

        /** @var Title|null $title */
        $title = $query
            ->orderBy('used_count')
            ->orderBy('id')
            ->first();

        if (! $title) {
            throw new RuntimeException((int) ($task->is_loop ?? 0) === 1 ? 'No available title' : 'Title library exhausted');
        }

        return $title;
    }

    private function pickAuthor(Task $task): Author
    {
        $authorId = (int) ($task->custom_author_id ?: $task->author_id);
        if ($authorId > 0) {
            $author = Author::query()->find($authorId);
            if ($author) {
                return $author;
            }
        }

        $author = Author::query()->orderBy('id')->first();
        if ($author) {
            return $author;
        }

        return Author::query()->firstOrCreate(
            ['name' => 'GEOFlow'],
            ['bio' => 'Default GEOFlow author for automated content generation.']
        );
    }

    private function pickCategory(Task $task): ?Category
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

        return $query->orderBy('sort_order')->orderBy('id')->first();
    }

    /**
     * 鏋勯€犳鏂囨彁绀鸿瘝锛氫紭鍏堢簿纭浛鎹㈠彉閲忥紱鏃犲彉閲忕殑鑷畾涔夋彁绀鸿瘝鑷姩琛ラ綈浠诲姟涓婁笅鏂囥€?     */
    private function buildContentPrompt(string $title, string $keyword, ?string $promptContent, string $knowledgeContext): string
    {
        return $this->buildContentPromptWithGeoContext($title, $keyword, $promptContent, $knowledgeContext, '');
    }

    private function buildContentPromptForTask(Task $task, string $title, string $keyword, ?string $promptContent, string $knowledgeContext): string
    {
        $geoContext = $this->geoArticleContextService->buildForTask($task, $keyword);

        return $this->buildContentPromptWithGeoContext($title, $keyword, $promptContent, $knowledgeContext, $geoContext);
    }

    private function buildContentPromptWithGeoContext(string $title, string $keyword, ?string $promptContent, string $knowledgeContext, string $geoContext): string
    {
        $prompt = trim((string) $promptContent);
        $isFallbackPrompt = false;
        if ($prompt === '') {
            $prompt = 'Write a clear, structured article based on the provided title and keyword.';
            $isFallbackPrompt = true;
        }

        $hasExplicitContextVariables = $isFallbackPrompt || $this->promptHasKnownContextVariables($prompt);
        $renderedPrompt = $this->renderPromptTemplate($prompt, [
            'title' => $title,
            'keyword' => $keyword,
            'knowledge' => $knowledgeContext,
        ]);

        if (! $hasExplicitContextVariables) {
            $renderedPrompt = $this->appendSmartPromptContext($renderedPrompt, $title, $keyword, $knowledgeContext);
        }

        if (trim($geoContext) !== '') {
            $renderedPrompt = trim($renderedPrompt)."\n\n".trim($geoContext);
        }

        return trim($renderedPrompt)."\n\n".$this->finalPromptInstruction($renderedPrompt, $knowledgeContext);
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
     * 鎸変换鍔￠厤缃绱㈢煡璇嗗簱涓婁笅鏂囧苟鍥炲～鍒?{{Knowledge}}銆?     */
    private function resolveKnowledgeContext(Task $task, string $title, string $keyword): string
    {
        $knowledgeBaseId = (int) ($task->knowledge_base_id ?? 0);
        if ($knowledgeBaseId <= 0) {
            return '';
        }

        $knowledgeBase = KnowledgeBase::query()
            ->whereKey($knowledgeBaseId)
            ->first(['id', 'content']);
        if (! $knowledgeBase) {
            return '';
        }

        $content = trim((string) ($knowledgeBase->content ?? ''));
        if ($content === '') {
            return '';
        }

        $chunkCount = KnowledgeChunk::query()->where('knowledge_base_id', $knowledgeBaseId)->count();
        if ($chunkCount <= 0) {
            $this->knowledgeChunkSyncService->sync($knowledgeBaseId, $content);
        }

        $query = trim($title."\n".$keyword);
        $context = $this->fetchKnowledgeContextFromChunks($knowledgeBaseId, $query, 4, 2400);
        if ($context !== '') {
            return $context;
        }

        return mb_strlen($content, 'UTF-8') > 2400 ? mb_substr($content, 0, 2400, 'UTF-8') : $content;
    }

    /**
     * 浠?knowledge_chunks 涓绱㈢浉鍏崇墖娈点€?     */
    private function fetchKnowledgeContextFromChunks(int $knowledgeBaseId, string $query, int $limit, int $maxChars): string
    {
        if (trim($query) !== '') {
            $vectorRows = $this->fetchKnowledgeChunksByPgvector($knowledgeBaseId, $query, max($limit * 3, 8));
            if ($vectorRows !== []) {
                return $this->composeKnowledgeContext($vectorRows, $limit, $maxChars);
            }
        }

        $rows = KnowledgeChunk::query()
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->orderBy('chunk_index')
            ->get(['chunk_index', 'content', 'embedding_json', 'embedding_model_id', 'embedding_dimensions'])
            ->all();
        if ($rows === []) {
            return '';
        }

        $queryTerms = $this->termFrequencies($query);
        $hasRealEmbeddingRows = collect($rows)->contains(
            fn ($row): bool => $this->chunkHasRealEmbedding($row)
        );
        $useRealEmbeddingScore = false;
        $queryVector = [];
        if ($hasRealEmbeddingRows && trim($query) !== '') {
            $queryVector = $this->knowledgeChunkSyncService->generateQueryEmbeddingVector($query);
            $useRealEmbeddingScore = $queryVector !== [];
        }
        if ($queryVector === []) {
            $queryVector = $this->decodeVector(json_encode($this->buildFallbackVector($query, 256)));
        }

        $scored = [];
        foreach ($rows as $row) {
            $content = trim((string) ($row->content ?? ''));
            if ($content === '') {
                continue;
            }

            $vector = $this->decodeVector((string) ($row->embedding_json ?? ''));
            $chunkTerms = $this->termFrequencies($content);
            $lexicalScore = $this->lexicalScore($queryTerms, $chunkTerms);
            $chunkUsesRealEmbedding = $this->chunkHasRealEmbedding($row);
            $vectorScore = ($useRealEmbeddingScore === $chunkUsesRealEmbedding)
                ? $this->dotProduct($queryVector, $vector)
                : 0.0;
            $score = ($vectorScore * 0.75) + ($lexicalScore * 0.25);

            $scored[] = [
                'chunk_index' => (int) ($row->chunk_index ?? 0),
                'content' => $content,
                'score' => $score,
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            $diff = ($b['score'] <=> $a['score']);

            return $diff !== 0 ? $diff : ($a['chunk_index'] <=> $b['chunk_index']);
        });

        return $this->composeKnowledgeContext($scored, $limit, $maxChars);
    }

    /**
     * 鍒ゆ柇 chunk 鏄惁淇濆瓨浜嗙湡瀹?embedding锛岃€屼笉鏄?fallback hash 鍚戦噺銆?     */
    private function chunkHasRealEmbedding(object $row): bool
    {
        return (int) ($row->embedding_model_id ?? 0) > 0
            && (int) ($row->embedding_dimensions ?? 0) > 0;
    }

    /**
     * 鎸変换鍔″浘鐗囬厤缃彃鍏?Markdown 閰嶅浘骞惰繑鍥炶閫変腑鐨勫浘鐗囧垪琛ㄣ€?     *
     *
     * @return array{content:string,images:list<Image>,generated_images?:list<array<string,mixed>>,image_error?:string|null}
     */
    private function insertTaskImagesIntoContent(Task $task, string $content, ?AiModel $chatModel = null, string $title = '', string $keyword = ''): array
    {
        $imageMode = (string) ($task->image_mode ?? 'library');
        if ($imageMode === 'none') {
            return ['content' => $content, 'images' => []];
        }

        if ($imageMode === 'ai') {
            try {
                if (! $chatModel) {
                    throw new RuntimeException('AI image generation requires a chat model context');
                }
                $result = $this->aiGeneratedArticleImageService->generateAndInsert($task, $chatModel, $content, $title, $keyword);

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
        $imageCount = max(0, (int) ($task->image_count ?? 0));
        if ($libraryId <= 0 || $imageCount <= 0) {
            return ['content' => $content, 'images' => []];
        }

        /** @var list<Image> $images */
        $images = Image::query()
            ->where('library_id', $libraryId)
            ->inRandomOrder()
            ->limit($imageCount)
            ->get(['id', 'file_path', 'original_name'])
            ->all();
        if ($images === []) {
            return ['content' => $content, 'images' => []];
        }

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

        return ['content' => $content, 'images' => $images];
    }

    /**
     * 鎸夋钀介棿闅旀彃鍏ュ浘鐗囷紝閬垮厤鍏ㄩ儴鍫嗗湪鏂囨湯銆?     *
     *
     * @param  list<string>  $markdownBlocks
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
            $parts[] = trim((string) $paragraph);
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
    private function generateArticleKeywords(Task $task, AiModel $aiModel, string $content, string $title, string $keyword): string
    {
        $fallback = trim($keyword);
        if ((int) ($task->auto_keywords ?? 1) !== 1) {
            return $fallback;
        }

        $prompt = $this->latestSpecialPromptContent($task, 'keyword');
        if ($prompt === '') {
            return $fallback;
        }

        try {
            $generated = $this->generateContent($aiModel, $this->renderSpecialPromptTemplate($prompt, $content, $title, $keyword));

            return $this->normalizeGeneratedKeywords($generated, $fallback);
        } catch (Throwable) {
            return $fallback;
        }
    }

    private function generateArticleDescription(Task $task, AiModel $aiModel, string $content, string $title, string $keyword, string $excerpt): string
    {
        $fallback = mb_substr(trim($excerpt), 0, 120);
        if ((int) ($task->auto_description ?? 1) !== 1) {
            return $fallback;
        }

        $prompt = $this->latestSpecialPromptContent($task, 'description');
        if ($prompt === '') {
            return $fallback;
        }

        try {
            $generated = $this->generateContent($aiModel, $this->renderSpecialPromptTemplate($prompt, $content, $title, $keyword));

            return $this->normalizeGeneratedDescription($generated, $fallback);
        } catch (Throwable) {
            return $fallback;
        }
    }

    private function latestSpecialPromptContent(Task $task, string $type): string
    {
        $siteId = (int) ($task->site_id ?? 0);

        if ($siteId > 0) {
            $sitePrompt = Prompt::withoutGlobalScope('current_site')
                ->select(['id', 'content'])
                ->where('type', $type)
                ->where('site_id', $siteId)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->first();

            if ($sitePrompt && trim((string) $sitePrompt->content) !== '') {
                return trim((string) $sitePrompt->content);
            }
        }

        $globalPrompt = Prompt::withoutGlobalScope('current_site')
            ->select(['id', 'content'])
            ->where('type', $type)
            ->where(function ($query): void {
                $query->whereNull('site_id')->orWhere('site_id', 0);
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        return $globalPrompt ? trim((string) $globalPrompt->content) : '';
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
    private function generateContent(AiModel $aiModel, string $contentPrompt): string
    {
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($aiModel->api_url ?? ''));
        if ($providerUrl === '') {
            throw new RuntimeException('AI 妯″瀷 API 鍦板潃涓虹┖');
        }

        $apiKey = $this->decryptApiKey((string) ($aiModel->getRawOriginal('api_key') ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('AI 妯″瀷瀵嗛挜涓虹┖');
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) ($aiModel->model_id ?? ''));
        $providerName = OpenAiRuntimeProvider::registerProvider('worker', $driver, $providerUrl, $apiKey);
        $agent = new MarkdownContentWriterAgent;

        try {
            $response = $agent->prompt($contentPrompt, [], $providerName, (string) ($aiModel->model_id ?? ''));
        } catch (Throwable $exception) {
            throw new RuntimeException('AI 鐢熸垚澶辫触: '.OpenAiRuntimeProvider::normalizeApiException($exception, $providerUrl), 0, $exception);
        }

        $rawContent = (string) ($response->text ?? '');
        $content = OpenAiRuntimeProvider::normalizeGeneratedText($rawContent);
        if ($content === '') {
            if (OpenAiRuntimeProvider::looksLikeSseCompletionPayload($rawContent)) {
                throw new RuntimeException('AI returned an empty streamed response. Please retry or check streaming compatibility.');
            }

            throw new RuntimeException('AI returned empty content');
        }

        AiModel::query()->whereKey((int) $aiModel->id)->update([
            'used_today' => DB::raw('COALESCE(used_today,0)+1'),
            'total_used' => DB::raw('COALESCE(total_used,0)+1'),
            'updated_at' => now(),
        ]);

        return $content;
    }

    /**
     * 浠庢鏂囨彁鍙栨憳瑕侊紝閬垮厤鎶婂畬鏁存彁绀鸿瘝鍘熸枃褰撴憳瑕併€?     */
    private function buildExcerpt(string $content): string
    {
        $plain = preg_replace('/[`#>*_\-\[\]\(\)]/u', ' ', $content) ?: $content;
        $plain = preg_replace('/\s+/u', ' ', $plain) ?: $plain;
        $plain = trim($plain);
        if ($plain === '') {
            return 'AI 鐢熸垚鍐呭鎽樿';
        }

        return mb_substr($plain, 0, 180);
    }

    /**
     * 鍏煎 enc:v1 鍘嗗彶鏍煎紡瑙ｅ瘑 API Key銆?     */
    private function decryptApiKey(string $storedApiKey): string
    {
        return $this->apiKeyCrypto->decrypt($storedApiKey);
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
    private function fetchKnowledgeChunksByPgvector(int $knowledgeBaseId, string $query, int $candidateLimit): array
    {
        if (! $this->canUsePgvectorSearch()) {
            return [];
        }

        $vectorLiteral = $this->knowledgeChunkSyncService->generateQueryVectorLiteral($query);
        if ($vectorLiteral === '') {
            return [];
        }

        $rows = DB::select(
            '
                SELECT chunk_index, content,
                       (embedding_vector <=> CAST(? AS vector)) AS vector_distance
                FROM knowledge_chunks
                WHERE knowledge_base_id = ?
                  AND embedding_vector IS NOT NULL
                ORDER BY embedding_vector <=> CAST(? AS vector), chunk_index ASC
                LIMIT ?
            ',
            [$vectorLiteral, $knowledgeBaseId, $vectorLiteral, max(1, $candidateLimit)]
        );

        $results = [];
        foreach ($rows as $row) {
            $content = trim((string) ($row->content ?? ''));
            if ($content === '') {
                continue;
            }
            $distance = (float) ($row->vector_distance ?? 1.0);
            $results[] = [
                'chunk_index' => (int) ($row->chunk_index ?? 0),
                'content' => $content,
                'score' => 1.0 - $distance,
            ];
        }

        return $results;
    }

    /**
     * 浠呭湪 PostgreSQL 涓?pgvector 鍙敤鏃跺惎鐢ㄥ悜閲忔绱€?     */
    private function canUsePgvectorSearch(): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        try {
            $typeRow = DB::selectOne("
                SELECT EXISTS (
                    SELECT 1 FROM pg_type WHERE typname = 'vector'
                ) AS ok
            ");
            if (! $typeRow || ! (bool) ($typeRow->ok ?? false)) {
                return false;
            }

            $columnRow = DB::selectOne("
                SELECT EXISTS (
                    SELECT 1
                    FROM information_schema.columns
                    WHERE table_name = 'knowledge_chunks'
                      AND column_name = 'embedding_vector'
                ) AS ok
            ");

            return $columnRow !== null && (bool) ($columnRow->ok ?? false);
        } catch (Throwable) {
            return false;
        }
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
