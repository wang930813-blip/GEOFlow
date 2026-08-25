<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\Site;
use App\Models\Task;
use App\Models\TaskRun;
use App\Services\Admin\Analytics\AnalyticsFilter;
use App\Services\Admin\Analytics\AnalyticsLogQueryService;
use App\Support\CurrentSite;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminAnalyticsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_analytics_page_renders_after_dashboard_nav_item(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics'));

        $response
            ->assertOk()
            ->assertSee('数据分析')
            ->assertSee('按日期、站点、内容和日志来源查看内容生产与访问趋势')
            ->assertSee(__('admin.analytics.filters.apply'))
            ->assertSee(__('admin.analytics.filters.source_pending', ['source' => __('admin.analytics.filters.server')]))
            ->assertSee(route('admin.analytics'), false)
            ->assertSee(__('admin.analytics.overall_title'))
            ->assertSee(__('admin.analytics.single_site_title'))
            ->assertSee(__('admin.analytics.multi_site_title'))
            ->assertSee(__('admin.analytics.self_log_title'))
            ->assertSee('data-analytics-single-site-section', false)
            ->assertSee('data-analytics-multi-site-section', false)
            ->assertSee('data-analytics-log-section', false)
            ->assertSee('内容运营分析')
            ->assertSee(__('admin.dashboard.category_distribution'))
            ->assertSee(__('admin.dashboard.system_performance'))
            ->assertSee(__('admin.dashboard.latest_articles'))
            ->assertSee(__('admin.dashboard.task_health'))
            ->assertSee(__('admin.dashboard.material_health'))
            ->assertSee(__('admin.dashboard.ai_health'))
            ->assertSee(__('admin.dashboard.url_import_health'))
            ->assertSee('data-analytics-health-grid', false)
            ->assertSee('lg:grid-cols-2', false)
            ->assertSee(route('admin.categories.index'), false)
            ->assertSee(route('admin.articles.index'), false)
            ->assertSee(route('admin.keyword-libraries.index'), false)
            ->assertSee(route('admin.title-libraries.index'), false)
            ->assertSee(route('admin.knowledge-bases.index'), false)
            ->assertSee(route('admin.authors.index'), false)
            ->assertSee(route('admin.url-import.history'), false)
            ->assertSee('日志分析')
            ->assertSee('暂无日志数据');

        $html = $response->getContent();
        $this->assertStringContainsString(route('admin.dashboard'), $html);
        $this->assertStringContainsString(route('admin.analytics'), $html);
        $this->assertLessThan(
            strpos($html, 'data-analytics-multi-site-section'),
            strpos($html, 'data-analytics-single-site-section')
        );
        $this->assertLessThan(
            strpos($html, 'data-analytics-log-section'),
            strpos($html, 'data-analytics-multi-site-section')
        );
        $this->assertLessThan(
            strpos($html, route('admin.analytics')),
            strpos($html, route('admin.dashboard'))
        );
        $this->assertStringContainsString('admin-nav-link', $html);
        $this->assertStringContainsString('is-active font-medium', $html);
    }

    public function test_analytics_page_applies_date_filters_to_content_metrics(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 12:00:00'));

        $fixtures = $this->contentFixtures();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics', [
                'date_from' => '2026-05-20',
                'date_to' => '2026-05-21',
                'channel_id' => (int) $fixtures['channel']->id,
            ]))
            ->assertOk()
            ->assertSee('2026-05-20')
            ->assertSee('2026-05-21')
            ->assertSee(__('admin.analytics.global_overview.title'))
            ->assertSee('data-analytics-global-overview', false)
            ->assertSee(__('admin.dashboard.total_articles'))
            ->assertSee('今日新增', false)
            ->assertSee(__('admin.dashboard.published'))
            ->assertSee(__('admin.dashboard.publish_rate', ['rate' => 66.7]), false)
            ->assertSee(__('admin.dashboard.ai_generated'))
            ->assertSee(__('admin.dashboard.ai_generated_ratio', ['rate' => 100]), false)
            ->assertSee(__('admin.dashboard.total_views'))
            ->assertSee(__('admin.dashboard.active_tasks'))
            ->assertSee(__('admin.dashboard.ai_models'))
            ->assertSee(__('admin.dashboard.material_total'))
            ->assertSee(__('admin.dashboard.pending_review'))
            ->assertSee('筛选范围文章')
            ->assertSee('2')
            ->assertSee('筛选范围发布')
            ->assertSee('1')
            ->assertSee('运行中任务')
            ->assertSee('1')
            ->assertSee('失败任务')
            ->assertSee('1')
            ->assertSee('AI/API 调用')
            ->assertSee('9')
            ->assertSee('分发失败')
            ->assertSee('1')
            ->assertSee('筛选内热门文章')
            ->assertSee('范围内热门文章')
            ->assertSee('分析分类')
            ->assertSee(__('admin.dashboard.latest_articles'))
            ->assertSee(__('admin.dashboard.system_performance'))
            ->assertSee(__('admin.dashboard.task_health'))
            ->assertSee(__('admin.dashboard.material_health'))
            ->assertSee(__('admin.dashboard.ai_health'))
            ->assertSee(__('admin.dashboard.url_import_health'))
            ->assertSee('分发状态概览')
            ->assertSee('已同步')
            ->assertSee('失败');

        Carbon::setTestNow();
    }

    public function test_analytics_filter_presets_and_custom_dates_are_usable(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 12:00:00'));

        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.analytics', [
                'preset' => '7d',
                'date_from' => '2026-01-01',
                'date_to' => '2026-01-01',
            ]))
            ->assertOk()
            ->assertSee('value="2026-05-15"', false)
            ->assertSee('value="2026-05-21"', false)
            ->assertDontSee('value="2026-01-01"', false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.analytics', [
                'preset' => 'custom',
                'date_from' => '2026-05-20',
                'date_to' => '2026-05-21',
            ]))
            ->assertOk()
            ->assertSee(__('admin.analytics.filters.custom'))
            ->assertSee('value="2026-05-20"', false)
            ->assertSee('value="2026-05-21"', false);

        Carbon::setTestNow();
    }

    public function test_analytics_quick_time_buttons_stage_dates_until_apply(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 12:00:00'));

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics'))
            ->assertOk()
            ->assertSee('id="analytics-filter-form"', false)
            ->assertSee('type="hidden" name="preset" value="7d"', false)
            ->assertSee('data-analytics-preset-button', false)
            ->assertSee('data-preset="today"', false)
            ->assertSee('data-date-from="2026-05-21"', false)
            ->assertSee('data-preset="30d"', false)
            ->assertSee('data-date-from="2026-04-22"', false)
            ->assertDontSee('data-analytics-preset-submit', false)
            ->assertDontSee('requestSubmit()', false);

        Carbon::setTestNow();
    }

    public function test_analytics_page_renders_local_log_data_when_view_logs_exist(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 12:00:00'));

        $this->ensureViewLogsTable();
        $fixtures = $this->contentFixtures();
        $site = app(CurrentSite::class)->get();
        $admin = $this->admin();

        DB::table('view_logs')->insert([
            [
                'site_id' => (int) $site->id,
                'article_id' => (int) $fixtures['article']->id,
                'method' => 'GET',
                'path' => '/article/analytics-hot-article',
                'route_name' => 'site.article',
                'status_code' => 200,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Mozilla/5.0',
                'created_at' => Carbon::parse('2026-05-20 10:00:00'),
            ],
            [
                'site_id' => (int) $site->id,
                'article_id' => (int) $fixtures['article']->id,
                'method' => 'GET',
                'path' => '/article/analytics-hot-article',
                'route_name' => 'site.article',
                'status_code' => 200,
                'ip_address' => '127.0.0.2',
                'user_agent' => 'ChatGPT-User/1.0',
                'created_at' => Carbon::parse('2026-05-21 11:00:00'),
            ],
            [
                'site_id' => (int) $site->id,
                'article_id' => (int) $fixtures['article']->id,
                'method' => 'GET',
                'path' => '/article/analytics-hot-article',
                'route_name' => 'site.article',
                'status_code' => 200,
                'ip_address' => '127.0.0.3',
                'user_agent' => 'Googlebot/2.1',
                'created_at' => Carbon::parse('2026-05-21 11:30:00'),
            ],
            [
                'site_id' => (int) $site->id,
                'article_id' => (int) $fixtures['article']->id,
                'method' => 'GET',
                'path' => '/article/analytics-hot-article',
                'route_name' => 'site.article',
                'status_code' => 200,
                'ip_address' => '127.0.0.4',
                'user_agent' => 'ChatGPT-User/1.0',
                'created_at' => Carbon::parse('2026-05-01 11:00:00'),
            ],
            [
                'site_id' => (int) $site->id,
                'article_id' => null,
                'method' => 'GET',
                'path' => '/',
                'route_name' => 'site.home',
                'status_code' => 404,
                'ip_address' => '127.0.0.5',
                'user_agent' => 'Mozilla/5.0',
                'created_at' => Carbon::parse('2026-05-21 12:00:00'),
            ],
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.analytics', [
                'preset' => 'custom',
                'date_from' => '2026-05-20',
                'date_to' => '2026-05-21',
                'log_source' => 'local',
                'traffic_type' => 'all',
            ]))
            ->assertOk()
            ->assertSee(__('admin.analytics.logs_overview'))
            ->assertSee(__('admin.analytics.logs_kpi.pv'))
            ->assertSee('3')
            ->assertSee(__('admin.analytics.logs_kpi.unique_ip'))
            ->assertSee(__('admin.analytics.logs_kpi.ai_bot_pv'))
            ->assertSee('范围内热门文章')
            ->assertSee(__('admin.analytics.logs_bot.ai_bot'))
            ->assertSee(__('admin.analytics.logs_bot.search_bot'))
            ->assertSee('/article/analytics-hot-article')
            ->assertSee(__('admin.analytics.logs_kpi.errors'))
            ->assertSee('1');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.analytics', [
                'preset' => 'custom',
                'date_from' => '2026-05-20',
                'date_to' => '2026-05-21',
                'log_source' => 'local',
                'traffic_type' => 'ai_bot',
            ]))
            ->assertOk()
            ->assertSee(__('admin.analytics.logs_kpi.pv'))
            ->assertSee('1');

        Carbon::setTestNow();
    }

    public function test_local_log_analytics_are_scoped_to_current_site(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 12:00:00'));

        $this->ensureViewLogsTable();
        $site = Site::query()->create([
            'name' => 'Analytics Site A',
            'status' => 'active',
        ]);
        $otherSite = Site::query()->create([
            'name' => 'Analytics Site B',
            'status' => 'active',
        ]);

        DB::table('view_logs')->insert([
            [
                'site_id' => (int) $site->id,
                'article_id' => null,
                'method' => 'GET',
                'path' => '/',
                'route_name' => 'site.home',
                'status_code' => 200,
                'ip_address' => '127.0.0.10',
                'user_agent' => 'Mozilla/5.0',
                'created_at' => Carbon::parse('2026-05-21 10:00:00'),
            ],
            [
                'site_id' => (int) $site->id,
                'article_id' => null,
                'method' => 'GET',
                'path' => '/about',
                'route_name' => 'site.page',
                'status_code' => 200,
                'ip_address' => '127.0.0.11',
                'user_agent' => 'ChatGPT-User/1.0',
                'created_at' => Carbon::parse('2026-05-21 11:00:00'),
            ],
            [
                'site_id' => (int) $otherSite->id,
                'article_id' => null,
                'method' => 'GET',
                'path' => '/other',
                'route_name' => 'site.home',
                'status_code' => 404,
                'ip_address' => '127.0.0.12',
                'user_agent' => 'Googlebot/2.1',
                'created_at' => Carbon::parse('2026-05-21 11:30:00'),
            ],
        ]);

        app(CurrentSite::class)->set($site);

        $summary = app(AnalyticsLogQueryService::class)->summary(AnalyticsFilter::fromRequest([
            'preset' => 'custom',
            'date_from' => '2026-05-21',
            'date_to' => '2026-05-21',
            'log_source' => 'local',
            'traffic_type' => 'all',
        ]));

        $this->assertSame(2, $summary['kpis']['pv']);
        $this->assertSame(2, $summary['kpis']['unique_ip']);
        $this->assertSame(1, $summary['kpis']['ai_bot_pv']);
        $this->assertSame(0, $summary['kpis']['errors']);
        $this->assertEqualsCanonicalizing(['/', '/about'], array_column($summary['top_paths'], 'path'));

        app(CurrentSite::class)->set(null);
        Carbon::setTestNow();
    }

    public function test_recent_task_failures_are_scoped_to_current_user_site(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 12:00:00'));

        $visibleUser = Admin::query()->create([
            'username' => 'analytics_visible_user',
            'password' => 'secret-123',
            'email' => 'visible@example.com',
            'display_name' => 'Visible User',
            'role' => 'site_user',
            'status' => 'active',
        ]);
        $otherUser = Admin::query()->create([
            'username' => 'analytics_other_user',
            'password' => 'secret-123',
            'email' => 'other@example.com',
            'display_name' => 'Other User',
            'role' => 'site_user',
            'status' => 'active',
        ]);

        $visibleSite = Site::query()->create([
            'owner_admin_id' => (int) $visibleUser->id,
            'name' => 'Visible Analytics Site',
            'status' => 'active',
        ]);
        $visibleSite->members()->attach((int) $visibleUser->id, ['role' => 'owner']);

        $otherSite = Site::query()->create([
            'owner_admin_id' => (int) $otherUser->id,
            'name' => 'Other Analytics Site',
            'status' => 'active',
        ]);
        $otherSite->members()->attach((int) $otherUser->id, ['role' => 'owner']);

        $visibleTask = Task::query()->create([
            'site_id' => (int) $visibleSite->id,
            'owner_admin_id' => (int) $visibleUser->id,
            'name' => '当前用户论文辅导任务',
            'status' => 'active',
        ]);
        $otherTask = Task::query()->create([
            'site_id' => (int) $otherSite->id,
            'owner_admin_id' => (int) $otherUser->id,
            'name' => '其他用户论文辅导任务',
            'status' => 'active',
        ]);

        TaskRun::query()->create([
            'task_id' => (int) $visibleTask->id,
            'site_id' => (int) $visibleSite->id,
            'owner_admin_id' => (int) $visibleUser->id,
            'status' => 'failed',
            'error_message' => '当前账号规格额度不足，请联系平台升级或续费',
            'created_at' => Carbon::parse('2026-06-24 10:00:00'),
        ]);
        TaskRun::query()->create([
            'task_id' => (int) $otherTask->id,
            'site_id' => (int) $otherSite->id,
            'owner_admin_id' => (int) $otherUser->id,
            'status' => 'failed',
            'error_message' => '其他账号规格额度不足，请联系平台升级或续费',
            'created_at' => Carbon::parse('2026-06-24 11:00:00'),
        ]);

        app(CurrentSite::class)->set(null);

        $this->actingAs($visibleUser, 'admin')
            ->withSession(['current_site_id' => (int) $visibleSite->id])
            ->get(route('admin.analytics', [
                'preset' => 'custom',
                'date_from' => '2026-06-24',
                'date_to' => '2026-06-24',
            ]))
            ->assertOk()
            ->assertSee('当前用户论文辅导任务')
            ->assertSee('当前账号规格额度不足，请联系平台升级或续费')
            ->assertDontSee('其他用户论文辅导任务')
            ->assertDontSee('其他账号规格额度不足，请联系平台升级或续费');

        app(CurrentSite::class)->set(null);
        Carbon::setTestNow();
    }

    public function test_recent_task_failures_are_scoped_to_agent_child_users(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 12:00:00'));

        $agent = Admin::query()->create([
            'username' => 'analytics_agent',
            'password' => 'secret-123',
            'email' => 'analytics-agent@example.com',
            'display_name' => 'Analytics Agent',
            'role' => 'agent_admin',
            'status' => 'active',
        ]);
        $agentUser = Admin::query()->create([
            'username' => 'analytics_agent_user',
            'password' => 'secret-123',
            'email' => 'analytics-agent-user@example.com',
            'display_name' => 'Analytics Agent User',
            'role' => 'site_user',
            'status' => 'active',
            'created_by' => (int) $agent->id,
        ]);
        $otherAgent = Admin::query()->create([
            'username' => 'analytics_other_agent',
            'password' => 'secret-123',
            'email' => 'analytics-other-agent@example.com',
            'display_name' => 'Analytics Other Agent',
            'role' => 'agent_admin',
            'status' => 'active',
        ]);
        $otherAgentUser = Admin::query()->create([
            'username' => 'analytics_other_agent_user',
            'password' => 'secret-123',
            'email' => 'analytics-other-agent-user@example.com',
            'display_name' => 'Analytics Other Agent User',
            'role' => 'site_user',
            'status' => 'active',
            'created_by' => (int) $otherAgent->id,
        ]);

        $agentSite = Site::query()->create([
            'owner_admin_id' => (int) $agentUser->id,
            'agent_admin_id' => (int) $agent->id,
            'name' => 'Agent Analytics Site',
            'status' => 'active',
            'customer_mode' => 'agent',
        ]);
        $agentSite->members()->attach((int) $agentUser->id, ['role' => 'owner']);

        $otherAgentSite = Site::query()->create([
            'owner_admin_id' => (int) $otherAgentUser->id,
            'agent_admin_id' => (int) $otherAgent->id,
            'name' => 'Other Agent Analytics Site',
            'status' => 'active',
            'customer_mode' => 'agent',
        ]);
        $otherAgentSite->members()->attach((int) $otherAgentUser->id, ['role' => 'owner']);

        $agentTask = Task::query()->withoutGlobalScopes()->create([
            'site_id' => (int) $agentSite->id,
            'owner_admin_id' => (int) $agentUser->id,
            'name' => '代理下级论文辅导任务',
            'status' => 'active',
        ]);
        $otherAgentTask = Task::query()->withoutGlobalScopes()->create([
            'site_id' => (int) $otherAgentSite->id,
            'owner_admin_id' => (int) $otherAgentUser->id,
            'name' => '其他代理论文辅导任务',
            'status' => 'active',
        ]);

        TaskRun::query()->withoutGlobalScopes()->create([
            'task_id' => (int) $agentTask->id,
            'site_id' => (int) $agentSite->id,
            'owner_admin_id' => (int) $agentUser->id,
            'status' => 'failed',
            'error_message' => '代理下级账号规格额度不足',
            'created_at' => Carbon::parse('2026-06-24 10:00:00'),
        ]);
        TaskRun::query()->withoutGlobalScopes()->create([
            'task_id' => (int) $otherAgentTask->id,
            'site_id' => (int) $otherAgentSite->id,
            'owner_admin_id' => (int) $otherAgentUser->id,
            'status' => 'failed',
            'error_message' => '其他代理账号规格额度不足',
            'created_at' => Carbon::parse('2026-06-24 11:00:00'),
        ]);

        app(CurrentSite::class)->set(null);

        $this->actingAs($agent, 'admin')
            ->get(route('admin.analytics', [
                'preset' => 'custom',
                'date_from' => '2026-06-24',
                'date_to' => '2026-06-24',
            ]))
            ->assertOk()
            ->assertSee('代理下级论文辅导任务')
            ->assertSee('代理下级账号规格额度不足')
            ->assertDontSee('其他代理论文辅导任务')
            ->assertDontSee('其他代理账号规格额度不足');

        app(CurrentSite::class)->set(null);
        Carbon::setTestNow();
    }

    public function test_analytics_charts_use_compact_three_point_date_axis(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 12:00:00'));

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics', ['preset' => '30d']))
            ->assertOk()
            ->assertSee('data-analytics-axis="compact"', false)
            ->assertSee('data-axis-label="start"', false)
            ->assertSee('data-axis-label="middle"', false)
            ->assertSee('data-axis-label="end"', false)
            ->assertSee('04-22')
            ->assertSee('05-06')
            ->assertSee('05-21')
            ->assertDontSee('04-23 ·', false)
            ->assertDontSee('04-23</div>', false);

        Carbon::setTestNow();
    }

    private function admin(): Admin
    {
        $admin = Admin::query()->create([
            'username' => 'analytics_admin',
            'password' => 'secret-123',
            'email' => 'analytics-admin@example.com',
            'display_name' => 'Analytics Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $site = app(CurrentSite::class)->get();
        if ($site instanceof Site) {
            $site->members()->syncWithoutDetaching([
                $admin->id => ['role' => 'owner'],
            ]);
        }

        return $admin;
    }

    /**
     * @return array<string, object>
     */
    private function contentFixtures(): array
    {
        $this->ensureAnalyticsSiteContext();

        $author = Author::query()->create([
            'name' => '分析作者',
            'slug' => 'analytics-author',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => '分析分类',
            'slug' => 'analytics-category',
            'status' => 'active',
        ]);
        $task = Task::query()->create([
            'name' => '分析任务',
            'status' => 'active',
            'article_limit' => 10,
            'created_count' => 2,
            'published_count' => 1,
        ]);
        $channel = DistributionChannel::query()->create([
            'name' => '分析渠道',
            'domain' => 'analytics.example.com',
            'endpoint_url' => 'https://analytics.example.com',
            'status' => 'active',
        ]);
        $article = Article::query()->create([
            'title' => '范围内热门文章',
            'slug' => 'analytics-hot-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'task_id' => (int) $task->id,
            'status' => 'published',
            'review_status' => 'approved',
            'view_count' => 12,
            'is_ai_generated' => 1,
            'published_at' => Carbon::parse('2026-05-20 10:00:00'),
            'created_at' => Carbon::parse('2026-05-20 09:00:00'),
        ]);
        Article::query()->create([
            'title' => '范围内草稿文章',
            'slug' => 'analytics-draft-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'task_id' => (int) $task->id,
            'status' => 'draft',
            'review_status' => 'pending',
            'view_count' => 0,
            'is_ai_generated' => 1,
            'created_at' => Carbon::parse('2026-05-21 09:00:00'),
        ]);
        Article::query()->create([
            'title' => '范围外文章',
            'slug' => 'analytics-old-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'task_id' => (int) $task->id,
            'status' => 'published',
            'review_status' => 'approved',
            'view_count' => 99,
            'is_ai_generated' => 1,
            'published_at' => Carbon::parse('2026-05-12 10:00:00'),
            'created_at' => Carbon::parse('2026-05-12 09:00:00'),
        ]);
        TaskRun::query()->create([
            'task_id' => (int) $task->id,
            'article_id' => (int) $article->id,
            'status' => 'running',
            'created_at' => Carbon::parse('2026-05-20 11:00:00'),
        ]);
        TaskRun::query()->create([
            'task_id' => (int) $task->id,
            'status' => 'failed',
            'error_message' => '测试失败',
            'created_at' => Carbon::parse('2026-05-21 11:00:00'),
        ]);
        AiModel::query()->create([
            'name' => '分析模型',
            'model_id' => 'gpt-test',
            'model_type' => 'chat',
            'api_url' => 'https://api.example.com',
            'used_today' => 9,
            'total_used' => 18,
            'status' => 'active',
        ]);
        ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'synced',
            'idempotency_key' => 'analytics-synced',
            'created_at' => Carbon::parse('2026-05-20 12:00:00'),
        ]);
        ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'update',
            'status' => 'failed',
            'idempotency_key' => 'analytics-failed',
            'created_at' => Carbon::parse('2026-05-21 12:00:00'),
        ]);

        return [
            'channel' => $channel,
            'task' => $task,
            'article' => $article,
        ];
    }

    private function ensureAnalyticsSiteContext(): Site
    {
        $current = app(CurrentSite::class)->get();
        if ($current instanceof Site) {
            return $current;
        }

        $site = Site::query()->create([
            'name' => 'Analytics Fixture Site',
            'status' => 'active',
        ]);
        app(CurrentSite::class)->set($site);

        return $site;
    }

    private function ensureViewLogsTable(): void
    {
        if (Schema::hasTable('view_logs')) {
            DB::table('view_logs')->truncate();

            return;
        }

        Schema::create('view_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('sites')->cascadeOnDelete();
            $table->unsignedBigInteger('article_id')->nullable();
            $table->string('method', 16)->default('GET');
            $table->string('path', 2048)->default('');
            $table->string('route_name', 128)->nullable();
            $table->unsignedSmallInteger('status_code')->default(200);
            $table->string('ip_address', 64)->default('');
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
}
