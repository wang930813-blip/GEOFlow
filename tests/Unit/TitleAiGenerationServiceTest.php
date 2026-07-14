<?php

namespace Tests\Unit;

use App\Models\AiModel;
use App\Services\GeoFlow\TitleAiGenerationService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Laravel\Ai\AnonymousAgent;
use Tests\TestCase;

class TitleAiGenerationServiceTest extends TestCase
{
    public function test_fallback_titles_preserve_their_assigned_keywords(): void
    {
        $service = new TitleAiGenerationService(app(ApiKeyCrypto::class));
        $model = new AiModel([
            'api_url' => '',
            'api_key' => '',
            'model_id' => 'missing-model',
        ]);

        $result = $service->generateTitles(
            $model,
            ['武城煊饼历史由来', '武城煊饼伴手礼'],
            2,
            'professional'
        );

        $this->assertTrue($result['fallback_used']);
        $this->assertCount(2, $result['entries']);
        $this->assertSame('武城煊饼历史由来', $result['entries'][0]['keyword']);
        $this->assertSame('武城煊饼伴手礼', $result['entries'][1]['keyword']);

        foreach ($result['entries'] as $entry) {
            $this->assertStringContainsString($entry['keyword'], $entry['title']);
        }
    }

    public function test_invalid_keyword_mapping_is_retried_without_reassigning_other_titles(): void
    {
        AnonymousAgent::fake([
            "武城煊饼历史由来|||武城煊饼制作方法大全\n武城煊饼伴手礼|||武城煊饼伴手礼选购指南",
            '武城煊饼历史由来|||武城煊饼历史由来与文化传承',
        ])->preventStrayPrompts();

        $crypto = app(ApiKeyCrypto::class);
        $service = new TitleAiGenerationService($crypto);
        $model = new AiModel([
            'api_url' => 'https://ai.test/v1',
            'api_key' => $crypto->encrypt('test-key'),
            'model_id' => 'test-chat',
        ]);
        $model->syncOriginal();

        $result = $service->generateTitles(
            $model,
            ['武城煊饼历史由来', '武城煊饼伴手礼'],
            2,
            'professional'
        );

        $this->assertFalse(
            $result['fallback_used'],
            (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $this->assertSame([
            [
                'keyword' => '武城煊饼历史由来',
                'title' => '武城煊饼历史由来与文化传承',
            ],
            [
                'keyword' => '武城煊饼伴手礼',
                'title' => '武城煊饼伴手礼选购指南',
            ],
        ], $result['entries']);
    }
}
