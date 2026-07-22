<?php

namespace App\Services\GeoFlow;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\AiModel;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KeywordQuestionVariant;
use App\Support\AiConfigurationScope;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use RuntimeException;
use Throwable;

class GeoQuestionVariantService
{
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly AiConfigurationScope $aiConfigurationScope
    ) {}

    /**
     * @return list<string>
     */
    public function generate(Keyword $keyword, KeywordLibrary $library, int $count): array
    {
        $count = max(1, min(20, $count));
        $candidateCount = $count * 2;
        $existingQuestions = $this->existingQuestionsForKeyword($keyword);
        $model = $this->resolveAiModel();
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($model->api_url ?? ''));
        if ($providerUrl === '') {
            throw new RuntimeException('AI model API URL is not configured.');
        }

        $apiKey = $this->apiKeyCrypto->decrypt((string) ($model->getRawOriginal('api_key') ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('AI model API key is not configured or cannot be decrypted.');
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) ($model->model_id ?? ''));
        $providerName = OpenAiRuntimeProvider::registerProvider('question_variants', $driver, $providerUrl, $apiKey);

        try {
            $agent = new MarkdownContentWriterAgent('Generate AI search question variants. Output only a JSON string array.');
            $response = $agent->prompt($this->buildPrompt($keyword, $library, $candidateCount, $count, $existingQuestions), [], $providerName, (string) ($model->model_id ?? ''));
            $rawContent = (string) ($response->text ?? '');
            $questions = $this->parseQuestions(OpenAiRuntimeProvider::normalizeGeneratedText($rawContent), $candidateCount);
        } catch (Throwable $exception) {
            throw new RuntimeException('AI question variant generation failed: '.OpenAiRuntimeProvider::normalizeApiException($exception, $providerUrl), 0, $exception);
        }

        $questions = $this->filterAndBackfillQuestions($questions, $keyword, $library, $count, $existingQuestions);
        if ($questions === []) {
            throw new RuntimeException('AI did not return usable question variants.');
        }

        return $questions;
    }

    /**
     * @return list<string>
     */
    public function parseQuestionsForTesting(string $output, int $limit): array
    {
        return $this->parseQuestions($output, $limit);
    }

    /**
     * @param  list<string>  $existingQuestions
     */
    private function buildPrompt(Keyword $keyword, KeywordLibrary $library, int $candidateCount, int $targetCount, array $existingQuestions): string
    {
        $termPackage = $this->profileTermPackage($keyword, $library);

        $lines = [
            'Generate '.$candidateCount.' Chinese AI search query candidates for AI search inclusion checks.',
            'Finally return the best '.$targetCount.' usable variants after removing duplicates, exact keywords, incomplete phrases, and overlong sentences.',
            'Return only a JSON string array. Do not include markdown, numbering, or explanations.',
            'Keyword: '.(string) $keyword->keyword,
            'Company/brand: '.(string) ($library->company_name ?? ''),
            'Domain keyword: '.(string) ($library->domain_keyword ?? ''),
            'Industry: '.(string) ($library->industry ?? ''),
            'Brand description: '.(string) ($library->brand_description ?? $library->description ?? ''),
            'Core term package extracted from brand description:',
            '- Business/capability terms: '.$this->termsLine($termPackage['business']),
            '- Audience terms: '.$this->termsLine($termPackage['audience']),
            '- Scenario terms: '.$this->termsLine($termPackage['scenario']),
            '- Pain-point terms: '.$this->termsLine($termPackage['pain']),
            '- Decision terms: '.$this->termsLine($termPackage['decision']),
            'Primary question dimension for this keyword: '.$termPackage['primary_dimension'].'. If only one variant is requested, prioritize this dimension instead of a generic recommendation pattern.',
            'Dimension rotation: generate from different dimensions such as audience fit, scenario usage, pain solution, decision comparison, and capability outcome. Do not let every keyword use the same "which one is good" pattern.',
            'Do not output the keyword itself as a standalone variant.',
            'Keyword intent guidance: Treat the keyword as the main search intent. Expand around its core phrases, user need, selection criteria, recommendation intent, or comparison intent. Do not drift into unrelated topics.',
            'Do not make every variant repeat the keyword verbatim. Some variants should express the same intent through industry terms, demand terms, service terms, recommendation terms, comparison terms, or scenario terms.',
            'For brand-name keywords, include about 2 variants with the brand name and about 2 variants without the brand name. The remaining variant may include the brand only if it sounds natural.',
            'Industry or demand variants should use service, recommendation, comparison, and scenario terms instead of forcing the brand name into every query.',
            'Brand mentions must be natural user questions, not stiff brand-plus-keyword phrases. For example, avoid "Brand Keyword"; prefer "Brand适合做Keyword吗？" only when a user would actually ask it.',
            'Query mix: when generating 5 items, include exactly 2 short keyword-style searches, 2 medium direct questions, and 1 scenario-based decision question. If the requested count is not 5, keep this mix proportionally.',
            'Short keyword-style searches: concise search phrases, usually 4-14 Chinese characters when possible; short searches do not need question marks.',
            'Medium direct questions: natural and direct questions around the keyword intent, usually 10-28 Chinese characters.',
            'Scenario-based decision question: include a concrete user scenario, need, or decision background, but avoid being overly long.',
            'Rules: variants should be realistic, varied, and likely to make Doubao, Qianwen, DeepSeek, or Wenxin mention relevant brands or solutions. Avoid marketing slogans and duplicate phrasing.',
        ];

        if ($existingQuestions !== []) {
            $lines[] = 'Existing variants to avoid: '.implode(' | ', array_slice($existingQuestions, 0, 20));
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function parseQuestions(string $output, int $limit): array
    {
        $limit = max(1, min(20, $limit));
        $decoded = json_decode(trim($output), true);
        $rawQuestions = is_array($decoded) ? $decoded : (preg_split('/\R/u', $output) ?: []);

        $seen = [];
        $questions = [];
        foreach ($rawQuestions as $rawQuestion) {
            $question = $this->normalizeQuestion((string) $rawQuestion);
            if ($question === '') {
                continue;
            }

            $dedupeKey = mb_strtolower($question, 'UTF-8');
            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;
            $questions[] = $question;
            if (count($questions) >= $limit) {
                break;
            }
        }

        return $questions;
    }

    /**
     * @return list<string>
     */
    private function existingQuestionsForKeyword(Keyword $keyword): array
    {
        $keywordId = (int) ($keyword->id ?? 0);
        if ($keywordId <= 0) {
            return [];
        }

        return KeywordQuestionVariant::query()
            ->where('keyword_id', $keywordId)
            ->orderByDesc('created_at')
            ->pluck('question')
            ->map(fn (mixed $question): string => $this->normalizeQuestion((string) $question))
            ->filter(static fn (string $question): bool => $question !== '')
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $questions
     * @param  list<string>  $existingQuestions
     * @return list<string>
     */
    private function filterAndBackfillQuestions(array $questions, Keyword $keyword, KeywordLibrary $library, int $targetCount, array $existingQuestions): array
    {
        $targetCount = max(1, min(20, $targetCount));
        $keywordText = $this->normalizeQuestion((string) ($keyword->keyword ?? ''));
        $companyName = $this->normalizeQuestion((string) ($library->company_name ?? ''));
        $profileTerms = $this->profileFilterTerms($keywordText, $library);
        $seenKeys = [];
        foreach ($existingQuestions as $existingQuestion) {
            $key = $this->dedupeKey($existingQuestion);
            if ($key !== '') {
                $seenKeys[$key] = true;
            }
        }

        $accepted = [];
        foreach ($questions as $question) {
            $this->acceptQuestion($accepted, $seenKeys, $question, $keywordText, $companyName, $profileTerms, $targetCount);
            if (count($accepted) >= $targetCount) {
                return $accepted;
            }
        }

        foreach ($this->templateQuestions($keywordText, $library) as $question) {
            $this->acceptQuestion($accepted, $seenKeys, $question, $keywordText, $companyName, $profileTerms, $targetCount);
            if (count($accepted) >= $targetCount) {
                break;
            }
        }

        return array_slice($accepted, 0, $targetCount);
    }

    /**
     * @param  list<string>  $accepted
     * @param  array<string,bool>  $seenKeys
     * @param  list<string>  $profileTerms
     */
    private function acceptQuestion(array &$accepted, array &$seenKeys, string $question, string $keywordText, string $companyName, array $profileTerms, int $targetCount): void
    {
        if (count($accepted) >= $targetCount || ! $this->isUsableQuestion($question, $keywordText, $companyName, $profileTerms)) {
            return;
        }

        $key = $this->dedupeKey($question);
        if ($key === '' || isset($seenKeys[$key]) || $this->hasNearDuplicate($key, array_keys($seenKeys))) {
            return;
        }

        $seenKeys[$key] = true;
        $accepted[] = $question;
    }

    /**
     * @param  list<string>  $profileTerms
     */
    private function isUsableQuestion(string $question, string $keywordText, string $companyName, array $profileTerms): bool
    {
        $question = $this->normalizeQuestion($question);
        if ($question === '') {
            return false;
        }

        $length = mb_strlen($question, 'UTF-8');
        if ($length > 80) {
            return false;
        }

        $questionKey = $this->dedupeKey($question);
        if ($questionKey === '' || ($keywordText !== '' && $questionKey === $this->dedupeKey($keywordText))) {
            return false;
        }

        if ($this->looksLikeStiffBrandKeyword($question, $keywordText, $companyName)) {
            return false;
        }

        return ! $this->looksIncomplete($question)
            && ! $this->looksLikeBareKeywordGenericQuestion($question, $keywordText, $profileTerms);
    }

    private function looksLikeStiffBrandKeyword(string $question, string $keywordText, string $companyName): bool
    {
        $brandKey = $this->dedupeKey($companyName);
        $keywordKey = $this->dedupeKey($keywordText);
        $questionKey = $this->dedupeKey($question);
        if ($brandKey === '' || $keywordKey === '' || $questionKey === '') {
            return false;
        }

        if (! str_contains($questionKey, $brandKey) || ! str_contains($questionKey, $keywordKey)) {
            return false;
        }

        return preg_match('/[？?]|(?:适合|怎么样|靠谱吗|靠不靠谱|好不好|值得|能不能|可以|是否|怎么|如何|哪些|哪个|哪家|为什么|是什么|对比|区别|场景|判断|选择|支持|提供)/u', $question) !== 1;
    }

    private function looksIncomplete(string $question): bool
    {
        $question = trim($question);
        if ($question === '') {
            return true;
        }

        if (preg_match('/[，,、：:；;（(]$/u', $question) === 1) {
            return true;
        }

        if (mb_strlen($question, 'UTF-8') >= 14 && preg_match('/(?:选|用|找|和|或|与|及|的|在|给|为)$/u', $question) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param  list<string>  $profileTerms
     */
    private function looksLikeBareKeywordGenericQuestion(string $question, string $keywordText, array $profileTerms): bool
    {
        $keywordKey = $this->dedupeKey($keywordText);
        $questionKey = $this->dedupeKey($question);
        if ($keywordKey === '' || $questionKey === '' || ! str_contains($questionKey, $keywordKey)) {
            return false;
        }

        foreach ($profileTerms as $term) {
            $termKey = $this->dedupeKey($term);
            if ($termKey === '' || $termKey === $keywordKey || str_contains($keywordKey, $termKey)) {
                continue;
            }

            if (str_contains($questionKey, $termKey)) {
                return false;
            }
        }

        return preg_match('/(?:哪家|哪个|哪个好|哪家好|哪家靠谱|怎么选|推荐|值得推荐|效果好|功能全|性价比高)/u', $question) === 1;
    }

    private function dedupeKey(string $question): string
    {
        $question = mb_strtolower($this->normalizeQuestion($question), 'UTF-8');
        $question = preg_replace('/[\s\p{P}\p{S}]+/u', '', $question) ?? $question;

        return trim($question);
    }

    /**
     * @param  list<string>  $existingKeys
     */
    private function hasNearDuplicate(string $candidateKey, array $existingKeys): bool
    {
        foreach ($existingKeys as $existingKey) {
            if ($existingKey === $candidateKey) {
                return true;
            }

            $score = $this->bigramSimilarity($candidateKey, (string) $existingKey);
            if ($score >= 0.88) {
                return true;
            }
        }

        return false;
    }

    private function bigramSimilarity(string $left, string $right): float
    {
        if ($left === '' || $right === '') {
            return 0.0;
        }

        $leftGrams = $this->bigrams($left);
        $rightGrams = $this->bigrams($right);
        if ($leftGrams === [] || $rightGrams === []) {
            return $left === $right ? 1.0 : 0.0;
        }

        $intersection = count(array_intersect($leftGrams, $rightGrams));
        $union = count(array_unique([...$leftGrams, ...$rightGrams]));

        return $union > 0 ? $intersection / $union : 0.0;
    }

    /**
     * @return list<string>
     */
    private function bigrams(string $text): array
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($chars) < 2) {
            return $chars;
        }

        $grams = [];
        for ($index = 0; $index < count($chars) - 1; $index++) {
            $grams[] = $chars[$index].$chars[$index + 1];
        }

        return array_values(array_unique($grams));
    }

    /**
     * @return list<string>
     */
    private function templateQuestions(string $keywordText, KeywordLibrary $library): array
    {
        $keywordText = trim($keywordText);
        if ($keywordText === '') {
            return [];
        }

        $termPackage = $this->profileTermPackageFromText($keywordText, $library);
        $domainKeyword = $this->normalizeQuestion((string) ($library->domain_keyword ?? ''));
        $industry = $this->normalizeQuestion((string) ($library->industry ?? ''));
        $companyName = $this->normalizeQuestion((string) ($library->company_name ?? ''));
        $audience = $termPackage['audience'];
        $scenario = $termPackage['scenario'];
        $pain = $termPackage['pain'];
        $decision = $termPackage['decision'];
        $primaryDimension = $termPackage['primary_dimension'];

        $audienceTerm = $audience[0] ?? '用户';
        $secondaryAudienceTerm = $audience[1] ?? $audienceTerm;
        $humanAudienceTerm = $this->humanAudienceTerm($audience) ?? $audienceTerm;
        $scenarioTerm = $scenario[0] ?? '业务场景';
        $secondaryScenarioTerm = $scenario[1] ?? $scenarioTerm;
        $painTerm = $pain[0] ?? '实际问题';
        $secondaryPainTerm = $pain[1] ?? $painTerm;
        $decisionTerm = $decision[0] ?? '效果';

        $dimensionTemplates = [
            'audience_fit' => [
                $audienceTerm.'用'.$keywordText.'能解决什么问题？',
                $secondaryAudienceTerm.'适合用'.$keywordText.'吗？',
            ],
            'scenario_usage' => [
                $scenarioTerm.'场景怎么用'.$keywordText.'？',
                $secondaryScenarioTerm.'需要哪些'.$keywordText.'能力？',
            ],
            'pain_solution' => [
                $painTerm.'时用'.$keywordText.'有效果吗？',
                $humanAudienceTerm.'遇到'.$secondaryPainTerm.'怎么通过'.$keywordText.'改善？',
            ],
            'decision_comparison' => [
                '选择'.$keywordText.'主要看'.$decisionTerm.'吗？',
                $keywordText.'怎么判断是否适合自己？',
            ],
            'capability_outcome' => [
                $keywordText.'能带来哪些实际价值？',
                $audienceTerm.'用'.$keywordText.'主要看什么效果？',
            ],
        ];

        $templates = $this->dimensionOrderedTemplates($dimensionTemplates, $primaryDimension);

        if ($domainKeyword !== '' && $this->dedupeKey($domainKeyword) !== $this->dedupeKey($keywordText)) {
            $templates[] = $domainKeyword.'服务怎么选？';
            $templates[] = $domainKeyword.'有哪些靠谱方案？';
        }

        if ($industry !== '') {
            $templates[] = $industry.'场景下'.$keywordText.'怎么选？';
        }

        if ($companyName !== '') {
            $templates[] = $companyName.'的'.$keywordText.'适合什么场景？';
        }

        return $templates;
    }

    /**
     * @return array{business:list<string>,audience:list<string>,scenario:list<string>,pain:list<string>,decision:list<string>,primary_dimension:string}
     */
    private function profileTermPackage(Keyword $keyword, KeywordLibrary $library): array
    {
        return $this->profileTermPackageFromText($this->normalizeQuestion((string) ($keyword->keyword ?? '')), $library);
    }

    /**
     * @return array{business:list<string>,audience:list<string>,scenario:list<string>,pain:list<string>,decision:list<string>,primary_dimension:string}
     */
    private function profileTermPackageFromText(string $keywordText, KeywordLibrary $library): array
    {
        $domainKeyword = $this->normalizeQuestion((string) ($library->domain_keyword ?? ''));
        $industry = $this->normalizeQuestion((string) ($library->industry ?? ''));
        $brandProfile = $this->normalizeQuestion(implode(' ', [
            (string) ($library->company_name ?? ''),
            $domainKeyword,
            $industry,
            (string) ($library->brand_description ?? ''),
            (string) ($library->description ?? ''),
        ]));

        $generalTerms = $this->generalProfileTerms($brandProfile);

        $business = $this->uniqueTerms([
            $keywordText,
            $domainKeyword,
            $industry,
            ...$this->extractProfileTermsByLabels($brandProfile, ['核心业务', '主营业务', '业务类型', '产品服务', '产品/服务', '服务内容', '核心能力', '主打']),
            ...$this->extractProfileTermsAfterMarkers($brandProfile, ['提供', '主打', '主营', '覆盖', '包括', '专注于', '聚焦']),
        ]);
        $audience = $this->uniqueTerms([
            ...$this->extractProfileTermsByLabels($brandProfile, ['服务对象', '目标用户', '目标客户', '目标人群', '用户群体', '受众', '面向对象']),
            ...$this->extractProfileTermsAfterMarkers($brandProfile, ['面向', '服务于', '适合', '针对']),
            ...$this->extractProfileTermsBetweenMarkers($brandProfile, ['帮助', '支持', '协助'], ['解决', '完成', '提升', '改善']),
        ]);
        $scenario = $this->uniqueTerms([
            ...$this->extractProfileTermsByLabels($brandProfile, ['典型场景', '应用场景', '使用场景', '服务场景', '场景', '需求']),
            ...$this->extractProfileTermsAfterMarkers($brandProfile, ['适用于', '应用于', '用于', '主打']),
        ]);
        $pain = $this->uniqueTerms([
            ...$this->extractProfileTermsByLabels($brandProfile, ['痛点', '问题', '难题', '挑战']),
            ...$this->extractProfileTermsAfterMarkers($brandProfile, ['解决', '改善', '降低', '提升']),
        ]);
        $decision = $this->uniqueTerms([
            ...$this->extractProfileTermsByLabels($brandProfile, ['选择标准', '决策因素', '核心优势', '优势', '特点']),
            ...$this->extractDecisionTermsFromText($brandProfile),
            '效果',
            '适配度',
        ]);

        $fallbackTerms = $this->fallbackProfileTerms($generalTerms, [$keywordText, $domainKeyword, $industry]);
        $fallbackScenarios = $this->fallbackProfileTerms($generalTerms, $fallbackTerms);

        return [
            'business' => $business !== [] ? $business : [$keywordText],
            'audience' => $audience !== [] ? $audience : ($fallbackTerms !== [] ? array_slice($fallbackTerms, 0, 2) : ['目标用户']),
            'scenario' => $scenario !== [] ? $scenario : ($fallbackScenarios !== [] ? array_slice($fallbackScenarios, 0, 2) : ['实际应用']),
            'pain' => $pain !== [] ? $pain : ($fallbackScenarios !== [] ? array_slice($fallbackScenarios, 0, 2) : ['实际问题']),
            'decision' => $decision,
            'primary_dimension' => $this->primaryDimension($keywordText),
        ];
    }

    /**
     * @return list<string>
     */
    private function profileFilterTerms(string $keywordText, KeywordLibrary $library): array
    {
        $termPackage = $this->profileTermPackageFromText($keywordText, $library);

        return $this->uniqueTerms([
            ...$termPackage['audience'],
            ...$termPackage['scenario'],
            ...$termPackage['pain'],
        ]);
    }

    /**
     * @param  list<string>  $terms
     * @return list<string>
     */
    private function uniqueTerms(array $terms): array
    {
        $seen = [];
        $unique = [];
        foreach ($terms as $term) {
            $term = $this->normalizeQuestion($term);
            if ($term === '') {
                continue;
            }

            $key = $this->dedupeKey($term);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $term;
        }

        return $unique;
    }

    /**
     * @param  list<string>  $labels
     * @return list<string>
     */
    private function extractProfileTermsByLabels(string $text, array $labels): array
    {
        $terms = [];
        foreach ($labels as $label) {
            $quotedLabel = preg_quote($label, '/');
            if (preg_match_all('/(?:^|[\s。；;\n])'.$quotedLabel.'\s*[：:]\s*([^。；;\n]+)/u', $text, $matches)) {
                foreach ((array) ($matches[1] ?? []) as $segment) {
                    array_push($terms, ...$this->splitProfileTermSegment((string) $segment));
                }
            }
        }

        return $this->uniqueTerms($terms);
    }

    /**
     * @param  list<string>  $markers
     * @return list<string>
     */
    private function extractProfileTermsAfterMarkers(string $text, array $markers): array
    {
        $terms = [];
        foreach ($markers as $marker) {
            $quotedMarker = preg_quote($marker, '/');
            if (preg_match_all('/'.$quotedMarker.'([^。；;，,\n]+)/u', $text, $matches)) {
                foreach ((array) ($matches[1] ?? []) as $segment) {
                    array_push($terms, ...$this->splitProfileTermSegment((string) $segment));
                }
            }
        }

        return $this->uniqueTerms($terms);
    }

    /**
     * @param  list<string>  $startMarkers
     * @param  list<string>  $endMarkers
     * @return list<string>
     */
    private function extractProfileTermsBetweenMarkers(string $text, array $startMarkers, array $endMarkers): array
    {
        $terms = [];
        $startPattern = implode('|', array_map(static fn (string $marker): string => preg_quote($marker, '/'), $startMarkers));
        $endPattern = implode('|', array_map(static fn (string $marker): string => preg_quote($marker, '/'), $endMarkers));
        if ($startPattern === '' || $endPattern === '') {
            return [];
        }

        if (preg_match_all('/(?:'.$startPattern.')([^。；;，,\n]{2,30}?)(?:'.$endPattern.')/u', $text, $matches)) {
            foreach ((array) ($matches[1] ?? []) as $segment) {
                array_push($terms, ...$this->splitProfileTermSegment((string) $segment));
            }
        }

        return $this->uniqueTerms($terms);
    }

    /**
     * @return list<string>
     */
    private function generalProfileTerms(string $text): array
    {
        $segments = preg_split('/[\n。；;，,]+/u', $text) ?: [];
        $terms = [];
        foreach ($segments as $segment) {
            array_push($terms, ...$this->splitProfileTermSegment((string) $segment));
        }

        return $this->uniqueTerms($terms);
    }

    /**
     * @return list<string>
     */
    private function splitProfileTermSegment(string $segment): array
    {
        $segment = $this->cleanupProfileTerm($segment);
        if ($segment === '') {
            return [];
        }

        $parts = preg_split('/(?:、|，|,|\/|以及|并且|同时|和|与|及)/u', $segment) ?: [];

        return collect($parts)
            ->map(fn (string $part): string => $this->cleanupProfileTerm($part))
            ->filter(fn (string $part): bool => $this->isUsableProfileTerm($part))
            ->values()
            ->all();
    }

    private function cleanupProfileTerm(string $term): string
    {
        $term = $this->normalizeQuestion($term);
        $term = preg_replace('/^(?:行业|品牌类型|服务对象|目标用户|目标客户|目标人群|用户群体|受众|地域|核心业务|主营业务|业务类型|产品服务|产品\/服务|服务内容|核心能力|典型场景|应用场景|使用场景|服务场景|场景|竞品方向|痛点|问题|难题|挑战|选择标准|决策因素|核心优势|优势|特点)\s*[：:]/u', '', $term) ?? $term;
        $term = preg_replace('/^(?:是一家|一家|一个|一种|面向|服务于|适合|针对|提供|主打|主营|覆盖|包括|专注于|聚焦|适用于|应用于|用于|解决|改善|降低|提升|帮助|主要|核心|专业|高端|一站式)\s*/u', '', $term) ?? $term;
        $term = preg_replace('/(?:的问题|等问题|相关问题|服务场景|应用场景|使用场景|场景需求|场景)$/u', '', $term) ?? $term;
        $term = preg_replace('/\s+/u', '', $term) ?? $term;
        $term = preg_replace('/^[：:，,。；;、]+|[：:，,。；;、]+$/u', '', $term) ?? $term;

        return trim($term);
    }

    private function isUsableProfileTerm(string $term): bool
    {
        $term = trim($term);
        if ($term === '') {
            return false;
        }

        $length = mb_strlen($term, 'UTF-8');
        if ($length < 2 || $length > 18) {
            return false;
        }

        if (preg_match('/^(?:品牌|行业|服务|产品|业务|场景|用户|客户|对象|人群|类型|地域|介绍|资料|不确定|暂无|无)$/u', $term) === 1) {
            return false;
        }

        return preg_match('/(?:暂缺|无法|不能|不知道|不清楚|未提供)/u', $term) !== 1;
    }

    /**
     * @return list<string>
     */
    private function extractDecisionTermsFromText(string $text): array
    {
        $terms = [];
        foreach (['效果', '成本', '价格', '费用', '功能', '质量', '效率', '体验', '口碑', '交付', '周期', '稳定性', '适配', '性价比'] as $term) {
            if (mb_stripos($text, $term, 0, 'UTF-8') !== false) {
                $terms[] = $term;
            }
        }

        return $this->uniqueTerms($terms);
    }

    /**
     * @param  list<string>  $terms
     * @param  list<string>  $excluded
     * @return list<string>
     */
    private function fallbackProfileTerms(array $terms, array $excluded): array
    {
        $excludedKeys = collect($excluded)
            ->map(fn (string $term): string => $this->dedupeKey($term))
            ->filter()
            ->flip()
            ->all();

        return collect($terms)
            ->reject(fn (string $term): bool => isset($excludedKeys[$this->dedupeKey($term)]))
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $terms
     */
    private function termsLine(array $terms): string
    {
        return $terms !== [] ? implode('、', array_slice($terms, 0, 8)) : '暂无明确提取结果';
    }

    private function primaryDimension(string $keywordText): string
    {
        $dimensions = ['audience_fit', 'scenario_usage', 'pain_solution', 'decision_comparison', 'capability_outcome'];
        $index = abs((int) crc32($keywordText)) % count($dimensions);

        return $dimensions[$index];
    }

    /**
     * @param  list<string>  $audience
     */
    private function humanAudienceTerm(array $audience): ?string
    {
        foreach ($audience as $term) {
            if (preg_match('/(?:老板|团队|人员|用户|客户|商家|企业)$/u', $term) === 1) {
                return $term;
            }
        }

        return null;
    }

    /**
     * @param  array<string,list<string>>  $dimensionTemplates
     * @return list<string>
     */
    private function dimensionOrderedTemplates(array $dimensionTemplates, string $primaryDimension): array
    {
        $orderedDimensions = array_values(array_unique([
            $primaryDimension,
            'audience_fit',
            'scenario_usage',
            'pain_solution',
            'decision_comparison',
            'capability_outcome',
        ]));

        $templates = [];
        foreach ($orderedDimensions as $dimension) {
            foreach ($dimensionTemplates[$dimension] ?? [] as $template) {
                $templates[] = $template;
            }
        }

        return $templates;
    }

    private function normalizeQuestion(string $question): string
    {
        $question = trim($question);
        $question = preg_replace('/^\s*(?:[-*]+|\d+[.)])\s*/u', '', $question) ?? $question;
        $question = preg_replace('/\s+/u', ' ', $question) ?? $question;

        return mb_strlen($question, 'UTF-8') <= 500 ? $question : '';
    }

    private function resolveAiModel(): AiModel
    {
        $model = $this->aiConfigurationScope->applyCurrentConsumerScope(
            AiModel::query()->withoutGlobalScope('current_site'),
            'ai_models.owner_admin_id'
        )
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
            throw new RuntimeException('Please add and enable a chat model in AI model settings first.');
        }

        return $model;
    }
}
