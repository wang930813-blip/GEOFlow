<?php

namespace App\Services\GeoFlow;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\AiModel;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use RuntimeException;
use Throwable;

class GeoQuestionVariantService
{
    public function __construct(private readonly ApiKeyCrypto $apiKeyCrypto) {}

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
            'Generate '.$count.' natural user questions for AI search inclusion checks.',
            'Return only a JSON string array.',
            'Keyword: '.(string) $keyword->keyword,
            'Company/brand: '.(string) ($library->company_name ?? ''),
            'Domain keyword: '.(string) ($library->domain_keyword ?? ''),
            'Industry: '.(string) ($library->industry ?? ''),
            'Brand description: '.(string) ($library->brand_description ?? $library->description ?? ''),
            'Rules: questions should be realistic, varied, and likely to make Doubao, Qianwen, or DeepSeek mention relevant brands or solutions.',
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
            throw new RuntimeException('Please add and enable a chat model in AI model settings first.');
        }

        return $model;
    }
}
