<?php

namespace Tests\Feature;

use App\Jobs\ProcessGeoFlowTaskJob;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Task;
use App\Models\TaskRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
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

    public function test_scheduler_keeps_article_limit_reached_task_active_when_publishable_drafts_remain(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-06-16 10:00:00'));

        $task = Task::query()->create([
            'name' => 'Reached limit with drafts task',
            'status' => 'active',
            'schedule_enabled' => 1,
            'created_count' => 1,
            'article_limit' => 1,
            'draft_limit' => 5,
            'publish_interval' => 120,
            'next_run_at' => Carbon::parse('2026-06-16 09:59:00'),
            'next_publish_at' => Carbon::parse('2026-06-16 09:59:00'),
        ]);
        $category = Category::query()->create([
            'name' => 'Reached Limit Category',
            'slug' => 'reached-limit-category',
        ]);
        $author = Author::query()->create([
            'name' => 'Reached Limit Author',
        ]);
        Article::query()->create([
            'title' => 'Publishable draft after generation limit',
            'slug' => 'publishable-draft-after-generation-limit',
            'excerpt' => 'excerpt',
            'content' => 'content',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'task_id' => (int) $task->id,
            'status' => 'draft',
            'review_status' => 'approved',
            'published_at' => null,
        ]);

        $this->artisan('geoflow:schedule-tasks')
            ->expectsOutputToContain('GeoFlow scheduler done')
            ->assertSuccessful();

        $task->refresh();
        $this->assertSame('active', (string) $task->status);
        $this->assertSame(1, (int) $task->schedule_enabled);
        $this->assertSame(1, TaskRun::query()->where('task_id', (int) $task->id)->where('status', 'pending')->count());
        Queue::assertPushed(ProcessGeoFlowTaskJob::class);

        Carbon::setTestNow();
    }
}
