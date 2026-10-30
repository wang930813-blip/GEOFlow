<?php

namespace App\Services\GeoFlow;

use App\Data\Ai\AiExecutionContext;
use App\Models\AiModel;
use App\Models\PlatformPlan;
use App\Models\Task;
use App\Services\Billing\AdminResourceQuotaService;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use RuntimeException;
use Throwable;

class AiGeneratedArticleImageService
{
    public function __construct(
        private readonly AiArticleImagePlanner $planner,
        private readonly ExternalImageHostClient $imageHostClient,
        private readonly AdminResourceQuotaService $quotaService,
        private readonly WorkerAiModelInvocationGateway $invocationGateway,
    ) {}

    /**
     * @return array{content:string,blocks:list<array<string,mixed>>}
     */
    public function generateAndInsert(
        Task $task,
        AiModel $chatModel,
        string $content,
        string $title,
        string $keyword,
        AiExecutionContext|int $executionIdentity,
    ): array
    {
        $taskId = (int) $task->getKey();
        if (($executionIdentity instanceof AiExecutionContext && $executionIdentity->sourceId !== $taskId)
            || (is_int($executionIdentity) && $executionIdentity !== $taskId)) {
            throw new RuntimeException('AI 图片生成任务身份无效');
        }

        $imageCount = max(0, min(5, (int) ($task->image_count ?? 0)));
        if ($imageCount <= 0) {
            return ['content' => $content, 'blocks' => []];
        }
        $siteId = (int) ($task->site_id ?? 0);
        $quotaAdminId = $this->quotaAdminId($task);
        if ($siteId > 0 && $quotaAdminId !== null) {
            $this->quotaService->assertCanUse($quotaAdminId, $siteId, PlatformPlan::RESOURCE_AI_IMAGE_GENERATIONS, $imageCount);
        }

        $imageModelId = (int) ($task->ai_image_model_id ?? 0);
        if ($imageModelId <= 0) {
            throw new RuntimeException('任务未配置 AI 图片模型');
        }

        $plannedBlocks = $this->planImages($executionIdentity, $chatModel, $content, $title, $keyword, $imageCount);
        $generatedBlocks = [];

        foreach (array_slice($plannedBlocks, 0, $imageCount) as $index => $plan) {
            $uploaded = $this->generateOne($executionIdentity, $imageModelId, (string) ($plan['prompt'] ?? ''), $index + 1);
            if ($siteId > 0 && $quotaAdminId !== null) {
                $this->quotaService->consume($quotaAdminId, $siteId, PlatformPlan::RESOURCE_AI_IMAGE_GENERATIONS, 1, [
                    'subject_type' => Task::class,
                    'subject_id' => (int) $task->id,
                    'idempotency_key' => 'ai-image:'.$task->id.':'.($uploaded['key'] ?? $index + 1),
                    'remark' => 'AI 配图生成消耗',
                ]);
            }
            $generatedBlocks[] = [
                'paragraph_after' => (int) ($plan['paragraph_after'] ?? 1),
                'alt' => (string) ($plan['alt'] ?? $title),
                'prompt' => (string) ($plan['prompt'] ?? ''),
                'url' => $uploaded['url'],
                'key' => $uploaded['key'],
            ];
        }

        return [
            'content' => $this->planner->insertGeneratedImages($content, $generatedBlocks),
            'blocks' => $generatedBlocks,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function planImages(
        AiExecutionContext|int $executionIdentity,
        AiModel $chatModel,
        string $content,
        string $title,
        string $keyword,
        int $count,
    ): array
    {
        try {
            $prompt = $this->buildPlanningPrompt($content, $title, $keyword, $count);
            $raw = $this->invocationGateway->generate(
                $executionIdentity,
                $chatModel,
                $prompt,
                static fn (array $invocation): string => (string) ($invocation['response']->text ?? ''),
                'article.image.plan',
                'worker_article_image_plan',
            );
            $planned = $this->parsePlan((string) $raw);
            if ($planned !== []) {
                return $planned;
            }
        } catch (Throwable) {
            // Fall back to deterministic placement so article generation can continue.
        }

        return $this->planner->fallbackPlan($content, $count, $title, $keyword);
    }

    private function buildPlanningPrompt(string $content, string $title, string $keyword, int $count): string
    {
        return implode("\n\n", [
            'Plan '.$count.' article images for this Markdown article.',
            'Return JSON only in this shape: {"images":[{"paragraph_after":1,"alt":"short alt","prompt":"image generation prompt"}]}',
            'Rules: paragraph_after is 1-based, choose positions that match the article meaning, prompts must ask for no text in the image.',
            'Title: '.$title,
            'Keyword: '.$keyword,
            'Article Markdown:',
            mb_substr($content, 0, 6000, 'UTF-8'),
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function parsePlan(string $raw): array
    {
        $text = trim(OpenAiRuntimeProvider::normalizeGeneratedText($raw));
        if ($text === '') {
            return [];
        }

        if (preg_match('/```(?:json)?\s*(.*?)```/is', $text, $matches) === 1) {
            $text = trim((string) $matches[1]);
        } elseif (preg_match('/\{.*\}/s', $text, $matches) === 1) {
            $text = (string) $matches[0];
        }

        $decoded = json_decode($text, true);
        if (! is_array($decoded) || ! is_array($decoded['images'] ?? null)) {
            return [];
        }

        $plans = [];
        foreach ($decoded['images'] as $image) {
            if (! is_array($image)) {
                continue;
            }
            $prompt = trim((string) ($image['prompt'] ?? ''));
            if ($prompt === '') {
                continue;
            }
            $plans[] = [
                'paragraph_after' => (int) ($image['paragraph_after'] ?? 1),
                'alt' => trim((string) ($image['alt'] ?? '')),
                'prompt' => $prompt,
            ];
        }

        return $plans;
    }

    /**
     * @return array{key:string,url:string,size:int,mime_type:string}
     */
    private function generateOne(AiExecutionContext|int $executionIdentity, int $imageModelId, string $prompt, int $index): array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new RuntimeException('AI图片提示词为空');
        }

        try {
            $response = $this->invocationGateway->generateImage($executionIdentity, $imageModelId, $prompt);
        } catch (Throwable $exception) {
            throw new RuntimeException('AI 图片生成失败: '.$exception->getMessage(), 0, $exception);
        }

        return $this->imageHostClient->upload(
            $response['content'],
            $response['mime'],
            $this->filenameFor($response['mime'], $index),
        );
    }

    private function quotaAdminId(Task $task): ?int
    {
        $adminId = (int) ($task->owner_admin_id ?? 0);

        return $adminId > 0 ? $adminId : null;
    }

    private function filenameFor(string $mime, int $index): string
    {
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'png',
        };

        return 'article-ai-image-'.$index.'.'.$extension;
    }

}
