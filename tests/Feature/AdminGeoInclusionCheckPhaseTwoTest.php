<?php

namespace Tests\Feature;

use App\Jobs\ProcessGeoInclusionCheckJob;
use App\Models\Admin;
use App\Models\GeoInclusionCheckResult;
use App\Models\GeoInclusionCheckRun;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KeywordQuestionVariant;
use App\Services\GeoFlow\AiSearchCheckResponse;
use App\Services\GeoFlow\AiSearchPlatformChecker;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminGeoInclusionCheckPhaseTwoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_admin_can_start_inclusion_check_run_for_keyword_library(): void
    {
        Queue::fake();

        $admin = $this->createAdmin();
        $library = $this->createLibraryWithQuestion();

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.keyword-libraries.inclusion-checks.store', ['libraryId' => (int) $library->id]), [
                'platforms' => ['doubao', 'qianwen', 'deepseek'],
            ]);

        $response->assertRedirect(route('admin.keyword-libraries.detail', ['libraryId' => (int) $library->id]));

        $run = GeoInclusionCheckRun::query()->first();
        $this->assertNotNull($run);
        $this->assertSame((int) $library->id, (int) $run->keyword_library_id);
        $this->assertSame('pending', $run->status);
        $this->assertSame(['doubao', 'qianwen', 'deepseek'], $run->platforms);
        $this->assertSame(3, (int) $run->total_checks);

        Queue::assertPushed(ProcessGeoInclusionCheckJob::class, 3);
    }

    public function test_inclusion_check_job_persists_platform_result(): void
    {
        $library = $this->createLibraryWithQuestion();
        $keyword = $library->keywords()->firstOrFail();
        $question = $keyword->questionVariants()->firstOrFail();
        $run = GeoInclusionCheckRun::query()->create([
            'keyword_library_id' => (int) $library->id,
            'platforms' => ['doubao'],
            'status' => 'pending',
            'total_checks' => 1,
            'completed_checks' => 0,
            'failed_checks' => 0,
        ]);

        $this->app->bind(AiSearchPlatformChecker::class, static fn () => new class implements AiSearchPlatformChecker {
            public function check(string $platform, string $question, KeywordLibrary $library, Keyword $keyword): AiSearchCheckResponse
            {
                return new AiSearchCheckResponse(
                    platform: $platform,
                    question: $question,
                    answer: 'Acme is mentioned as a GEO visibility platform.',
                    keywordHit: true,
                    brandHit: true,
                    status: 'success',
                    errorMessage: null,
                    meta: ['source' => 'fake']
                );
            }
        });

        (new ProcessGeoInclusionCheckJob(
            runId: (int) $run->id,
            keywordId: (int) $keyword->id,
            questionVariantId: (int) $question->id,
            platform: 'doubao'
        ))->handle($this->app->make(AiSearchPlatformChecker::class));

        $this->assertDatabaseHas('geo_inclusion_check_results', [
            'run_id' => (int) $run->id,
            'keyword_id' => (int) $keyword->id,
            'question_variant_id' => (int) $question->id,
            'platform' => 'doubao',
            'question' => 'Which tools improve AI search visibility?',
            'keyword_hit' => true,
            'brand_hit' => true,
            'status' => 'success',
        ]);

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, (int) $run->completed_checks);
        $this->assertSame(0, (int) $run->failed_checks);

        $this->assertSame(1, GeoInclusionCheckResult::query()->count());
    }

    private function createLibraryWithQuestion(): KeywordLibrary
    {
        $library = KeywordLibrary::query()->create([
            'name' => 'Acme GEO Project',
            'company_name' => 'Acme',
            'domain_keyword' => 'GEO visibility',
            'industry' => 'SaaS',
            'brand_description' => 'Acme helps teams manage AI search visibility.',
            'keyword_count' => 1,
            'status' => 'active',
        ]);
        $keyword = Keyword::query()->create([
            'library_id' => (int) $library->id,
            'keyword' => 'AI search visibility',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        KeywordQuestionVariant::query()->create([
            'keyword_id' => (int) $keyword->id,
            'question' => 'Which tools improve AI search visibility?',
        ]);

        return $library;
    }

    private function createAdmin(string $username = 'geo_phase_two_admin'): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => 'GEO Phase Two Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }
}
