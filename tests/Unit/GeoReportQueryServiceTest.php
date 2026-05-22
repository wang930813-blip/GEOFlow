<?php

namespace Tests\Unit;

use App\Models\GeoInclusionCheckResult;
use App\Models\GeoInclusionCheckRun;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KeywordQuestionVariant;
use App\Services\GeoFlow\GeoReportQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GeoReportQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_geo_report_overview_platform_keyword_and_trend_data(): void
    {
        [$library, $keywordA, $questionA] = $this->createProject('Acme GEO', 'Acme', 'AI visibility');
        [, $keywordB, $questionB] = $this->createProject('Beta GEO', 'Beta', 'GEO reporting');

        $run = GeoInclusionCheckRun::query()->create([
            'keyword_library_id' => (int) $library->id,
            'platforms' => ['doubao', 'qianwen', 'deepseek'],
            'status' => 'completed',
            'total_checks' => 4,
            'completed_checks' => 4,
            'failed_checks' => 0,
            'created_at' => now()->subDays(1),
            'updated_at' => now()->subDays(1),
        ]);

        $this->createResult($run, $library, $keywordA, $questionA, 'doubao', true, true, now()->subDays(2));
        $this->createResult($run, $library, $keywordA, $questionA, 'qianwen', true, false, now()->subDay());
        $this->createResult($run, $library, $keywordB, $questionB, 'deepseek', false, false, now());
        $this->createResult($run, $library, $keywordB, $questionB, 'doubao', true, true, now());

        $report = app(GeoReportQueryService::class)->build();

        $this->assertSame(2, $report['overview']['projects']);
        $this->assertSame(2, $report['overview']['keywords']);
        $this->assertSame(4, $report['overview']['checks']);
        $this->assertSame(75.0, $report['overview']['keyword_hit_rate']);
        $this->assertSame(50.0, $report['overview']['brand_hit_rate']);

        $this->assertSame('doubao', $report['platforms'][0]['platform']);
        $this->assertSame(2, $report['platforms'][0]['checks']);
        $this->assertSame(100.0, $report['platforms'][0]['brand_hit_rate']);

        $this->assertSame('AI visibility', $report['keywordRanking'][0]['keyword']);
        $this->assertSame(100.0, $report['keywordRanking'][0]['keyword_hit_rate']);
        $this->assertSame(50.0, $report['keywordRanking'][0]['brand_hit_rate']);

        $this->assertCount(7, $report['trend']);
        $this->assertSame(2, $report['trend'][6]['checks']);
    }

    public function test_report_aggregate_queries_do_not_compare_boolean_columns_to_integer_literals(): void
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(GeoReportQueryService::class)->build();

        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(keyword_hit|brand_hit)\s*=\s*1\b/', $query);
        }
    }

    /**
     * @return array{0: KeywordLibrary, 1: Keyword, 2: KeywordQuestionVariant}
     */
    private function createProject(string $name, string $brand, string $keywordText): array
    {
        $library = KeywordLibrary::query()->create([
            'name' => $name,
            'company_name' => $brand,
            'keyword_count' => 1,
            'status' => 'active',
        ]);
        $keyword = Keyword::query()->create([
            'library_id' => (int) $library->id,
            'keyword' => $keywordText,
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $question = KeywordQuestionVariant::query()->create([
            'keyword_id' => (int) $keyword->id,
            'question' => 'How does '.$keywordText.' work?',
        ]);

        return [$library, $keyword, $question];
    }

    private function createResult(
        GeoInclusionCheckRun $run,
        KeywordLibrary $library,
        Keyword $keyword,
        KeywordQuestionVariant $question,
        string $platform,
        bool $keywordHit,
        bool $brandHit,
        \DateTimeInterface $checkedAt
    ): void {
        GeoInclusionCheckResult::query()->create([
            'run_id' => (int) $run->id,
            'keyword_library_id' => (int) $library->id,
            'keyword_id' => (int) $keyword->id,
            'question_variant_id' => (int) $question->id,
            'platform' => $platform,
            'question' => (string) $question->question,
            'answer' => 'Answer',
            'keyword_hit' => $keywordHit,
            'brand_hit' => $brandHit,
            'status' => 'success',
            'checked_at' => $checkedAt,
        ]);
    }
}
