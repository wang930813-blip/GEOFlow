<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminCreditAccount;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\MediaResource;
use App\Models\MediaResourceSitePrice;
use App\Models\MediaSubmission;
use App\Models\Prompt;
use App\Models\Site;
use App\Models\Task;
use App\Models\TitleLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * API v1 契约：鉴权、scope、登录与统一信封（SQLite 测试库依赖 {@see 2026_04_18_120002_sqlite_geoflow_minimal_for_testing}）。
 */
class ApiV1ContractTest extends TestCase
{
    use RefreshDatabase;

    private function createActiveAdmin(string $username = 'api_test_admin', string $password = 'secret-123'): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => $password,
            'email' => 't@example.com',
            'display_name' => 'API Test',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    /**
     * @param  list<string>  $scopes
     * @return array{plain: string}
     */
    private function createBearerToken(Admin $admin, array $scopes, ?int $siteId = null): array
    {
        $token = $admin->createToken('contract-test', $scopes);
        if ($siteId !== null) {
            $token->accessToken->forceFill(['site_id' => $siteId])->save();
        }

        return ['plain' => $token->plainTextToken];
    }

    public function test_catalog_requires_bearer_token(): void
    {
        $this->getJson('/api/v1/catalog')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'unauthorized');
    }

    public function test_login_validation_empty_credentials(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_error_response_includes_request_id_meta(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonStructure(['meta' => ['request_id', 'timestamp']]);
    }

    public function test_login_invalid_credentials_returns_401(): void
    {
        $this->createActiveAdmin('u1', 'right-pass');

        $this->postJson('/api/v1/auth/login', [
            'username' => 'u1',
            'password' => 'wrong-pass',
        ])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_credentials');
    }

    public function test_login_success_returns_token_and_admin_summary(): void
    {
        $this->createActiveAdmin('u2', 'good-pass');

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'u2',
            'password' => 'good-pass',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['token', 'scopes', 'expires_at', 'admin' => ['id', 'username', 'display_name', 'role', 'status']],
                'meta' => ['request_id', 'timestamp'],
            ]);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertNotEmpty($response->json('data.expires_at'));
        $this->assertContains('materials:read', $response->json('data.scopes'));
        $this->assertContains('materials:write', $response->json('data.scopes'));
    }

    public function test_login_locks_account_after_repeated_password_failures(): void
    {
        $admin = $this->createActiveAdmin('lock_me', 'right-pass');

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'username' => 'lock_me',
                'password' => 'wrong-pass',
            ])->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', [
            'username' => 'lock_me',
            'password' => 'wrong-pass',
        ])
            ->assertStatus(423)
            ->assertJsonPath('error.code', 'account_locked');

        $this->assertSame('locked', $admin->fresh()->status);
    }

    public function test_catalog_forbidden_when_scope_missing(): void
    {
        $admin = $this->createActiveAdmin('u3', 'p');
        $bearer = $this->createBearerToken($admin, ['tasks:read']);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/catalog')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_catalog_success_envelope_with_catalog_read_scope(): void
    {
        $admin = $this->createActiveAdmin('u4', 'p');
        $bearer = $this->createBearerToken($admin, ['catalog:read']);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/catalog')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'models',
                    'prompts',
                    'keyword_libraries',
                    'title_libraries',
                    'image_libraries',
                    'knowledge_bases',
                    'authors',
                    'categories',
                ],
                'meta' => ['request_id', 'timestamp'],
            ]);
    }

    public function test_materials_require_materials_scope(): void
    {
        $admin = $this->createActiveAdmin('u5', 'p');
        $bearer = $this->createBearerToken($admin, ['catalog:read']);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/materials')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_keyword_library_material_crud_and_items(): void
    {
        $admin = $this->createActiveAdmin('u6', 'p');
        $bearer = $this->createBearerToken($admin, ['materials:read', 'materials:write']);

        $create = $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson('/api/v1/materials/keyword-libraries', [
                'name' => 'API Keywords',
                'description' => 'Created from API',
            ]);

        $create->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'keyword-libraries')
            ->assertJsonPath('data.item.name', 'API Keywords');

        $libraryId = (int) $create->json('data.item.id');

        $item = $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson("/api/v1/materials/keyword-libraries/{$libraryId}/items", [
                'keyword' => 'geo automation',
            ]);

        $item->assertCreated()
            ->assertJsonPath('data.parent_id', $libraryId)
            ->assertJsonPath('data.item.keyword', 'geo automation');

        $this->assertDatabaseHas('keyword_libraries', ['id' => $libraryId, 'keyword_count' => 1]);
        $this->assertDatabaseHas('keywords', ['library_id' => $libraryId, 'keyword' => 'geo automation']);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/materials/keyword-libraries')
            ->assertOk()
            ->assertJsonPath('data.type', 'keyword-libraries')
            ->assertJsonPath('data.pagination.total', 1);
    }

    public function test_delete_material_items_refreshes_counts(): void
    {
        $admin = $this->createActiveAdmin('u7', 'p');
        $bearer = $this->createBearerToken($admin, ['materials:read', 'materials:write']);
        $library = KeywordLibrary::query()->create([
            'name' => 'Delete Items',
            'description' => '',
            'keyword_count' => 1,
        ]);
        $keyword = Keyword::query()->create([
            'library_id' => $library->id,
            'keyword' => 'delete me',
            'used_count' => 0,
            'usage_count' => 0,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->deleteJson("/api/v1/materials/keyword-libraries/{$library->id}/items", [
                'ids' => [$keyword->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.deleted_count', 1);

        $this->assertDatabaseMissing('keywords', ['id' => $keyword->id]);
        $this->assertDatabaseHas('keyword_libraries', ['id' => $library->id, 'keyword_count' => 0]);
    }

    public function test_task_delete_api_removes_task(): void
    {
        $admin = $this->createActiveAdmin('u8', 'p');
        $bearer = $this->createBearerToken($admin, ['tasks:write']);
        $task = Task::query()->create([
            'name' => 'API delete task',
            'status' => 'paused',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->deleteJson("/api/v1/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true)
            ->assertJsonPath('data.id', $task->id);

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }

    public function test_task_create_accepts_omitted_optional_material_fields(): void
    {
        $admin = $this->createActiveAdmin('u9', 'p');
        $bearer = $this->createBearerToken($admin, ['tasks:write']);
        $model = AiModel::query()->create([
            'name' => 'Task Create Model',
            'model_id' => 'task-create-model',
            'model_type' => 'chat',
            'status' => 'active',
        ]);
        $prompt = Prompt::query()->create([
            'name' => 'Task Create Prompt',
            'type' => 'content',
            'content' => 'Write an article.',
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => 'Task Create Titles',
            'description' => '',
            'title_count' => 0,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson('/api/v1/tasks', [
                'name' => 'API create task with optional fields omitted',
                'title_library_id' => $titleLibrary->id,
                'prompt_id' => $prompt->id,
                'ai_model_id' => $model->id,
                'status' => 'paused',
                'category_mode' => 'smart',
                'draft_limit' => 1,
                'article_limit' => 1,
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'API create task with optional fields omitted')
            ->assertJsonPath('data.image_library_id', null)
            ->assertJsonPath('data.author_id', null)
            ->assertJsonPath('data.knowledge_base_id', null)
            ->assertJsonPath('data.fixed_category_id', null);

        $this->assertDatabaseHas('tasks', [
            'id' => $response->json('data.id'),
            'image_library_id' => null,
            'author_id' => null,
            'knowledge_base_id' => null,
            'fixed_category_id' => null,
        ]);
    }

    public function test_article_create_accepts_keywords_array_from_external_editors(): void
    {
        $admin = $this->createActiveAdmin('article_keywords_admin', 'p');
        $site = Site::query()->create([
            'owner_admin_id' => $admin->id,
            'name' => 'API Article Site',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'site_id' => $site->id,
            'name' => 'API Article Category',
            'slug' => 'api-article-category',
        ]);
        $author = Author::query()->create([
            'site_id' => $site->id,
            'name' => 'API Article Author',
        ]);
        $bearer = $this->createBearerToken($admin, ['articles:write'], (int) $site->id);

        $response = $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson('/api/v1/articles', [
                'title' => 'External Editor Article',
                'content' => '<p>External editor content.</p>',
                'category_id' => $category->id,
                'author_id' => $author->id,
                'status' => 'draft',
                'review_status' => 'pending',
                'keywords' => ['GEO优化', 'AI搜索曝光', '', 'GEO优化'],
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.keywords', 'GEO优化,AI搜索曝光');

        $this->assertDatabaseHas('articles', [
            'id' => $response->json('data.id'),
            'site_id' => $site->id,
            'keywords' => 'GEO优化,AI搜索曝光',
        ]);
    }

    public function test_media_resources_require_media_read_scope(): void
    {
        $admin = $this->createActiveAdmin('media_scope_admin', 'p');
        $bearer = $this->createBearerToken($admin, ['articles:read']);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/media/resources')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_media_resource_list_returns_active_resources(): void
    {
        $admin = $this->createActiveAdmin('media_resource_admin', 'p');
        $bearer = $this->createBearerToken($admin, ['media:read']);
        MediaResource::query()->create([
            'source_type' => 'website_media',
            'external_resource_id' => 'api-media-1',
            'title' => 'API Media',
            'category' => 'news',
            'status' => 'active',
            'cost_price' => '1.00',
            'sale_price' => '3.00',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/media/resources')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items.0.title', 'API Media')
            ->assertJsonPath('data.items.0.sale_price', '3.00');
    }

    public function test_site_bound_token_uses_bound_site_for_media_prices(): void
    {
        $admin = $this->createActiveAdmin('media_site_price_admin', 'p');
        [$siteA, $siteB] = $this->createTwoSites($admin);
        $bearer = $this->createBearerToken($admin, ['media:read'], (int) $siteA->id);
        $resource = MediaResource::query()->create([
            'source_type' => 'website_media',
            'external_resource_id' => 'api-media-price',
            'title' => 'API Media Price',
            'category' => 'news',
            'status' => 'active',
            'cost_price' => '1.00',
            'sale_price' => '3.00',
        ]);
        MediaResourceSitePrice::query()->create([
            'site_id' => $siteA->id,
            'media_resource_id' => $resource->id,
            'sale_price' => '6.00',
        ]);
        MediaResourceSitePrice::query()->create([
            'site_id' => $siteB->id,
            'media_resource_id' => $resource->id,
            'sale_price' => '9.00',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/media/resources?site_id='.$siteB->id)
            ->assertOk()
            ->assertJsonPath('data.items.0.sale_price', '6.00');
    }

    public function test_media_submission_api_submits_article_to_media(): void
    {
        $admin = $this->createActiveAdmin('media_submit_api_admin', 'p');
        $bearer = $this->createBearerToken($admin, ['media:submit']);
        [$article, $resource] = $this->createArticleAndMediaResource($admin);
        AdminCreditAccount::query()->create([
            'admin_id' => $admin->id,
            'site_id' => $article->site_id,
            'balance' => '50.00',
            'frozen_balance' => '0.00',
            'total_granted' => '50.00',
            'total_consumed' => '0.00',
        ]);
        Http::fake([
            '*/api/media/send' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => ['order_nid' => 'api-submit-order'],
            ]),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson('/api/v1/media/submissions', [
                'article_ids' => [$article->id],
                'media_resource_ids' => [$resource->id],
                'remark' => 'api submit',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.submissions.0.external_order_nid', 'api-submit-order')
            ->assertJsonPath('data.submissions.0.status_label', '待安排')
            ->assertJsonPath('data.submissions.0.points_amount', '3.00');

        $this->assertDatabaseHas('media_submissions', [
            'article_id' => $article->id,
            'media_resource_id' => $resource->id,
            'external_order_nid' => 'api-submit-order',
        ]);
        $this->assertDatabaseHas('admin_credit_accounts', [
            'admin_id' => $admin->id,
            'site_id' => $article->site_id,
            'balance' => '47.00',
            'total_consumed' => '3.00',
        ]);
    }

    public function test_media_submission_api_list_auto_syncs_visible_orders(): void
    {
        $admin = $this->createActiveAdmin('media_list_sync_api_admin', 'p');
        $bearer = $this->createBearerToken($admin, ['media:read']);
        [$article, $resource] = $this->createArticleAndMediaResource($admin);
        $submission = $this->createMediaSubmission($article, $resource, 'api-list-sync-order');
        Http::fake([
            '*/api/media/order_info' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => [[
                    'order_nid' => 'api-list-sync-order',
                    'status' => 'published',
                    'url' => 'https://example.com/api-list-sync.html',
                ]],
            ]),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/media/submissions')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $submission->id)
            ->assertJsonPath('data.items.0.status_label', '已发布')
            ->assertJsonPath('data.items.0.published_url', 'https://example.com/api-list-sync.html');

        $submission->refresh();
        $this->assertSame('published', $submission->status);
    }

    public function test_media_submission_api_show_auto_syncs_order(): void
    {
        $admin = $this->createActiveAdmin('media_show_sync_api_admin', 'p');
        $bearer = $this->createBearerToken($admin, ['media:read']);
        [$article, $resource] = $this->createArticleAndMediaResource($admin);
        $submission = $this->createMediaSubmission($article, $resource, 'api-show-sync-order');
        Http::fake([
            '*/api/media/order_info' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => [[
                    'order_nid' => 'api-show-sync-order',
                    'status' => 'published',
                    'url' => 'https://example.com/api-show-sync.html',
                ]],
            ]),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson("/api/v1/media/submissions/{$submission->id}")
            ->assertOk()
            ->assertJsonPath('data.status_label', '已发布')
            ->assertJsonPath('data.published_url', 'https://example.com/api-show-sync.html');
    }

    public function test_site_bound_token_only_sees_own_media_submissions(): void
    {
        $admin = $this->createActiveAdmin('media_site_scope_admin', 'p');
        [$siteA, $siteB] = $this->createTwoSites($admin);
        $bearer = $this->createBearerToken($admin, ['media:read'], (int) $siteA->id);
        [$articleA, $resourceA] = $this->createArticleAndMediaResource($admin, $siteA);
        [$articleB, $resourceB] = $this->createArticleAndMediaResource($admin, $siteB);
        $submissionA = $this->createMediaSubmission($articleA, $resourceA, 'api-site-a-order');
        $submissionB = $this->createMediaSubmission($articleB, $resourceB, 'api-site-b-order');

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/media/submissions')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $submissionA->id);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson("/api/v1/media/submissions/{$submissionB->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'media_submission_not_found');
    }

    public function test_site_bound_token_only_sees_own_catalog_and_articles(): void
    {
        $admin = $this->createActiveAdmin('content_site_scope_admin', 'p');
        [$siteA, $siteB] = $this->createTwoSites($admin);
        $bearer = $this->createBearerToken($admin, ['catalog:read', 'articles:read'], (int) $siteA->id);
        [$articleA] = $this->createArticleAndMediaResource($admin, $siteA);
        [$articleB] = $this->createArticleAndMediaResource($admin, $siteB);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/catalog')
            ->assertOk()
            ->assertJsonPath('data.authors.0.name', 'API Author '.$siteA->id);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson('/api/v1/articles')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $articleA->id);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->getJson("/api/v1/articles/{$articleB->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'article_not_found');
    }

    public function test_media_submission_api_can_cancel_order(): void
    {
        $admin = $this->createActiveAdmin('media_cancel_api_admin', 'p');
        $bearer = $this->createBearerToken($admin, ['media:sync']);
        [$article, $resource] = $this->createArticleAndMediaResource($admin);
        $submission = $this->createMediaSubmission($article, $resource, 'api-cancel-order');
        Http::fake([
            '*/api/media/cancel_order' => Http::response(['code' => 1, 'msg' => 'cancelled', 'data' => []]),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson("/api/v1/media/submissions/{$submission->id}/cancel", [
                'reason' => 'wrong article',
            ])
            ->assertOk()
            ->assertJsonPath('data.status_label', '已取消');

        $submission->refresh();
        $this->assertSame('cancelled', $submission->status);
        $this->assertSame('wrong article', $submission->cancel_reason);
    }

    public function test_media_submission_api_can_appeal_order(): void
    {
        $admin = $this->createActiveAdmin('media_appeal_api_admin', 'p');
        $bearer = $this->createBearerToken($admin, ['media:sync']);
        [$article, $resource] = $this->createArticleAndMediaResource($admin);
        $submission = $this->createMediaSubmission($article, $resource, 'api-appeal-order');
        $submission->forceFill(['status' => 'rejected'])->save();
        Http::fake([
            '*/api/media/rejection' => Http::response(['code' => 1, 'msg' => 'appeal accepted', 'data' => []]),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$bearer['plain'])
            ->postJson("/api/v1/media/submissions/{$submission->id}/appeal", [
                'content' => 'please recheck',
            ])
            ->assertOk()
            ->assertJsonPath('data.status_label', '售后中');

        $submission->refresh();
        $this->assertSame('appealing', $submission->status);
        $this->assertSame('please recheck', $submission->appeal_content);
    }

    private function createArticleAndMediaResource(Admin $admin, ?Site $site = null): array
    {
        $site ??= Site::query()->create([
            'owner_admin_id' => $admin->id,
            'name' => 'API Media Site',
            'status' => 'active',
        ]);
        $this->openTestingPlanForSite($site, $admin);
        $category = Category::query()->create([
            'site_id' => $site->id,
            'name' => 'API Category '.$site->id,
            'slug' => 'api-category-'.uniqid(),
        ]);
        $author = Author::query()->create([
            'site_id' => $site->id,
            'name' => 'API Author '.$site->id,
        ]);
        $article = Article::withoutGlobalScope('current_site')->create([
            'site_id' => $site->id,
            'title' => 'API Media Article '.$site->id,
            'slug' => 'api-media-article-'.uniqid(),
            'content' => 'API media article content.',
            'excerpt' => 'API media article content.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
        ]);
        $resource = MediaResource::query()->create([
            'source_type' => 'website_media',
            'external_resource_id' => 'api-media-resource-'.uniqid(),
            'title' => 'API Publish Media',
            'category' => 'news',
            'status' => 'active',
            'cost_price' => '1.00',
            'sale_price' => '3.00',
        ]);

        return [$article, $resource];
    }

    private function createTwoSites(Admin $admin): array
    {
        $siteA = Site::query()->create([
            'owner_admin_id' => $admin->id,
            'name' => 'API Site A',
            'status' => 'active',
        ]);
        $siteB = Site::query()->create([
            'owner_admin_id' => $admin->id,
            'name' => 'API Site B',
            'status' => 'active',
        ]);

        return [$siteA, $siteB];
    }

    private function createMediaSubmission(Article $article, MediaResource $resource, string $orderNid): MediaSubmission
    {
        return MediaSubmission::withoutGlobalScope('current_site')->create([
            'site_id' => $article->site_id,
            'article_id' => $article->id,
            'media_resource_id' => $resource->id,
            'source_type' => $resource->source_type,
            'external_order_nid' => $orderNid,
            'title_snapshot' => $article->title,
            'content_snapshot' => $article->content,
            'cost_price_snapshot' => '1.00',
            'sale_price_snapshot' => '3.00',
            'points_amount' => '3.00',
            'status' => 'submitted',
        ]);
    }
}
