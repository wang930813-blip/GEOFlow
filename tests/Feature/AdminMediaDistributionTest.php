<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\MediaResource;
use App\Models\MediaResourceSitePrice;
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

    public function test_command_auto_syncs_unfinished_media_submission_statuses(): void
    {
        [, $site] = $this->createAdminWithSite('media_sync_command_admin', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'sync-command-article');
        $submission = MediaSubmission::query()->create([
            'site_id' => $site->id,
            'article_id' => $article->id,
            'media_resource_id' => $resource->id,
            'source_type' => $resource->source_type,
            'external_order_nid' => 'order-1001',
            'title_snapshot' => $article->title,
            'content_snapshot' => $article->content,
            'cost_price_snapshot' => '27.00',
            'sale_price_snapshot' => '88.00',
            'points_amount' => '88.00',
            'status' => 'submitted',
        ]);
        MediaSubmission::query()->create([
            'site_id' => $site->id,
            'article_id' => $article->id,
            'media_resource_id' => $resource->id,
            'source_type' => $resource->source_type,
            'external_order_nid' => 'order-done',
            'title_snapshot' => $article->title,
            'content_snapshot' => $article->content,
            'cost_price_snapshot' => '27.00',
            'sale_price_snapshot' => '88.00',
            'points_amount' => '88.00',
            'status' => 'published',
        ]);
        Http::fake([
            '*/api/media/order_info' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => [
                    'status' => 'published',
                    'url' => 'https://example.com/auto-synced.html',
                ],
            ]),
        ]);

        $this->artisan('media-distribution:sync-submissions', ['--limit' => 10])
            ->assertExitCode(0);

        $submission->refresh();
        $this->assertSame('published', $submission->status);
        $this->assertSame('https://example.com/auto-synced.html', $submission->published_url);
        Http::assertSentCount(1);
    }

    public function test_admin_can_cancel_and_appeal_media_submission(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$admin, $site] = $this->createAdminWithSite('media_cancel_admin', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'cancel-appeal-article');
        $submission = MediaSubmission::query()->create([
            'site_id' => $site->id,
            'article_id' => $article->id,
            'media_resource_id' => $resource->id,
            'source_type' => $resource->source_type,
            'external_order_nid' => 'order-cancel',
            'title_snapshot' => $article->title,
            'content_snapshot' => $article->content,
            'cost_price_snapshot' => '27.00',
            'sale_price_snapshot' => '88.00',
            'points_amount' => '88.00',
            'status' => 'submitted',
        ]);
        Http::fake([
            '*/api/media/cancel_order' => Http::response(['code' => 1, 'msg' => 'cancelled', 'data' => []]),
            '*/api/media/rejection' => Http::response(['code' => 1, 'msg' => 'appeal accepted', 'data' => []]),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.cancel', ['submission' => $submission->id]), [
                'reason' => 'wrong article',
            ])
            ->assertRedirect(route('admin.media-distribution.submissions.show', ['submission' => $submission->id]));

        $submission->refresh();
        $this->assertSame('cancelled', $submission->status);
        $this->assertSame('wrong article', $submission->cancel_reason);

        $submission->forceFill(['status' => 'rejected'])->save();
        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.appeal', ['submission' => $submission->id]), [
                'content' => 'please recheck',
            ])
            ->assertRedirect(route('admin.media-distribution.submissions.show', ['submission' => $submission->id]));

        $submission->refresh();
        $this->assertSame('appealing', $submission->status);
        $this->assertSame('please recheck', $submission->appeal_content);
    }

    public function test_admin_can_bulk_submit_articles_to_media(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$admin, $site] = $this->createAdminWithSite('media_bulk_submit_admin', 'admin');
        [$articleA, $resource] = $this->createArticleAndResource($site, 'bulk-submit-a');
        [$articleB] = $this->createArticleAndResource($site, 'bulk-submit-b');
        SiteCreditAccount::query()->create([
            'site_id' => $site->id,
            'balance' => '200.00',
            'frozen_balance' => '0.00',
            'total_recharged' => '200.00',
            'total_consumed' => '0.00',
        ]);
        Http::fake([
            '*/api/media/send' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => ['order_nid' => 'bulk-order'],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.bulk-store'), [
                'article_ids' => [$articleA->id, $articleB->id],
                'media_resource_id' => $resource->id,
                'remark' => 'bulk',
            ])
            ->assertRedirect(route('admin.media-distribution.submissions.index'));

        $this->assertSame(2, MediaSubmission::query()->where('media_resource_id', $resource->id)->count());
        $this->assertSame('24.00', SiteCreditAccount::query()->where('site_id', $site->id)->value('balance'));
        Http::assertSentCount(2);
    }

    public function test_media_resources_support_status_category_and_price_filters(): void
    {
        [$admin] = $this->createAdminWithSite('media_filter_admin', 'admin');
        MediaResource::query()->create([
            'source_type' => 'website_media',
            'external_resource_id' => 'filter-1',
            'title' => 'Finance Media',
            'category' => 'finance',
            'status' => 'active',
            'cost_price' => '20.00',
            'sale_price' => '80.00',
        ]);
        MediaResource::query()->create([
            'source_type' => 'zi_media',
            'external_resource_id' => 'filter-2',
            'title' => 'Travel Media',
            'category' => 'travel',
            'status' => 'inactive',
            'cost_price' => '10.00',
            'sale_price' => '30.00',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.media-distribution.resources.index', [
                'category' => 'finance',
                'status' => 'active',
                'min_price' => '60',
                'max_price' => '100',
            ]))
            ->assertOk()
            ->assertSee('Finance Media')
            ->assertDontSee('Travel Media');
    }

    public function test_super_admin_can_export_media_submissions_and_credit_ledger_csv(): void
    {
        [$superAdmin] = $this->createAdminWithSite('media_export_root', 'super_admin');
        [, $site] = $this->createAdminWithSite('media_export_site', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'export-article');
        $submission = MediaSubmission::query()->create([
            'site_id' => $site->id,
            'article_id' => $article->id,
            'media_resource_id' => $resource->id,
            'source_type' => $resource->source_type,
            'external_order_nid' => 'export-order',
            'title_snapshot' => $article->title,
            'content_snapshot' => $article->content,
            'cost_price_snapshot' => '27.00',
            'sale_price_snapshot' => '88.00',
            'points_amount' => '88.00',
            'status' => 'submitted',
        ]);
        SiteCreditAccount::query()->create([
            'site_id' => $site->id,
            'balance' => '100.00',
            'frozen_balance' => '0.00',
            'total_recharged' => '100.00',
            'total_consumed' => '0.00',
        ]);

        $submissionsCsv = $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.media-distribution.submissions.export'));
        $submissionsCsv->assertOk();
        $submissionsCsv->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('export-order', $submissionsCsv->streamedContent());
        $this->assertStringContainsString((string) $submission->id, $submissionsCsv->streamedContent());

        $creditsCsv = $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.media-distribution.credits.adjust', ['site' => $site->id]), [
                'amount' => '10',
                'remark' => 'export ledger',
            ])
            ->assertRedirect(route('admin.media-distribution.credits.index'));

        $creditsCsv = $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.media-distribution.credits.export'));
        $creditsCsv->assertOk();
        $creditsCsv->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('export ledger', $creditsCsv->streamedContent());
    }

    public function test_site_specific_media_price_is_used_for_submission_and_hidden_from_other_sites(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$superAdmin] = $this->createAdminWithSite('media_site_price_root', 'super_admin');
        [$admin, $site] = $this->createAdminWithSite('media_site_price_admin', 'admin');
        [, $otherSite] = $this->createAdminWithSite('media_site_price_other', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'site-price-article');
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
                'msg' => 'success',
                'data' => ['order_nid' => 'site-price-order'],
            ]),
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.media-distribution.resources.site-price', ['resource' => $resource->id]), [
                'site_id' => $site->id,
                'sale_price' => '55',
            ])
            ->assertRedirect(route('admin.media-distribution.resources.index'));

        $this->assertDatabaseHas('media_resource_site_prices', [
            'site_id' => $site->id,
            'media_resource_id' => $resource->id,
            'sale_price' => '55.00',
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.store'), [
                'article_id' => $article->id,
                'media_resource_id' => $resource->id,
            ])
            ->assertRedirect(route('admin.media-distribution.submissions.index'));

        $submission = MediaSubmission::query()->where('article_id', $article->id)->firstOrFail();
        $this->assertSame('55.00', $submission->sale_price_snapshot);
        $this->assertSame('55.00', $submission->points_amount);
        $this->assertSame('45.00', SiteCreditAccount::query()->where('site_id', $site->id)->value('balance'));
        $this->assertDatabaseMissing('media_resource_site_prices', [
            'site_id' => $otherSite->id,
            'media_resource_id' => $resource->id,
        ]);
    }

    public function test_cancelled_and_rejected_orders_refund_consumed_credits_once(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        [$admin, $site] = $this->createAdminWithSite('media_refund_admin', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'refund-article');
        SiteCreditAccount::query()->create([
            'site_id' => $site->id,
            'balance' => '12.00',
            'frozen_balance' => '0.00',
            'total_recharged' => '100.00',
            'total_consumed' => '88.00',
        ]);
        $submission = MediaSubmission::query()->create([
            'site_id' => $site->id,
            'article_id' => $article->id,
            'media_resource_id' => $resource->id,
            'source_type' => $resource->source_type,
            'external_order_nid' => 'refund-order',
            'title_snapshot' => $article->title,
            'content_snapshot' => $article->content,
            'cost_price_snapshot' => '27.00',
            'sale_price_snapshot' => '88.00',
            'points_amount' => '88.00',
            'status' => 'submitted',
        ]);
        Http::fake([
            '*/api/media/cancel_order' => Http::response(['code' => 1, 'msg' => 'cancelled', 'data' => []]),
            '*/api/media/order_info' => Http::response([
                'code' => 1,
                'msg' => 'success',
                'data' => ['status' => 'rejected', 'reason' => 'not suitable'],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.cancel', ['submission' => $submission->id]), [
                'reason' => 'cancel request',
            ])
            ->assertRedirect(route('admin.media-distribution.submissions.show', ['submission' => $submission->id]));

        $this->assertSame('100.00', SiteCreditAccount::query()->where('site_id', $site->id)->value('balance'));
        $this->assertSame(1, \App\Models\SiteCreditLedger::query()->where('submission_id', $submission->id)->where('type', 'refund')->count());

        $submission->forceFill(['status' => 'submitted'])->save();
        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->post(route('admin.media-distribution.submissions.sync', ['submission' => $submission->id]))
            ->assertRedirect(route('admin.media-distribution.submissions.show', ['submission' => $submission->id]));

        $this->assertSame('100.00', SiteCreditAccount::query()->where('site_id', $site->id)->value('balance'));
        $this->assertSame(1, \App\Models\SiteCreditLedger::query()->where('submission_id', $submission->id)->where('type', 'refund')->count());
    }

    public function test_super_admin_can_view_and_export_all_site_consumption_records(): void
    {
        [$superAdmin] = $this->createAdminWithSite('media_consumption_root', 'super_admin');
        [, $siteA] = $this->createAdminWithSite('media_consumption_a', 'admin');
        [, $siteB] = $this->createAdminWithSite('media_consumption_b', 'admin');
        [$articleA, $resourceA] = $this->createArticleAndResource($siteA, 'consumption-a');
        [$articleB, $resourceB] = $this->createArticleAndResource($siteB, 'consumption-b');
        foreach ([[$siteA, $articleA, $resourceA, 'order-a'], [$siteB, $articleB, $resourceB, 'order-b']] as [$site, $article, $resource, $order]) {
            $submission = MediaSubmission::query()->create([
                'site_id' => $site->id,
                'article_id' => $article->id,
                'media_resource_id' => $resource->id,
                'source_type' => $resource->source_type,
                'external_order_nid' => $order,
                'title_snapshot' => $article->title,
                'content_snapshot' => $article->content,
                'cost_price_snapshot' => '27.00',
                'sale_price_snapshot' => '88.00',
                'points_amount' => '88.00',
                'status' => 'submitted',
            ]);
            \App\Models\SiteCreditLedger::query()->create([
                'site_id' => $site->id,
                'submission_id' => $submission->id,
                'type' => 'deduct',
                'amount' => '-88.00',
                'balance_after' => '12.00',
                'frozen_after' => '0.00',
                'remark' => '媒体投稿扣除',
                'created_at' => now(),
            ]);
        }

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.media-distribution.credits.index'))
            ->assertOk()
            ->assertSee($siteA->name)
            ->assertSee($siteB->name);

        $csv = $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.media-distribution.credits.consumption-export'));

        $csv->assertOk();
        $csv->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $csv->streamedContent();
        $this->assertStringContainsString($siteA->name, $content);
        $this->assertStringContainsString($siteB->name, $content);
        $this->assertStringContainsString('order-a', $content);
        $this->assertStringContainsString('order-b', $content);
    }

    public function test_super_admin_can_view_profit_report(): void
    {
        [$superAdmin] = $this->createAdminWithSite('media_profit_root', 'super_admin');
        [, $site] = $this->createAdminWithSite('media_profit_site', 'admin');
        [$article, $resource] = $this->createArticleAndResource($site, 'profit-article');
        MediaSubmission::query()->create([
            'site_id' => $site->id,
            'article_id' => $article->id,
            'media_resource_id' => $resource->id,
            'source_type' => $resource->source_type,
            'external_order_nid' => 'profit-order',
            'title_snapshot' => $article->title,
            'content_snapshot' => $article->content,
            'cost_price_snapshot' => '27.00',
            'sale_price_snapshot' => '88.00',
            'points_amount' => '88.00',
            'status' => 'published',
            'submitted_at' => now(),
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.media-distribution.reports.profit'))
            ->assertOk()
            ->assertSee($site->name)
            ->assertSee('88.00')
            ->assertSee('27.00')
            ->assertSee('61.00');

        $csv = $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.media-distribution.reports.profit-export'));
        $csv->assertOk();
        $this->assertStringContainsString('61.00', $csv->streamedContent());
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
