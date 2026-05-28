<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeoFlowScheduleTasksCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduler_completes_active_task_that_already_reached_article_limit(): void
    {
        $task = Task::query()->create([
            'name' => 'Already completed task',
            'status' => 'active',
            'schedule_enabled' => 1,
            'created_count' => 1,
            'article_limit' => 1,
            'draft_limit' => 1,
            'publish_interval' => 3600,
            'next_run_at' => now()->subMinute(),
        ]);

        $this->artisan('geoflow:schedule-tasks')
            ->expectsOutputToContain('GeoFlow scheduler done')
            ->assertSuccessful();

        $task->refresh();
        $this->assertSame('completed', (string) $task->status);
        $this->assertSame(0, (int) $task->schedule_enabled);
        $this->assertNull($task->next_run_at);
    }
}
