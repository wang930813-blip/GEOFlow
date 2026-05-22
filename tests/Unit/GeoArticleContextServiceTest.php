<?php

namespace Tests\Unit;

use App\Models\GeoInclusionCheckResult;
use App\Models\GeoInclusionCheckRun;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KeywordQuestionVariant;
use App\Models\Task;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\GeoArticleContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeoArticleContextServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_geo_article_context_from_task_keyword_library_and_inclusion_gaps(): void
    {
        $keywordLibrary = KeywordLibrary::query()->create([
            'name' => 'Acme GEO Project',
            'company_name' => 'Acme',
            'domain_keyword' => 'GEO visibility',
            'industry' => 'SaaS',
            'brand_description' => 'Acme helps teams improve AI search visibility.',
            'keyword_count' => 1,
            'status' => 'active',
        ]);
        $keyword = Keyword::query()->create([
            'library_id' => (int) $keywordLibrary->id,
            'keyword' => 'AI search visibility',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        KeywordQuestionVariant::query()->create([
            'keyword_id' => (int) $keyword->id,
            'question' => 'Which tools improve AI search visibility?',
        ]);
        $run = GeoInclusionCheckRun::query()->create([
            'keyword_library_id' => (int) $keywordLibrary->id,
            'platforms' => ['doubao'],
            'status' => 'completed',
            'total_checks' => 1,
            'completed_checks' => 1,
            'failed_checks' => 0,
        ]);
        GeoInclusionCheckResult::query()->create([
            'run_id' => (int) $run->id,
            'keyword_library_id' => (int) $keywordLibrary->id,
            'keyword_id' => (int) $keyword->id,
            'question_variant_id' => (int) $keyword->questionVariants()->firstOrFail()->id,
            'platform' => 'doubao',
            'question' => 'Which tools improve AI search visibility?',
            'answer' => 'The answer mentioned generic SEO tools but not Acme.',
            'keyword_hit' => true,
            'brand_hit' => false,
            'status' => 'success',
            'checked_at' => now(),
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => 'Acme Titles',
            'title_count' => 0,
            'keyword_library_id' => (int) $keywordLibrary->id,
        ]);
        $task = new Task([
            'title_library_id' => (int) $titleLibrary->id,
        ]);

        $context = app(GeoArticleContextService::class)->buildForTask($task, 'AI search visibility');

        $this->assertStringContainsString('GEO article context:', $context);
        $this->assertStringContainsString('Brand: Acme', $context);
        $this->assertStringContainsString('Domain keyword: GEO visibility', $context);
        $this->assertStringContainsString('Industry: SaaS', $context);
        $this->assertStringContainsString('Acme helps teams improve AI search visibility.', $context);
        $this->assertStringContainsString('Which tools improve AI search visibility?', $context);
        $this->assertStringContainsString('Doubao', $context);
        $this->assertStringContainsString('brand not hit', $context);
    }

    public function test_it_returns_empty_context_when_task_has_no_keyword_library(): void
    {
        $titleLibrary = TitleLibrary::query()->create([
            'name' => 'Generic Titles',
            'title_count' => 0,
        ]);
        $task = new Task([
            'title_library_id' => (int) $titleLibrary->id,
        ]);

        $this->assertSame('', app(GeoArticleContextService::class)->buildForTask($task, 'AI search visibility'));
    }
}
