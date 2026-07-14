<?php

namespace App\Services\GeoFlow;

use App\Models\AiModel;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Throwable;

use function Laravel\Ai\agent;

/**
 * 标题 AI 生成服务。
 *
 * 该服务负责：
 * 1. 基于 ai_models 配置发起真实模型调用；
 * 2. 在模型不可用时使用模板兜底，保证流程可用性；
 * 3. 输出统一结构，便于控制器处理入库逻辑。
 */
class TitleAiGenerationService
{
    /**
     * 复用统一 API Key 解密组件，避免标题生成链路与其他 AI 链路出现差异。
     */
    public function __construct(private readonly ApiKeyCrypto $apiKeyCrypto) {}

    /**
     * 生成标题列表。
     *
     * @param  list<string>  $keywords
     * @return array{
     *   entries:list<array{keyword:string,title:string}>,
     *   fallback_used:bool,
     *   fallback_reason:?string
     * }
     */
    public function generateTitles(
        AiModel $aiModel,
        array $keywords,
        int $count,
        string $style,
        string $customPrompt = ''
    ): array {
        $assignments = $this->allocateKeywords($keywords, $count);
        if ($assignments === []) {
            return [
                'entries' => [],
                'fallback_used' => true,
                'fallback_reason' => 'no_keywords',
            ];
        }

        $entries = [];
        $fallbackReason = null;

        try {
            $content = $this->requestTitlesFromModel($aiModel, $assignments, $style, $customPrompt);
            $entries = $this->parseGeneratedEntries($content, $assignments);

            $missingAssignments = array_diff_key($assignments, $entries);
            if ($missingAssignments !== []) {
                try {
                    $retryContent = $this->requestTitlesFromModel($aiModel, $missingAssignments, $style, $customPrompt);
                    $entries += $this->parseGeneratedEntries($retryContent, $missingAssignments);
                } catch (Throwable $exception) {
                    $fallbackReason = $exception->getMessage();
                }
            }
        } catch (Throwable $exception) {
            $fallbackReason = $exception->getMessage();
        }

        $fallbackUsed = count($entries) !== count($assignments);
        foreach (array_diff_key($assignments, $entries) as $index => $keyword) {
            $entries[$index] = $this->generateFallbackEntry($keyword, $style, $index);
        }
        ksort($entries);

        return [
            'entries' => array_values($entries),
            'fallback_used' => $fallbackUsed,
            'fallback_reason' => $fallbackUsed ? ($fallbackReason ?: 'invalid_keyword_mapping') : null,
        ];
    }

    /**
     * 请求真实模型生成标题。
     *
     * @param  array<int,string>  $assignments
     */
    private function requestTitlesFromModel(
        AiModel $aiModel,
        array $assignments,
        string $style,
        string $customPrompt
    ): string {
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($aiModel->api_url ?? ''));
        if ($providerUrl === '') {
            throw new \RuntimeException('ai_url_missing');
        }

