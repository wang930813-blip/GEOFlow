<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\Site;
use App\Models\Task;
use App\Services\GeoFlow\WorkerExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class WorkerExecutionServiceAuthorFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_uses_existing_author_when_task_author_is_empty(): void
    {
        $author = Author::query()->create(['name' => 'Existing Author']);
        $task = Task::query()->create(['name' => 'Task without author']);

        $picked = $this->pickAuthor($task);

        $this->assertSame($author->id, $picked->id);
        $this->assertSame(1, Author::query()->count());
    }

    public function test_worker_falls_back_when_configured_author_is_missing(): void
    {
        $author = Author::query()->create(['name' => 'Fallback Author']);
        $task = Task::query()->create([
            'name' => 'Task with missing author',
            'author_id' => 99999,
        ]);

        $picked = $this->pickAuthor($task);

        $this->assertSame($author->id, $picked->id);
    }

    public function test_worker_creates_default_author_when_no_author_exists(): void
    {
        $task = Task::query()->create(['name' => 'Task without any author']);

        $picked = $this->pickAuthor($task);

        $this->assertSame('GEOFlow', $picked->name);
        $this->assertDatabaseHas('authors', [
            'id' => $picked->id,
            'name' => 'GEOFlow',
        ]);
    }

    public function test_worker_randomly_selects_authors_only_from_the_task_site(): void
    {
        $otherSite = Site::query()->create([
            'name' => 'Other Author Site',
            'status' => 'active',
        ]);
        $taskSite = Site::query()->create([
            'name' => 'Task Author Site',
            'status' => 'active',
        ]);
        $otherAuthor = Author::query()->create([
            'site_id' => (int) $otherSite->id,
            'name' => 'Other Site Author',
        ]);
        $taskAuthors = collect([
            Author::query()->create([
                'site_id' => (int) $taskSite->id,
                'name' => 'Task Site Author A',
            ]),
            Author::query()->create([
                'site_id' => (int) $taskSite->id,
                'name' => 'Task Site Author B',
            ]),
        ]);
        $task = Task::query()->create([
            'site_id' => (int) $taskSite->id,
            'name' => 'Task with random author',
            'author_id' => (int) $otherAuthor->id,
        ]);

        $pickedIds = collect(range(1, 40))
            ->map(fn (): int => (int) $this->pickAuthor($task)->id)
            ->unique()
            ->sort()
            ->values();

        $this->assertNotContains((int) $otherAuthor->id, $pickedIds->all());
        $this->assertSame(
            $taskAuthors->pluck('id')->map(static fn ($id): int => (int) $id)->sort()->values()->all(),
            $pickedIds->all()
        );
    }

    public function test_worker_always_uses_the_configured_author_from_the_task_site(): void
    {
        $site = Site::query()->create([
            'name' => 'Fixed Author Site',
            'status' => 'active',
        ]);
        Author::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Unselected Author',
        ]);
        $selectedAuthor = Author::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Selected Author',
        ]);
        $task = Task::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Task with selected author',
            'author_id' => (int) $selectedAuthor->id,
        ]);

        $pickedIds = collect(range(1, 10))
            ->map(fn (): int => (int) $this->pickAuthor($task)->id)
            ->unique()
            ->values()
            ->all();

        $this->assertSame([(int) $selectedAuthor->id], $pickedIds);
    }

    public function test_worker_creates_default_author_inside_the_task_site(): void
    {
        $site = Site::query()->create([
            'name' => 'Default Author Site',
            'status' => 'active',
        ]);
        $task = Task::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Task without site author',
        ]);

        $picked = $this->pickAuthor($task);

        $this->assertSame((int) $site->id, (int) $picked->site_id);
        $this->assertSame('GEOFlow', $picked->name);
    }

    private function pickAuthor(Task $task): Author
    {
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'pickAuthor');
        $method->setAccessible(true);

        return $method->invoke($service, $task);
    }
}
