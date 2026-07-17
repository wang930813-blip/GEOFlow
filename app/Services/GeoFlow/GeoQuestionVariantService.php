<?php

namespace App\Services\GeoFlow;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\AiModel;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
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
            $response = $agent->prompt($this->buildPrompt($keyword, $library, $count), [], $providerName, (string) ($model->model_id ?? ''));
            $rawContent = (string) ($response->text ?? '');
            $questions = $this->parseQuestions(OpenAiRuntimeProvider::normalizeGeneratedText($rawContent), $count);
        } catch (Throwable $exception) {
            throw new RuntimeException('AI question variant generation failed: '.OpenAiRuntimeProvider::normalizeApiException($exception, $providerUrl), 0, $exception);
        }

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

    private function buildPrompt(Keyword $keyword, KeywordLibrary $library, int $count): string
    {
        return implode("\n", [
            'Generate '.$count.' Chinese AI search query variants for AI search inclusion checks.',
            'Return only a JSON string array. Do not include markdown, numbering, or explanations.',
            'Keyword: '.(string) $keyword->keyword,
            'Company/brand: '.(string) ($library->company_name ?? ''),
            'Domain keyword: '.(string) ($library->domain_keyword ?? ''),
            'Industry: '.(string) ($library->industry ?? ''),
            'Brand description: '.(string) ($library->brand_description ?? $library->description ?? ''),
            'Keyword intent guidance: Treat the keyword as the main search intent. Expand around its core phrases, user need, selection criteria, recommendation intent, or comparison intent. Do not drift into unrelated topics.',
            'Query mix: when generating 5 items, include exactly 2 short keyword-style searches, 2 medium direct questions, and 1 scenario-based decision question. If the requested count is not 5, keep this mix proportionally.',
            'Short keyword-style searches: concise search phrases, usually 4-14 Chinese characters when possible; short searches do not need question marks.',
            'Medium direct questions: natural and direct questions around the keyword intent, usually 10-28 Chinese characters.',
            'Scenario-based decision question: include a concrete user scenario, need, or decision background, but avoid being overly long.',
            'Rules: variants should be realistic, varied, and likely to make Doubao, Qianwen, DeepSeek, or Wenxin mention relevant brands or solutions. Avoid marketing slogans and duplicate phrasing.',
        ]);
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
