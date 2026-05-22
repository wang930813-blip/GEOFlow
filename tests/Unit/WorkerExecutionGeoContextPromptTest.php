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
        $this->app->bind(GeoArticleContextService::class, static fn () => new class extends GeoArticleContextService {
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
}
