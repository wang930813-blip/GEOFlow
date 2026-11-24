<?php

namespace App\Services\BrandDiagnosis;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class DoubaoBrandDiagnosisClient
{
    public function __construct(
        private readonly BrandDiagnosisModelSettings $modelSettings,
    ) {}

    /**
     * @return list<array{question:string,type:string,core_term:string}>
     */
    public function generateQuestions(string $brandName, int $count): array
    {
        return $this->generateCandidateQuestions($brandName, $count, 'doubao', '');
    }

    /**
     * @param  list<string>  $platforms
     * @param  list<string>  $brandCoreTerms
     * @return list<array{question:string,type:string,core_term:string}>
     */
    public function generateQuestionPool(string $brandName, int $count, array $platforms, string $brandProfile = '', array $brandCoreTerms = []): array
    {
        $brandName = trim($brandName);
        $count = max(1, $count);
        $platform = $this->normalizePlatform((string) (array_values($platforms)[0] ?? BrandDiagnosisPlatform::DOUBAO));
        $coreTerms = $this->normalizeBrandCoreTerms($brandCoreTerms, $brandName, 5);
        if ($coreTerms === []) {
            $coreTerms = $this->extractBrandCoreTerms($brandName, $brandProfile, 5, $platform);
        }

        return $this->generateQuestionsFromCoreTerms($brandName, $count, $brandProfile, $coreTerms, $platform);
    }

    /**
     * @return list<string>
     */
    public function extractBrandCoreTerms(string $brandName, string $brandProfile, int $count = 5, string $platform = BrandDiagnosisPlatform::DOUBAO): array
    {
        $brandName = trim($brandName);
        $brandProfile = trim($brandProfile);
        $count = max(1, $count);
        $platform = $this->normalizePlatform($platform);
        if ($brandProfile === '') {
            throw new RuntimeException('品牌介绍为空，无法提取品牌核心词。');
        }

        $response = $this->postResponses($this->buildBrandCoreTermsPrompt($brandName, $brandProfile, $count), $platform, false);
        $coreTerms = $this->parseBrandCoreTerms($this->extractText($response), $brandName, $count, $platform);
        $coreTerms = $this->ensureBrandSubjectCoreTerm($coreTerms, $brandName, $count);
        if ($coreTerms === []) {
            throw new RuntimeException($this->platformLabel($platform).'品牌诊断核心词提取为空，请稍后重试。');
        }

        return $coreTerms;
    }

