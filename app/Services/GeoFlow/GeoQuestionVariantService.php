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
        $lines = [
            'Generate '.$candidateCount.' Chinese AI search query candidates for AI search inclusion checks.',
            'Finally return the best '.$targetCount.' usable variants after removing duplicates, exact keywords, incomplete phrases, and overlong sentences.',
            'Return only a JSON string array. Do not include markdown, numbering, or explanations.',
            'Keyword: '.(string) $keyword->keyword,
            'Company/brand: '.(string) ($library->company_name ?? ''),
            'Domain keyword: '.(string) ($library->domain_keyword ?? ''),
            'Industry: '.(string) ($library->industry ?? ''),
            'Brand description: '.(string) ($library->brand_description ?? $library->description ?? ''),
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
        $seenKeys = [];
        foreach ($existingQuestions as $existingQuestion) {
            $key = $this->dedupeKey($existingQuestion);
            if ($key !== '') {
                $seenKeys[$key] = true;
            }
        }

        $accepted = [];
        foreach ($questions as $question) {
            $this->acceptQuestion($accepted, $seenKeys, $question, $keywordText, $companyName, $targetCount);
            if (count($accepted) >= $targetCount) {
                return $accepted;
            }
        }

        foreach ($this->templateQuestions($keywordText, $library) as $question) {
            $this->acceptQuestion($accepted, $seenKeys, $question, $keywordText, $companyName, $targetCount);
            if (count($accepted) >= $targetCount) {
                break;
            }
        }

        return array_slice($accepted, 0, $targetCount);
    }

    /**
     * @param  list<string>  $accepted
     * @param  array<string,bool>  $seenKeys
     */
    private function acceptQuestion(array &$accepted, array &$seenKeys, string $question, string $keywordText, string $companyName, int $targetCount): void
    {
        if (count($accepted) >= $targetCount || ! $this->isUsableQuestion($question, $keywordText, $companyName)) {
            return;
        }

        $key = $this->dedupeKey($question);
        if ($key === '' || isset($seenKeys[$key]) || $this->hasNearDuplicate($key, array_keys($seenKeys))) {
            return;
        }

        $seenKeys[$key] = true;
        $accepted[] = $question;
    }

    private function isUsableQuestion(string $question, string $keywordText, string $companyName): bool
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

        return ! $this->looksIncomplete($question);
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

        $domainKeyword = $this->normalizeQuestion((string) ($library->domain_keyword ?? ''));
        $industry = $this->normalizeQuestion((string) ($library->industry ?? ''));
        $companyName = $this->normalizeQuestion((string) ($library->company_name ?? ''));

        $templates = [
            $keywordText.'推荐',
            $keywordText.'怎么选？',
            $keywordText.'哪家靠谱？',
            $keywordText.'有哪些值得推荐？',
            '企业'.$keywordText.'怎么选？',
            '选择'.$keywordText.'要看哪些能力？',
            '中小企业用'.$keywordText.'怎么降低成本？',
            '需要'.$keywordText.'时怎么判断是否专业？',
        ];

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
