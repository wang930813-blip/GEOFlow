<?php

namespace Tests\Feature;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\AiModel;
use App\Models\Task;
use App\Services\GeoFlow\WorkerExecutionService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\AgentStreamed;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class WorkerExecutionStreamingTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_article_content_generation_uses_streaming(): void
    {
        config(['geoflow.api_key_crypto_roots' => ['worker-streaming-test-key']]);

        $model = AiModel::query()->create([
            'name' => 'Streaming Chat Model',
            'model_id' => 'gpt-test',
            'model_type' => 'chat',
            'api_url' => 'https://ai.example.test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'status' => 'active',
        ]);
        $task = Task::query()->create([
            'name' => 'Streaming Article Task',
            'ai_model_id' => (int) $model->id,
            'model_selection_mode' => 'fixed',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);

        MarkdownContentWriterAgent::fake(['Streamed article body.'])->preventStrayPrompts();
        Event::fake([AgentStreamed::class]);

        $method = new ReflectionMethod(WorkerExecutionService::class, 'generateContentWithModelSelection');
        $method->setAccessible(true);

        $result = $method->invoke(app(WorkerExecutionService::class), $task, 'Write a complete article.');

        $this->assertSame('Streamed article body.', $result['content']);
        Event::assertDispatched(AgentStreamed::class);
    }

    public function test_task_without_available_ai_model_fails_immediately(): void
    {
        $task = Task::query()->create([
            'name' => 'Task without AI model',
            'ai_model_id' => null,
            'model_selection_mode' => 'fixed',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);

        $method = new ReflectionMethod(WorkerExecutionService::class, 'generateContentWithModelSelection');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Task AI model is not configured');

        $method->invoke(app(WorkerExecutionService::class), $task, 'Write a complete article.');
    }
}
