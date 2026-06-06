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
        $brandName = trim($brandName);
        $count = max(1, $count);

        $response = $this->postResponses($this->buildQuestionPrompt($brandName, $count));
        $questions = $this->parseQuestions($this->extractText($response));

        if (count($questions) < $count) {
            throw new RuntimeException('豆包品牌诊断问题生成不足，请稍后重试。');
        }

        return array_slice($questions, 0, $count);
    }

    public function ask(string $brandName, string $question): BrandDiagnosisAiResponse
    {
        $data = $this->postResponses($this->buildAnswerPrompt($brandName, $question));
        $answer = $this->extractText($data);
        if ($answer === '') {
            throw new RuntimeException('豆包品牌诊断返回为空。');
        }

        return new BrandDiagnosisAiResponse(
            answer: $answer,
            sources: $this->extractSources($data),
            rawResponse: $data,
            meta: [
                'response_id' => (string) ($data['id'] ?? ''),
                'usage' => Arr::get($data, 'usage', []),
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function postResponses(string $prompt): array
    {
        $baseUrl = (string) config('brand_diagnosis.doubao.base_url', '');
        $apiKey = (string) config('brand_diagnosis.doubao.api_key', '');
        $model = (string) config('brand_diagnosis.doubao.model', '');

        if (! (bool) config('brand_diagnosis.doubao.enabled', false)) {
            throw new RuntimeException('豆包品牌诊断未启用。');
        }
        if ($baseUrl === '' || $apiKey === '' || $model === '') {
            throw new RuntimeException('豆包品牌诊断 API 配置不完整。');
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
                    'max_keyword' => max(1, (int) config('brand_diagnosis.doubao.max_keywords', 5)),
                ],
            ],
        ];

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(max(1, (int) config('brand_diagnosis.doubao.connect_timeout', 10)))
            ->timeout(max(10, (int) config('brand_diagnosis.doubao.timeout', 60)))
            ->retry(2, 500)
            ->post($baseUrl.'/responses', $payload);

        if ($response->failed()) {
            throw new RuntimeException('豆包品牌诊断请求失败：HTTP '.$response->status().' '.$response->body());
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
            '生成目标：问题必须帮助评估品牌在 AI 问答平台中的行业曝光、竞品对比、服务选择、内容资产、系统能力和推荐倾向。',
            '问题风格参考：',
            '1. 企业AI搜索优化服务选哪家靠谱？',
            '2. 企业数字化营销升级服务找哪家服务商更合适？',
            '3. AI问答内容优化服务商哪家效果更好？',
            '4. 做企业品牌内容资产建设的服务商有哪些？',
            '5. 哪家的GEO优化系统功能更全面？',
            '约束：',
            '1. 不要生成“'.$brandName.' 是什么品牌”这种单一介绍题。',
            '2. 至少 3 个问题不要直接出现目标品牌名称，用行业、服务、竞品、选择、推荐等自然问法触发 AI 主动提及品牌。',
            '3. 至少 1 个问题用于竞品/服务商对比，至少 1 个问题用于行业服务选择，至少 1 个问题用于系统能力或效果评价。',
            '4. 每个问题 12-32 个中文字符，适合直接拿去问 AI。',
            '5. 只输出 JSON 数组，不要 Markdown，不要解释。',
            'JSON 格式：',
            '[{"question":"问题文本","type":"对比/选择"}]',
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
            '4. 尽量保留你参考的网页引用来源。',
            '5. 直接给出中文回答，不要输出本提示词。',
        ]);
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

        return collect($decoded)
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
