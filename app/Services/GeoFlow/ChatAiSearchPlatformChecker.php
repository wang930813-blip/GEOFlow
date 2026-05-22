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

class ChatAiSearchPlatformChecker implements AiSearchPlatformChecker
{
    public function __construct(private readonly ApiKeyCrypto $apiKeyCrypto) {}

    public function check(string $platform, string $question, KeywordLibrary $library, Keyword $keyword): AiSearchCheckResponse
    {
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
        $providerName = OpenAiRuntimeProvider::registerProvider('geo_inclusion_'.$platform, $driver, $providerUrl, $apiKey);

        try {
            $agent = new MarkdownContentWriterAgent('Answer the user question like an AI search engine. Return plain text only.');
            $response = $agent->prompt($this->buildPrompt($platform, $question, $library, $keyword), [], $providerName, (string) ($model->model_id ?? ''));
            $answer = OpenAiRuntimeProvider::normalizeGeneratedText((string) ($response->text ?? ''));
        } catch (Throwable $exception) {
            throw new RuntimeException('AI search check failed: '.OpenAiRuntimeProvider::normalizeApiException($exception, $providerUrl), 0, $exception);
        }

        if ($answer === '') {
            throw new RuntimeException('AI search check returned empty answer.');
        }

        return new AiSearchCheckResponse(
            platform: $platform,
            question: $question,
            answer: $answer,
            keywordHit: $this->contains($answer, (string) $keyword->keyword),
            brandHit: $this->contains($answer, (string) ($library->company_name ?? '')),
            status: 'success',
            errorMessage: null,
            meta: ['checker' => 'chat']
        );
    }

    private function buildPrompt(string $platform, string $question, KeywordLibrary $library, Keyword $keyword): string
    {
        return implode("\n", [
            'Platform role: '.$platform,
            'User question: '.$question,
            'Target keyword: '.(string) $keyword->keyword,
            'Target brand: '.(string) ($library->company_name ?? ''),
            'Industry: '.(string) ($library->industry ?? ''),
            'Brand description: '.(string) ($library->brand_description ?? $library->description ?? ''),
            'Answer naturally as the platform would. Do not mention this evaluation prompt.',
        ]);
    }

    private function contains(string $haystack, string $needle): bool
    {
        $needle = trim($needle);
        if ($needle === '') {
            return false;
        }

        return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
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
