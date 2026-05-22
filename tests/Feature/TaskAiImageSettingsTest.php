<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Author;
use App\Models\Category;
use App\Models\Prompt;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\TaskLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskAiImageSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_lifecycle_persists_ai_image_settings(): void
    {
        $titleLibrary = TitleLibrary::query()->create(['name' => 'Titles']);
        $prompt = Prompt::query()->create(['name' => 'Content Prompt', 'type' => 'content', 'content' => 'Write.']);
        $chatModel = AiModel::query()->create([
            'name' => 'Chat Model',
            'model_id' => 'gpt-4o',
            'model_type' => 'chat',
            'api_url' => 'https://api.openai.com',
            'api_key' => 'enc:v1:test',
            'status' => 'active',
        ]);
        $imageModel = AiModel::query()->create([
            'name' => 'Image Model',
            'model_id' => 'gpt-image-1',
            'model_type' => 'image',
            'api_url' => 'https://api.openai.com',
            'api_key' => 'enc:v1:test',
            'status' => 'active',
        ]);
        $author = Author::query()->create(['name' => 'Author']);
        Category::query()->create(['name' => 'Default', 'slug' => 'default']);

        $task = app(TaskLifecycleService::class)->createTask([
            'name' => 'AI image task',
            'title_library_id' => $titleLibrary->id,
            'prompt_id' => $prompt->id,
            'ai_model_id' => $chatModel->id,
            'author_id' => $author->id,
            'image_mode' => 'ai',
            'image_count' => 3,
            'ai_image_model_id' => $imageModel->id,
            'status' => 'paused',
            'category_mode' => 'smart',
        ]);

        $this->assertSame('ai', $task['image_mode']);
        $this->assertSame(3, $task['image_count']);
        $this->assertSame($imageModel->id, $task['ai_image_model_id']);
    }
}