        $apiKey = $this->decryptApiKey((string) ($aiModel->getRawOriginal('api_key') ?? ''));
        if ($apiKey === '') {
            throw new \RuntimeException('ai_key_missing');
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) ($aiModel->model_id ?? ''));
        $providerName = OpenAiRuntimeProvider::registerProvider('title_ai', $driver, $providerUrl, $apiKey);

        $styleMap = [
            'professional' => '专业严谨的',
            'attractive' => '吸引眼球的',
            'seo' => 'SEO优化的',
            'creative' => '创意新颖的',
            'question' => '疑问式的',
        ];
        $styleDescription = $styleMap[$style] ?? '专业严谨的';
        $tasks = collect(array_values($assignments))
            ->map(static fn (string $keyword, int $index): string => ($index + 1).'. '.$keyword)
            ->implode("\n");
        $count = count($assignments);

        $systemPrompt = "你是一个专业的内容标题生成专家。请为每个指定关键词生成一个{$styleDescription}文章标题，并保持关键词与标题严格对应。";
        $userPrompt = "请按任务顺序生成 {$count} 个{$styleDescription}文章标题：\n\n{$tasks}\n\n";
        if ($customPrompt !== '') {
            $userPrompt .= "额外要求：{$customPrompt}\n\n";
        }
        $userPrompt .= "要求：\n1. 每个任务只生成一个标题\n2. 标题必须完整包含对应关键词原文，不得替换、缩写或关联到其他关键词\n3. 标题要有吸引力、可读性并适合搜索引擎优化\n4. 严格按照任务顺序逐行输出\n5. 每行格式固定为：关键词|||标题\n6. 不要输出序号、解释、Markdown或其他内容";

        try {
            $response = agent($systemPrompt)->prompt(
                $userPrompt,
                [],
                $providerName,
                (string) ($aiModel->model_id ?? '')
            );
        } catch (Throwable $exception) {
            throw new \RuntimeException(OpenAiRuntimeProvider::normalizeApiException($exception, $providerUrl), 0, $exception);
        }

        $rawContent = (string) ($response->text ?? '');
        $content = OpenAiRuntimeProvider::normalizeGeneratedText($rawContent);

        if ($content === '') {
            if (OpenAiRuntimeProvider::looksLikeSseCompletionPayload($rawContent)) {
                throw new \RuntimeException('ai_empty_stream_content');
            }

            throw new \RuntimeException('ai_empty_content');
        }

        return $content;
    }

    /**
     * 解析模型输出，并按预先分配的关键词恢复一一对应关系。
     *
     * @param  array<int,string>  $assignments
     * @return array<int,array{keyword:string,title:string}>
     */
    private function parseGeneratedEntries(string $content, array $assignments): array
    {
        /** @var array<string,list<string>> $titlesByKeyword */
        $titlesByKeyword = [];
        foreach (preg_split('/\R/u', $content) ?: [] as $line) {
            $line = preg_replace('/^\d+[\.\)\-、\s]*/u', '', trim($line));
            $parts = preg_split('/\s*(?:\|\|\||｜｜｜)\s*/u', trim((string) $line), 2);
            if (! is_array($parts) || count($parts) !== 2) {
                continue;
            }

            $keyword = trim((string) $parts[0]);
            $title = trim((string) $parts[1]);
            if ($keyword === '' || $title === '' || ! in_array($keyword, $assignments, true)) {
                continue;
            }
            if (mb_stripos($title, $keyword, 0, 'UTF-8') === false) {
                continue;
            }

            $titlesByKeyword[$keyword] ??= [];
            if (! in_array($title, $titlesByKeyword[$keyword], true)) {
                $titlesByKeyword[$keyword][] = $title;
            }
        }

        $entries = [];
        foreach ($assignments as $index => $keyword) {
            if (($titlesByKeyword[$keyword] ?? []) === []) {
                continue;
            }
            $title = array_shift($titlesByKeyword[$keyword]);
            if (! is_string($title) || $title === '') {
                continue;
            }
            $entries[$index] = [
                'keyword' => $keyword,
                'title' => $title,
            ];
        }

        return $entries;
    }

    /**
     * 解密 ai_models 中的 API Key（兼容旧系统 enc:v1 格式）。
     */
    private function decryptApiKey(string $storedApiKey): string
    {
        return $this->apiKeyCrypto->decrypt($storedApiKey);
    }

    /**
     * @param  list<string>  $keywords
     * @return array<int,string>
     */
    private function allocateKeywords(array $keywords, int $count): array
    {
        $keywords = array_values(array_unique(array_filter(array_map(
            static fn (mixed $keyword): string => trim((string) $keyword),
            $keywords
        ), static fn (string $keyword): bool => $keyword !== '')));
        if ($keywords === [] || $count < 1) {
            return [];
        }

        $assignments = [];
        for ($index = 0; $index < $count; $index++) {
            $assignments[$index] = $keywords[$index % count($keywords)];
        }

        return $assignments;
    }

    /**
     * @return array{keyword:string,title:string}
     */
    private function generateFallbackEntry(string $keyword, string $style, int $index): array
    {
        $styleTemplates = [
            'professional' => [
                '{keyword}的深度分析与研究',
                '关于{keyword}的专业见解',
                '{keyword}行业发展趋势报告',
            ],
            'attractive' => [
                '你绝对不知道的{keyword}秘密',
                '揭秘{keyword}背后的故事',
                '{keyword}让人意想不到的用途',
            ],
            'seo' => [
                '{keyword}完整指南：从入门到精通',
                '{keyword}常见问题解答大全',
                '如何选择最适合的{keyword}方案',
            ],
            'creative' => [
                '重新定义{keyword}的可能性',
                '如果{keyword}会说话，它会告诉你什么？',
                '当{keyword}遇上创新思维',
            ],
            'question' => [
                '{keyword}真的有用吗？',
                '为什么{keyword}如此重要？',
                '{keyword}的未来在哪里？',
            ],
        ];

        $templates = $styleTemplates[$style] ?? $styleTemplates['professional'];

        return [
            'keyword' => $keyword,
            'title' => str_replace('{keyword}', $keyword, $templates[$index % count($templates)]),
        ];
    }
}
