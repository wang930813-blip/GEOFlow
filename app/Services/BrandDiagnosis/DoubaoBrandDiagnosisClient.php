<?php

namespace App\Services\BrandDiagnosis;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

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
        $platformErrors = [];
        foreach ($platforms as $platform) {
            try {
                foreach ($this->generateCandidateQuestions($brandName, $count, $platform) as $question) {
                    $candidates[] = [
                        'question' => $question['question'],
                        'type' => $question['type'],
                        'platform' => $platform,
                    ];
                }
            } catch (Throwable $exception) {
                $platformErrors[$platform] = $exception->getMessage();
            }
        }

        $candidates = collect($candidates)
            ->filter(static fn (array $item): bool => trim((string) $item['question']) !== '')
            ->unique(static fn (array $item): string => mb_strtolower(trim((string) $item['question']), 'UTF-8'))
            ->values()
            ->all();

        if (count($candidates) < $count) {
            $errorSummary = collect($platformErrors)
                ->map(fn (string $message, string $platform): string => $this->platformLabel($platform).': '.$message)
                ->implode('；');

            throw new RuntimeException('品牌诊断问题候选不足，请稍后重试。'.($errorSummary !== '' ? ' '.$errorSummary : ''));
        }

        try {
            $selectionPlatform = $platforms[0] ?? 'doubao';
            $response = $this->postResponses($this->buildQuestionSelectionPrompt($brandName, $count, $candidates), $selectionPlatform);
            $questions = $this->parseQuestions($this->extractText($response));
            $questions = $this->preferNaturalQuestions($questions, $candidates, $brandName, $count, false);
        } catch (Throwable) {
            $questions = $this->fallbackQuestionsFromCandidates($candidates, $brandName, $count);
        }

        if (count($questions) < $count) {
            throw new RuntimeException('品牌诊断问题精选不足，请稍后重试。');
        }

        return array_slice($questions, 0, $count);
    }

    /**
     * @param  list<array{question:string,type:string,platform?:string}>  $candidates
     * @return list<array{question:string,type:string}>
     */
    private function fallbackQuestionsFromCandidates(array $candidates, string $brandName, int $count): array
    {
        return array_slice(
            $this->preferNaturalQuestions($candidates, $candidates, $brandName, $count, false),
            0,
            $count
        );
    }

    public function ask(string $brandName, string $question, string $platform = 'doubao'): BrandDiagnosisAiResponse
    {
        $platform = $this->normalizePlatform($platform);
        $data = $this->postResponses($this->buildAnswerPrompt($brandName, $question), $platform);
        $parsed = $this->parseAnswerPayload($this->extractText($data));
        $sources = $this->extractSources($data);
        $brandMentions = $parsed['brand_mentions'];
        $mentionExtractionMeta = [
            'status' => 'not_needed',
        ];

        if ($parsed['answer'] === '') {
            throw new RuntimeException($this->platformLabel($platform).'品牌诊断返回为空。');
        }

        if ($brandMentions === []) {
            try {
                $extractionData = $this->postResponses(
                    $this->buildBrandMentionExtractionPrompt($parsed['answer'], $sources),
                    $platform,
                    false
                );
                $brandMentions = $this->parseAnswerPayload($this->extractText($extractionData))['brand_mentions'];
                $mentionExtractionMeta = [
                    'status' => 'success',
                    'response_id' => (string) ($extractionData['id'] ?? ''),
                    'usage' => Arr::get($extractionData, 'usage', []),
                ];
            } catch (Throwable $exception) {
                $mentionExtractionMeta = [
                    'status' => 'failed',
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return new BrandDiagnosisAiResponse(
            answer: $parsed['answer'],
            sources: $sources,
            rawResponse: $data,
            meta: [
                'platform' => $platform,
                'response_id' => (string) ($data['id'] ?? ''),
                'usage' => Arr::get($data, 'usage', []),
                'mention_extraction' => $mentionExtractionMeta,
            ],
            brandMentions: $brandMentions,
        );
    }

    /**
     * @param  array<string,mixed>  $rawResponse
     * @return list<array{brand:string,mention_count:int,mention_rank:int,sentiment:string,evidence:string,source_count?:int,meta?:array<string,mixed>}>
     */
    public function extractBrandMentionsFromRawResponse(array $rawResponse): array
    {
        return $this->parseAnswerPayload($this->extractText($rawResponse))['brand_mentions'];
    }

    /**
     * @param  list<array{title:string,url:string,type:string,meta:array<string,mixed>}>  $sources
     * @return list<array{brand:string,mention_count:int,mention_rank:int,sentiment:string,evidence:string,source_count?:int,meta?:array<string,mixed>}>
     */
    public function extractBrandMentionsFromEvidence(string $answer, array $sources, string $platform): array
    {
        $platform = $this->normalizePlatform($platform);
        $data = $this->postResponses($this->buildBrandMentionExtractionPrompt($answer, $sources), $platform, false);

        return $this->parseAnswerPayload($this->extractText($data))['brand_mentions'];
    }

    /**
     * @return list<array{question:string,type:string}>
     */
    private function generateCandidateQuestions(string $brandName, int $count, string $platform): array
    {
        $brandName = trim($brandName);
        $count = max(1, $count);
        $candidateCount = max($count, $count * max(1, (int) config('brand_diagnosis.question_candidate_multiplier', 2)));
        $platform = $this->normalizePlatform($platform);

        $response = $this->postResponses($this->buildQuestionPrompt($brandName, $candidateCount, $count), $platform);
        $questions = $this->parseQuestions($this->extractText($response));

        if (count($questions) < $count) {
            throw new RuntimeException($this->platformLabel($platform).'品牌诊断问题生成不足，请稍后重试。');
        }

        return array_slice($this->preferNaturalQuestions($questions, $questions, $brandName, $count, false), 0, $count);
    }

    /**
     * @return array<string,mixed>
     */
    private function postResponses(string $prompt, string $platform, bool $withWebSearch = true): array
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
        ];

        if ($withWebSearch) {
            $payload['tools'] = [
                [
                    'type' => 'web_search',
                    'max_keyword' => max(1, (int) config('brand_diagnosis.'.$platform.'.max_keywords', 5)),
                ],
            ];
        }

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

    private function buildQuestionPrompt(string $brandName, int $candidateCount, int $finalCount): string
    {
        return implode("\n", [
            '请使用联网搜索，围绕目标品牌生成 '.$candidateCount.' 个用户会真实询问 AI 的品牌诊断候选问题，系统最终只会精选 '.$finalCount.' 个。',
            '最终诊断会生成 '.$finalCount.' 个 AI 问题。',
            '目标品牌：'.$brandName,
            '请先联网检索目标品牌，智能分析它可能对应的行业、业务类型、服务对象、地域、产品形态、竞品集合、常见应用场景等维度；不要把目标品牌强行归入固定行业。',
            '生成目标：问题必须帮助评估品牌在 AI 问答平台中的自然曝光、真实讨论、相关对象、市场认知和推荐倾向。',
            '问题风格要求：',
            '1. 用你分析出的行业、类型、服务对象、地域、应用场景等自然生成问题。',
            '2. 问题要像真实用户会问 AI 的认知、选择、对比、评价、位置、服务、作品、口碑或合作问题。',
            '3. 不要套用固定行业模板，不要默认生成某个特定领域的问题。',
            '约束：',
            '1. 不要生成“'.$brandName.' 是什么品牌”这种单一介绍题。',
            '2. 不要直接出现目标品牌名称、简称、公司简称或品牌词；诊断要模拟普通用户自然提问，用行业、品类、地域、场景和服务对象来提问，让 AI 自然决定是否提及目标品牌。',
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
            '5. 最终问题默认不得出现目标品牌名称或简称；同时也不得出现公司简称或品牌词，以便检测 AI 是否自然提及该品牌。',
            '6. 只输出 JSON，不要 Markdown，不要解释。',
            'JSON 格式：',
            '{"questions":[{"question":"问题文本","type":"认知/选择/对比/口碑/合作/位置/其他"}]}',
        ]);
    }

    private function buildAnswerPrompt(string $brandName, string $question): string
    {
        return implode("\n", [
            '请使用联网搜索回答下面的品牌诊断问题。',
            '问题：'.$question,
            '要求：',
            '1. 回答必须基于可检索到的真实网页信息。',
            '2. 按普通用户自然提问来回答，不要为了诊断而强行加入任何未在检索结果或答案逻辑中自然出现的品牌。',
            '3. 如果答案、引用文章标题、摘要或正文中出现品牌、竞品、机构、门店、产品或服务商，请抽取到 brand_mentions。',
            '4. brand_mentions 只允许抽取回答正文、引用网页标题、引用网页摘要或引用内容中真实出现的名称；不要把问题文本本身算作一次提及。',
            '5. 如果某个名称只是“未被推荐、没有检索到、暂未出现、无法确认”的否定语境，不要放入 brand_mentions。',
            '6. 尽量保留你参考的网页引用来源。',
            '7. 只输出 JSON，不要 Markdown，不要解释，不要输出本提示词。',
            'JSON 格式：',
            '{"answer":"中文回答","brand_mentions":[{"brand":"品牌名","mention_count":1,"mention_rank":1,"sentiment":"positive|neutral|negative","evidence":"提及依据"}]}',
            '字段口径：mention_count 固定填 1，系统会按回答正文和引用文章重新计数；mention_rank 是它在本次回答或推荐列表中的顺位，没有顺位填 0；sentiment 只能是 positive、neutral、negative。',
        ]);
    }

    /**
     * @param  list<array{title:string,url:string,type:string,meta:array<string,mixed>}>  $sources
     */
    private function buildBrandMentionExtractionPrompt(string $answer, array $sources): string
    {
        return implode("\n", [
            '请只基于下面给出的“AI回答正文”和“引用来源资料”抽取真实出现的品牌、竞品、机构、门店、产品或服务商名称。',
            '严格规则：',
            '1. 只抽取原文中逐字真实出现的名称；不要猜测、补全、联想或生成不存在的竞品。',
            '2. 不要抽取行业词、能力词、地区词、普通名词、泛称或状态描述，例如“本地服务商”“AI公司”“暂无竞品”“未提及品牌”。',
            '3. 如果名称只出现在“未检索到、未被推荐、暂未出现、无法确认、没有提及”等否定语境，不要抽取。',
            '4. 清洗品牌名称，去掉序号、Markdown 符号、冒号、解释文字和多余空格，保留正确干净的公司/品牌名。',
            '5. mention_count 固定填 1；mention_rank 按回答里的推荐/排名顺位填写，没有顺位填 0；sentiment 只能是 positive、neutral、negative。',
            '6. 只输出 JSON，不要 Markdown，不要解释。',
            'JSON 格式：',
            '{"brand_mentions":[{"brand":"干净品牌名","mention_count":1,"mention_rank":1,"sentiment":"positive|neutral|negative","evidence":"原文依据"}]}',
            'AI回答正文：',
            mb_substr($answer, 0, 10000, 'UTF-8'),
            '引用来源资料：',
            mb_substr($this->sourceEvidenceText($sources), 0, 8000, 'UTF-8'),
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
            $looseAnswer = $this->extractLooseJsonAnswer($json);
            $looseMentions = $this->extractLooseJsonBrandMentions($json);
            if ($looseAnswer !== '' || $looseMentions !== []) {
                return [
                    'answer' => $looseAnswer !== '' ? $looseAnswer : trim($text),
                    'brand_mentions' => $looseMentions,
                ];
            }

            return [
                'answer' => trim($text),
                'brand_mentions' => [],
            ];
        }

        $answer = trim((string) ($decoded['answer'] ?? ''));
        if ($answer === '') {
            $answer = trim((string) Arr::get($decoded, 'content', ''));
        }

        $mentions = $this->normalizeParsedBrandMentions((array) ($decoded['brand_mentions'] ?? []));

        return [
            'answer' => $answer !== '' ? $answer : trim($text),
            'brand_mentions' => $mentions,
        ];
    }

    /**
     * @param  list<array<string,mixed>>|array<int,array<string,mixed>>  $mentions
     * @return list<array{brand:string,mention_count:int,mention_rank:int,sentiment:string,evidence:string,source_count?:int,meta?:array<string,mixed>}>
     */
    private function normalizeParsedBrandMentions(array $mentions): array
    {
        return collect($mentions)
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
    }

    private function extractLooseJsonAnswer(string $text): string
    {
        $json = trim($text);
        if (! str_starts_with($json, '{') || mb_stripos($json, '"answer"', 0, 'UTF-8') === false) {
            return '';
        }

        if (! preg_match('/"answer"\s*:\s*"/u', $json, $matches, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $start = (int) $matches[0][1] + strlen((string) $matches[0][0]);
        $markers = [
            '","brand_mentions"',
            '","brands"',
            '","sources"',
            '" , "brand_mentions"',
        ];
        $end = false;
        foreach ($markers as $marker) {
            $position = strpos($json, $marker, $start);
            if ($position !== false && ($end === false || $position < $end)) {
                $end = $position;
            }
        }

        if ($end === false) {
            $end = strrpos($json, '"');
        }
        if ($end === false || $end <= $start) {
            return '';
        }

        $answer = substr($json, $start, $end - $start);
        $answer = str_replace(['\\n', '\\r', '\\t', '\\"', '\\/'], ["\n", "\r", "\t", '"', '/'], $answer);
        $answer = preg_replace('/\s+/u', ' ', $answer) ?? $answer;

        return trim($answer);
    }

    /**
     * @return list<array{brand:string,mention_count:int,mention_rank:int,sentiment:string,evidence:string,source_count?:int,meta?:array<string,mixed>}>
     */
    private function extractLooseJsonBrandMentions(string $text): array
    {
        if (mb_stripos($text, '"brand_mentions"', 0, 'UTF-8') === false) {
            return [];
        }

        if (! preg_match('/"brand_mentions"\s*:\s*\[/u', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $arrayStart = (int) $matches[0][1] + strlen((string) $matches[0][0]) - 1;
        $arrayJson = $this->extractJsonArrayAt($text, $arrayStart);
        if ($arrayJson === '') {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($arrayJson, true);
        if (! is_array($decoded)) {
            return [];
        }

        return $this->normalizeParsedBrandMentions($decoded);
    }

    private function extractJsonArrayAt(string $text, int $start): string
    {
        $length = strlen($text);
        if ($start < 0 || $start >= $length || $text[$start] !== '[') {
            return '';
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        for ($index = $start; $index < $length; $index++) {
            $char = $text[$index];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;

                continue;
            }

            if ($char === '[') {
                $depth++;
            } elseif ($char === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $index - $start + 1);
                }
            }
        }

        return '';
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
     * @param  list<array{question:string,type:string}>  $selected
     * @param  list<array{question:string,type:string}>|list<array{question:string,type:string,platform:string}>  $candidates
     * @return list<array{question:string,type:string}>
     */
    private function preferNaturalQuestions(array $selected, array $candidates, string $brandName, int $count, bool $allowBrandedFallback = true): array
    {
        $selectedKeys = collect($selected)
            ->map(static fn (array $question): string => mb_strtolower(trim((string) $question['question']), 'UTF-8'))
            ->all();

        $fillerQuestions = collect($candidates)
            ->filter(fn (array $question): bool => $this->isNaturalQuestion((string) $question['question'], $brandName))
            ->reject(static fn (array $question): bool => in_array(mb_strtolower(trim((string) $question['question']), 'UTF-8'), $selectedKeys, true))
            ->map(static fn (array $question): array => [
                'question' => (string) $question['question'],
                'type' => (string) $question['type'],
            ])
            ->values();

        $fillerIndex = 0;
        $naturalQuestions = collect($selected)
            ->map(function (array $question) use ($brandName, $fillerQuestions, &$fillerIndex): array {
                if ($this->isNaturalQuestion((string) $question['question'], $brandName)) {
                    return [
                        'question' => (string) $question['question'],
                        'type' => (string) $question['type'],
                    ];
                }

                $replacement = $fillerQuestions->get($fillerIndex);
                $fillerIndex++;

                return is_array($replacement)
                    ? $replacement
                    : [
                        'question' => (string) $question['question'],
                        'type' => (string) $question['type'],
                    ];
            })
            ->unique(static fn (array $question): string => mb_strtolower(trim((string) $question['question']), 'UTF-8'))
            ->values();

        $usedKeys = $naturalQuestions
            ->map(static fn (array $question): string => mb_strtolower(trim((string) $question['question']), 'UTF-8'))
            ->all();
        $remainingFillers = $fillerQuestions
            ->reject(static fn (array $question): bool => in_array(mb_strtolower(trim((string) $question['question']), 'UTF-8'), $usedKeys, true))
            ->values();
        $naturalQuestions = $naturalQuestions->concat($remainingFillers)->unique(static fn (array $question): string => mb_strtolower(trim((string) $question['question']), 'UTF-8'))->values();
        if ($naturalQuestions->count() >= $count) {
            return $naturalQuestions->take($count)->all();
        }

        if (! $allowBrandedFallback) {
            return $naturalQuestions->take($count)->values()->all();
        }

        return $naturalQuestions
            ->concat(collect($selected)->map(static fn (array $question): array => [
                'question' => (string) $question['question'],
                'type' => (string) $question['type'],
            ]))
            ->unique(static fn (array $question): string => mb_strtolower(trim((string) $question['question']), 'UTF-8'))
            ->take($count)
            ->values()
            ->all();
    }

    private function isNaturalQuestion(string $question, string $brandName): bool
    {
        $question = trim($question);
        if ($question === '') {
            return false;
        }

        return ! $this->questionContainsBrandAlias($question, $brandName)
            && ! $this->questionContainsStaleTemplateTerms($question, $brandName);
    }

    private function questionContainsBrandAlias(string $question, string $brandName): bool
    {
        foreach ($this->brandAliases($brandName) as $alias) {
            if (mb_strlen($alias, 'UTF-8') < 2) {
                continue;
            }

            if (mb_stripos($question, $alias, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    private function questionContainsStaleTemplateTerms(string $question, string $brandName): bool
    {
        $normalizedBrand = mb_strtolower($brandName, 'UTF-8');
        $geoLikeBrand = mb_stripos($normalizedBrand, 'geo', 0, 'UTF-8') !== false
            || mb_stripos($brandName, '搜索优化', 0, 'UTF-8') !== false
            || mb_stripos($brandName, '问答', 0, 'UTF-8') !== false;

        if ($geoLikeBrand) {
            return false;
        }

        foreach (['GEO', 'AI搜索优化', 'AI问答品牌', '问答品牌内容资产', '品牌内容资产建设'] as $term) {
            if (mb_stripos($question, $term, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function brandAliases(string $brandName): array
    {
        $brandName = trim($brandName);
        if ($brandName === '') {
            return [];
        }

        $aliases = [$brandName];
        $withoutParentheses = preg_replace('/[（(].*?[）)]/u', '', $brandName);
        if (is_string($withoutParentheses) && trim($withoutParentheses) !== '') {
            $aliases[] = trim($withoutParentheses);
        }

        $shortName = preg_replace('/(股份)?有限公司$/u', '', trim((string) $withoutParentheses));
        if (is_string($shortName) && trim($shortName) !== '') {
            $aliases[] = trim($shortName);
        }

        $shortName = preg_replace('/(人工智能|科技|技术|信息|网络|软件|数字|智能|公司)+$/u', '', trim((string) $shortName));
        if (is_string($shortName) && trim($shortName) !== '') {
            $aliases[] = trim($shortName);
        }

        return collect($aliases)
            ->map(static fn (string $alias): string => trim($alias))
            ->filter(static fn (string $alias): bool => $alias !== '')
            ->unique(static fn (string $alias): string => mb_strtolower($alias, 'UTF-8'))
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function extractText(array $data): string
    {
        $preferredTexts = [];
        $fallbackTexts = [];

        foreach ((array) ($data['output'] ?? []) as $output) {
            if (! is_array($output)) {
                continue;
            }

            $type = strtolower(trim((string) ($output['type'] ?? '')));
            $isPreferred = in_array($type, ['message', 'assistant', 'response'], true);
            foreach ((array) ($output['content'] ?? []) as $content) {
                if (! is_array($content)) {
                    continue;
                }

                $text = trim((string) ($content['text'] ?? ''));
                if ($text !== '') {
                    if ($isPreferred) {
                        $preferredTexts[] = $text;
                    } elseif (empty($fallbackTexts)) {
                        $fallbackTexts[] = $text;
                    }
                }
            }
        }

        $fallback = trim((string) Arr::get($data, 'output_text', ''));
        if ($preferredTexts !== []) {
            return trim(implode("\n\n", $preferredTexts));
        }

        if ($fallback !== '') {
            return $fallback;
        }

        return trim(implode("\n\n", $fallbackTexts));
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
     * @param  list<array{title:string,url:string,type:string,meta:array<string,mixed>}>  $sources
     */
    private function sourceEvidenceText(array $sources): string
    {
        return collect($sources)
            ->map(static function (array $source): string {
                $text = collect([
                    (string) ($source['title'] ?? ''),
                    (string) data_get($source, 'meta.summary', ''),
                    (string) data_get($source, 'meta.snippet', ''),
                    (string) data_get($source, 'meta.content', ''),
                ])
                    ->filter(static fn (string $value): bool => trim($value) !== '')
                    ->implode(' ');

                return trim((string) preg_replace('/\s+/u', ' ', $text));
            })
            ->filter(static fn (string $value): bool => trim($value) !== '')
            ->implode(BrandDiagnosisMetricsCalculator::SOURCE_UNIT_SEPARATOR);
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
