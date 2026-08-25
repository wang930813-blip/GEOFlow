<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Prompt;
use App\Models\Site;
use App\Models\Task;
use App\Models\TitleLibrary;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminAiModelsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_user_cannot_open_ai_configuration_pages(): void
    {
        $agent = $this->createAdmin('ai_config_agent', 'agent_admin');
        $user = $this->createAdmin('ai_config_member', 'site_user', $agent);
        $site = $this->createSiteForAdmin($user, $agent);

        $this->actingAs($user, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.ai.configurator'))
            ->assertForbidden();

        $this->actingAs($user, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.ai-models.index'))
            ->assertForbidden();

        $this->actingAs($user, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.ai-prompts'))
            ->assertForbidden();
    }

    public function test_agent_ai_configuration_is_available_to_child_user_task_form(): void
    {
        $agent = $this->createAdmin('ai_config_agent_owner', 'agent_admin');
        $user = $this->createAdmin('ai_config_child_user', 'site_user', $agent);
        $site = $this->createSiteForAdmin($user, $agent);

        $model = AiModel::withoutEvents(fn (): AiModel => AiModel::query()->withoutGlobalScope('current_site')->create([
            'site_id' => null,
            'owner_admin_id' => (int) $agent->id,
            'name' => 'Agent Shared Chat Model',
            'version' => 'shared',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('agent-api-key'),
            'model_id' => 'agent-shared-chat',
            'model_type' => 'chat',
            'api_url' => 'https://agent-ai.test',
            'failover_priority' => 100,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]));
        Prompt::withoutEvents(fn (): Prompt => Prompt::query()->withoutGlobalScope('current_site')->create([
            'site_id' => null,
            'owner_admin_id' => (int) $agent->id,
            'name' => 'Agent Shared Content Prompt',
            'type' => 'content',
            'content' => 'Write article for {title}',
            'variables' => '',
        ]));

        $response = $this->actingAs($user, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.tasks.create'));

        $response->assertOk()
            ->assertViewHas('formOptions', function (array $options): bool {
                return collect($options['aiModels'] ?? [])->pluck('name')->contains('Agent Shared Chat Model')
                    && collect($options['prompts'] ?? [])->pluck('name')->contains('Agent Shared Content Prompt');
            });
    }

    public function test_agent_ai_configuration_is_used_by_child_user_url_import(): void
    {
        $agent = $this->createAdmin('ai_config_url_agent_owner', 'agent_admin');
        $user = $this->createAdmin('ai_config_url_child_user', 'site_user', $agent);
        $site = $this->createSiteForAdmin($user, $agent);

        AiModel::withoutEvents(fn (): AiModel => AiModel::query()->withoutGlobalScope('current_site')->create([
            'site_id' => null,
            'owner_admin_id' => null,
            'name' => 'Platform Url Import Model',
            'version' => 'platform',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('platform-api-key'),
            'model_id' => 'platform-url-import',
            'model_type' => 'chat',
            'api_url' => 'https://platform-ai.test',
            'failover_priority' => 1,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]));
        AiModel::withoutEvents(fn (): AiModel => AiModel::query()->withoutGlobalScope('current_site')->create([
            'site_id' => null,
            'owner_admin_id' => (int) $agent->id,
            'name' => 'Agent Url Import Model',
            'version' => 'agent',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('agent-api-key'),
            'model_id' => 'agent-url-import',
            'model_type' => 'chat',
            'api_url' => 'https://agent-ai.test',
            'failover_priority' => 1,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]));

        $response = $this->actingAs($user, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.url-import'));

        $response->assertOk()
            ->assertViewHas('aiModelReady', true)
            ->assertViewHas('canManageAiConfig', false)
            ->assertDontSee(route('admin.ai-models.index'), false);
    }

    public function test_admin_can_test_chat_model_connection(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'OK']],
                ],
            ]),
        ]);

        $admin = $this->createAdmin();
        $site = $this->createSiteForAdmin($admin);
        $model = $this->createAiModel('chat', $site);

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->postJson(route('admin.ai-models.test', ['modelId' => (int) $model->id]));

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.model_type', 'chat')
            ->assertJsonPath('meta.http_status', 200);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://ai.test/v1/chat/completions'
            && $request['model'] === 'test-chat-model'
            && $request->hasHeader('Authorization', 'Bearer test-api-key'));
    }

    public function test_admin_models_page_shows_test_action(): void
    {
        $admin = $this->createAdmin();
        $site = $this->createSiteForAdmin($admin);
        $this->createAiModel('chat', $site);

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.ai-models.index'));

        $response->assertOk()
            ->assertSee(__('admin.ai_models.test'));
    }

    public function test_admin_can_delete_model_even_when_tasks_or_title_libraries_reference_it(): void
    {
        $admin = $this->createAdmin('ai_model_delete_admin');
        $site = $this->createSiteForAdmin($admin);
        $model = $this->createAiModel('chat', $site);
        $imageModel = $this->createAiModel('image', $site);

        $task = Task::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Task using deleted model',
            'ai_model_id' => (int) $model->id,
            'ai_image_model_id' => (int) $imageModel->id,
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $library = TitleLibrary::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Library using deleted model',
            'ai_model_id' => (int) $model->id,
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.ai-models.delete', ['modelId' => (int) $model->id]))
            ->assertRedirect(route('admin.ai-models.index'))
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseMissing('ai_models', [
            'id' => (int) $model->id,
        ]);
        $this->assertNull(Task::query()->whereKey((int) $task->id)->value('ai_model_id'));
        $this->assertSame((int) $imageModel->id, (int) Task::query()->whereKey((int) $task->id)->value('ai_image_model_id'));
        $this->assertNull(TitleLibrary::query()->whereKey((int) $library->id)->value('ai_model_id'));

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.ai-models.delete', ['modelId' => (int) $imageModel->id]))
            ->assertRedirect(route('admin.ai-models.index'))
            ->assertSessionDoesntHaveErrors();

        $this->assertNull(Task::query()->whereKey((int) $task->id)->value('ai_image_model_id'));
    }

    public function test_admin_can_test_embedding_model_connection(): void
    {
        Http::fake([
            'https://ai.test/v1/embeddings' => Http::response([
                'data' => [
                    ['embedding' => [0.1, 0.2, 0.3]],
                ],
            ]),
        ]);

        $admin = $this->createAdmin();
        $site = $this->createSiteForAdmin($admin);
        $model = $this->createAiModel('embedding', $site);

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->postJson(route('admin.ai-models.test', ['modelId' => (int) $model->id]));

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.model_type', 'embedding')
            ->assertJsonPath('meta.http_status', 200);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://ai.test/v1/embeddings'
            && $request['model'] === 'test-embedding-model'
            && $request['input'] === 'GEOFlow embedding connection test');
    }

    public function test_model_connection_test_reports_provider_errors(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response(['detail' => 'API Key invalid'], 401),
        ]);

        $admin = $this->createAdmin();
        $site = $this->createSiteForAdmin($admin);
        $model = $this->createAiModel('chat', $site);

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->postJson(route('admin.ai-models.test', ['modelId' => (int) $model->id]));

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('meta.http_status', 401);
    }

    private function createAdmin(
        string $username = 'ai_model_admin',
        string $role = 'super_admin',
        ?Admin $creator = null
    ): Admin {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => $username,
            'role' => $role,
            'status' => 'active',
            'created_by' => $creator?->id,
        ]);
    }

    private function createSiteForAdmin(Admin $admin, ?Admin $agent = null): Site
    {
        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'agent_admin_id' => $agent?->id,
            'name' => 'AI Model Test Site',
            'status' => 'active',
            'customer_mode' => $agent instanceof Admin ? 'agent' : 'direct',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'owner']);

        return $site;
    }

    private function createAiModel(string $type, Site $site): AiModel
    {
        return AiModel::query()->create([
            'site_id' => (int) $site->id,
            'name' => $type === 'embedding' ? 'Test Embedding' : 'Test Chat',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => $type === 'embedding' ? 'test-embedding-model' : 'test-chat-model',
            'model_type' => $type,
            'api_url' => 'https://ai.test',
            'failover_priority' => 100,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
    }
}
