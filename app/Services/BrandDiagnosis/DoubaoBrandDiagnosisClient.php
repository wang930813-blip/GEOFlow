<?php

namespace App\Services\BrandDiagnosis;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DoubaoBrandDiagnosisClient
{
    /**
     * @return list<array{question:string,type:string}>
     */
    public function generateQuestions(string $brandName, int $count): array
    {
        return $this->generateCandidateQuestions($brandName, $count, 'doubao');
    }

    /**
     * @param  list<string>  $platforms
     * @return list<array{question:string,type:string}>
     */
    public function generateQuestionPool(string $brandName, int $count, array $platforms): array
    {
        $brandName = trim($brandName);
        $count = max(1, $count);
        $platforms = collect($platforms)
            ->map(fn (mixed $platform): string => $this->normalizePlatform((string) $platform))
            ->unique()
            ->values()
            ->all();
        if ($platforms === []) {
            $platforms = ['doubao'];
        }

        $candidates = [];
        foreach ($platforms as $platform) {
            foreach ($this->generateCandidateQuestions($brandName, $count, $platform) as $question) {
                $candidates[] = [
                    'question' => $question['question'],
                    'type' => $question['type'],
                    'platform' => $platform,
                ];
            }
        }

        $candidates = collect($candidates)
            ->filter(static fn (array $item): bool => trim((string) $item['question']) !== '')
            ->unique(static fn (array $item): string => mb_strtolower(trim((string) $item['question']), 'UTF-8'))
            ->values()
            ->all();

        if (count($candidates) < $count) {
            throw new RuntimeException('品牌诊断问题候选不足，请稍后重试。');
        }

        $selectionPlatform = $platforms[0] ?? 'doubao';
        $response = $this->postResponses($this->buildQuestionSelectionPrompt($brandName, $count, $candidates), $selectionPlatform);
        $questions = $this->parseQuestions($this->extractText($response));

        if (count($questions) < $count) {
            throw new RuntimeException('品牌诊断问题精选不足，请稍后重试。');
        }

        return array_slice($questions, 0, $count);
    }

    public function ask(string $brandName, string $question, string $platform = 'doubao'): BrandDiagnosisAiResponse
    {
        $platform = $this->normalizePlatform($platform);
        $data = $this->postResponses($this->buildAnswerPrompt($brandName, $question), $platform);
        $parsed = $this->parseAnswerPayload($this->extractText($data));

        if ($parsed['answer'] === '') {
            throw new RuntimeException($this->platformLabel($platform).'品牌诊断返回为空。');
        }

        return new BrandDiagnosisAiResponse(
            answer: $parsed['answer'],
            sources: $this->extractSources($data),
            rawResponse: $data,
            meta: [
                'platform' => $platform,
                'response_id' => (string) ($data['id'] ?? ''),
                'usage' => Arr::get($data, 'usage', []),
            ],
            brandMentions: $parsed['brand_mentions'],
        );
    }

    /**
     * @return list<array{question:string,type:string}>
     */
    private function generateCandidateQuestions(string $brandName, int $count, string $platform): array
    {
        $brandName = trim($brandName);
        $count = max(1, $count);
        $platform = $this->normalizePlatform($platform);

        $response = $this->postResponses($this->buildQuestionPrompt($brandName, $count), $platform);
        $questions = $this->parseQuestions($this->extractText($response));

        if (count($questions) < $count) {
            throw new RuntimeException($this->platformLabel($platform).'品牌诊断问题生成不足，请稍后重试。');
        }

        return array_slice($questions, 0, $count);
    }

