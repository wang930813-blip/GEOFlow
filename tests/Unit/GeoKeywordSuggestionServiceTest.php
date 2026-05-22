<?php

namespace Tests\Unit;

use App\Services\GeoFlow\GeoKeywordSuggestionService;
use Tests\TestCase;

class GeoKeywordSuggestionServiceTest extends TestCase
{
    public function test_it_parses_json_keyword_suggestions(): void
    {
        $service = app(GeoKeywordSuggestionService::class);

        $suggestions = $service->parseSuggestionsForTesting(
            '["GEO优化", "AI搜索优化", "品牌 GEO 内容策略"]',
            10
        );

        $this->assertSame([
            'GEO优化',
            'AI搜索优化',
            '品牌 GEO 内容策略',
        ], $suggestions);
    }

    public function test_it_parses_line_separated_keyword_suggestions(): void
    {
        $service = app(GeoKeywordSuggestionService::class);

        $suggestions = $service->parseSuggestionsForTesting(
            "1. GEO优化是什么\n- AI搜索优化怎么做\n• 品牌内容被AI引用",
            10
        );

        $this->assertSame([
            'GEO优化是什么',
            'AI搜索优化怎么做',
            '品牌内容被AI引用',
        ], $suggestions);
    }

    public function test_it_deduplicates_and_limits_suggestions(): void
    {
        $service = app(GeoKeywordSuggestionService::class);

        $suggestions = $service->parseSuggestionsForTesting(
            "GEO优化\nGEO优化\n GEO 优化 \nAI搜索优化\n\n",
            2
        );

        $this->assertSame([
            'GEO优化',
            'GEO 优化',
        ], $suggestions);
    }
}
