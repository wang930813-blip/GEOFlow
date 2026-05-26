<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\MediaResource;
use App\Models\MediaSubmission;
use App\Models\Site;
use App\Models\SiteCreditAccount;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminMediaDistributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_distribution_replaces_old_distribution_navigation(): void
    {
        [$admin] = $this->createAdminWithSite('media_nav_admin', 'admin');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.media-distribution.resources.index'), false)
            ->assertSee('分发媒体')
            ->assertDontSee(route('admin.distribution.index'), false);
    }

    public function test_super_admin_can_configure_api_and_sync_media_resources(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$superAdmin] = $this->createAdminWithSite('media_root_admin', 'super_admin');

        Http::fake([
            '*/api/media/media_list' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => [
                    [
                        'resource_id' => 73880,
                        'title' => '中华网生活',
                        'remarks' => '图片涉及版权问题默认删',
                        'case_link' => 'http://life.china.com/example.html',
                        'status' => 1,
                        'price' => '27.00',
                    ],
                ],
            ]),
            '*/api/zi_media_api/media_list' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => [
                    [
                        'resource_id' => 90001,
                        'title' => '第三方账号A',
                        'remarks' => '自媒体资源',
                        'case_link' => '',
                        'status' => 1,
                        'price' => '40.00',
                    ],
                ],
            ]),
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.media-distribution.settings.update'), [
                'api_base_url' => 'http://8.138.187.158:8082',
                'api_key' => 'test-api-key',
                'status' => 'active',
            ])
            ->assertRedirect(route('admin.media-distribution.settings.index'));

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.media-distribution.resources.sync'))
            ->assertRedirect(route('admin.media-distribution.resources.index'));

        $this->assertDatabaseHas('media_resources', [
            'source_type' => 'website_media',
            'external_resource_id' => '73880',
            'title' => '中华网生活',
            'cost_price' => '27.00',
            'sale_price' => '27.00',
        ]);
        $this->assertDatabaseHas('media_resources', [
            'source_type' => 'zi_media',
            'external_resource_id' => '90001',
            'title' => '第三方账号A',
            'cost_price' => '40.00',
            'sale_price' => '40.00',
        ]);
    }

    public function test_super_admin_can_recharge_site_credits_and_update_media_sale_price(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$superAdmin] = $this->createAdminWithSite('media_credit_root', 'super_admin');
        [, $site] = $this->createAdminWithSite('media_credit_owner', 'admin');
        $resource = MediaResource::query()->create([
            'source_type' => 'website_media',
            'external_resource_id' => '73880',
            'title' => '中华网生活',
            'status' => 'active',
            'cost_price' => '27.00',
            'sale_price' => '27.00',
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.media-distribution.credits.recharge', ['site' => $site->id]), [
                'amount' => '200',
                'remark' => '首次充值',
            ])
            ->assertRedirect(route('admin.media-distribution.credits.index'));

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.media-distribution.credits.adjust', ['site' => $site->id]), [
                'amount' => '-25',
                'remark' => 'manual debit',
            ])
            ->assertRedirect(route('admin.media-distribution.credits.index'));

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.media-distribution.resources.price', ['resource' => $resource->id]), [
                'sale_price' => '88',
            ])
            ->assertRedirect(route('admin.media-distribution.resources.index'));

        $this->assertDatabaseHas('site_credit_accounts', [
            'site_id' => $site->id,
            'balance' => '175.00',
        ]);
        $this->assertDatabaseHas('site_credit_ledger', [
            'site_id' => $site->id,
            'type' => 'adjust',
            'amount' => '-25.00',
            'balance_after' => '175.00',
        ]);
        $this->assertSame('88.00', $resource->fresh()->sale_price);
    }

    public function test_standard_admin_can_submit_article_and_sync_order_status_with_site_credits(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$admin, $site] = $this->createAdminWithSite('media_submit_admin', 'admin');
        $category = Category::query()->create([
            'site_id' => $site->id,
            'name' => '默认分类',
            'slug' => 'default',
            'status' => 'active',
        ]);
        $author = Author::query()->create([
            'site_id' => $site->id,
            'name' => '默认作者',
            'slug' => 'default-author',
            'status' => 'active',
        ]);
        $article = Article::query()->create([
            'site_id' => $site->id,
            'title' => '品牌出海内容',
            'slug' => 'brand-global-content',
            'content' => '<p>这是一篇可投稿文章。</p>',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
        ]);
        $resource = MediaResource::query()->create([
            'source_type' => 'website_media',
            'external_resource_id' => '73880',
            'title' => '中华网生活',
            'status' => 'active',
            'cost_price' => '27.00',
            'sale_price' => '88.00',
        ]);
        SiteCreditAccount::query()->create([
            'site_id' => $site->id,
            'balance' => '100.00',
            'frozen_balance' => '0.00',
            'total_recharged' => '100.00',
            'total_consumed' => '0.00',
        ]);

        Http::fake([
            '*/api/media/send' => Http::response([
                'code' => 1,
                'msg' => '投稿成功',
                'data' => ['order_nid' => 123456],
            ]),
            '*/api/media/order_info' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => [
                    'status' => 'published',
                    'url' => 'https://example.com/published.html',
                ],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.store'), [
                'article_id' => $article->id,
                'media_resource_id' => $resource->id,
                'remark' => '请尽快发布',
            ])
            ->assertRedirect(route('admin.media-distribution.submissions.index'));

        $submission = MediaSubmission::query()->where('article_id', $article->id)->firstOrFail();

        $this->assertSame('submitted', $submission->status);
        $this->assertSame('123456', $submission->external_order_nid);
        $this->assertSame('12.00', SiteCreditAccount::query()->where('site_id', $site->id)->value('balance'));

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.sync', ['submission' => $submission->id]))
            ->assertRedirect(route('admin.media-distribution.submissions.show', ['submission' => $submission->id]));

        $submission->refresh();
        $this->assertSame('published', $submission->status);
        $this->assertSame('https://example.com/published.html', $submission->published_url);
    }

    public function test_submission_requires_enough_site_credits(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$admin, $site] = $this->createAdminWithSite('media_low_credit_admin', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'low-credit-article');
        SiteCreditAccount::query()->create([
            'site_id' => $site->id,
            'balance' => '10.00',
            'frozen_balance' => '0.00',
            'total_recharged' => '10.00',
            'total_consumed' => '0.00',
        ]);
        Http::fake();

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.store'), [
                'article_id' => $article->id,
                'media_resource_id' => $resource->id,
            ])
            ->assertSessionHasErrors();

        $this->assertDatabaseMissing('media_submissions', [
            'article_id' => $article->id,
        ]);
        $this->assertSame('10.00', SiteCreditAccount::query()->where('site_id', $site->id)->value('balance'));
        Http::assertNothingSent();
    }

    public function test_submit_failure_records_failed_order_and_refunds_credits(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$admin, $site] = $this->createAdminWithSite('media_failed_submit_admin', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'failed-submit-article');
        SiteCreditAccount::query()->create([
            'site_id' => $site->id,
            'balance' => '100.00',
            'frozen_balance' => '0.00',
            'total_recharged' => '100.00',
            'total_consumed' => '0.00',
        ]);
        Http::fake([
            '*/api/media/send' => Http::response([
                'code' => 0,
                'msg' => 'remote rejected',
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.store'), [
                'article_id' => $article->id,
                'media_resource_id' => $resource->id,
            ])
            ->assertSessionHasErrors();

        $submission = MediaSubmission::query()->where('article_id', $article->id)->firstOrFail();
        $this->assertSame('failed', $submission->status);
        $this->assertSame('100.00', SiteCreditAccount::query()->where('site_id', $site->id)->value('balance'));
        $this->assertDatabaseHas('site_credit_ledger', [
            'site_id' => $site->id,
            'submission_id' => $submission->id,
            'type' => 'refund',
            'amount' => '88.00',
            'balance_after' => '100.00',
        ]);
    }

    public function test_media_submissions_are_isolated_by_current_site_for_standard_admins(): void
    {
        [$adminA, $siteA] = $this->createAdminWithSite('media_site_a_admin', 'admin');
        [, $siteB] = $this->createAdminWithSite('media_site_b_admin', 'admin');
        [$article, $resource] = $this->createArticleAndResource($siteB, 'site-b-article');
        $submission = MediaSubmission::query()->create([
            'site_id' => $siteB->id,
            'article_id' => $article->id,
            'media_resource_id' => $resource->id,
            'source_type' => $resource->source_type,
            'title_snapshot' => $article->title,
            'content_snapshot' => $article->content,
            'cost_price_snapshot' => '27.00',
            'sale_price_snapshot' => '88.00',
            'points_amount' => '88.00',
            'status' => 'submitted',
        ]);

        $this->actingAs($adminA, 'admin')
            ->withSession(['current_site_id' => $siteA->id])
            ->get(route('admin.media-distribution.submissions.show', ['submission' => $submission->id]))
            ->assertNotFound();
    }

    private function createAdminWithSite(string $username, string $role): array
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => str_replace('_', ' ', $username),
            'role' => $role,
            'status' => 'active',
        ]);

        $site = Site::query()->create([
            'owner_admin_id' => $admin->id,
            'name' => $admin->name.' 的默认站点',
            'status' => 'active',
        ]);
        $site->members()->attach($admin->id, ['role' => 'owner']);

        return [$admin, $site];
    }

    private function createArticleAndResource(Site $site, string $slug): array
    {
        $category = Category::query()->create([
            'site_id' => $site->id,
            'name' => 'Default',
            'slug' => $slug.'-category',
            'status' => 'active',
        ]);
        $author = Author::query()->create([
            'site_id' => $site->id,
            'name' => 'Default Author',
            'slug' => $slug.'-author',
            'status' => 'active',
        ]);
        $article = Article::query()->create([
            'site_id' => $site->id,
            'title' => 'Media Submit '.$slug,
            'slug' => $slug,
            'content' => '<p>Ready to publish.</p>',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
        ]);
        $resource = MediaResource::query()->create([
            'source_type' => 'website_media',
            'external_resource_id' => 'resource-'.$slug,
            'title' => 'Website Media '.$slug,
            'status' => 'active',
            'cost_price' => '27.00',
            'sale_price' => '88.00',
        ]);

        return [$article, $resource];
    }
}
