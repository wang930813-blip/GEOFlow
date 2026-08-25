<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\CrebeeAccount;
use App\Models\CrebeeAgent;
use App\Models\CrebeeBindRequest;
use App\Models\CrebeePublishJob;
use App\Models\CrebeePublishJobItem;
use App\Models\ImageLibrary;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\Site;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Models\VideoGenerationJob;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAgentDataScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_agent_can_open_self_media_page_without_own_site_and_only_sees_child_user_accounts(): void
    {
        $own = $this->agentScenario('agent_self_media_own');
        $other = $this->agentScenario('agent_self_media_other');
        $agent = $this->crebeeAgent();

        $this->crebeeAccount($agent, $own['site'], $own['user'], 'douyin', 'Own Douyin Account');
        $this->crebeeAccount($agent, $other['site'], $other['user'], 'bilibili', 'Other Bilibili Account');
        $this->bindRequest($own['site'], $own['user'], 'weibo');
        $this->bindRequest($other['site'], $other['user'], 'xiaohongshu');

        $this->actingAs($own['agent'], 'admin')
            ->get(route('admin.crebee-accounts.index'))
            ->assertOk()
            ->assertSee('Own Douyin Account')
            ->assertSee($own['user']->username)
            ->assertSee('weibo')
            ->assertDontSee('Other Bilibili Account')
            ->assertDontSee($other['user']->username)
            ->assertDontSee('xiaohongshu');
    }

    public function test_agent_can_open_b2b_page_but_cannot_open_website_without_own_site(): void
    {
        $scenario = $this->agentScenario('agent_b2b_scope');

        $this->actingAs($scenario['agent'], 'admin')
            ->get(route('admin.b2b-websites.index'))
            ->assertOk()
            ->assertSee('B2B')
            ->assertSee('仅查看')
            ->assertDontSee('鏌ョ湅')
            ->assertDontSee('admin.b2b-websites.open');

        $this->actingAs($scenario['agent'], 'admin')
            ->post(route('admin.b2b-websites.open', ['websiteKey' => 'tianzhu']))
            ->assertForbidden();
    }

    public function test_agent_task_page_is_scoped_to_child_user_sites_without_current_site(): void
    {
        $own = $this->agentScenario('agent_tasks_own');
        $other = $this->agentScenario('agent_tasks_other');
        $direct = $this->directScenario('agent_tasks_direct');

        $this->task($own['site'], $own['user'], 'Own Agent Task');
        $this->task($other['site'], $other['user'], 'Other Agent Task');
        $this->task($direct['site'], $direct['admin'], 'Direct Task');

        $this->actingAs($own['agent'], 'admin')
            ->get(route('admin.tasks.index'))
            ->assertOk()
            ->assertSee('Own Agent Task')
            ->assertDontSee('Other Agent Task')
            ->assertDontSee('Direct Task');
    }

    public function test_agent_material_stats_are_scoped_to_child_user_sites_without_current_site(): void
    {
        $own = $this->agentScenario('agent_materials_own');
        $other = $this->agentScenario('agent_materials_other');

        $this->materialSet($own['site'], $own['user'], 'Own');
        $this->materialSet($other['site'], $other['user'], 'Other');

        $response = $this->actingAs($own['agent'], 'admin')
            ->get(route('admin.materials.index'));

        $response->assertOk();
        $response->assertViewHas('stats', function (array $stats): bool {
            return (int) $stats['keyword_libraries'] === 1
                && (int) $stats['total_keywords'] === 1
                && (int) $stats['title_libraries'] === 1
                && (int) $stats['total_titles'] === 1
                && (int) $stats['image_libraries'] === 1
                && (int) $stats['knowledge_bases'] === 1
                && (int) $stats['authors'] === 1;
            });
    }

    public function test_agent_material_sub_pages_are_scoped_to_child_user_sites_without_current_site(): void
    {
        $own = $this->agentScenario('agent_material_sub_pages_own');
        $other = $this->agentScenario('agent_material_sub_pages_other');

        $this->materialSet($own['site'], $own['user'], 'Own Agent Material');
        $this->materialSet($other['site'], $other['user'], 'Other Agent Material');

        foreach ([
            route('admin.keyword-libraries.index'),
            route('admin.title-libraries.index'),
            route('admin.image-libraries.index'),
            route('admin.knowledge-bases.index'),
            route('admin.authors.index'),
        ] as $url) {
            $this->actingAs($own['agent'], 'admin')
                ->get($url)
                ->assertOk()
                ->assertSee('Own Agent Material')
                ->assertDontSee('Other Agent Material');
        }
    }

    public function test_agent_article_page_is_scoped_to_child_user_sites_without_current_site(): void
    {
        $own = $this->agentScenario('agent_articles_own');
        $other = $this->agentScenario('agent_articles_other');
        $direct = $this->directScenario('agent_articles_direct');

        $this->article($own['site'], $own['user'], 'Own Agent Article');
        $this->article($other['site'], $other['user'], 'Other Agent Article');
        $this->article($direct['site'], $direct['admin'], 'Direct Article');

        $this->actingAs($own['agent'], 'admin')
            ->get(route('admin.articles.index'))
            ->assertOk()
            ->assertSee('Own Agent Article')
            ->assertDontSee('Other Agent Article')
            ->assertDontSee('Direct Article')
            ->assertDontSee(route('admin.articles.create'), false);
    }

    public function test_agent_video_page_is_scoped_to_child_user_sites_without_current_site(): void
    {
        $own = $this->agentScenario('agent_videos_own');
        $other = $this->agentScenario('agent_videos_other');
        $direct = $this->directScenario('agent_videos_direct');

        $this->video($own['site'], $own['user'], 'Own Agent Video');
        $this->video($other['site'], $other['user'], 'Other Agent Video');
        $this->video($direct['site'], $direct['admin'], 'Direct Video');

        $this->actingAs($own['agent'], 'admin')
            ->get(route('admin.video-generations.index'))
            ->assertOk()
            ->assertSee('Own Agent Video')
            ->assertDontSee('Other Agent Video')
            ->assertDontSee('Direct Video')
            ->assertDontSee(route('admin.video-generations.create'), false);
    }

    public function test_agent_publish_records_are_scoped_to_child_user_sites_without_current_site(): void
    {
        $own = $this->agentScenario('agent_publish_records_own');
        $other = $this->agentScenario('agent_publish_records_other');
        $direct = $this->directScenario('agent_publish_records_direct');
        $agent = $this->crebeeAgent();

        $ownAccount = $this->crebeeAccount($agent, $own['site'], $own['user'], 'douyin', 'Own Publish Account');
        $otherAccount = $this->crebeeAccount($agent, $other['site'], $other['user'], 'bilibili', 'Other Publish Account');
        $directAccount = $this->crebeeAccount($agent, $direct['site'], $direct['admin'], 'weibo', 'Direct Publish Account');

        $this->publishRecord($own['site'], $own['user'], $ownAccount, 'Own Agent Published Article');
        $this->publishRecord($other['site'], $other['user'], $otherAccount, 'Other Agent Published Article');
        $this->publishRecord($direct['site'], $direct['admin'], $directAccount, 'Direct Published Article');

        $this->actingAs($own['agent'], 'admin')
            ->get(route('admin.crebee-publish-records.index'))
            ->assertOk()
            ->assertSee('Own Agent Published Article')
            ->assertDontSee('Other Agent Published Article')
            ->assertDontSee('Direct Published Article');
    }

    public function test_agent_cannot_operate_self_media_binding_or_publish_actions(): void
    {
        $own = $this->agentScenario('agent_read_only_actions');
        $bridgeAgent = $this->crebeeAgent();
        $account = $this->crebeeAccount($bridgeAgent, $own['site'], $own['user'], 'douyin', 'Own Readonly Account');
        $request = $this->bindRequest($own['site'], $own['user'], 'weibo');
        $article = $this->article($own['site'], $own['user'], 'Readonly Publish Article');
        $video = $this->video($own['site'], $own['user'], 'Readonly Publish Video');

        $this->actingAs($own['agent'], 'admin')
            ->withSession(['current_site_id' => (int) $own['site']->id])
            ->post(route('admin.crebee-accounts.requests.processing', $request))
            ->assertForbidden();

        $this->actingAs($own['agent'], 'admin')
            ->withSession(['current_site_id' => (int) $own['site']->id])
            ->post(route('admin.crebee-accounts.unbind', $account))
            ->assertForbidden();

        $this->actingAs($own['agent'], 'admin')
            ->withSession(['current_site_id' => (int) $own['site']->id])
            ->post(route('admin.articles.self-media.publish', $article), [
                'crebee_account_ids' => [(int) $account->id],
            ])
            ->assertForbidden();

        $this->actingAs($own['agent'], 'admin')
            ->withSession(['current_site_id' => (int) $own['site']->id])
            ->post(route('admin.video-generations.self-media.publish', $video), [
                'crebee_account_ids' => [(int) $account->id],
            ])
            ->assertForbidden();
    }

    /**
     * @return array{agent: Admin, user: Admin, site: Site}
     */
    private function agentScenario(string $prefix): array
    {
        $agent = $this->admin($prefix.'_agent', 'agent_admin');
        $user = $this->admin($prefix.'_user', 'site_user', $agent);
        $site = Site::query()->create([
            'owner_admin_id' => (int) $user->id,
            'agent_admin_id' => (int) $agent->id,
            'name' => $prefix.' site',
            'status' => 'active',
            'customer_mode' => 'agent',
        ]);
        $site->members()->attach((int) $user->id, ['role' => 'owner']);

        return ['agent' => $agent, 'user' => $user, 'site' => $site];
    }

    /**
     * @return array{admin: Admin, site: Site}
     */
    private function directScenario(string $prefix): array
    {
        $admin = $this->admin($prefix.'_direct', 'direct_admin');
        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => $prefix.' direct site',
            'status' => 'active',
            'customer_mode' => 'direct',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'owner']);

        return ['admin' => $admin, 'site' => $site];
    }

    private function admin(string $username, string $role, ?Admin $creator = null): Admin
    {
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

    private function crebeeAgent(): CrebeeAgent
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

    private function crebeeAccount(CrebeeAgent $agent, Site $site, Admin $owner, string $platform, string $name): CrebeeAccount
    {
        return CrebeeAccount::query()->create([
            'agent_id' => (int) $agent->id,
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'platform' => $platform,
            'crebee_account_id' => str($name)->slug('-')->toString(),
            'account_name' => $name,
            'avatar' => '',
            'status' => 'bound',
            'bound_at' => now(),
            'last_synced_at' => now(),
            'raw_account' => [],
        ]);
    }

    private function bindRequest(Site $site, Admin $owner, string $platform): CrebeeBindRequest
    {
        return CrebeeBindRequest::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'platform' => $platform,
            'status' => 'pending',
            'requested_at' => now(),
            'expired_at' => now()->addHours(2),
            'meta' => [],
        ]);
    }

    private function task(Site $site, Admin $owner, string $name): Task
    {
        return Task::query()->withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'name' => $name,
            'status' => 'active',
            'publish_interval' => 3600,
            'draft_limit' => 10,
            'article_limit' => 10,
            'need_review' => 0,
        ]);
    }

    private function materialSet(Site $site, Admin $owner, string $prefix): void
    {
        $keywordLibrary = KeywordLibrary::query()->withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'name' => $prefix.' Keyword Library',
            'description' => '',
            'keyword_count' => 1,
        ]);
        Keyword::query()->withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'library_id' => (int) $keywordLibrary->id,
            'keyword' => $prefix.' keyword',
        ]);

        $titleLibrary = TitleLibrary::query()->withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'name' => $prefix.' Title Library',
            'description' => '',
            'title_count' => 1,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => 0,
        ]);
        Title::query()->withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'library_id' => (int) $titleLibrary->id,
            'title' => $prefix.' title',
            'keyword' => $prefix.' keyword',
        ]);

        ImageLibrary::query()->withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'name' => $prefix.' Image Library',
            'description' => '',
            'image_count' => 0,
            'used_task_count' => 0,
        ]);

        KnowledgeBase::query()->withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'name' => $prefix.' Knowledge Base',
            'description' => '',
            'content' => 'content',
            'character_count' => 7,
            'used_task_count' => 0,
            'file_type' => 'markdown',
            'file_path' => '',
            'word_count' => 1,
            'usage_count' => 0,
        ]);

        Author::query()->withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'name' => $prefix.' Author',
            'email' => '',
            'avatar' => '',
            'website' => '',
        ]);
    }

    private function article(Site $site, Admin $owner, string $title): Article
    {
        $author = Author::query()->withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'name' => $title.' Author',
            'email' => '',
            'avatar' => '',
            'website' => '',
        ]);

        return Article::query()->withoutGlobalScopes()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'title' => $title,
            'slug' => str($title)->slug('-')->toString(),
            'excerpt' => '',
            'content' => $title.' content',
            'category_id' => $this->categoryId($title),
            'author_id' => (int) $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);
    }

    private function categoryId(string $name): int
    {
        return (int) \App\Models\Category::query()->create([
            'name' => $name.' Category',
            'slug' => str($name.' Category')->slug('-')->toString(),
            'sort_order' => 0,
        ])->id;
    }

    private function video(Site $site, Admin $owner, string $title): VideoGenerationJob
    {
        return VideoGenerationJob::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'created_by_admin_id' => (int) $owner->id,
            'title' => $title,
            'subject' => $title,
            'script' => 'script',
            'status' => 'success',
            'progress' => 100,
            'api_task_id' => 'task-'.str($title)->slug('-')->toString(),
            'video_count' => 1,
            'request_payload' => [],
            'result_payload' => [],
            'videos' => ['https://video.example.test/'.$title.'.mp4'],
            'combined_videos' => [],
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
    }

    private function publishRecord(Site $site, Admin $owner, CrebeeAccount $account, string $title): CrebeePublishJobItem
    {
        $job = CrebeePublishJob::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'agent_id' => (int) $account->agent_id,
            'content_type' => 'article',
            'title' => $title,
            'status' => 'success',
            'submitted_at' => now()->subMinute(),
            'finished_at' => now(),
            'payload' => [],
            'raw_response' => [],
        ]);

        return CrebeePublishJobItem::query()->create([
            'job_id' => (int) $job->id,
            'crebee_account_id' => (int) $account->id,
            'platform' => (string) $account->platform,
            'crebee_task_id' => 'crebee-task-'.str($title)->slug('-')->toString(),
            'status' => 'success',
            'progress' => 100,
            'message' => '',
            'published_url' => 'https://example.test/'.str($title)->slug('-')->toString(),
            'published_at' => now(),
            'payload' => [],
            'raw_response' => [],
        ]);
    }
}
