<?php

namespace App\Services\GeoFlow;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\AiModel;
use App\Models\PlatformPlan;
use App\Models\Task;
use App\Services\Billing\AdminResourceQuotaService;
use App\Support\AiConfigurationScope;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Image as AiImage;
use RuntimeException;
use Throwable;

class AiGeneratedArticleImageService
{
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly AiArticleImagePlanner $planner,
        private readonly ExternalImageHostClient $imageHostClient,
        private readonly AdminResourceQuotaService $quotaService,
        private readonly AiConfigurationScope $aiConfigurationScope,
    ) {}

    /**
     * @return array{content:string,blocks:list<array<string,mixed>>}
     */
    public function generateAndInsert(Task $task, AiModel $chatModel, string $content, string $title, string $keyword): array
    {
        $imageCount = max(0, min(5, (int) ($task->image_count ?? 0)));
        if ($imageCount <= 0) {
            return ['content' => $content, 'blocks' => []];
        }
        $siteId = (int) ($task->site_id ?? 0);
        $quotaAdminId = $this->quotaAdminId($task);
        if ($siteId > 0 && $quotaAdminId !== null) {
            $this->quotaService->assertCanUse($quotaAdminId, $siteId, PlatformPlan::RESOURCE_AI_IMAGE_GENERATIONS, $imageCount);
        }

        $imageModel = $this->resolveImageModel($task);
        $plannedBlocks = $this->planImages($chatModel, $content, $title, $keyword, $imageCount);
        $generatedBlocks = [];

        foreach (array_slice($plannedBlocks, 0, $imageCount) as $index => $plan) {
            $uploaded = $this->generateOne($imageModel, (string) ($plan['prompt'] ?? ''), $index + 1);
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
    private function planImages(AiModel $chatModel, string $content, string $title, string $keyword, int $count): array
    {
        try {
            $prompt = $this->buildPlanningPrompt($content, $title, $keyword, $count);
            $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($chatModel->api_url ?? ''));
            $apiKey = $this->apiKeyCrypto->decrypt((string) ($chatModel->getRawOriginal('api_key') ?? ''));
            if ($providerUrl === '' || $apiKey === '') {
                throw new RuntimeException('chat model unavailable for image planning');
            }

            $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) ($chatModel->model_id ?? ''));
            $providerName = OpenAiRuntimeProvider::registerProvider('image_planner', $driver, $providerUrl, $apiKey);
            $agent = new MarkdownContentWriterAgent('You plan article images. Output only strict JSON.');
            $response = $agent->prompt($prompt, [], $providerName, (string) ($chatModel->model_id ?? ''));
            $planned = $this->parsePlan((string) ($response->text ?? ''));
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
    private function generateOne(AiModel $imageModel, string $prompt, int $index): array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new RuntimeException('AI图片提示词为空');
        }

        $providerUrl = OpenAiRuntimeProvider::resolveImageBaseUrl((string) ($imageModel->api_url ?? ''));
        $apiKey = $this->apiKeyCrypto->decrypt((string) ($imageModel->getRawOriginal('api_key') ?? ''));
        if ($providerUrl === '' || $apiKey === '') {
            throw new RuntimeException('AI图片模型未配置 API 地址或密钥');
        }

        $driver = OpenAiRuntimeProvider::resolveImageDriver($providerUrl, (string) ($imageModel->model_id ?? ''));
        $providerName = OpenAiRuntimeProvider::registerProvider('image', $driver, $providerUrl, $apiKey);

        try {
            $response = AiImage::of($prompt)
                ->landscape()
                ->quality('medium')
                ->timeout(120)
                ->generate($providerName, (string) ($imageModel->model_id ?? ''));
        } catch (Throwable $exception) {
            throw new RuntimeException('AI图片生成失败: '.OpenAiRuntimeProvider::normalizeApiException($exception, $providerUrl), 0, $exception);
        }

        $image = $response->firstImage();
        $mime = $image->mime ?: 'image/png';
        $uploaded = $this->imageHostClient->upload($image->content(), $mime, $this->filenameFor($mime, $index));

        AiModel::query()->withoutGlobalScope('current_site')->whereKey((int) $imageModel->id)->update([
            'used_today' => DB::raw('COALESCE(used_today,0)+1'),
            'total_used' => DB::raw('COALESCE(total_used,0)+1'),
            'updated_at' => now(),
        ]);

        return $uploaded;
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

    private function resolveImageModel(Task $task): AiModel
    {
        $modelId = (int) ($task->ai_image_model_id ?? 0);
        if ($modelId <= 0) {
            throw new RuntimeException('任务未配置AI图片模型');
        }

        $model = $this->aiConfigurationScope->applyOwnerIdScope(
            AiModel::query()->withoutGlobalScope('current_site'),
            $this->aiConfigOwnerIdForTask($task),
            'ai_models.owner_admin_id'
        )->whereKey($modelId)
            ->where('status', 'active')
            ->where('model_type', 'image')
            ->first();

        if (! $model) {
            throw new RuntimeException('任务AI图片模型不可用');
        }

        return $model;
    }

    private function aiConfigOwnerIdForTask(Task $task): ?int
    {
        $adminId = (int) ($task->owner_admin_id ?? 0);
        if ($adminId <= 0) {
            return null;
        }

        $admin = \App\Models\Admin::query()->whereKey($adminId)->first(['id', 'role', 'created_by']);

        return $admin instanceof \App\Models\Admin ? $this->aiConfigurationScope->ownerAdminIdForConsumer($admin) : null;
    }
}
