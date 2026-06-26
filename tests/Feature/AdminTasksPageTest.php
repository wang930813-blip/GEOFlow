<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\Prompt;
use App\Models\Site;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Support\AdminWeb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 后台任务页（Blade）最小可用测试：鉴权与页面渲染。
 */
class AdminTasksPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_admin_login_when_visiting_tasks_page(): void
    {
        $this->get(route('admin.tasks.index'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_authenticated_admin_can_view_tasks_page_with_filters(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_admin',
            'password' => 'secret-123',
            'email' => 'tasks-admin@example.com',
            'display_name' => 'Tasks Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.index', ['keyword' => 'demo', 'status' => 'active']))
            ->assertOk()
            ->assertSee(__('admin.tasks.page_title'))
            ->assertSee(__('admin.tasks.empty_title'))
            ->assertViewHas('tasks')
            ->assertViewHas('taskI18n');
    }

    public function test_authenticated_admin_can_open_task_create_page(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_admin_create',
            'password' => 'secret-123',
            'email' => 'tasks-admin-create@example.com',
            'display_name' => 'Tasks Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.create'))
            ->assertOk()
            ->assertSee(__('admin.task_create.page_heading'));
    }

    public function test_task_create_page_hides_distribution_channel_create_entry(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_admin_create_no_distribution_entry',
            'password' => 'secret-123',
            'email' => 'tasks-admin-create-no-distribution-entry@example.com',
            'display_name' => 'Tasks Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => 'Distribution Entry Hidden Site',
            'status' => 'active',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'owner']);
        Category::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Distribution Entry Hidden Category',
            'slug' => 'distribution-entry-hidden-category',
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.tasks.create'))
            ->assertOk()
            ->assertSee(__('admin.task_create.section.distribution_title'))
            ->assertDontSee(route('admin.distribution.create'), false)
            ->assertDontSee(__('admin.task_create.distribution.create_link'));
    }

    public function test_task_create_page_shows_scheduled_publish_controls(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_admin_schedule_controls',
            'password' => 'secret-123',
            'email' => 'tasks-admin-schedule-controls@example.com',
            'display_name' => 'Tasks Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => 'Schedule Controls Site',
            'status' => 'active',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'owner']);
        Category::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Schedule Controls Category',
            'slug' => 'schedule-controls-category',
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.tasks.create'))
            ->assertOk()
            ->assertSee(__('admin.task_create.field.scheduled_publish_enabled'))
            ->assertSee(__('admin.task_create.field.scheduled_publish_at'))
            ->assertSee('name="scheduled_publish_enabled"', false)
            ->assertSee('name="scheduled_publish_at"', false);
    }

    public function test_task_creation_uses_scheduled_first_publish_time_for_local_only_scope(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 09:00:00'));

        $admin = Admin::query()->create([
            'username' => 'tasks_admin_store_schedule',
            'password' => 'secret-123',
            'email' => 'tasks-admin-store-schedule@example.com',
            'display_name' => 'Tasks Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        [$site, $fixtures] = $this->createTaskFormFixtures($admin);
        $scheduledAt = Carbon::parse('2026-06-16 18:30:00');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.tasks.store'), array_merge($fixtures, [
                'task_name' => 'Local Scheduled Publish Task',
                'status' => 'paused',
                'publish_scope' => 'local_only',
                'publish_interval' => 30,
                'scheduled_publish_enabled' => '1',
                'scheduled_publish_at' => $scheduledAt->format('Y-m-d\TH:i'),
            ]))
            ->assertRedirect(route('admin.tasks.index'));

        $task = Task::query()->where('name', 'Local Scheduled Publish Task')->firstOrFail();
        $this->assertSame('local_only', (string) $task->publish_scope);
        $this->assertTrue($task->next_publish_at->equalTo($scheduledAt));

        Carbon::setTestNow();
    }

    public function test_task_creation_ignores_scheduled_first_publish_time_for_non_local_scope(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 09:00:00'));

        $admin = Admin::query()->create([
            'username' => 'tasks_admin_ignore_schedule',
            'password' => 'secret-123',
            'email' => 'tasks-admin-ignore-schedule@example.com',
            'display_name' => 'Tasks Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        [$site, $fixtures] = $this->createTaskFormFixtures($admin);
        $scheduledAt = Carbon::parse('2026-06-20 18:30:00');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.tasks.store'), array_merge($fixtures, [
                'task_name' => 'Non Local Scheduled Publish Task',
                'status' => 'paused',
                'publish_scope' => 'local_and_distribution',
                'publish_interval' => 30,
                'scheduled_publish_enabled' => '1',
                'scheduled_publish_at' => $scheduledAt->format('Y-m-d\TH:i'),
            ]))
            ->assertRedirect(route('admin.tasks.index'));

        $task = Task::query()->where('name', 'Non Local Scheduled Publish Task')->firstOrFail();
        $this->assertSame('local_and_distribution', (string) $task->publish_scope);
        $this->assertTrue($task->next_publish_at->equalTo(Carbon::parse('2026-06-16 09:30:00')));
        $this->assertFalse($task->next_publish_at->equalTo($scheduledAt));

        Carbon::setTestNow();
    }

    public function test_task_create_page_prefills_title_library_from_query_string(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_admin_prefill_title',
            'password' => 'secret-123',
            'email' => 'tasks-admin-prefill-title@example.com',
            'display_name' => 'Tasks Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        Category::query()->create([
            'name' => 'Prefill Category',
            'slug' => 'prefill-category',
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => 'Prefill Title Library',
            'description' => 'desc',
            'title_count' => 1,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => 0,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.create', ['title_library_id' => (int) $titleLibrary->id]))
            ->assertOk()
            ->assertSee('value="'.(int) $titleLibrary->id.'" selected', false);
    }

    public function test_task_create_page_lists_titles_for_selected_title_library(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_admin_select_title',
            'password' => 'secret-123',
            'email' => 'tasks-admin-select-title@example.com',
            'display_name' => 'Tasks Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => 'Title Option Site',
            'status' => 'active',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'owner']);
        Category::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Title Option Category',
            'slug' => 'title-option-category',
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Selectable Title Library',
            'description' => 'desc',
            'title_count' => 2,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => 0,
        ]);
        $title = Title::query()->create([
            'site_id' => (int) $site->id,
            'library_id' => (int) $titleLibrary->id,
            'title' => 'Selectable task title',
            'keyword' => 'selectable',
            'is_ai_generated' => false,
            'used_count' => 0,
            'usage_count' => 0,
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.tasks.create', [
                'title_library_id' => (int) $titleLibrary->id,
                'fixed_title_id' => (int) $title->id,
            ]))
            ->assertOk()
            ->assertSee('name="fixed_title_id"', false)
            ->assertSee('Selectable task title')
            ->assertSee('value="'.(int) $title->id.'" selected', false);
    }

    public function test_task_create_saves_selected_title_when_it_belongs_to_library(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_admin_store_title',
            'password' => 'secret-123',
            'email' => 'tasks-admin-store-title@example.com',
            'display_name' => 'Tasks Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => 'Store Title Site',
            'status' => 'active',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'owner']);
        $titleLibrary = TitleLibrary::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Store Title Library',
            'description' => 'desc',
            'title_count' => 1,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => 0,
        ]);
        $title = Title::query()->create([
            'site_id' => (int) $site->id,
            'library_id' => (int) $titleLibrary->id,
            'title' => 'Stored selected title',
            'keyword' => 'stored',
            'is_ai_generated' => false,
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $prompt = Prompt::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Task Prompt',
            'type' => 'content',
            'content' => 'Write {{title}}',
        ]);
        $aiModel = AiModel::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Task Model',
            'model_id' => 'task-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.example.test',
            'api_key' => 'plain-key',
            'status' => 'active',
        ]);
        Category::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Store Category',
            'slug' => 'store-category',
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.tasks.store'), [
                'task_name' => 'Selected Title Task',
                'title_library_id' => (int) $titleLibrary->id,
                'fixed_title_id' => (int) $title->id,
                'prompt_id' => (int) $prompt->id,
                'ai_model_id' => (int) $aiModel->id,
                'status' => 'paused',
                'article_limit' => 1,
                'draft_limit' => 1,
                'publish_interval' => 60,
                'category_mode' => 'smart',
                'model_selection_mode' => 'fixed',
            ])
            ->assertRedirect(route('admin.tasks.index'));

        $this->assertDatabaseHas('tasks', [
            'name' => 'Selected Title Task',
            'title_library_id' => (int) $titleLibrary->id,
            'fixed_title_id' => (int) $title->id,
        ]);
    }

    public function test_task_article_action_links_to_filtered_article_list(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_article_filter_admin',
            'password' => 'secret-123',
            'email' => 'tasks-article-filter-admin@example.com',
            'display_name' => 'Tasks Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => 'Tasks Article Filter Site',
            'status' => 'active',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'owner']);

        $task = Task::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Filtered Task',
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.tasks.index'))
            ->assertOk()
            ->assertSee('/'.AdminWeb::basePath().'/articles?task_id='.(int) $task->id, false);
    }

    public function test_task_lifecycle_button_matches_task_status(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_lifecycle_admin',
            'password' => 'secret-123',
            'email' => 'tasks-lifecycle-admin@example.com',
            'display_name' => 'Tasks Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => 'Tasks Lifecycle Site',
            'status' => 'active',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'owner']);

        $activeTask = Task::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Active Task',
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);
        $pausedTask = Task::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Paused Task',
            'status' => 'paused',
            'schedule_enabled' => 0,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.tasks.index'))
            ->assertOk();

        $response->assertSee('id="batch-btn-'.(int) $activeTask->id.'"', false)
            ->assertSee('data-batch-action="stop"', false)
            ->assertSee('id="batch-btn-'.(int) $pausedTask->id.'"', false)
            ->assertSee('data-batch-action="start"', false)
            ->assertSee('text-green-600 hover:text-green-800 hover:bg-green-50', false);
    }

    public function test_completed_task_is_shown_as_completed_and_cannot_be_started_from_list(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_completed_status_admin',
            'password' => 'secret-123',
            'email' => 'tasks-completed-status@example.com',
            'display_name' => 'Tasks Completed Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => 'Tasks Completed Status Site',
            'status' => 'active',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'owner']);

        $task = Task::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Completed Task',
            'status' => 'completed',
            'schedule_enabled' => 0,
            'publish_interval' => 3600,
            'draft_limit' => 1,
            'article_limit' => 1,
            'created_count' => 1,
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.tasks.index'))
            ->assertOk();

        $response->assertSee(__('admin.tasks.status.completed'))
            ->assertSee('data-task-runnable="0"', false)
            ->assertDontSee('id="batch-btn-'.(int) $task->id.'"', false);
    }

    public function test_completed_task_cannot_be_started_by_batch_endpoint(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_completed_batch_admin',
            'password' => 'secret-123',
            'email' => 'tasks-completed-batch@example.com',
            'display_name' => 'Tasks Completed Batch Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => 'Tasks Completed Batch Site',
            'status' => 'active',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'owner']);

        $task = Task::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Completed Batch Task',
            'status' => 'completed',
            'schedule_enabled' => 0,
            'publish_interval' => 3600,
            'draft_limit' => 1,
            'article_limit' => 1,
            'created_count' => 1,
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->postJson(route('admin.tasks.batch'), [
                'task_id' => (int) $task->id,
                'action' => 'start',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $task->refresh();
        $this->assertSame('completed', (string) $task->status);
        $this->assertSame(0, (int) $task->schedule_enabled);
    }

    public function test_task_list_shows_distribution_failure_summary(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_distribution_status_admin',
            'password' => 'secret-123',
            'email' => 'tasks-distribution-status@example.com',
            'display_name' => 'Tasks Distribution Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => 'Tasks Distribution Status Site',
            'status' => 'active',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'owner']);
        $task = Task::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Distribution Failure Task',
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);
        $category = Category::query()->create([
            'name' => '任务分发分类',
            'site_id' => (int) $site->id,
            'slug' => 'task-distribution-category',
        ]);
        $author = Author::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'GEOFlow',
        ]);
        $channel = DistributionChannel::query()->create([
            'name' => '失败目标站点',
            'domain' => 'failed-target.example.com',
            'endpoint_url' => 'https://failed-target.example.com/geoflow/agent',
            'status' => 'active',
        ]);
        $article = Article::query()->create([
            'title' => '任务分发失败文章',
            'site_id' => (int) $site->id,
            'slug' => 'task-distribution-failed-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        ArticleDistribution::query()->create([
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'publish',
            'status' => 'failed',
            'idempotency_key' => 'task-list-failed',
            'last_error_message' => 'Target timeout',
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.tasks.index'))
            ->assertOk()
            ->assertSee(__('admin.distribution.task_status.failed', ['count' => 1]));
    }

    public function test_authenticated_admin_can_delete_task_without_legacy_article_queue_table(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tasks_delete_admin',
            'password' => 'secret-123',
            'email' => 'tasks-delete-admin@example.com',
            'display_name' => 'Tasks Delete Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => 'Tasks Delete Site',
            'status' => 'active',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'owner']);

        $task = Task::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Delete Task Without Legacy Queue',
            'status' => 'paused',
            'schedule_enabled' => 0,
            'publish_interval' => 3600,
            'draft_limit' => 5,
            'article_limit' => 10,
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->from(route('admin.tasks.index'))
            ->post(route('admin.tasks.delete', ['taskId' => (int) $task->id]))
            ->assertRedirect(route('admin.tasks.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('message', __('admin.tasks.message.delete_success'));

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }

    /**
     * @return array{0:Site,1:array<string,mixed>}
     */
    private function createTaskFormFixtures(Admin $admin): array
    {
        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => 'Scheduled Publish Site',
            'status' => 'active',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'owner']);
        $titleLibrary = TitleLibrary::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Scheduled Publish Title Library',
            'description' => 'desc',
            'title_count' => 1,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => 0,
        ]);
        $prompt = Prompt::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Scheduled Publish Prompt',
            'type' => 'content',
            'content' => 'Write {{title}}',
        ]);
        $aiModel = AiModel::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Scheduled Publish Model',
            'model_id' => 'scheduled-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.example.test',
            'api_key' => 'plain-key',
            'status' => 'active',
        ]);
        Category::query()->create([
            'site_id' => (int) $site->id,
            'name' => 'Scheduled Publish Category',
            'slug' => 'scheduled-publish-category',
        ]);

        return [$site, [
            'title_library_id' => (int) $titleLibrary->id,
            'prompt_id' => (int) $prompt->id,
            'ai_model_id' => (int) $aiModel->id,
            'article_limit' => 3,
            'draft_limit' => 2,
            'category_mode' => 'smart',
            'model_selection_mode' => 'fixed',
        ]];
    }
}