    /**
     * @param  list<string>  $brandCoreTerms
     * @return list<array{question:string,type:string,core_term:string}>
     */
    public function generateQuestionsFromCoreTerms(string $brandName, int $count, string $brandProfile, array $brandCoreTerms, string $platform = BrandDiagnosisPlatform::DOUBAO): array
    {
        $brandName = trim($brandName);
        $count = max(1, $count);
        $platform = $this->normalizePlatform($platform);
        $coreTerms = $this->normalizeBrandCoreTerms($brandCoreTerms, $brandName, 5);
        $coreTerms = $this->ensureBrandSubjectCoreTerm($coreTerms, $brandName, 5);
        if ($coreTerms === []) {
            throw new RuntimeException('品牌诊断核心词为空，无法生成问题。');
        }

        $candidateCount = max($count, $count * max(1, (int) config('brand_diagnosis.question_candidate_multiplier', 3)));
        $response = $this->postResponses(
            $this->buildQuestionPrompt($brandName, $candidateCount, $count, $brandProfile, $coreTerms),
            $platform,
            false
        );
        $candidatePool = $this->filteredQuestionSet($this->parseQuestions($this->extractText($response), $platform), $brandName, $coreTerms, $count, false);
        $questions = $this->balanceQuestionSet($candidatePool, $count);

        for ($round = 0; $round < 2 && $this->questionPoolNeedsSupplement($questions, $count); $round++) {
            $supplementalCount = $this->supplementalQuestionCount($questions, $count);
            $supplementalResponse = $this->postResponses(
                $this->buildSupplementalQuestionPrompt($brandName, $supplementalCount, $count, $brandProfile, $coreTerms, $questions),
                $platform,
                false
            );
            $existingKeys = collect($candidatePool)
                ->map(fn (array $item): string => $this->normalizedSearchText((string) $item['question']))
                ->all();
            $newCandidates = collect($this->parseQuestions($this->extractText($supplementalResponse), $platform))
                ->reject(fn (array $item): bool => in_array($this->normalizedSearchText((string) $item['question']), $existingKeys, true))
                ->values()
                ->all();
            $supplementalQuestions = $this->filteredQuestionSet($newCandidates, $brandName, $coreTerms, $count, false);
            $candidatePool = collect($candidatePool)
                ->concat($supplementalQuestions)
                ->unique(fn (array $item): string => $this->normalizedSearchText((string) $item['question']))
                ->values()
                ->all();
            $questions = $this->balanceQuestionSet($candidatePool, $count);
        }

        if ($this->questionPoolNeedsSupplement($questions, $count)) {
            $questions = $this->filteredQuestionSet($candidatePool, $brandName, $coreTerms, $count, true);
        }

        if (count($questions) < $count) {
            throw new RuntimeException($this->platformLabel($platform).'品牌诊断问题生成不足，请调整品牌资料后重试。');
        }

        return array_slice($questions, 0, $count);
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
            rawResponse: $this->cleanExternalValue($data),
            meta: [
                'platform' => $platform,
                'response_id' => (string) ($data['id'] ?? ''),
                'usage' => Arr::get($data, 'usage', []),
                'retrieval' => $this->extractRetrievalMetadata($data),
                'mention_extraction' => $mentionExtractionMeta,
            ],
            brandMentions: $brandMentions,
        );
    }

    /**
     * @Name: generateBrandProfileWithWebSearch
     *
     * @Description: 使用联网搜索核验品牌资料，并将监测中心提交的官网、简介、品牌词和竞品作为辅助检索线索。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-22 16:27:47
     *
     * @UpdateTime: 2026-11-23 14:53:16
     *
     * @Param: string $brandName 目标品牌名称
     *
     * @Param: string $platform 执行联网搜索的平台标识
     *
     * @Param: array<string,mixed> $context 用户补充的品牌资料上下文
     *
     * @Return: array{text:string,sources:list<array{title:string,url:string,type:string,meta:array<string,mixed>}>,raw_response:array<string,mixed>,platform:string} 品牌资料和引用来源
     *
     * @Throws: RuntimeException 模型未启用、接口配置错误或联网搜索请求失败
     */
    public function generateBrandProfileWithWebSearch(
        string $brandName,
        string $platform = BrandDiagnosisPlatform::DOUBAO,
        array $context = []
    ): array {
        $platform = $this->normalizePlatform($platform);
        $data = $this->postResponses($this->buildBrandProfileWebSearchPrompt($brandName, $context), $platform, true);

        return [
            'text' => $this->extractText($data),
            'sources' => $this->extractSources($data),
            'raw_response' => $this->cleanExternalValue($data),
            'platform' => $platform,
            'retrieval' => $this->extractRetrievalMetadata($data),
        ];
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
     * @return list<array{question:string,type:string,core_term:string}>
     */
    private function generateCandidateQuestions(string $brandName, int $count, string $platform, string $brandProfile): array
    {
        $brandName = trim($brandName);
        $count = max(1, $count);
        $candidateCount = max($count, $count * max(1, (int) config('brand_diagnosis.question_candidate_multiplier', 2)));
        $platform = $this->normalizePlatform($platform);

        $response = $this->postResponses($this->buildQuestionPrompt($brandName, $candidateCount, $count, $brandProfile), $platform, false);
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
        if ($platform === BrandDiagnosisPlatform::QIANWEN) {
            return $this->postDashScopeGeneration($prompt, $platform, $withWebSearch);
        }

        if ($this->usesChatCompletions($platform)) {
            return $this->postChatCompletions($prompt, $platform, $withWebSearch);
        }

        return $this->postArkResponses($prompt, $platform, $withWebSearch);
    }

    /**
     * @return array<string,mixed>
     */
    private function postArkResponses(string $prompt, string $platform, bool $withWebSearch = true): array
    {
        $platform = $this->normalizePlatform($platform);
        $label = $this->platformLabel($platform);
        $baseUrl = (string) config('brand_diagnosis.'.$platform.'.base_url', '');
        $apiKey = (string) config('brand_diagnosis.'.$platform.'.api_key', '');
        $model = (string) config('brand_diagnosis.'.$platform.'.model', '');
        if ($platform !== BrandDiagnosisPlatform::DOUBAO) {
            $baseUrl = $baseUrl !== '' ? $baseUrl : (string) config('brand_diagnosis.doubao.base_url', '');
            $apiKey = $apiKey !== '' ? $apiKey : (string) config('brand_diagnosis.doubao.api_key', '');
        }

        if (! $this->modelSettings->isEnabled($platform)) {
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
            ->post($baseUrl.'/responses', $payload);

        if ($response->failed()) {
            throw new RuntimeException($this->formatArkRequestFailure($response, $label, $platform, $model));
        }

        /** @var array<string,mixed> $data */
        $data = $response->json() ?: [];

        return $data;
    }

    /**
     * 生成火山方舟请求失败提示，保留平台、模型、HTTP 状态和平台错误码。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-22 16:27:47
     *
     * @UpdateTime: 2026-10-09 15:06:50
     *
     * @Param: Response $response 火山方舟 HTTP 响应
     *
     * @Param: string $label 平台展示名称
     *
     * @Param: string $platform 平台标识
     *
     * @Param: string $model 当前请求模型
     *
     * @Return string 可直接展示的失败原因
     */
    private function formatArkRequestFailure(Response $response, string $label, string $platform, string $model): string
    {
        $errorCode = trim((string) $response->json('error.code', ''));
        $errorMessage = $this->cleanExternalText((string) $response->json('error.message', ''));
        $status = $response->status();
        $modelText = $model !== '' ? $model : '未配置';

        if ($errorCode === 'InvalidEndpoint.ClosedEndpoint' && $platform === BrandDiagnosisPlatform::DEEPSEEK) {
            return $label.'品牌诊断请求失败：HTTP '.$status
                .'，模型 '.$modelText.' 已关闭或暂不可用，请更新 BRAND_DIAGNOSIS_DEEPSEEK_MODEL 为当前可用的火山方舟模型。'
                .' 错误码：'.$errorCode
                .'；错误信息：'.($errorMessage !== '' ? $errorMessage : '火山方舟端点已关闭。');
        }

        $details = [];
        if ($errorCode !== '') {
            $details[] = '错误码：'.$errorCode;
        }
        if ($errorMessage !== '') {
            $details[] = '错误信息：'.$errorMessage;
        }

        if ($details === []) {
            $body = $this->cleanExternalText($response->body());
            if ($body !== '') {
                $details[] = $body;
            }
        }

        return $label.'品牌诊断请求失败：HTTP '.$status
            .'，模型 '.$modelText
            .($details !== [] ? '；'.implode('；', $details) : '');
    }

    /**
     * @return array<string,mixed>
     */
    private function postDashScopeGeneration(string $prompt, string $platform, bool $withWebSearch = true): array
    {
        $platform = $this->normalizePlatform($platform);
        $label = $this->platformLabel($platform);
        $baseUrl = (string) config('brand_diagnosis.'.$platform.'.base_url', '');
        $apiKey = (string) config('brand_diagnosis.'.$platform.'.api_key', '');
        $model = (string) config('brand_diagnosis.'.$platform.'.model', '');

        if (! $this->modelSettings->isEnabled($platform)) {
            throw new RuntimeException($label.' brand diagnosis is disabled.');
        }
        if ($baseUrl === '' || $apiKey === '' || $model === '') {
            throw new RuntimeException($label.' brand diagnosis API config is incomplete.');
        }

        $isMultimodalModel = $this->usesDashScopeMultimodalGeneration($model);
        $payload = [
            'model' => $model,
            'input' => [
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $isMultimodalModel ? [['text' => $prompt]] : $prompt,
                    ],
                ],
            ],
            'parameters' => [
                'result_format' => 'message',
                'enable_thinking' => false,
            ],
        ];

        if ($withWebSearch) {
            $payload['parameters']['enable_search'] = true;
            $payload['parameters']['search_options'] = [
                'forced_search' => true,
                'enable_source' => true,
                'enable_citation' => true,
                'citation_format' => '[ref_<number>]',
            ];
        }

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(max(1, (int) config('brand_diagnosis.'.$platform.'.connect_timeout', 10)))
            ->timeout(max(10, (int) config('brand_diagnosis.'.$platform.'.timeout', 60)))
            ->post($this->dashScopeGenerationUrl($baseUrl, $model), $payload);

        if ($response->failed()) {
            throw new RuntimeException($label.' brand diagnosis request failed: HTTP '.$response->status().' '.$response->body());
        }

        /** @var array<string,mixed> $data */
        $data = $response->json() ?: [];

        return $data;
    }

    /**
     * @return array<string,mixed>
     */
    private function postChatCompletions(string $prompt, string $platform, bool $withWebSearch = true): array
    {
        $platform = $this->normalizePlatform($platform);
        $label = $this->platformLabel($platform);
        $baseUrl = (string) config('brand_diagnosis.'.$platform.'.base_url', '');
        $apiKey = (string) config('brand_diagnosis.'.$platform.'.api_key', '');
        $model = (string) config('brand_diagnosis.'.$platform.'.model', '');

        if (! $this->modelSettings->isEnabled($platform)) {
            throw new RuntimeException($label.' brand diagnosis is disabled.');
        }
        if ($baseUrl === '' || $apiKey === '' || $model === '') {
            throw new RuntimeException($label.' brand diagnosis API config is incomplete.');
        }

        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
        ];
        if ($platform === BrandDiagnosisPlatform::YUANBAO) {
            $payload['stream'] = false;
        }

        if ($withWebSearch) {
            $payload = $this->withChatCompletionsWebSearchPayload($payload, $platform);
        }
        $expectsStream = $withWebSearch && $platform === BrandDiagnosisPlatform::WENXIN;

        $request = Http::withToken($apiKey)
            ->asJson()
            ->connectTimeout(max(1, (int) config('brand_diagnosis.'.$platform.'.connect_timeout', 10)))
            ->timeout(max(10, (int) config('brand_diagnosis.'.$platform.'.timeout', 60)));

        $request = $expectsStream
            ? $request->accept('text/event-stream')->withOptions(['stream' => true])
            : $request->acceptJson();

        $response = $request->post($this->chatCompletionsUrl($baseUrl, $platform), $payload);

        if ($response->failed()) {
            throw new RuntimeException($label.' brand diagnosis request failed: HTTP '.$response->status().' '.$response->body());
        }

        return $this->decodeChatCompletionsResponse($response, $expectsStream);
    }

    private function usesChatCompletions(string $platform): bool
    {
        return in_array($this->normalizePlatform($platform), [
            BrandDiagnosisPlatform::YUANBAO,
            BrandDiagnosisPlatform::WENXIN,
        ], true);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function withChatCompletionsWebSearchPayload(array $payload, string $platform): array
    {
        $platform = $this->normalizePlatform($platform);

        if ($platform === BrandDiagnosisPlatform::QIANWEN) {
            $payload['enable_search'] = true;
            $payload['search_options'] = [
                'forced_search' => true,
            ];
        }

        if ($platform === BrandDiagnosisPlatform::WENXIN) {
            $payload['stream'] = true;
            $payload['web_search'] = [
                'enable' => true,
                'enable_trace' => true,
                'enable_status' => true,
                'enable_citation' => true,
                'search_mode' => 'auto',
                'search_number' => max(1, (int) config('brand_diagnosis.'.$platform.'.max_keywords', 5)),
                'reference_number' => max(1, (int) config('brand_diagnosis.'.$platform.'.max_keywords', 5)),
            ];
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeChatCompletionsResponse(Response $response, bool $expectsStream): array
    {
        if ($expectsStream) {
            return $this->decodeChatCompletionsStreamResponse($response);
        }

        /** @var array<string,mixed>|null $json */
        $json = $response->json();

        return $json ?: [];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeChatCompletionsStreamResponse(Response $response): array
    {
        $chunks = [];
        $buffer = '';
        $raw = '';
        $body = $response->toPsrResponse()->getBody();

        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }

            while (! $body->eof()) {
                $piece = $body->read(8192);
                if ($piece === '') {
                    if ($body->eof()) {
                        break;
                    }
                    usleep(10_000);

                    continue;
                }

                $raw .= $piece;
                $buffer .= $piece;
                if ($this->consumeChatCompletionsStreamBuffer($buffer, $chunks)) {
                    break;
                }
            }

            if (trim($buffer) !== '') {
                $this->consumeChatCompletionsStreamBuffer($buffer, $chunks, true);
            }
        } finally {
            $response->close();
        }

        if ($chunks === []) {
            $decoded = json_decode(trim($raw), true);

            return is_array($decoded) ? $decoded : [];
        }

        return $this->normalizeChatCompletionsStreamChunks($chunks);
    }

    /**
     * @param  list<array<string,mixed>>  $chunks
     */
    private function consumeChatCompletionsStreamBuffer(string &$buffer, array &$chunks, bool $flush = false): bool
    {
        while (($position = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $position);
            $buffer = substr($buffer, $position + 1);

            if ($this->consumeChatCompletionsStreamLine($line, $chunks)) {
                return true;
            }
        }

        if ($flush && trim($buffer) !== '') {
            $line = $buffer;
            $buffer = '';

            return $this->consumeChatCompletionsStreamLine($line, $chunks);
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $chunks
     */
    private function consumeChatCompletionsStreamLine(string $line, array &$chunks): bool
    {
        $line = trim($line);
        if (! str_starts_with($line, 'data:')) {
            return false;
        }

        $payload = trim(substr($line, 5));
        if ($payload === '') {
            return false;
        }
        if ($payload === '[DONE]') {
            return true;
        }

        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            return false;
        }

        $chunks[] = $decoded;

        return $this->chatCompletionsStreamChunkIsFinished($decoded);
    }

    /**
     * @param  array<string,mixed>  $chunk
     */
    private function chatCompletionsStreamChunkIsFinished(array $chunk): bool
    {
        foreach ((array) ($chunk['choices'] ?? []) as $choice) {
            if (! is_array($choice) || ! array_key_exists('finish_reason', $choice)) {
                continue;
            }

            $finishReason = $choice['finish_reason'];
            if ($finishReason !== null && trim((string) $finishReason) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $chunks
     * @return array<string,mixed>
     */
    private function normalizeChatCompletionsStreamChunks(array $chunks): array
    {
        $content = '';
        $role = 'assistant';
        $finishReason = null;
        $usage = [];
        $searchResults = [];
        $base = [
            'id' => '',
            'object' => 'chat.completion',
            'created' => null,
            'model' => '',
        ];

        foreach ($chunks as $chunk) {
            foreach (['id', 'object', 'created', 'model'] as $key) {
                if (($base[$key] === '' || $base[$key] === null) && array_key_exists($key, $chunk)) {
                    $base[$key] = $chunk[$key];
                }
            }

            if (isset($chunk['usage']) && is_array($chunk['usage'])) {
                $usage = $chunk['usage'];
            }

            foreach ((array) ($chunk['search_results'] ?? []) as $source) {
                if (is_array($source)) {
                    $searchResults[] = $source;
                }
            }

            if (($chunk['delta_tag'] ?? '') === 'search_status') {
                continue;
            }

            foreach ((array) ($chunk['choices'] ?? []) as $choice) {
                if (! is_array($choice)) {
                    continue;
                }

                $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];
                $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
                $role = (string) ($delta['role'] ?? $message['role'] ?? $role);
                $piece = $delta['content'] ?? $message['content'] ?? '';
                if (is_string($piece)) {
                    $content .= $piece;
                }
                if (array_key_exists('finish_reason', $choice)) {
                    $finishReason = $choice['finish_reason'];
                }
            }
        }

        $data = $base;
        $data['choices'] = [
            [
                'index' => 0,
                'message' => [
                    'role' => $role,
                    'content' => $content,
                ],
                'finish_reason' => $finishReason,
            ],
        ];

        if ($usage !== []) {
            $data['usage'] = $usage;
        }
        if ($searchResults !== []) {
            $data['search_results'] = collect($searchResults)
                ->unique(static fn (array $source): string => trim((string) ($source['url'] ?? '')).'|'.trim((string) ($source['title'] ?? '')))
                ->values()
                ->all();
        }

        return $data;
    }

    private function dashScopeGenerationUrl(string $baseUrl, string $model): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        if (str_ends_with($baseUrl, '/services/aigc/text-generation/generation')
            || str_ends_with($baseUrl, '/services/aigc/multimodal-generation/generation')) {
            return $baseUrl;
        }
        if (str_ends_with($baseUrl, '/compatible-mode/v1')) {
            $baseUrl = substr($baseUrl, 0, -strlen('/compatible-mode/v1')).'/api/v1';
        }
        if (! str_ends_with($baseUrl, '/api/v1')) {
            $baseUrl .= '/api/v1';
        }

        $task = $this->usesDashScopeMultimodalGeneration($model)
            ? 'multimodal-generation'
            : 'text-generation';

        return $baseUrl.'/services/aigc/'.$task.'/generation';
    }

    private function usesDashScopeMultimodalGeneration(string $model): bool
    {
        $model = strtolower(trim($model));

        return str_starts_with($model, 'qwen3.7')
            || str_contains($model, 'qwen-vl')
            || str_contains($model, 'qwen2-vl')
            || str_contains($model, 'qwen2.5-vl')
            || str_contains($model, 'qwen-omni')
            || str_contains($model, 'qwen-audio');
    }

    private function chatCompletionsUrl(string $baseUrl, string $platform): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $platform = $this->normalizePlatform($platform);
        if ($platform === BrandDiagnosisPlatform::QIANWEN && str_ends_with($baseUrl, '/api/v1')) {
            $baseUrl = substr($baseUrl, 0, -strlen('/api/v1')).'/compatible-mode/v1';
        }

        return str_ends_with($baseUrl, '/chat/completions')
            ? $baseUrl
            : $baseUrl.'/chat/completions';
    }

    private function buildBrandCoreTermsPrompt(string $brandName, string $brandProfile, int $count): string
    {
        return implode("\n", [
            '请根据下面的品牌介绍，提取 '.$count.' 个品牌诊断要使用的高频核心词。',
            '目标品牌：'.$brandName,
            '品牌介绍：'.$brandProfile,
            '提取目标：',
            '1. 核心词必须来自品牌介绍里的高频、高相关信息，不要凭空联想。',
            '2. 优先覆盖不同维度：行业/品类、地域、产品或服务、用户场景、服务对象、价值诉求。',
            '3. 核心词要适合直接组合成用户会搜索的问题，例如“核心词+哪家好”“核心词推荐”“核心词怎么选”。',
            '4. 不要把完整问题当核心词，不要输出“哪家好”“怎么选”“推荐吗”这类问题后缀。',
            '5. 默认不要把目标品牌名称、简称、公司简称原样作为核心词；除非它本身就是被广泛搜索的通用品类词。',
            '6. 每个核心词 2-14 个中文字符，尽量精简。',
            '7. 只输出 JSON，不要 Markdown，不要解释。',
            'JSON 格式：',
            '{"terms":["核心词1","核心词2","核心词3","核心词4","核心词5"]}',
        ]);
    }

    /**
     * @Name: buildQuestionPrompt
     *
     * @Description: 为品牌诊断生成无品牌名的泛类问题，固定三类业务维度并要求以认知资料中的行业词作为锚点。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-20 16:24:06
     *
     * @UpdateTime: 2026-11-20 16:24:06
     *
     * @param  list<string>  $brandCoreTerms
     *
     * @Return: string 品牌诊断问题生成提示词
     *
     * @Throws: 无
     */
    private function buildQuestionPrompt(string $brandName, int $candidateCount, int $finalCount, string $brandProfile, array $brandCoreTerms = []): string
    {
        $brandProfile = trim($brandProfile);
        if ($brandProfile === '') {
            $brandProfile = '品牌资料暂缺。当前仅确认目标品牌词为 '.$brandName.'，请围绕该品牌词可能对应的品类、地域、用户需求和选择比较场景生成自然搜索问题。';
        }
        $coreTerms = $this->normalizeBrandCoreTerms($brandCoreTerms, $brandName, 5);
        $coreTermsText = $coreTerms !== [] ? implode('、', $coreTerms) : '未提供，请先从品牌介绍中提炼。';

        return implode("\n", [
            '请根据品牌介绍和品牌核心词，生成 '.$candidateCount.' 个候选问题，供品牌自然可见度诊断筛选，最终保留 '.$finalCount.' 个。',
            '目标品牌：'.$brandName,
            '品牌介绍：'.$brandProfile,
            '品牌核心词：'.$coreTermsText,
            '目标是测量用户没有指定品牌时，平台是否会自然提及目标品牌。问题必须围绕品牌介绍中的真实行业、品类、地域或业务，不得扩写无证据的资质、客户或市场地位。',
            '只生成以下三个维度，并尽量均衡覆盖：区域榜单、行业实力、业务匹配。',
            '区域榜单：用户在特定地区寻找该行业或品类的候选品牌、公司或服务方。',
            '行业实力：用户比较该行业或品类中哪些品牌值得信任、口碑较好或更专业。',
            '业务匹配：用户描述真实业务需求，寻找适合承接该类需求的品牌或服务方。',
            '每条问题都必须包含“品牌核心词”中的一个行业或品类锚点；区域维度可以在资料包含地域时使用该地域。',
            '所有问题都不得包含目标品牌名称、简称、商标、竞品名称，也不得把品牌名改写成问题。',
            '品牌诊断问“找谁或选哪个品牌”，不问产品参数、价格、型号、续航、维修、清洗、保养、使用感受等产品细节。',
            '问题必须是普通用户可以直接输入的检索型表达，避免“怎么办、如何解决、为什么”一类泛咨询。',
            '其中 2-3 条应是 8-18 字的短搜索句，其他问题通常为 15-40 字；不为凑字数添加无关限定。',
            '每项必须包含 question、core_term、dimension。core_term 必须从给定品牌核心词中选择，dimension 只能是区域榜单、行业实力、业务匹配。',
            '不要再次联网检索；只根据上方已核验的品牌介绍生成。只输出 JSON，不要 Markdown 或解释。',
            '{"questions":[{"question":"问题文本","core_term":"品牌核心词","dimension":"区域榜单|行业实力|业务匹配"}]}',
        ]);
    }

    /**
     * @Name: buildSupplementalQuestionPrompt
     *
     * @Description: 根据当前问题集的数量、维度缺口和短搜索句缺口生成定向补充问题，避免重写已确认候选。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-20 16:24:06
     *
     * @UpdateTime: 2026-11-20 16:24:06
     *
     * @param: string $brandName 目标品牌名称
     *
     * @param: int $supplementalCount 需要补充的问题数
     *
     * @param: int $targetCount 目标问题总数
     *
     * @param: string $brandProfile 已核验的品牌资料
     *
     * @param: list<string> $coreTerms 品牌行业与品类锚点
     *
     * @param: list<array{question:string,type:string,core_term:string}> $existingQuestions 当前已保留的问题
     *
     * @Return: string 定向补题提示词
     *
     * @Throws: 无
     */
    private function buildSupplementalQuestionPrompt(string $brandName, int $supplementalCount, int $targetCount, string $brandProfile, array $coreTerms, array $existingQuestions): string
    {
        $dimensionCounts = collect($existingQuestions)->countBy('type');
        $requiredPerDimension = intdiv($targetCount, 3);
        $dimensionGaps = collect(['区域榜单', '行业实力', '业务匹配'])
            ->map(static fn (string $dimension): string => $dimension.' '.max(0, $requiredPerDimension - (int) ($dimensionCounts[$dimension] ?? 0)).' 条')
            ->implode('；');
        $existingText = collect($existingQuestions)
            ->map(static fn (array $item): string => '- '.$item['question'].'（'.$item['type'].'）')
            ->implode("\n");

        return implode("\n", [
            '请只补充 '.$supplementalCount.' 条新的品牌诊断问题，不要修改或复述已有问题。',
            '目标品牌：'.$brandName,
            '品牌介绍：'.$brandProfile,
            '品牌核心词：'.implode('、', $coreTerms),
            '已有问题：'.($existingText !== '' ? "\n".$existingText : '无'),
            '当前维度缺口参考：'.$dimensionGaps,
            '只生成区域榜单、行业实力、业务匹配三类问题。问题不出现目标品牌或竞品名称，必须包含一个给定行业/品类锚点，排除价格、参数、型号、维修、清洗、保养和泛咨询问题。',
            '如缺少短搜索句，优先补充自然简短的检索表达；不得用同义改写重复已有问题。',
            '每项包含 question、core_term、dimension；dimension 只能是区域榜单、行业实力、业务匹配。只输出 JSON。',
            '{"questions":[{"question":"问题文本","core_term":"品牌核心词","dimension":"区域榜单|行业实力|业务匹配"}]}',
        ]);
    }

    /**
     * @Name: buildBrandProfileWebSearchPrompt
     *
     * @Description: 构造品牌资料联网检索提示词，并将用户上下文标记为待核验线索。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-09 15:06:50
     *
     * @UpdateTime: 2026-11-23 14:53:16
     *
     * @Param: string $brandName 目标品牌名称
     *
     * @Param: array<string,mixed> $context 用户补充的品牌上下文
     *
     * @Return: string 用于联网搜索的提示词
     *
     * @Throws: 无
     */
    private function buildBrandProfileWebSearchPrompt(string $brandName, array $context = []): string
    {
        $contextLines = [];
        $websiteUrl = trim((string) ($context['website_url'] ?? ''));
        $brandProfile = trim((string) ($context['brand_profile'] ?? ''));
        $brandTerms = collect((array) ($context['brand_terms'] ?? []))
            ->map(static fn (mixed $term): string => trim((string) $term))
            ->filter(static fn (string $term): bool => $term !== '')
            ->values()
            ->all();
        $competitors = collect((array) ($context['competitors'] ?? []))
            ->map(static function (mixed $competitor): string {
                if (! is_array($competitor)) {
                    return '';
                }

                $name = trim((string) ($competitor['name'] ?? ''));
                $terms = collect((array) ($competitor['terms'] ?? []))
                    ->map(static fn (mixed $term): string => trim((string) $term))
                    ->filter(static fn (string $term): bool => $term !== '')
                    ->values()
                    ->all();

                return $name === '' ? '' : $name.($terms !== [] ? '（相关词：'.implode('、', $terms).'）' : '');
            })
            ->filter(static fn (string $competitor): bool => $competitor !== '')
            ->values()
            ->all();

        if ($websiteUrl !== '') {
            $contextLines[] = '官网地址：'.$websiteUrl;
        }
        if ($brandProfile !== '') {
            $contextLines[] = '用户补充简介：'.$brandProfile;
        }
        if ($brandTerms !== []) {
            $contextLines[] = '品牌词：'.implode('、', $brandTerms);
        }
        if ($competitors !== []) {
            $contextLines[] = '竞品和竞品词：'.implode('；', $competitors);
        }

        $prompt = [
            $brandName.'的品牌介绍',
            '请使用联网搜索检索目标品牌，并生成品牌介绍。',
            '目标品牌：'.$brandName,
        ];
        if ($contextLines !== []) {
            $prompt[] = '用户补充的品牌资料（只作为检索线索，须通过公开信息核验，不得直接当作事实）：';
            $prompt = [...$prompt, ...$contextLines];
        }
        $prompt = [
            ...$prompt,
            '要求：',
            '1. 基于可检索到的信息总结品牌介绍。',
            '2. 提取行业、品牌类型、服务对象、地域、核心业务、典型场景、竞品方向。',
            '3. 识别品牌官方网站；只有公开来源能够核验时才填写，不要猜测或使用目录站、社交平台链接。无法确认时 official_website 留空。',
            '4. 如果检索不到有效信息，明确返回 found=false。',
            '5. 用户补充资料与公开来源不一致时，以可核验的公开来源为准。',
            '6. 只返回 JSON，不要 Markdown。',
            'JSON 格式：',
            '{"found":true,"summary":"品牌介绍","official_website":"https://example.com","industry":"行业","brand_type":"品牌类型","audience":"服务对象","region":"地域","business":"核心业务","scenarios":["场景"],"competitors":["竞品方向"]}',
            '如果无法检索到有效资料，请返回：',
            '{"found":false,"summary":"","official_website":"","reason":"无法检索到有效品牌资料"}',
        ];

        return implode("\n", $prompt);
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
                    'answer' => $this->cleanExternalText($looseAnswer !== '' ? $looseAnswer : trim($text)),
                    'brand_mentions' => $looseMentions,
                ];
            }

            return [
                'answer' => $this->cleanExternalText(trim($text)),
                'brand_mentions' => [],
            ];
        }

        $answer = trim((string) ($decoded['answer'] ?? ''));
        if ($answer === '') {
            $answer = trim((string) Arr::get($decoded, 'content', ''));
        }

        $mentions = $this->normalizeParsedBrandMentions((array) ($decoded['brand_mentions'] ?? []));

        return [
            'answer' => $this->cleanExternalText($answer !== '' ? $answer : trim($text)),
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
                $brand = $this->cleanExternalText((string) ($item['brand'] ?? $item['brand_name'] ?? ''));

                return [
                    'brand' => $brand,
                    'mention_count' => max(1, (int) ($item['mention_count'] ?? 1)),
                    'mention_rank' => max(0, (int) ($item['mention_rank'] ?? 0)),
                    'sentiment' => $this->normalizeSentiment((string) ($item['sentiment'] ?? 'neutral')),
                    'evidence' => $this->cleanExternalText((string) ($item['evidence'] ?? '')),
                    'source_count' => max(0, (int) ($item['source_count'] ?? 0)),
                    'meta' => $this->cleanExternalValue($item),
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
     * @return list<array{question:string,type:string,core_term:string}>
     */
    private function parseQuestions(string $text, string $platform = BrandDiagnosisPlatform::DOUBAO): array
    {
        $json = trim($text);
        if (preg_match('/```(?:json)?\s*(.*?)```/is', $json, $matches)) {
            $json = trim((string) $matches[1]);
        }

        /** @var mixed $decoded */
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            throw new RuntimeException($this->platformLabel($platform).'品牌诊断问题生成返回格式错误。');
        }

        $items = $decoded;
        if (! array_is_list($items) && isset($items['questions']) && is_array($items['questions'])) {
            $items = $items['questions'];
        }

        return collect($items)
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->map(function (array $item): array {
                $question = trim((string) ($item['question'] ?? ''));
                $labeler = app(BrandDiagnosisQuestionLabeler::class);

                return [
                    'question' => $question,
                    'core_term' => $labeler->coreTerm($question, (string) ($item['core_term'] ?? $item['core_keyword'] ?? $item['keyword'] ?? '')),
                    'type' => $labeler->questionType($question, (string) ($item['dimension'] ?? $item['type'] ?? $item['question_type'] ?? '')),
                ];
            })
            ->filter(static fn (array $item): bool => $item['question'] !== '')
            ->unique(static fn (array $item): string => $item['question'])
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function parseBrandCoreTerms(string $text, string $brandName, int $count, string $platform = BrandDiagnosisPlatform::DOUBAO): array
    {
        $json = trim($text);
        if (preg_match('/```(?:json)?\s*(.*?)```/is', $json, $matches)) {
            $json = trim((string) $matches[1]);
        }

        /** @var mixed $decoded */
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            throw new RuntimeException($this->platformLabel($platform).'品牌诊断核心词提取返回格式错误。');
        }

        $items = $decoded;
        if (! array_is_list($items)) {
            $items = (array) ($decoded['terms'] ?? $decoded['keywords'] ?? $decoded['core_terms'] ?? []);
        }

        return $this->normalizeBrandCoreTerms($items, $brandName, $count);
    }

    /**
     * @param  array<int|string,mixed>  $terms
     * @return list<string>
     */
    private function normalizeBrandCoreTerms(array $terms, string $brandName, int $count): array
    {
        $normalizedBrand = $this->normalizedSearchText($brandName);

        return collect($terms)
            ->map(function (mixed $item): string {
                if (is_array($item)) {
                    $item = $item['term'] ?? $item['keyword'] ?? $item['core_term'] ?? $item['name'] ?? '';
                }

                $term = trim((string) $item);
                $term = preg_replace('/^[\s\d\.\)、\]】\->]+/u', '', $term) ?? $term;
                $term = preg_replace('/[\s，。！？?；;：:、]+$/u', '', $term) ?? $term;
                $term = preg_replace('/\s+/u', '', $term) ?? $term;

                return trim($term);
            })
            ->filter(function (string $term) use ($normalizedBrand): bool {
                if ($term === '') {
                    return false;
                }

                $length = mb_strlen($term, 'UTF-8');
                if ($length < 2 || $length > 18) {
                    return false;
                }

                if ($this->looksLikeQuestion($term)) {
                    return false;
                }

                $normalizedTerm = $this->normalizedSearchText($term);

                return $normalizedTerm !== '' && $normalizedTerm !== $normalizedBrand;
            })
            ->unique(fn (string $term): string => $this->normalizedSearchText($term))
            ->take(max(1, $count))
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $coreTerms
     * @return list<string>
     */
    private function ensureBrandSubjectCoreTerm(array $coreTerms, string $brandName, int $count): array
    {
        $subject = $this->brandSubjectCoreTerm($brandName);
        if ($subject === '') {
            return array_slice($coreTerms, 0, max(1, $count));
        }

        $subjectKey = $this->normalizedSearchText($subject);
        $subjectBaseKey = $this->normalizedSearchText($this->baseSearchSubject($subject));
        foreach ($coreTerms as $coreTerm) {
            $termKey = $this->normalizedSearchText($coreTerm);
            if ($termKey === '' || $subjectKey === '') {
                continue;
            }

            if ($termKey === $subjectKey
                || str_contains($termKey, $subjectKey)
                || str_contains($subjectKey, $termKey)
                || ($subjectBaseKey !== '' && str_contains($termKey, $subjectBaseKey))) {
                return array_slice($coreTerms, 0, max(1, $count));
            }
        }

        return collect([$subject])
            ->concat($coreTerms)
            ->unique(fn (string $term): string => $this->normalizedSearchText($term))
            ->take(max(1, $count))
            ->values()
            ->all();
    }

    /**
     * @param  list<array{question:string,type:string,core_term?:string}>  $questions
     * @param  list<string>  $coreTerms
     * @return list<array{question:string,type:string,core_term:string}>
     */
    private function filteredQuestionSet(array $questions, string $brandName, array $coreTerms, int $count, bool $allowTemplateFallback = true): array
    {
        $labeler = app(BrandDiagnosisQuestionLabeler::class);
        $seen = [];
        $filtered = [];

        foreach ($questions as $item) {
            $question = trim((string) ($item['question'] ?? ''));
            if (! $this->isUsableGeneratedQuestion($question, $brandName, $coreTerms)) {
                continue;
            }

            $key = $this->normalizedSearchText($question);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $filtered[] = [
                'question' => $question,
                'core_term' => $this->questionCoreTerm($question, (string) ($item['core_term'] ?? ''), $coreTerms),
                'type' => $this->normalizeBrandQuestionDimension(
                    $question,
                    $labeler->questionType($question, (string) ($item['type'] ?? ''))
                ),
            ];
        }

        if (! $allowTemplateFallback) {
            return $filtered;
        }

        $selected = $this->balanceQuestionSet($filtered, $count);
        $selectedKeys = collect($selected)
            ->map(static fn (array $item): string => $item['question'])
            ->all();
        $seen = array_fill_keys(array_map(fn (string $question): string => $this->normalizedSearchText($question), $selectedKeys), true);
        $selected = $this->ensureShortSearchQuestions($selected, $coreTerms, $brandName, $count, $seen);
        $templateQuestions = $this->templateQuestionsFromCoreTerms($coreTerms, $brandName, $count, array_keys($seen));
        $combined = collect($selected)
            ->concat($templateQuestions)
            ->concat($filtered)
            ->unique(fn (array $item): string => $this->normalizedSearchText((string) $item['question']))
            ->values()
            ->all();

        return $this->ensureShortSearchQuestions(
            $this->balanceQuestionSet($combined, $count),
            $coreTerms,
            $brandName,
            $count,
            $seen
        );
    }

    /**
     * @param  list<array{question:string,type:string,core_term:string}>  $questions
     * @return list<array{question:string,type:string,core_term:string}>
     */
    private function balanceQuestionSet(array $questions, int $count): array
    {
        $dimensions = ['区域榜单', '行业实力', '业务匹配'];
        $baseCount = intdiv($count, count($dimensions));
        $remainder = $count % count($dimensions);
        $selected = [];
        $selectedKeys = [];

        foreach ($dimensions as $index => $dimension) {
            $targetCount = $baseCount + ($index < $remainder ? 1 : 0);
            foreach ($questions as $question) {
                if ($question['type'] !== $dimension || count(array_filter($selected, static fn (array $item): bool => $item['type'] === $dimension)) >= $targetCount) {
                    continue;
                }

                $key = $this->normalizedSearchText((string) $question['question']);
                if ($key === '' || isset($selectedKeys[$key])) {
                    continue;
                }
                $selectedKeys[$key] = true;
                $selected[] = $question;
            }
        }

        foreach ($questions as $question) {
            if (count($selected) >= $count) {
                break;
            }

            $key = $this->normalizedSearchText((string) $question['question']);
            if ($key === '' || isset($selectedKeys[$key])) {
                continue;
            }
            $selectedKeys[$key] = true;
            $selected[] = $question;
        }

        return array_slice($selected, 0, $count);
    }

    /**
     * @param  list<array{question:string,type:string,core_term:string}>  $questions
     */
    private function questionPoolNeedsSupplement(array $questions, int $count): bool
    {
        if (count($questions) < $count || $this->shortSearchQuestionCount($questions) < $this->targetShortSearchQuestionCount($count)) {
            return true;
        }

        $requiredPerDimension = intdiv($count, 3);
        $dimensionCounts = collect($questions)->countBy('type');

        foreach (['区域榜单', '行业实力', '业务匹配'] as $dimension) {
            if ((int) ($dimensionCounts[$dimension] ?? 0) < $requiredPerDimension) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{question:string,type:string,core_term:string}>  $questions
     */
    private function supplementalQuestionCount(array $questions, int $count): int
    {
        $missingCount = max(0, $count - count($questions));
        $requiredPerDimension = intdiv($count, 3);
        $dimensionCounts = collect($questions)->countBy('type');
        $missingDimensions = collect(['区域榜单', '行业实力', '业务匹配'])
            ->sum(static fn (string $dimension): int => max(0, $requiredPerDimension - (int) ($dimensionCounts[$dimension] ?? 0)));
        $missingShortQuestions = max(0, $this->targetShortSearchQuestionCount($count) - $this->shortSearchQuestionCount($questions));

        return max(1, $missingCount, $missingDimensions, $missingShortQuestions);
    }

    private function normalizeBrandQuestionDimension(string $question, string $dimension): string
    {
        if (in_array($dimension, ['区域榜单', '行业实力', '业务匹配'], true)) {
            return $dimension;
        }

        if (preg_match('/(本地|附近|地区|区域|全国|国内|城市|省内|十大|排行|排名|有哪些|哪几家)/u', $question) === 1) {
            return '区域榜单';
        }
        if (preg_match('/(需求|适合|合作|项目|方案|业务|预算|上市|推广|承接)/u', $question) === 1) {
            return '业务匹配';
        }

        return '行业实力';
    }

    /**
     * @param  list<array{question:string,type:string,core_term:string}>  $questions
     * @param  list<string>  $coreTerms
     * @param  array<string,bool>  $seen
     * @return list<array{question:string,type:string,core_term:string}>
     */
    private function ensureShortSearchQuestions(array $questions, array $coreTerms, string $brandName, int $count, array &$seen): array
    {
        $targetShortCount = $this->targetShortSearchQuestionCount($count);
        if ($targetShortCount === 0 || $this->shortSearchQuestionCount($questions) >= $targetShortCount) {
            return array_slice($questions, 0, $count);
        }

        foreach ($this->templateQuestionsFromCoreTerms($coreTerms, $brandName, $count, array_keys($seen)) as $item) {
            if ($this->shortSearchQuestionCount($questions) >= $targetShortCount) {
                break;
            }

            if (! $this->isShortSearchQuestion($item['question'])) {
                continue;
            }

            $key = $this->normalizedSearchText($item['question']);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            if (count($questions) < $count) {
                $seen[$key] = true;
                $questions[] = [
                    ...$item,
                    'type' => $this->normalizeBrandQuestionDimension((string) $item['question'], (string) $item['type']),
                ];

                continue;
            }

            $replaceIndex = $this->lastNonShortQuestionIndex($questions);
            if ($replaceIndex === null) {
                break;
            }

            $removedKey = $this->normalizedSearchText($questions[$replaceIndex]['question']);
            if ($removedKey !== '') {
                unset($seen[$removedKey]);
            }
            $seen[$key] = true;
            $questions[$replaceIndex] = [
                ...$item,
                'type' => $this->normalizeBrandQuestionDimension((string) $item['question'], (string) $item['type']),
            ];
        }

        return array_slice(array_values($questions), 0, $count);
    }

    private function targetShortSearchQuestionCount(int $count): int
    {
        if ($count < 4) {
            return 0;
        }

        return min(3, max(2, intdiv($count, 3)));
    }

    /**
     * @param  list<array{question:string,type:string,core_term:string}>  $questions
     */
    private function shortSearchQuestionCount(array $questions): int
    {
        return collect($questions)
            ->filter(fn (array $item): bool => $this->isShortSearchQuestion((string) $item['question']))
            ->count();
    }

    /**
     * @param  list<array{question:string,type:string,core_term:string}>  $questions
     */
    private function lastNonShortQuestionIndex(array $questions): ?int
    {
        for ($index = count($questions) - 1; $index >= 0; $index--) {
            if (! $this->isShortSearchQuestion((string) $questions[$index]['question'])) {
                return $index;
            }
        }

        return null;
    }

    private function isShortSearchQuestion(string $question): bool
    {
        $cleanQuestion = preg_replace('/[\s，。！？、?]+/u', '', trim($question)) ?? trim($question);
        $length = mb_strlen($cleanQuestion, 'UTF-8');
        if ($length < 4 || $length > 18) {
            return false;
        }

        return preg_match('/(哪家|推荐|最好|好吃|好用|口碑|怎么选|靠谱吗|靠谱)/u', $question) === 1;
    }

    /**
     * @param  list<string>  $coreTerms
     */
    private function isUsableGeneratedQuestion(string $question, string $brandName, array $coreTerms): bool
    {
        $question = trim($question);
        $length = mb_strlen($question, 'UTF-8');
        if ($length < 4 || $length > 45) {
            return false;
        }

        if (! $this->isNaturalQuestion($question, $brandName)) {
            return false;
        }

        $normalizedQuestion = $this->normalizedSearchText($question);
        if ($normalizedQuestion === '' || $normalizedQuestion === $this->normalizedSearchText($brandName)) {
            return false;
        }

        foreach ($coreTerms as $coreTerm) {
            if ($normalizedQuestion === $this->normalizedSearchText($coreTerm)) {
                return false;
            }
        }

        if (! $this->questionHasCoreTermAnchor($question, $coreTerms)) {
            return false;
        }

        if (preg_match('/(多少钱|价格|价位|参数|型号|功率|续航|充电|材质|重量|尺寸|配件|维修|保修|售后|寿命|清洗|保养|使用感受|怎么办|如何解决|为什么)/u', $question) === 1) {
            return false;
        }

        return preg_match('/(哪家|哪些|推荐|排行|排名|口碑|评价|实力|专业|靠谱|选择|选哪|服务公司|服务商|机构|团队|找|合作|承接|方案|业务需求|擅长|品牌)/u', $question) === 1;
    }

    /**
     * @param  list<string>  $coreTerms
     */
    private function questionHasCoreTermAnchor(string $question, array $coreTerms): bool
    {
        $normalizedQuestion = $this->normalizedSearchText($question);
        $suffixes = ['服务公司', '服务商', '公司', '企业', '团队', '机构', '服务', '品牌', '产品', '门店', '餐厅', '行业', '领域', '品类', '店'];

        foreach ($coreTerms as $coreTerm) {
            $normalizedTerm = $this->normalizedSearchText($coreTerm);
            if ($normalizedTerm === '' || mb_strlen($normalizedTerm, 'UTF-8') < 2) {
                continue;
            }
            if (str_contains($normalizedQuestion, $normalizedTerm)) {
                return true;
            }

            foreach ($suffixes as $suffix) {
                $normalizedSuffix = $this->normalizedSearchText($suffix);
                if (str_ends_with($normalizedTerm, $normalizedSuffix)) {
                    $anchor = mb_substr($normalizedTerm, 0, mb_strlen($normalizedTerm, 'UTF-8') - mb_strlen($normalizedSuffix, 'UTF-8'), 'UTF-8');
                    if (mb_strlen($anchor, 'UTF-8') >= 2 && str_contains($normalizedQuestion, $anchor)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $coreTerms
     */
    private function questionCoreTerm(string $question, string $storedCoreTerm, array $coreTerms): string
    {
        $storedKey = $this->normalizedSearchText($storedCoreTerm);
        foreach ($coreTerms as $coreTerm) {
            if ($storedKey !== '' && $storedKey === $this->normalizedSearchText($coreTerm)) {
                return $coreTerm;
            }

            if (mb_stripos($question, $coreTerm, 0, 'UTF-8') !== false) {
                return $coreTerm;
            }
        }

        $labeler = app(BrandDiagnosisQuestionLabeler::class);
        $fallback = $labeler->coreTerm($question, $storedCoreTerm);

        return $fallback !== '' ? $fallback : (string) ($coreTerms[0] ?? '');
    }

    /**
     * @param  list<string>  $coreTerms
     * @param  list<string>  $existingKeys
     * @return list<array{question:string,type:string,core_term:string}>
     */
    private function templateQuestionsFromCoreTerms(array $coreTerms, string $brandName, int $count, array $existingKeys): array
    {
        $patterns = [
            ['%s哪家好', '选择'],
            ['%s推荐', '推荐'],
            ['%s怎么选', '选择'],
            ['%s口碑怎么样', '口碑'],
            ['%s靠谱吗', '可信判断'],
        ];
        $seen = array_fill_keys($existingKeys, true);
        $questions = [];

        foreach ($this->brandSubjectTemplateQuestions($brandName, $coreTerms) as $item) {
            if (! $this->appendTemplateQuestion($questions, $seen, $item, $brandName, $coreTerms, $count)) {
                continue;
            }

            if (count($questions) >= $count) {
                return $questions;
            }
        }

        for ($round = 0; $round < max(1, $count); $round++) {
            foreach ($coreTerms as $index => $coreTerm) {
                [$pattern, $type] = $patterns[($round + $index) % count($patterns)];
                $question = sprintf($pattern, $coreTerm);
                $this->appendTemplateQuestion($questions, $seen, [
                    'question' => $question,
                    'core_term' => $coreTerm,
                    'type' => $type,
                ], $brandName, $coreTerms, $count);

                if (count($questions) >= $count) {
                    return $questions;
                }
            }
        }

        return $questions;
    }

    /**
     * @param  list<string>  $coreTerms
     * @return list<array{question:string,type:string,core_term:string}>
     */
    private function brandSubjectTemplateQuestions(string $brandName, array $coreTerms): array
    {
        $subject = $this->brandSubjectCoreTerm($brandName);
        if ($subject === '') {
            return [];
        }

        $coreTerm = $this->matchingCoreTerm($subject, $coreTerms) ?: $subject;
        $region = $this->brandSearchRegion($brandName, $subject);
        $questions = [];

        if ($region !== '' && $this->looksFoodSearchSubject($subject)) {
            $questions[] = [
                'question' => $region.'哪家'.$subject.'最好吃',
                'core_term' => $coreTerm,
                'type' => '美食选择',
            ];
        }

        if ($region !== '') {
            $questions[] = [
                'question' => $region.$subject.'哪家好',
                'core_term' => $coreTerm,
                'type' => '选择',
            ];
        }

        $questions[] = [
            'question' => $subject.'哪家好',
            'core_term' => $coreTerm,
            'type' => '选择',
        ];
        $questions[] = [
            'question' => $subject.'推荐',
            'core_term' => $coreTerm,
            'type' => '推荐',
        ];

        return $questions;
    }

    /**
     * @param  list<array{question:string,type:string,core_term:string}>  $questions
     * @param  array<string,bool>  $seen
     * @param  array{question:string,type:string,core_term:string}  $item
     * @param  list<string>  $coreTerms
     */
    private function appendTemplateQuestion(array &$questions, array &$seen, array $item, string $brandName, array $coreTerms, int $count): bool
    {
        if (count($questions) >= $count) {
            return false;
        }

        $question = trim((string) $item['question']);
        if (! $this->isUsableGeneratedQuestion($question, $brandName, $coreTerms)) {
            return false;
        }

        $key = $this->normalizedSearchText($question);
        if ($key === '' || isset($seen[$key])) {
            return false;
        }

        $seen[$key] = true;
        $questions[] = [
            'question' => $question,
            'core_term' => (string) $item['core_term'],
            'type' => $this->normalizeBrandQuestionDimension($question, (string) $item['type']),
        ];

        return true;
    }

    /**
     * @param  list<string>  $coreTerms
     */
    private function matchingCoreTerm(string $subject, array $coreTerms): string
    {
        $subjectKey = $this->normalizedSearchText($subject);
        $subjectBaseKey = $this->normalizedSearchText($this->baseSearchSubject($subject));
        foreach ($coreTerms as $coreTerm) {
            $termKey = $this->normalizedSearchText($coreTerm);
            if ($termKey === '' || $subjectKey === '') {
                continue;
            }

            if ($termKey === $subjectKey
                || str_contains($termKey, $subjectKey)
                || str_contains($subjectKey, $termKey)
                || ($subjectBaseKey !== '' && str_contains($termKey, $subjectBaseKey))) {
                return $coreTerm;
            }
        }

        return '';
    }

    private function brandSubjectCoreTerm(string $brandName): string
    {
        $brandName = preg_replace('/[^\p{Han}A-Za-z0-9]/u', '', trim($brandName)) ?? trim($brandName);
        if ($brandName === '') {
            return '';
        }

        if (preg_match('/^(?<region>[\p{Han}]{2})(?<subject>[\p{Han}]{2,5})$/u', $brandName, $matches) === 1) {
            $subject = (string) ($matches['subject'] ?? '');
            if ($this->looksFoodSearchSubject($subject)) {
                return $this->withFoodPlaceSuffix($subject);
            }
        }

        if ($this->looksFoodSearchSubject($brandName)) {
            return $this->withFoodPlaceSuffix($brandName);
        }

        return '';
    }

    private function brandSearchRegion(string $brandName, string $subject): string
    {
        $brandName = preg_replace('/[^\p{Han}A-Za-z0-9]/u', '', trim($brandName)) ?? trim($brandName);
        $subjectBase = $this->baseSearchSubject($subject);
        if ($brandName === '' || $subjectBase === '') {
            return '';
        }

        if (str_ends_with($brandName, $subjectBase)) {
            $region = mb_substr($brandName, 0, max(0, mb_strlen($brandName, 'UTF-8') - mb_strlen($subjectBase, 'UTF-8')), 'UTF-8');

            return mb_strlen($region, 'UTF-8') >= 2 && mb_strlen($region, 'UTF-8') <= 4 ? $region : '';
        }

        if (preg_match('/^(?<region>[\p{Han}]{2})(?<subject>[\p{Han}]{2,5})$/u', $brandName, $matches) === 1) {
            return (string) ($matches['region'] ?? '');
        }

        return '';
    }

    private function baseSearchSubject(string $subject): string
    {
        $subject = trim($subject);
        $subject = preg_replace('/(门店|旗舰店|店铺|餐厅|饭店|小店|店|馆|铺)$/u', '', $subject) ?? $subject;

        return trim($subject);
    }

    private function withFoodPlaceSuffix(string $subject): string
    {
        $subject = trim($subject);
        if ($subject === '' || preg_match('/(店|馆|铺|餐厅|饭店)$/u', $subject) === 1) {
            return $subject;
        }

        return $subject.'店';
    }

    private function looksFoodSearchSubject(string $value): bool
    {
        return preg_match('/(煊饼|馅饼|饼|面|粉|糕|包|粥|饭|菜|汤|串|小吃|美食|餐厅|饭店|火锅|奶茶|咖啡|甜品|烤|烧|煎|炸|卤|鸡|鸭|鱼|虾|牛肉|羊肉)/u', $value) === 1;
    }

    private function looksLikeQuestion(string $value): bool
    {
        return preg_match('/(哪家|推荐|怎么选|怎么样|靠谱吗|是什么|有哪些|好不好|可以吗|吗|\?|？)$/u', $value) === 1;
    }

    private function normalizedSearchText(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = preg_replace('/[\s，。！？?；;：:、,.!()（）【】\[\]《》"\'“”‘’]+/u', '', $value) ?? $value;

        return trim($value);
    }

    /**
     * @param  list<array{question:string,type:string,core_term?:string}>  $selected
     * @param  list<array{question:string,type:string,core_term?:string}>|list<array{question:string,type:string,core_term?:string,platform:string}>  $candidates
     * @return list<array{question:string,type:string,core_term:string}>
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
                'core_term' => (string) ($question['core_term'] ?? ''),
                'type' => (string) $question['type'],
            ])
            ->values();

        $fillerIndex = 0;
        $naturalQuestions = collect($selected)
            ->map(function (array $question) use ($brandName, $fillerQuestions, &$fillerIndex): array {
                if ($this->isNaturalQuestion((string) $question['question'], $brandName)) {
                    return [
                        'question' => (string) $question['question'],
                        'core_term' => (string) ($question['core_term'] ?? ''),
                        'type' => (string) $question['type'],
                    ];
                }

                $replacement = $fillerQuestions->get($fillerIndex);
                $fillerIndex++;

                return is_array($replacement)
                    ? $replacement
                    : [
                        'question' => (string) $question['question'],
                        'core_term' => (string) ($question['core_term'] ?? ''),
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
                'core_term' => (string) ($question['core_term'] ?? ''),
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
        return app(BrandEntityResolver::class)->containsBrandAlias($question, $brandName);
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
     * @param  array<string,mixed>  $data
     */
    private function extractText(array $data): string
    {
        $chatContent = Arr::get($data, 'choices.0.message.content');
        if (is_string($chatContent) && trim($chatContent) !== '') {
            return trim($chatContent);
        }
        if (is_array($chatContent)) {
            $texts = collect($chatContent)
                ->map(static function (mixed $item): string {
                    if (is_string($item)) {
                        return $item;
                    }
                    if (is_array($item)) {
                        return trim((string) ($item['text'] ?? ''));
                    }

                    return '';
                })
                ->filter(static fn (string $text): bool => trim($text) !== '')
                ->values()
                ->all();
            if ($texts !== []) {
                return trim(implode("\n\n", $texts));
            }
        }

        $dashScopeContent = Arr::get($data, 'output.choices.0.message.content');
        if (is_string($dashScopeContent) && trim($dashScopeContent) !== '') {
            return trim($dashScopeContent);
        }
        if (is_array($dashScopeContent)) {
            $texts = collect($dashScopeContent)
                ->map(static function (mixed $item): string {
                    if (is_string($item)) {
                        return $item;
                    }
                    if (is_array($item)) {
                        return trim((string) ($item['text'] ?? $item['content'] ?? ''));
                    }

                    return '';
                })
                ->filter(static fn (string $text): bool => trim($text) !== '')
                ->values()
                ->all();
            if ($texts !== []) {
                return trim(implode("\n\n", $texts));
            }
        }

        $dashScopeText = trim((string) Arr::get($data, 'output.text', ''));
        if ($dashScopeText !== '') {
            return $dashScopeText;
        }

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
        return BrandDiagnosisPlatform::normalize($platform);
    }

    private function platformLabel(string $platform): string
    {
        return BrandDiagnosisPlatform::label($platform);
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

        $this->collectSourcesFromArray($data, $sources);

        foreach ((array) ($data['output'] ?? []) as $output) {
            if (! is_array($output)) {
                continue;
            }

            $this->collectSourcesFromArray($output, $sources);
        }

        foreach ((array) ($data['choices'] ?? []) as $choice) {
            if (! is_array($choice)) {
                continue;
            }

            $this->collectSourcesFromArray($choice, $sources);
        }

        return collect($sources)
            ->filter(static fn (array $source): bool => trim((string) $source['url']) !== '')
            ->unique(static fn (array $source): string => (string) $source['url'])
            ->all();
    }

    /**
     * @Name: extractRetrievalMetadata
     *
     * @Description: 从平台原始响应中提取显式搜索工具轮次、查询词和引用数量；平台未返回查询词时保留空列表。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-20 16:43:39
     *
     * @UpdateTime: 2026-11-20 16:43:39
     *
     * @Param: array<string,mixed> $data 平台原始响应
     *
     * @Return: array{search_queries:list<string>,search_rounds:int,source_count:int} 可审计的检索摘要
     *
     * @Throws: 无
     */
    private function extractRetrievalMetadata(array $data): array
    {
        $searchCalls = [];
        $visit = function (array $node) use (&$visit, &$searchCalls): void {
            $toolName = strtolower(trim((string) (
                $node['name']
                ?? data_get($node, 'function.name', '')
                ?? $node['tool_name']
                ?? ''
            )));
            $type = strtolower(trim((string) ($node['type'] ?? '')));
            $isSearchCall = (str_contains($type, 'search') && (str_contains($type, 'call') || isset($node['action'])))
                || str_contains($toolName, 'search');

            if ($isSearchCall) {
                $queries = data_get($node, 'action.queries', $node['queries'] ?? data_get($node, 'action.query', $node['query'] ?? []));
                $queries = is_array($queries) ? $queries : [$queries];
                $searchCalls[] = collect($queries)
                    ->map(static function (mixed $query): string {
                        if (is_array($query)) {
                            $query = $query['query'] ?? $query['q'] ?? $query['search_query'] ?? '';
                        }

                        return is_scalar($query) ? trim((string) $query) : '';
                    })
                    ->filter(static fn (string $query): bool => $query !== '')
                    ->values()
                    ->all();
            }

            foreach ($node as $value) {
                if (is_array($value)) {
                    if (array_is_list($value)) {
                        foreach ($value as $item) {
                            if (is_array($item)) {
                                $visit($item);
                            }
                        }
                    } else {
                        $visit($value);
                    }
                }
            }
        };
        $visit($data);

        return [
            'search_queries' => collect($searchCalls)->flatten()->filter()->unique()->values()->all(),
            'search_rounds' => count($searchCalls),
            'source_count' => count($this->extractSources($data)),
        ];
    }

    /**
     * @param  array<string,mixed>  $node
     * @param  list<array{title:string,url:string,type:string,meta:array<string,mixed>}>  $sources
     */
    private function collectSourcesFromArray(array $node, array &$sources): void
    {
        $type = $this->cleanExternalText((string) ($node['type'] ?? $node['source_type'] ?? ''));
        $url = $this->cleanExternalText((string) ($node['url'] ?? $node['link'] ?? ''));
        $isKnownSource = in_array($type, ['url_citation', 'web_search_result', 'citation', 'search_result'], true);
        if ($url !== '' && ($isKnownSource || isset($node['title']) || isset($node['snippet']))) {
            $sources[] = [
                'title' => $this->cleanExternalText((string) ($node['title'] ?? $url)),
                'url' => $url,
                'type' => $type !== '' ? $type : 'web_search_result',
                'meta' => $this->cleanExternalValue($node),
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

    private function cleanExternalText(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            $value = $cleaned !== false ? $cleaned : mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        $withoutControls = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        return trim($withoutControls ?? $value);
    }

    private function cleanExternalValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->cleanExternalText($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $cleaned = [];
        foreach ($value as $key => $item) {
            $cleanKey = is_string($key) ? $this->cleanExternalText($key) : $key;
            $cleaned[$cleanKey] = $this->cleanExternalValue($item);
        }

        return $cleaned;
    }
}