    /**
     * @return array<string,mixed>
     */
    private function postResponses(string $prompt, string $platform): array
    {
        $platform = $this->normalizePlatform($platform);
        $label = $this->platformLabel($platform);
        $baseUrl = (string) config('brand_diagnosis.'.$platform.'.base_url', '');
        $apiKey = (string) config('brand_diagnosis.'.$platform.'.api_key', '');
        $model = (string) config('brand_diagnosis.'.$platform.'.model', '');
        if ($platform === 'deepseek') {
            $baseUrl = $baseUrl !== '' ? $baseUrl : (string) config('brand_diagnosis.doubao.base_url', '');
            $apiKey = $apiKey !== '' ? $apiKey : (string) config('brand_diagnosis.doubao.api_key', '');
        }

        if (! (bool) config('brand_diagnosis.'.$platform.'.enabled', false)) {
            throw new RuntimeException($label.'品牌诊断未启用。');
        }
        if ($baseUrl === '' || $apiKey === '' || $model === '') {
            throw new RuntimeException($label.'品牌诊断 API 配置不完整。');
        }

        $payload = [
            'model' => $model,
            'input' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $prompt,
                        ],
                    ],
                ],
            ],
            'tools' => [
                [
                    'type' => 'web_search',
                    'max_keyword' => max(1, (int) config('brand_diagnosis.'.$platform.'.max_keywords', 5)),
                ],
            ],
        ];

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(max(1, (int) config('brand_diagnosis.'.$platform.'.connect_timeout', 10)))
            ->timeout(max(10, (int) config('brand_diagnosis.'.$platform.'.timeout', 60)))
            ->retry(2, 500)
            ->post($baseUrl.'/responses', $payload);

        if ($response->failed()) {
            throw new RuntimeException($label.'品牌诊断请求失败：HTTP '.$response->status().' '.$response->body());
        }

        /** @var array<string,mixed> $data */
        $data = $response->json() ?: [];

        return $data;
    }

    private function buildQuestionPrompt(string $brandName, int $count): string
    {
        return implode("\n", [
            '请使用联网搜索，围绕目标品牌生成 '.$count.' 个用户会真实询问 AI 的品牌诊断问题。',
            '目标品牌：'.$brandName,
            '请先联网检索目标品牌，智能分析它可能对应的行业、业务类型、服务对象、地域、产品形态、竞品集合、常见应用场景等维度；不要把目标品牌强行归入固定行业。',
            '生成目标：问题必须帮助评估品牌在 AI 问答平台中的自然曝光、真实讨论、相关对象、市场认知和推荐倾向。',
            '问题风格要求：',
            '1. 用你分析出的行业、类型、服务对象、地域、应用场景等自然生成问题。',
            '2. 问题要像真实用户会问 AI 的认知、选择、对比、评价、位置、服务、作品、口碑或合作问题。',
            '3. 不要套用固定行业模板，不要默认生成某个特定领域的问题。',
            '约束：',
            '1. 不要生成“'.$brandName.' 是什么品牌”这种单一介绍题。',
            '2. 生成的问题尽量不要直接出现目标品牌名称，除非该问题必须围绕品牌自身认知、地址、资质、口碑或合作对象。',
            '3. 不要预设目标品牌是系统、平台、服务商、公司或门店；如果检索结果显示是个体户、个人品牌、产品、项目或机构，就按真实类型生成。',
            '4. 每个问题 12-36 个中文字符，适合直接拿去问 AI。',
            '5. 只输出 JSON 数组，不要 Markdown，不要解释。',
            'JSON 格式可以是：',
            '[{"question":"问题文本","type":"对比/选择"}]',
            '或：',
            '{"analysis":{"industry":"行业","type":"类型","scenario":"场景"},"questions":[{"question":"问题文本","type":"对比/选择"}]}',
        ]);
    }

    /**
     * @param  list<array{question:string,type:string,platform:string}>  $candidates
     */
    private function buildQuestionSelectionPrompt(string $brandName, int $count, array $candidates): string
    {
        return implode("\n", [
            '请从多个 AI 模型生成的候选问题中，精选 '.$count.' 个最适合品牌诊断的最终问题。',
            '目标品牌：'.$brandName,
            '候选问题 JSON：',
            json_encode($candidates, JSON_UNESCAPED_UNICODE) ?: '[]',
            '精选原则：',
            '1. 先根据目标品牌的真实行业、类型、地域、服务对象、业务范围、品牌形态判断问题是否合理。',
            '2. 优先保留不同维度的问题，避免 5 个问题都问同一件事。',
            '3. 不要选明显套用固定行业模板的问题；如果候选问题中出现与目标品牌无关的领域词，要剔除。',
            '4. 不要强行要求服务商对比、系统能力或行业服务选择；品牌是个体户、个人、产品、门店或项目时，按实际形态选择问题。',
            '5. 除非确有必要，最终问题不要直接出现目标品牌名称，以便检测 AI 是否自然提及该品牌。',
            '6. 只输出 JSON，不要 Markdown，不要解释。',
            'JSON 格式：',
            '{"questions":[{"question":"问题文本","type":"认知/选择/对比/口碑/合作/位置/其他"}]}',
        ]);
    }

    private function buildAnswerPrompt(string $brandName, string $question): string
    {
        return implode("\n", [
            '请使用联网搜索回答下面的品牌诊断问题。',
            '目标品牌：'.$brandName,
            '问题：'.$question,
            '要求：',
            '1. 回答必须基于可检索到的真实网页信息。',
            '2. 不要为了命中目标品牌而强行提及；如果自然答案里目标品牌不靠前或不适合推荐，请如实说明。',
            '3. 如果提到目标品牌，请说明它在答案中的位置、推荐层级、竞品关系和语义倾向。',
            '4. 如果答案、引用文章或问题中出现其他品牌、竞品、服务商，也必须抽取出来。',
            '5. brand_mentions 只允许抽取回答正文、引用网页标题、引用网页摘要或引用内容中真实出现的品牌；不要把“目标品牌”输入本身算作一次提及。',
            '6. 如果目标品牌没有在回答正文或引用来源中真实出现，不要把目标品牌放入 brand_mentions。',
            '7. 尽量保留你参考的网页引用来源。',
            '8. 只输出 JSON，不要 Markdown，不要解释，不要输出本提示词。',
            'JSON 格式：',
            '{"answer":"中文回答","brand_mentions":[{"brand":"品牌名","mention_count":1,"mention_rank":1,"sentiment":"positive|neutral|negative","evidence":"提及依据"}]}',
            '字段口径：mention_count 是该品牌在本次回答中被主动提及/推荐的次数；mention_rank 是它在本次回答或推荐列表中的顺位，没有顺位填 0；sentiment 只能是 positive、neutral、negative。',
        ]);
    }

    /**
     * @return array{answer:string,brand_mentions:list<array{brand:string,mention_count:int,mention_rank:int,sentiment:string,evidence:string,source_count?:int,meta?:array<string,mixed>}>}
     */
    private function parseAnswerPayload(string $text): array
    {
        $json = trim($text);
        if (preg_match('/```(?:json)?\s*(.*?)```/is', $json, $matches)) {
            $json = trim((string) $matches[1]);
        }

        /** @var mixed $decoded */
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return [
                'answer' => trim($text),
                'brand_mentions' => [],
            ];
        }

        $answer = trim((string) ($decoded['answer'] ?? ''));
        if ($answer === '') {
            $answer = trim((string) Arr::get($decoded, 'content', ''));
        }

        $mentions = collect((array) ($decoded['brand_mentions'] ?? []))
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->map(function (array $item): array {
                $brand = trim((string) ($item['brand'] ?? $item['brand_name'] ?? ''));

                return [
                    'brand' => $brand,
                    'mention_count' => max(1, (int) ($item['mention_count'] ?? 1)),
                    'mention_rank' => max(0, (int) ($item['mention_rank'] ?? 0)),
                    'sentiment' => $this->normalizeSentiment((string) ($item['sentiment'] ?? 'neutral')),
                    'evidence' => trim((string) ($item['evidence'] ?? '')),
                    'source_count' => max(0, (int) ($item['source_count'] ?? 0)),
                    'meta' => $item,
                ];
            })
            ->filter(static fn (array $item): bool => $item['brand'] !== '')
            ->values()
            ->all();

        return [
            'answer' => $answer !== '' ? $answer : trim($text),
            'brand_mentions' => $mentions,
        ];
    }

    /**
     * @return list<array{question:string,type:string}>
     */
    private function parseQuestions(string $text): array
    {
        $json = trim($text);
        if (preg_match('/```(?:json)?\s*(.*?)```/is', $json, $matches)) {
            $json = trim((string) $matches[1]);
        }

        /** @var mixed $decoded */
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('豆包品牌诊断问题生成返回格式错误。');
        }

        $items = $decoded;
        if (! array_is_list($items) && isset($items['questions']) && is_array($items['questions'])) {
            $items = $items['questions'];
        }

        return collect($items)
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->map(static fn (array $item): array => [
                'question' => trim((string) ($item['question'] ?? '')),
                'type' => trim((string) ($item['type'] ?? 'AI问题')),
            ])
            ->filter(static fn (array $item): bool => $item['question'] !== '')
            ->unique(static fn (array $item): string => $item['question'])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function extractText(array $data): string
    {
        $texts = [];

        foreach ((array) ($data['output'] ?? []) as $output) {
            if (! is_array($output)) {
                continue;
            }

            foreach ((array) ($output['content'] ?? []) as $content) {
                if (! is_array($content)) {
                    continue;
                }

                $text = trim((string) ($content['text'] ?? ''));
                if ($text !== '') {
                    $texts[] = $text;
                }
            }
        }

        $fallback = trim((string) Arr::get($data, 'output_text', ''));
        if ($texts === [] && $fallback !== '') {
            $texts[] = $fallback;
        }

        return trim(implode("\n\n", $texts));
    }

    private function normalizePlatform(string $platform): string
    {
        $platform = strtolower(trim($platform));

        return in_array($platform, ['doubao', 'deepseek'], true) ? $platform : 'doubao';
    }

    private function platformLabel(string $platform): string
    {
        return $platform === 'deepseek' ? 'DeepSeek' : '豆包';
    }

    private function normalizeSentiment(string $sentiment): string
    {
        $sentiment = strtolower(trim($sentiment));

        return in_array($sentiment, ['positive', 'neutral', 'negative'], true) ? $sentiment : 'neutral';
    }

    /**
     * @param  array<string,mixed>  $data
     * @return list<array{title:string,url:string,type:string,meta:array<string,mixed>}>
     */
    private function extractSources(array $data): array
    {
        $sources = [];

        foreach ((array) ($data['output'] ?? []) as $output) {
            if (! is_array($output)) {
                continue;
            }

            $this->collectSourcesFromArray($output, $sources);
        }

        return collect($sources)
            ->filter(static fn (array $source): bool => trim((string) $source['url']) !== '')
            ->unique(static fn (array $source): string => (string) $source['url'])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $node
     * @param  list<array{title:string,url:string,type:string,meta:array<string,mixed>}>  $sources
     */
    private function collectSourcesFromArray(array $node, array &$sources): void
    {
        $type = (string) ($node['type'] ?? '');
        $url = trim((string) ($node['url'] ?? ''));
        if ($url !== '' && in_array($type, ['url_citation', 'web_search_result', 'citation'], true)) {
            $sources[] = [
                'title' => trim((string) ($node['title'] ?? $url)),
                'url' => $url,
                'type' => $type,
                'meta' => $node,
            ];
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                if (array_is_list($value)) {
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $this->collectSourcesFromArray($item, $sources);
                        }
                    }
                } else {
                    $this->collectSourcesFromArray($value, $sources);
                }
            }
        }
    }
}
