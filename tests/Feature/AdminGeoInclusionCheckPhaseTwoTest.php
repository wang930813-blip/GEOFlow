<?php

namespace Tests\Feature;

use App\Events\Admin\KeywordLibraryInclusionUpdated;
use App\Jobs\ProcessGeoInclusionCheckJob;
use App\Models\Admin;
use App\Models\GeoInclusionCheckResult;
use App\Models\GeoInclusionCheckRun;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KeywordQuestionVariant;
use App\Models\Site;
use App\Services\GeoFlow\AiSearchCheckResponse;
use App\Services\GeoFlow\AiSearchPlatformChecker;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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
        $site = $this->createSiteForAdmin($admin);
        $library = $this->createLibraryWithQuestion((int) $site->id);

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
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

    public function test_keyword_library_detail_groups_inclusion_results_by_day(): void
    {
        $admin = $this->createAdmin('daily_report_admin');
        $site = $this->createSiteForAdmin($admin);
        $library = $this->createLibraryWithQuestion((int) $site->id);
        $keyword = $library->keywords()->firstOrFail();
        $question = $keyword->questionVariants()->firstOrFail();
        $run = $this->createInclusionRun($library, (int) $site->id);

        GeoInclusionCheckResult::query()->create([
            'site_id' => (int) $site->id,
            'run_id' => (int) $run->id,
            'keyword_library_id' => (int) $library->id,
            'keyword_id' => (int) $keyword->id,
            'question_variant_id' => (int) $question->id,
            'platform' => 'deepseek',
            'question' => 'Which tools improve AI search visibility?',
            'answer' => 'Acme and AI search visibility are both mentioned.',
            'keyword_hit' => true,
            'brand_hit' => true,
            'status' => 'success',
            'checked_at' => CarbonImmutable::parse('2026-05-23 09:30:00'),
        ]);
        GeoInclusionCheckResult::query()->create([
            'site_id' => (int) $site->id,
            'run_id' => (int) $run->id,
            'keyword_library_id' => (int) $library->id,
            'keyword_id' => (int) $keyword->id,
            'question_variant_id' => (int) $question->id,
            'platform' => 'qianwen',
            'question' => 'How should teams monitor GEO visibility?',
            'answer' => 'Visibility monitoring is discussed without the brand.',
            'keyword_hit' => false,
            'brand_hit' => false,
            'status' => 'success',
            'checked_at' => CarbonImmutable::parse('2026-05-22 18:15:00'),
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.keyword-libraries.detail', ['libraryId' => (int) $library->id]));

        $response->assertOk();
        $response->assertSee('2026-05-23');
        $response->assertSee('2026-05-22');
        $response->assertSee('AI search visibility');
        $response->assertSee('DeepSeek');
        $response->assertSee('关键词命中');
        $response->assertSee('品牌命中');
        $response->assertSee('<details class="group">', false);
        $response->assertSee('展开');
        $response->assertSee('收起');
        $response->assertSee(route('admin.keyword-libraries.inclusion-results.export', ['libraryId' => (int) $library->id]), false);
    }

    public function test_keyword_library_detail_splits_same_day_results_by_run(): void
    {
        $admin = $this->createAdmin('daily_run_report_admin');
        $site = $this->createSiteForAdmin($admin);
        $library = $this->createLibraryWithQuestion((int) $site->id);
        $keyword = $library->keywords()->firstOrFail();
        $question = $keyword->questionVariants()->firstOrFail();
        $firstRun = $this->createInclusionRun($library, (int) $site->id);
        $secondRun = GeoInclusionCheckRun::query()->create([
            'site_id' => (int) $site->id,
            'keyword_library_id' => (int) $library->id,
            'platforms' => ['deepseek'],
            'status' => 'completed',
            'total_checks' => 1,
            'completed_checks' => 1,
            'failed_checks' => 0,
            'started_at' => CarbonImmutable::parse('2026-05-23 15:00:00'),
            'completed_at' => CarbonImmutable::parse('2026-05-23 15:10:00'),
            'created_at' => CarbonImmutable::parse('2026-05-23 15:00:00'),
        ]);

        foreach ([[$firstRun, '2026-05-23 09:30:00'], [$secondRun, '2026-05-23 15:05:00']] as [$run, $checkedAt]) {
            GeoInclusionCheckResult::query()->create([
                'site_id' => (int) $site->id,
                'run_id' => (int) $run->id,
                'keyword_library_id' => (int) $library->id,
                'keyword_id' => (int) $keyword->id,
                'question_variant_id' => (int) $question->id,
                'platform' => 'deepseek',
                'question' => 'Which tools improve AI search visibility?',
                'answer' => 'Acme is cited.',
                'keyword_hit' => true,
                'brand_hit' => true,
                'status' => 'success',
                'checked_at' => CarbonImmutable::parse($checkedAt),
            ]);
        }

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.keyword-libraries.detail', ['libraryId' => (int) $library->id]));

        $response->assertOk();
        $response->assertSee('2026-05-23');
        $response->assertSee('#'.(int) $firstRun->id.' completed');
        $response->assertSee('#'.(int) $secondRun->id.' completed');
        $response->assertSee('批次 2');
    }

    public function test_admin_can_export_keyword_library_inclusion_results_as_csv(): void
    {
        $admin = $this->createAdmin('export_report_admin');
        $site = $this->createSiteForAdmin($admin);
        $library = $this->createLibraryWithQuestion((int) $site->id);
        $keyword = $library->keywords()->firstOrFail();
        $question = $keyword->questionVariants()->firstOrFail();
        $run = $this->createInclusionRun($library, (int) $site->id);

        GeoInclusionCheckResult::query()->create([
            'site_id' => (int) $site->id,
            'run_id' => (int) $run->id,
            'keyword_library_id' => (int) $library->id,
            'keyword_id' => (int) $keyword->id,
            'question_variant_id' => (int) $question->id,
            'platform' => 'deepseek',
            'question' => 'Which tools improve AI search visibility?',
            'answer' => 'Acme is cited.',
            'keyword_hit' => true,
            'brand_hit' => true,
            'status' => 'success',
            'checked_at' => CarbonImmutable::parse('2026-05-23 09:30:00'),
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.keyword-libraries.inclusion-results.export', ['libraryId' => (int) $library->id]));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $response->assertHeader('content-disposition');
        $content = $response->streamedContent();
        $this->assertStringContainsString('日期,检测时间,平台,关键词,问题,关键词命中,品牌命中,状态,回答摘要,错误信息', $content);
        $this->assertStringContainsString('2026-05-23', $content);
        $this->assertStringContainsString('AI search visibility', $content);
        $this->assertStringContainsString('是', $content);
    }

    public function test_keyword_library_detail_uses_realtime_inclusion_updates_without_page_reload(): void
    {
        $admin = $this->createAdmin('realtime_detail_admin');
        $site = $this->createSiteForAdmin($admin);
        $library = $this->createLibraryWithQuestion((int) $site->id);
        $this->createInclusionRun($library, (int) $site->id);

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.keyword-libraries.detail', ['libraryId' => (int) $library->id]));

        $response->assertOk();
        $response->assertSee('KEYWORD_INCLUSION_REALTIME', false);
        $response->assertSee('admin.keyword-libraries.'.(int) $library->id);
        $response->assertDontSee('window.location.reload', false);
    }

    public function test_admin_can_fetch_inclusion_snapshot_for_silent_refresh(): void
    {
        $admin = $this->createAdmin('snapshot_admin');
        $site = $this->createSiteForAdmin($admin);
        $library = $this->createLibraryWithQuestion((int) $site->id);
        $run = GeoInclusionCheckRun::query()->create([
            'site_id' => (int) $site->id,
            'keyword_library_id' => (int) $library->id,
            'platforms' => ['deepseek'],
            'status' => 'running',
            'total_checks' => 3,
            'completed_checks' => 1,
            'failed_checks' => 0,
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->getJson(route('admin.keyword-libraries.inclusion-snapshot', ['libraryId' => (int) $library->id]));

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('has_running', true)
            ->assertJsonPath('latest_run_id', (int) $run->id);

        $payload = $response->json();
        $this->assertStringContainsString('#'.(int) $run->id.' running', (string) $payload['runs_html']);
        $this->assertStringContainsString('每日监测结果', (string) $payload['daily_reports_html']);
    }

    public function test_inclusion_check_job_broadcasts_progress_update(): void
    {
        Event::fake([KeywordLibraryInclusionUpdated::class]);

        $site = Site::query()->create([
            'owner_admin_id' => null,
            'name' => 'Broadcast Site',
            'status' => 'active',
        ]);
        $library = $this->createLibraryWithQuestion((int) $site->id);
        $keyword = $library->keywords()->firstOrFail();
        $question = $keyword->questionVariants()->firstOrFail();
        $run = GeoInclusionCheckRun::query()->create([
            'site_id' => (int) $site->id,
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
                    answer: 'Acme is mentioned.',
                    keywordHit: true,
                    brandHit: true,
                    status: 'success',
                    errorMessage: null,
                    meta: []
                );
            }
        });

        (new ProcessGeoInclusionCheckJob(
            runId: (int) $run->id,
            keywordId: (int) $keyword->id,
            questionVariantId: (int) $question->id,
            platform: 'doubao'
        ))->handle($this->app->make(AiSearchPlatformChecker::class));

        Event::assertDispatched(KeywordLibraryInclusionUpdated::class, function (KeywordLibraryInclusionUpdated $event) use ($library, $run): bool {
            return $event->libraryId === (int) $library->id
                && $event->runId === (int) $run->id
                && $event->status === 'completed';
        });
    }

    private function createLibraryWithQuestion(?int $siteId = null): KeywordLibrary
    {
        $library = KeywordLibrary::query()->create([
            'site_id' => $siteId,
            'name' => 'Acme GEO Project',
            'company_name' => 'Acme',
            'domain_keyword' => 'GEO visibility',
            'industry' => 'SaaS',
            'brand_description' => 'Acme helps teams manage AI search visibility.',
            'keyword_count' => 1,
            'status' => 'active',
        ]);
        $keyword = Keyword::query()->create([
            'site_id' => $siteId,
            'library_id' => (int) $library->id,
            'keyword' => 'AI search visibility',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        KeywordQuestionVariant::query()->create([
            'site_id' => $siteId,
            'keyword_id' => (int) $keyword->id,
            'question' => 'Which tools improve AI search visibility?',
        ]);

        return $library;
    }

    private function createInclusionRun(KeywordLibrary $library, int $siteId): GeoInclusionCheckRun
    {
        return GeoInclusionCheckRun::query()->create([
            'site_id' => $siteId,
            'keyword_library_id' => (int) $library->id,
            'platforms' => ['deepseek', 'qianwen'],
            'status' => 'completed',
            'total_checks' => 2,
            'completed_checks' => 2,
            'failed_checks' => 0,
            'started_at' => CarbonImmutable::parse('2026-05-23 09:00:00'),
            'completed_at' => CarbonImmutable::parse('2026-05-23 09:35:00'),
        ]);
    }

    private function createSiteForAdmin(Admin $admin): Site
    {
        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => 'Acme Site',
            'status' => 'active',
        ]);
        $site->members()->attach($admin->id, ['role' => 'owner']);

        return $site;
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
