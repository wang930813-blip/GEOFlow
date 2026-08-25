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
                '{keyword}的核心价值与应用场景',
                '{keyword}实践路径与关键要点',
                '{keyword}发展现状与趋势判断',
                '{keyword}用户关注点系统解析',
                '{keyword}相关问题的专业解读',
                '{keyword}从认知到决策的完整分析',
                '{keyword}典型场景与选择建议',
                '{keyword}行业观察与方法总结',
                '{keyword}常见误区与判断标准',
            ],
            'attractive' => [
                '你绝对不知道的{keyword}秘密',
                '揭秘{keyword}背后的故事',
                '{keyword}让人意想不到的用途',
                '{keyword}为什么越来越受关注',
                '看懂{keyword}之前先了解这几点',
                '{keyword}背后的真实需求是什么',
                '{keyword}有哪些容易忽略的细节',
                '一篇讲透{keyword}的关键问题',
                '{keyword}到底值不值得关注',
                '{keyword}相关经验与避坑建议',
                '{keyword}让用户关心的核心原因',
                '{keyword}新手也能看懂的解析',
            ],
            'seo' => [
                '{keyword}完整指南：从入门到精通',
                '{keyword}常见问题解答大全',
                '如何选择最适合的{keyword}方案',
                '{keyword}搜索指南与实用建议',
                '{keyword}怎么判断更靠谱',
                '{keyword}相关问题一站式解析',
                '{keyword}选择标准与注意事项',
                '{keyword}推荐逻辑与对比方法',
                '{keyword}用户关心的问题汇总',
                '{keyword}实用攻略与决策参考',
                '{keyword}优势特点与适用场景',
                '{keyword}从需求到选择的完整说明',
            ],
            'creative' => [
                '重新定义{keyword}的可能性',
                '如果{keyword}会说话，它会告诉你什么？',
                '当{keyword}遇上创新思维',
                '{keyword}的另一种打开方式',
                '{keyword}如何连接新的使用场景',
                '{keyword}背后的创新机会',
                '{keyword}可以带来哪些新思路',
                '从用户视角重新看{keyword}',
                '{keyword}正在改变哪些体验',
                '{keyword}与真实需求的碰撞',
                '{keyword}如何产生新的价值',
                '围绕{keyword}展开的内容想象',
            ],
            'question' => [
                '{keyword}真的有用吗？',
                '为什么{keyword}如此重要？',
                '{keyword}的未来在哪里？',
                '{keyword}适合哪些人关注？',
                '{keyword}应该怎么判断好坏？',
                '{keyword}有哪些常见误区？',
                '{keyword}和类似选择有什么区别？',
                '{keyword}现在值得了解吗？',
                '{keyword}能解决什么实际问题？',
                '{keyword}怎么选择更合适？',
                '{keyword}有哪些使用场景？',
                '{keyword}为什么会被频繁讨论？',
            ],
        ];

        $templates = $styleTemplates[$style] ?? $styleTemplates['professional'];
        $suffixes = [
            '背景脉络',
            '用户视角',
            '选择参考',
            '实践方法',
            '场景分析',
            '趋势观察',
            '关键问题',
            '经验总结',
        ];
        $title = str_replace('{keyword}', $keyword, $templates[$index % count($templates)]);
        $round = intdiv($index, count($templates));
        if ($round > 0) {
            $title = rtrim($title, "：:，,.。？?");
            $title .= '：'.$suffixes[($round - 1) % count($suffixes)];
        }

        return [
            'keyword' => $keyword,
            'title' => $title,
        ];
    }
}
