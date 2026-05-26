<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Prompt;
use App\Models\Site;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\WorkerExecutionService;
use App\Support\CurrentSite;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkerExecutionSiteIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_generated_article_inherits_task_site_without_request_context(): void
    {
        config(['geoflow.api_key_crypto_roots' => ['worker-site-isolation-test-key']]);

        $site = Site::query()->create([
            'name' => 'Default Site',
            'status' => 'active',
        ]);

        $titleLibrary = TitleLibrary::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Company Titles',
        ]);
        Title::query()->create([
            'site_id' => (int) $site->id,
            'library_id' => (int) $titleLibrary->id,
            'title' => 'Generated Company Article',
            'keyword' => 'company profile',
        ]);

        $prompt = Prompt::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Prompt',
            'type' => 'content',
            'content' => 'Write about {{title}}.',
        ]);
        $author = Author::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Site Author',
        ]);
        Category::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Default',
            'slug' => 'default',
        ]);

        $aiModel = AiModel::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Compatible Chat',
            'model_id' => 'deepseek-chat',
            'model_type' => 'chat',
            'api_url' => 'https://ai.example.test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'status' => 'active',
        ]);

        $task = Task::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Site scoped generation task',
            'title_library_id' => (int) $titleLibrary->id,
            'prompt_id' => (int) $prompt->id,
            'ai_model_id' => (int) $aiModel->id,
            'author_id' => (int) $author->id,
            'need_review' => 1,
            'status' => 'active',
            'schedule_enabled' => 1,
            'draft_limit' => 5,
            'article_limit' => 5,
        ]);

        app(CurrentSite::class)->set(null);

        Http::fake([
            'https://ai.example.test/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-test',
                'object' => 'chat.completion',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => "# Generated Company Article\n\nGenerated body.",
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
            ]),
        ]);

        $result = app(WorkerExecutionService::class)->executeTask((int) $task->id);

        $article = Article::withoutGlobalScope('current_site')->findOrFail((int) $result['article_id']);
        $this->assertSame((int) $site->id, (int) $article->site_id);

        app(CurrentSite::class)->set($site);

        $this->assertTrue(Article::query()->whereKey((int) $article->id)->exists());
    }
}
