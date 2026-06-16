<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\PlatformPlan;
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
use ReflectionMethod;
use Tests\TestCase;

class WorkerExecutionSiteIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_uses_fixed_task_title_when_configured(): void
    {
        $titleLibrary = TitleLibrary::query()->create([
            'name' => 'Fixed Title Library',
        ]);
        Title::query()->create([
            'library_id' => (int) $titleLibrary->id,
            'title' => 'First rotating title',
            'keyword' => 'first',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $fixedTitle = Title::query()->create([
            'library_id' => (int) $titleLibrary->id,
            'title' => 'Fixed selected title',
            'keyword' => 'fixed',
            'used_count' => 5,
            'usage_count' => 5,
        ]);
        $task = Task::query()->create([
            'name' => 'Fixed Title Task',
            'title_library_id' => (int) $titleLibrary->id,
            'fixed_title_id' => (int) $fixedTitle->id,
            'status' => 'active',
            'schedule_enabled' => 1,
            'draft_limit' => 5,
            'article_limit' => 5,
        ]);

        $method = new ReflectionMethod(WorkerExecutionService::class, 'pickTitle');
        $method->setAccessible(true);

        $pickedTitle = $method->invoke(app(WorkerExecutionService::class), $task);

        $this->assertInstanceOf(Title::class, $pickedTitle);
        $this->assertSame((int) $fixedTitle->id, (int) $pickedTitle->id);
    }

    public function test_worker_pauses_task_after_reaching_article_limit(): void
    {
        config(['geoflow.api_key_crypto_roots' => ['worker-limit-pause-test-key']]);

        $site = $this->createSite([
            'name' => 'Limit Pause Site',
            'status' => 'active',
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Limit Pause Titles',
        ]);
        Title::query()->create([
            'site_id' => (int) $site->id,
            'library_id' => (int) $titleLibrary->id,
            'title' => 'Limit Pause Article',
            'keyword' => 'limit pause',
        ]);
        $prompt = Prompt::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Limit Pause Prompt',
            'type' => 'content',
            'content' => 'Write about {{title}}.',
        ]);
        $author = Author::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Limit Pause Author',
        ]);
        Category::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Limit Pause Category',
            'slug' => 'limit-pause-category',
        ]);
        $aiModel = AiModel::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Limit Pause Chat',
            'model_id' => 'deepseek-chat',
            'model_type' => 'chat',
            'api_url' => 'https://ai.example.test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'status' => 'active',
        ]);
        $task = Task::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Limit pause task',
            'title_library_id' => (int) $titleLibrary->id,
            'prompt_id' => (int) $prompt->id,
            'ai_model_id' => (int) $aiModel->id,
            'author_id' => (int) $author->id,
            'need_review' => 1,
            'status' => 'active',
            'schedule_enabled' => 1,
            'draft_limit' => 1,
            'article_limit' => 1,
        ]);

        app(CurrentSite::class)->set(null);

        Http::fake([
            'https://ai.example.test/v1/chat/completions' => Http::response($this->chatCompletion("# Limit Pause Article\n\nGenerated body.")),
        ]);

        app(WorkerExecutionService::class)->executeTask((int) $task->id);

        $task->refresh();
        $this->assertSame(1, (int) $task->created_count);
        $this->assertSame('paused', (string) $task->status);
        $this->assertSame(0, (int) $task->schedule_enabled);
        $this->assertNull($task->next_run_at);
    }

    public function test_worker_generated_article_inherits_task_site_without_request_context(): void
    {
        config(['geoflow.api_key_crypto_roots' => ['worker-site-isolation-test-key']]);

        $site = $this->createSite([
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

    public function test_worker_consumes_article_generation_quota_for_task_owner_and_sets_article_owner(): void
    {
        config(['geoflow.api_key_crypto_roots' => ['worker-owner-quota-test-key']]);

        $owner = Admin::query()->create([
            'username' => 'worker_owner_quota_admin',
            'password' => 'secret-123',
            'email' => 'worker-owner-quota@example.com',
            'display_name' => 'Worker Owner Quota Admin',
            'role' => 'site_user',
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => (int) $owner->id,
            'name' => 'Worker Owner Quota Site',
            'status' => 'active',
        ]);
        $site->members()->attach((int) $owner->id, ['role' => 'member']);
        $this->openTestingPlanForSite($site, $owner, [
            PlatformPlan::RESOURCE_ARTICLE_GENERATIONS => ['quota_value' => 1, 'quota_period' => 'cycle', 'unit' => 'times'],
        ]);

        $titleLibrary = TitleLibrary::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'name' => 'Owner Quota Titles',
        ]);
        Title::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'library_id' => (int) $titleLibrary->id,
            'title' => 'Owner Quota Article',
            'keyword' => 'owner quota',
        ]);
        $prompt = Prompt::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Owner Quota Prompt',
            'type' => 'content',
            'content' => 'Write about {{title}}.',
        ]);
        $author = Author::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'name' => 'Owner Quota Author',
        ]);
        Category::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'name' => 'Owner Quota Category',
            'slug' => 'owner-quota-category',
        ]);
        $aiModel = AiModel::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Owner Quota Chat',
            'model_id' => 'deepseek-chat',
            'model_type' => 'chat',
            'api_url' => 'https://ai.example.test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'status' => 'active',
        ]);
        $task = Task::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'name' => 'Owner quota task',
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
            'https://ai.example.test/v1/chat/completions' => Http::response($this->chatCompletion("# Owner Quota Article\n\nGenerated body.")),
        ]);

        $result = app(WorkerExecutionService::class)->executeTask((int) $task->id);

        $article = Article::withoutGlobalScopes()->findOrFail((int) $result['article_id']);
        $this->assertSame((int) $owner->id, (int) $article->owner_admin_id);
        $this->assertDatabaseHas('admin_resource_usages', [
            'admin_id' => (int) $owner->id,
            'site_id' => (int) $site->id,
            'resource_key' => PlatformPlan::RESOURCE_ARTICLE_GENERATIONS,
            'used_amount' => 1,
        ]);
    }

    public function test_worker_applies_special_keyword_and_description_prompts_to_generated_article(): void
    {
        config(['geoflow.api_key_crypto_roots' => ['worker-special-prompts-test-key']]);

        $site = $this->createSite([
            'name' => 'Special Prompt Site',
            'status' => 'active',
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Special Prompt Titles',
        ]);
        Title::query()->create([
            'site_id' => (int) $site->id,
            'library_id' => (int) $titleLibrary->id,
            'title' => 'Special Prompt Article',
            'keyword' => 'original keyword',
        ]);
        $contentPrompt = Prompt::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Content Prompt',
            'type' => 'content',
            'content' => 'Write about {{title}}.',
        ]);
        Prompt::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Keyword Prompt',
            'type' => 'keyword',
            'content' => 'CUSTOM_KEYWORD_PROMPT title={{title}} keyword={{keyword}} content={{content}}',
        ]);
        Prompt::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Description Prompt',
            'type' => 'description',
            'content' => 'CUSTOM_DESCRIPTION_PROMPT title={{title}} keyword={{keyword}} content={{content}}',
        ]);
        $author = Author::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Prompt Author',
        ]);
        Category::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Prompt Category',
            'slug' => 'prompt-category',
        ]);
        $aiModel = AiModel::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Prompt Chat',
            'model_id' => 'deepseek-chat',
            'model_type' => 'chat',
            'api_url' => 'https://ai.example.test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'status' => 'active',
        ]);
        $task = Task::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Special prompt generation task',
            'title_library_id' => (int) $titleLibrary->id,
            'prompt_id' => (int) $contentPrompt->id,
            'ai_model_id' => (int) $aiModel->id,
            'author_id' => (int) $author->id,
            'auto_keywords' => 1,
            'auto_description' => 1,
            'need_review' => 1,
            'status' => 'active',
            'schedule_enabled' => 1,
            'draft_limit' => 5,
            'article_limit' => 5,
        ]);

        app(CurrentSite::class)->set(null);

        Http::fake([
            'https://ai.example.test/v1/chat/completions' => Http::sequence()
                ->push($this->chatCompletion("# Special Prompt Article\n\nGenerated body for prompt testing."))
                ->push($this->chatCompletion('custom keyword one, custom keyword two'))
                ->push($this->chatCompletion('Custom generated meta description.')),
        ]);

        $result = app(WorkerExecutionService::class)->executeTask((int) $task->id);

        $article = Article::withoutGlobalScope('current_site')->findOrFail((int) $result['article_id']);
        $this->assertSame('custom keyword one、custom keyword two', (string) $article->keywords);
        $this->assertSame('Custom generated meta description.', (string) $article->meta_description);

        Http::assertSentCount(3);
        Http::assertSent(fn ($request): bool => str_contains(json_encode($request->data(), JSON_UNESCAPED_UNICODE) ?: '', 'CUSTOM_KEYWORD_PROMPT'));
        Http::assertSent(fn ($request): bool => str_contains(json_encode($request->data(), JSON_UNESCAPED_UNICODE) ?: '', 'CUSTOM_DESCRIPTION_PROMPT'));
    }

    public function test_worker_smart_category_selection_stays_inside_task_site_without_request_context(): void
    {
        config(['geoflow.api_key_crypto_roots' => ['worker-category-site-test-key']]);

        $otherSite = $this->createSite([
            'name' => 'Other Site',
            'status' => 'active',
        ]);
        Category::query()->create([
            'site_id' => (int) $otherSite->id,
            'name' => 'Other Category',
            'slug' => 'other-category',
            'sort_order' => 0,
        ]);

        $site = $this->createSite([
            'name' => 'Task Site',
            'status' => 'active',
        ]);
        $siteCategory = Category::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Task Site Category',
            'slug' => 'task-site-category',
            'sort_order' => 0,
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Task Site Titles',
        ]);
        Title::query()->create([
            'site_id' => (int) $site->id,
            'library_id' => (int) $titleLibrary->id,
            'title' => 'Task Site Article',
            'keyword' => 'task site keyword',
        ]);
        $prompt = Prompt::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Content Prompt',
            'type' => 'content',
            'content' => 'Write about {{title}}.',
        ]);
        $author = Author::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Task Site Author',
        ]);
        $aiModel = AiModel::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Task Site Chat',
            'model_id' => 'deepseek-chat',
            'model_type' => 'chat',
            'api_url' => 'https://ai.example.test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'status' => 'active',
        ]);
        $task = Task::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Task site smart category task',
            'title_library_id' => (int) $titleLibrary->id,
            'prompt_id' => (int) $prompt->id,
            'ai_model_id' => (int) $aiModel->id,
            'author_id' => (int) $author->id,
            'category_mode' => 'smart',
            'need_review' => 1,
            'status' => 'active',
            'schedule_enabled' => 1,
            'draft_limit' => 5,
            'article_limit' => 5,
        ]);

        app(CurrentSite::class)->set(null);

        Http::fake([
            'https://ai.example.test/v1/chat/completions' => Http::response($this->chatCompletion("# Task Site Article\n\nGenerated body.")),
        ]);

        $result = app(WorkerExecutionService::class)->executeTask((int) $task->id);

        $article = Article::withoutGlobalScope('current_site')->findOrFail((int) $result['article_id']);
        $this->assertSame((int) $site->id, (int) $article->site_id);
        $this->assertSame((int) $siteCategory->id, (int) $article->category_id);
    }

    /**
     * @return array<string,mixed>
     */
    private function chatCompletion(string $content): array
    {
        return [
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $content,
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function createSite(array $attributes): Site
    {
        $site = Site::query()->create($attributes);
        $this->openTestingPlanForSite($site);

        return $site;
    }
}
