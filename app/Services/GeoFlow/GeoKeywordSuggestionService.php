<?php

namespace App\Services\GeoFlow;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\AiModel;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use RuntimeException;
use Throwable;

class GeoKeywordSuggestionService
{
    public function __construct(private readonly ApiKeyCrypto $apiKeyCrypto) {}

    /**
     * @return list<string>
     */
    public function suggest(string $seedKeyword, int $count): array
    {
        $seedKeyword = $this->normalizeKeyword($seedKeyword);
        if ($seedKeyword === '') {
            throw new RuntimeException('请输入种子关键词');
        }

        $count = max(1, min(100, $count));
        $model = $this->resolveAiModel();
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($model->api_url ?? ''));
        if ($providerUrl === '') {
            throw new RuntimeException('AI 模型 API URL 未配置');
        }

        $apiKey = $this->apiKeyCrypto->decrypt((string) ($model->getRawOriginal('api_key') ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('AI 模型 API Key 未配置或无法解密');
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) ($model->model_id ?? ''));
        $providerName = OpenAiRuntimeProvider::registerProvider('keyword_suggestion', $driver, $providerUrl, $apiKey);

        try {
            $agent = new MarkdownContentWriterAgent('你是 GEO 关键词策略专家，只输出可解析的关键词结果。');
            $content = $agent->prompt($this->buildPrompt($seedKeyword, $count), [], $providerName, (string) ($model->model_id ?? ''));
            $suggestions = $this->parseSuggestions(OpenAiRuntimeProvider::normalizeGeneratedText((string) $content), $count);
        } catch (Throwable $exception) {
            throw new RuntimeException('AI 关键词生成失败: '.OpenAiRuntimeProvider::normalizeApiException($exception, $providerUrl), 0, $exception);
        }

        if ($suggestions === []) {
            throw new RuntimeException('AI 未返回可用关键词，请调整种子词后重试');
        }

        return $suggestions;
    }

    /**
     * Exposes parser behavior to unit tests without making AI calls.
     *
     * @return list<string>
     */
    public function parseSuggestionsForTesting(string $output, int $limit): array
    {
        return $this->parseSuggestions($output, $limit);
    }

    /**
     * @return list<string>
     */
    private function parseSuggestions(string $output, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $decoded = json_decode(trim($output), true);
        $rawSuggestions = [];

        if (is_array($decoded)) {
            $rawSuggestions = $this->flattenJsonSuggestions($decoded);
        }

        if ($rawSuggestions === []) {
            $rawSuggestions = preg_split('/\R|,|，|;|；/u', $output) ?: [];
        }

        $seen = [];
        $suggestions = [];
        foreach ($rawSuggestions as $rawSuggestion) {
            $keyword = $this->normalizeKeyword((string) $rawSuggestion);
            if ($keyword === '') {
                continue;
            }

            $dedupeKey = mb_strtolower($keyword, 'UTF-8');
            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;
            $suggestions[] = $keyword;
            if (count($suggestions) >= $limit) {
                break;
            }
        }

        return $suggestions;
    }

    private function resolveAiModel(): AiModel
    {
        $model = AiModel::query()
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->orderBy('failover_priority')
            ->orderByDesc('id')
            ->first();

        if (! $model) {
            throw new RuntimeException('请先在 AI 配置中添加并启用 Chat 模型');
        }

        return $model;
    }

    private function buildPrompt(string $seedKeyword, int $count): string
    {
        return <<<PROMPT
请围绕种子关键词“{$seedKeyword}”生成 {$count} 个适合 GEO（生成式引擎优化）内容抓取和引用的相关关键词。

要求：
1. 覆盖核心主题词、长尾问题词、对比/评测词、场景词、品牌/行业组合词、用户意图词。
2. 关键词要适合用于文章选题、知识库召回、AI 搜索引用和内容分发。
3. 不要输出解释、编号、Markdown 或多余文案。
4. 只输出 JSON 字符串数组，例如 ["关键词A","关键词B"]。
PROMPT;
    }

    /**
     * @param  array<mixed>  $decoded
     * @return list<string>
     */
    private function flattenJsonSuggestions(array $decoded): array
    {
        $suggestions = [];
        foreach ($decoded as $item) {
            if (is_string($item) || is_numeric($item)) {
                $suggestions[] = (string) $item;
                continue;
            }

            if (is_array($item)) {
                foreach (['keyword', 'term', 'name', 'text'] as $key) {
                    if (isset($item[$key]) && (is_string($item[$key]) || is_numeric($item[$key]))) {
                        $suggestions[] = (string) $item[$key];
                        break;
                    }
                }
            }
        }

        return $suggestions;
    }

    private function normalizeKeyword(string $keyword): string
    {
        $keyword = trim($keyword);
        $keyword = preg_replace('/^\s*(?:[-*•]+|\d+[.)、])\s*/u', '', $keyword) ?? $keyword;
        $keyword = preg_replace('/^[\s"\'`“”‘’\[\]【】()（）<>《》:：]+|[\s"\'`“”‘’\[\]【】()（）<>《》:：]+$/u', '', $keyword) ?? $keyword;
        $keyword = preg_replace('/\s+/u', ' ', $keyword) ?? $keyword;

        if (mb_strlen($keyword, 'UTF-8') > 200) {
            return '';
        }

        return $keyword;
    }
}
