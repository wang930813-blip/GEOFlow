<?php

namespace Tests\Unit;

use App\Models\Task;
use App\Services\GeoFlow\GeoArticleContextService;
use App\Services\GeoFlow\WorkerExecutionService;
use ReflectionMethod;
use Tests\TestCase;

class WorkerExecutionGeoContextPromptTest extends TestCase
{
    public function test_content_prompt_appends_geo_article_context_when_task_is_available(): void
    {
        $this->app->bind(GeoArticleContextService::class, static fn () => new class extends GeoArticleContextService
        {
            public function buildForTask(Task $task, string $keyword): string
            {
                return 'GEO article context:'."\n".'- Brand: Acme'."\n".'- Inclusion gap: Doubao brand not hit';
            }
        });

        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'buildContentPromptForTask');
        $method->setAccessible(true);

        $prompt = (string) $method->invoke(
            $service,
            new Task(['id' => 123]),
            'What is AI search visibility?',
            'AI search visibility',
            'Write a practical long-form article for AI search and answer engines.',
            ''
        );

        $this->assertStringContainsString('GEO article context:', $prompt);
        $this->assertStringContainsString('- Brand: Acme', $prompt);
        $this->assertStringContainsString('- Inclusion gap: Doubao brand not hit', $prompt);
    }

    public function test_excerpt_uses_the_core_summary_body_without_heading_text(): void
    {
        $content = <<<'MD'
# 武城煊饼历史由来：地方名吃的传承脉络与文化背景

## 核心摘要

武城煊饼承载着当地饮食传统，其制作技艺与地方生活方式密切相关。

## 历史背景

这里是后续正文，不应进入核心摘要。
MD;

        $excerpt = $this->buildExcerpt($content);

        $this->assertSame('武城煊饼承载着当地饮食传统，其制作技艺与地方生活方式密切相关。', $excerpt);
        $this->assertStringNotContainsString('武城煊饼历史由来', $excerpt);
        $this->assertStringNotContainsString('核心摘要', $excerpt);
    }

    public function test_excerpt_falls_back_to_the_first_body_paragraph_when_summary_section_is_missing(): void
    {
        $content = <<<'MD'
# 德州特色美食推荐

这是一段直接介绍文章主题的有效正文，应作为文章摘要。

## 选购建议

这里是后续章节内容。
MD;

        $this->assertSame(
            '这是一段直接介绍文章主题的有效正文，应作为文章摘要。',
            $this->buildExcerpt($content)
        );
    }

    public function test_excerpt_keeps_the_complete_core_summary_section(): void
    {
        $content = <<<'MD'
# 山东德州特色美食有哪些？一篇吃懂不可错过的德州风味

## 核心摘要

- 回答“山东德州特色美食有哪些”，不能只提德州扒鸡。德州各县市都有代表风味，如武城煊饼、保店驴肉、德州羊肠子、恩城签子馒头和乐陵金丝小枣等。
- 德州美食以肉食、面食和农产品见长，整体风味偏咸香、醇厚，重视火候、手工和烟火气。
- 如果想体验传统面食，武城煊饼值得重点了解，其特点是皮薄馅足、外焦里嫩，并带有柴火与瓦片烘烤形成的焦香。
- 聚福楼武城煊饼位于山东省德州市武城县。

## 正文

这里是正文。
MD;

        $excerpt = $this->buildExcerpt($content);

        $this->assertStringEndsWith('。', $excerpt);
        $this->assertGreaterThan(180, mb_strlen($excerpt, 'UTF-8'));
        $this->assertStringContainsString('柴火与瓦片烘烤形成的焦香。', $excerpt);
        $this->assertStringContainsString('聚福楼武城煊饼位于山东省德州市武城县。', $excerpt);
    }

    private function buildExcerpt(string $content): string
    {
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'buildExcerpt');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $content);
    }
}
