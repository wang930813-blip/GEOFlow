<?php

namespace Tests\Feature;

use App\Jobs\ProcessGeoFlowTaskJob;
use App\Models\Task;
use App\Models\TaskRun;
use App\Services\GeoFlow\JobQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class JobQueueServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_model_configuration_errors_fail_without_retrying(): void
    {
        Queue::fake();

        foreach ([
            'Task AI model is not configured',
            'Task AI model is unavailable',
        ] as $message) {
            $task = Task::query()->create([
                'name' => 'Task without model',
                'ai_model_id' => null,
                'status' => 'active',
                'schedule_enabled' => 1,
                'max_retry_count' => 3,
            ]);
            $run = TaskRun::query()->create([
                'task_id' => (int) $task->id,
                'status' => 'running',
                'meta' => [
                    'attempt_count' => 0,
                    'max_attempts' => 3,
                ],
                'started_at' => now(),
            ]);

            app(JobQueueService::class)->failJob(
                (int) $run->id,
                (int) $task->id,
                $message,
                25
            );

            $run->refresh();

            $this->assertSame('failed', (string) $run->status);
            $this->assertSame(25, (int) $run->duration_ms);
            $this->assertNotNull($run->finished_at);
            $this->assertSame(1, (int) ($run->meta['attempt_count'] ?? 0));
            $this->assertTrue((bool) ($run->meta['non_retryable'] ?? false));
        }

        Queue::assertNotPushed(ProcessGeoFlowTaskJob::class);
    }
}
