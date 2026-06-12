<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Site;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminAiModelsPageTest extends TestCase
{
    use RefreshDatabase;

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

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'ai_model_admin',
            'password' => 'secret-123',
            'email' => 'ai-model-admin@example.com',
            'display_name' => 'AI Model Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function createSiteForAdmin(Admin $admin): Site
    {
        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => 'AI Model Test Site',
            'status' => 'active',
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
