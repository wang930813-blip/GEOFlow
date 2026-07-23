<?php

namespace Tests\Unit;

use App\Models\Admin;
use App\Models\BrandDiagnosisRun;
use App\Services\BrandDiagnosis\BrandDiagnosisApiResultPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandDiagnosisApiResultPresenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_run_payload_contains_brand_performance(): void
    {
        $admin = Admin::query()->create([
            'username' => 'brand_api_presenter_admin',
            'password' => 'secret-123',
            'email' => 'brand-api-presenter@example.com',
            'display_name' => 'Brand API Presenter Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => null,
            'owner_admin_id' => (int) $admin->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '武城煊饼',
            'platforms' => ['doubao'],
            'api_task_key' => 'bdg_'.str_repeat('3', 32),
            'status' => 'completed',
            'total_questions' => 1,
            'completed_questions' => 1,
            'failed_questions' => 0,
            'brand_score' => 88,
            'mention_rate' => 75,
            'average_rank' => 2.5,
            'mention_count' => 6,
            'sentiment_rate' => 100,
        ]);

        $payload = app(BrandDiagnosisApiResultPresenter::class)->detail($run);

        $this->assertSame('completed', $payload['status']);
        $this->assertSame('武城煊饼', $payload['brand_name']);
        $this->assertSame(88, $payload['brand_performance']['score']);
        $this->assertSame(75, $payload['brand_performance']['mention_rate']);
        $this->assertSame('2.5', $payload['brand_performance']['average_rank']);
        $this->assertSame(6, $payload['brand_performance']['mention_count']);
        $this->assertSame(100, $payload['brand_performance']['sentiment_rate']);
    }
}
