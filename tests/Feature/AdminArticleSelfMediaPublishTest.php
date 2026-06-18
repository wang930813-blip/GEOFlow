<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminResourceUsage;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\CrebeeAccount;
use App\Models\CrebeeAgent;
use App\Models\CrebeePublishJob;
use App\Models\CrebeePublishJobItem;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Services\Billing\AdminPlanSubscriptionService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminArticleSelfMediaPublishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_article_list_shows_self_media_publish_action_and_only_bound_article_platform_accounts(): void
    {
        [$admin, $site] = $this->provisionSubscribedAdmin('article_publish_ui_owner', 10);
        $article = $this->article($site, $admin, '自媒体发布入口文章');
        $agent = $this->agent();
        $this->account($agent, $site, $admin, 'douyin', 'douyin-ui-account', '抖音已绑定');
        $gongzhonghao = $this->account($agent, $site, $admin, 'gongzhonghao', 'gongzhonghao-ui-account', '公众号已绑定');
        $gongzhonghao->forceFill(['avatar' => '/storage/crebee-avatars/gongzhonghao-ui-account.jpg'])->save();
        $this->account($agent, $site, $admin, 'kuaishou', 'kuaishou-ui-account', '快手已绑定');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.articles.index'))
            ->assertOk()
            ->assertSee('发布自媒体')
            ->assertSee('自媒体发布入口文章')
            ->assertSee('抖音已绑定')
            ->assertSee('公众号已绑定')
            ->assertDontSee('快手已绑定')
            ->assertSee('src="'.asset('assets/self-media-platforms/10.png').'"', false)
            ->assertSee('src="/storage/crebee-avatars/gongzhonghao-ui-account.jpg"', false)
            ->assertSee('data-action="'.route('admin.articles.self-media.publish', ['articleId' => (int) $article->id]).'"', false)
            ->assertSee('data-title="自媒体发布入口文章"', false)
            ->assertSee('js-self-media-publish', false);
    }

    public function test_user_can_create_self_media_article_publish_job_and_consume_by_selected_platform_count(): void
    {
        [$admin, $site] = $this->provisionSubscribedAdmin('article_publish_owner', 3);
        $article = $this->article($site, $admin, '自媒体发布测试文章', "第一段\n\n第二段");
        $article->forceFill(['cover_image' => 'https://cdn.example.com/article-cover.jpg'])->save();
        $agent = $this->agent();
        $douyin = $this->account($agent, $site, $admin, 'douyin', 'douyin-account-001', '抖音号');
        $bilibili = $this->account($agent, $site, $admin, 'bilibili', 'bilibili-account-001', 'B站号');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->from(route('admin.articles.index'))
            ->post(route('admin.articles.self-media.publish', ['articleId' => (int) $article->id]), [
                'crebee_account_ids' => [
                    (int) $douyin->id,
                    (int) $bilibili->id,
                ],
            ])
            ->assertRedirect(route('admin.articles.index'));

        $job = CrebeePublishJob::query()->firstOrFail();
        $this->assertSame((int) $site->id, (int) $job->site_id);
        $this->assertSame((int) $admin->id, (int) $job->owner_admin_id);
        $this->assertSame((int) $agent->id, (int) $job->agent_id);
        $this->assertSame('article', (string) $job->content_type);
        $this->assertSame('article', (string) $job->content_source_type);
        $this->assertSame('queued', (string) $job->status);
        $this->assertNotNull($job->quota_ledger_id);
        $this->assertSame('自媒体发布测试文章', (string) $job->title);
        $this->assertSame('article', (string) data_get($job->payload, 'contentType'));
        $this->assertSame('自媒体发布测试文章', (string) data_get($job->payload, 'commonForm.title'));
        $this->assertStringContainsString('<p>第一段</p>', (string) data_get($job->payload, 'commonForm.content'));
        $this->assertSame(['https://cdn.example.com/article-cover.jpg'], data_get($job->payload, 'commonForm.covers'));

        $this->assertSame(2, CrebeePublishJobItem::query()->count());
        $this->assertDatabaseHas('crebee_publish_job_items', [
            'job_id' => (int) $job->id,
            'crebee_account_id' => (int) $douyin->id,
            'platform' => 'douyin',
            'status' => 'queued',
        ]);
        $this->assertDatabaseHas('crebee_publish_job_items', [
            'job_id' => (int) $job->id,
            'crebee_account_id' => (int) $bilibili->id,
            'platform' => 'bilibili',
            'status' => 'queued',
        ]);
        $this->assertSame(
            ['https://cdn.example.com/article-cover.jpg'],
            data_get(CrebeePublishJobItem::query()->where('platform', 'bilibili')->firstOrFail()->payload, 'params.covers')
        );

        $usage = AdminResourceUsage::query()
            ->where('admin_id', (int) $admin->id)
            ->where('site_id', (int) $site->id)
            ->where('resource_key', PlatformPlan::RESOURCE_CREBEE_PUBLISHES)
            ->firstOrFail();
        $this->assertSame(2, (int) $usage->used_amount);
    }

    public function test_user_cannot_publish_to_unbound_or_unsupported_self_media_account(): void
    {
        [$admin, $site] = $this->provisionSubscribedAdmin('article_publish_forbidden_owner', 5);
        $otherAdmin = $this->admin('article_publish_other_owner', 'direct_admin');
        $article = $this->article($site, $admin, '自媒体越权发布文章');
        $agent = $this->agent();
        $otherAccount = $this->account($agent, $site, $otherAdmin, 'douyin', 'douyin-other-account', '别人抖音号');
        $unsupportedAccount = $this->account($agent, $site, $admin, 'kuaishou', 'kuaishou-own-account', '快手号');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->from(route('admin.articles.index'))
            ->post(route('admin.articles.self-media.publish', ['articleId' => (int) $article->id]), [
                'crebee_account_ids' => [(int) $otherAccount->id],
            ])
            ->assertRedirect(route('admin.articles.index'))
            ->assertSessionHasErrors('crebee_account_ids');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->from(route('admin.articles.index'))
            ->post(route('admin.articles.self-media.publish', ['articleId' => (int) $article->id]), [
                'crebee_account_ids' => [(int) $unsupportedAccount->id],
            ])
            ->assertRedirect(route('admin.articles.index'))
            ->assertSessionHasErrors('crebee_account_ids');

        $this->assertSame(0, CrebeePublishJob::query()->count());
        $this->assertSame(0, CrebeePublishJobItem::query()->count());
    }

    public function test_user_cannot_publish_bilibili_article_without_cover_image(): void
    {
        [$admin, $site] = $this->provisionSubscribedAdmin('article_publish_bilibili_cover_owner', 3);
        $article = $this->article($site, $admin, 'B站无封面文章', '正文');
        $agent = $this->agent();
        $bilibili = $this->account($agent, $site, $admin, 'bilibili', 'bilibili-no-cover-account', 'B站号');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->from(route('admin.articles.index'))
            ->post(route('admin.articles.self-media.publish', ['articleId' => (int) $article->id]), [
                'crebee_account_ids' => [(int) $bilibili->id],
            ])
            ->assertRedirect(route('admin.articles.index'))
            ->assertSessionHasErrors('crebee_account_ids');

        $this->assertSame(0, CrebeePublishJob::query()->count());
        $this->assertSame(0, CrebeePublishJobItem::query()->count());
        $this->assertSame(0, AdminResourceUsage::query()->count());
    }

    public function test_agent_can_pull_article_publish_job_created_from_article_list(): void
    {
        [$admin, $site] = $this->provisionSubscribedAdmin('article_publish_agent_pull_owner', 2);
        $article = $this->article($site, $admin, 'Agent 拉取文章', '<p>正文内容</p>');
        $agent = $this->agent();
        $account = $this->account($agent, $site, $admin, 'douyin', 'douyin-agent-pull-account', '抖音号');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.articles.self-media.publish', ['articleId' => (int) $article->id]), [
                'crebee_account_ids' => [(int) $account->id],
            ]);

        $job = CrebeePublishJob::query()->firstOrFail();
        $item = CrebeePublishJobItem::query()->firstOrFail();

        $this->withHeaders([
            'X-CreBee-Agent-Id' => (string) $agent->agent_uid,
            'X-CreBee-Agent-Secret' => 'agent-secret',
        ])->getJson('/api/v1/crebee-agent/jobs/next')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.job.id', (int) $job->id)
            ->assertJsonPath('data.job.contentType', 'article')
            ->assertJsonPath('data.job.commonForm.title', 'Agent 拉取文章')
            ->assertJsonPath('data.job.tasks.0.accountId', 'douyin-agent-pull-account')
            ->assertJsonPath('data.job.tasks.0.platform', 'douyin')
            ->assertJsonPath('data.job.tasks.0.contentType', 'article')
            ->assertJsonPath('data.job.tasks.0.params.taskId', (string) $item->crebee_task_id)
            ->assertJsonPath('data.job.tasks.0.params.visibilityType', 0);
    }

    /**
     * @return array{0: Admin, 1: Site}
     */
    private function provisionSubscribedAdmin(string $username, int $crebeePublishesQuota): array
    {
        $admin = $this->admin($username, 'direct_admin');
        $site = $this->site($username.' Site', $admin);
        $plan = PlatformPlan::query()->create([
            'name' => $username.' Plan',
            'code' => $username.'_plan',
            'audience' => 'both',
            'duration_days' => 30,
            'status' => 'active',
            'sort_order' => 0,
        ]);
        $plan->entitlements()->create([
            'resource_key' => PlatformPlan::RESOURCE_CREBEE_PUBLISHES,
            'enabled' => true,
            'quota_value' => $crebeePublishesQuota,
            'quota_period' => 'cycle',
            'unit' => 'times',
            'meta' => [],
        ]);

        app(AdminPlanSubscriptionService::class)->openOwner(
            admin: $admin,
            site: $site,
            plan: $plan,
            mode: 'direct_owner',
            operator: $admin,
            startsAt: now()->subMinute(),
            endsAt: now()->addDays(30),
            grantCredits: false
        );

        return [$admin, $site];
    }

    private function admin(string $username, string $role): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => $username,
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function site(string $name, Admin $owner): Site
    {
        $site = Site::query()->create([
            'owner_admin_id' => (int) $owner->id,
            'name' => $name,
            'status' => 'active',
            'customer_mode' => 'direct',
        ]);
        $site->members()->attach((int) $owner->id, ['role' => 'owner']);

        return $site;
    }

    private function agent(): CrebeeAgent
    {
        return CrebeeAgent::query()->create([
            'name' => 'Local Bridge',
            'agent_uid' => 'agent-'.str()->random(8),
            'secret_hash' => Hash::make('agent-secret'),
            'status' => 'active',
            'last_seen_at' => now(),
            'crebee_status' => 'online',
            'version' => '0.1.0',
        ]);
    }

    private function account(
        CrebeeAgent $agent,
        Site $site,
        Admin $owner,
        string $platform,
        string $crebeeAccountId,
        string $name
    ): CrebeeAccount {
        return CrebeeAccount::query()->create([
            'agent_id' => (int) $agent->id,
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'platform' => $platform,
            'crebee_account_id' => $crebeeAccountId,
            'account_name' => $name,
            'avatar' => '',
            'status' => 'bound',
            'bound_at' => now(),
            'last_synced_at' => now(),
            'raw_account' => [],
        ]);
    }

    private function article(Site $site, Admin $owner, string $title, string $content = '正文'): Article
    {
        $category = Category::query()->create([
            'site_id' => (int) $site->id,
            'name' => $title.' 分类',
            'slug' => str()->slug($title).'-category',
        ]);
        $author = Author::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'GEOFlow',
        ]);

        return Article::withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'title' => $title,
            'slug' => str()->slug($title).'-'.str()->random(6),
            'excerpt' => '',
            'cover_image' => '',
            'content' => $content,
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
    }
}
