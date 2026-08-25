<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\KnowledgeBase;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminAccountResourceIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_business_tables_have_owner_admin_id(): void
    {
        foreach ([
            'knowledge_bases',
            'knowledge_chunks',
            'image_libraries',
            'images',
            'articles',
            'categories',
            'authors',
            'tasks',
            'task_runs',
            'task_schedules',
            'title_libraries',
            'titles',
            'keyword_libraries',
            'keywords',
            'keyword_question_variants',
            'url_import_jobs',
            'url_import_job_logs',
            'geo_inclusion_check_runs',
            'geo_inclusion_check_results',
            'brand_diagnosis_runs',
            'brand_diagnosis_questions',
            'brand_diagnosis_results',
            'brand_diagnosis_sources',
            'brand_diagnosis_brand_mentions',
            'media_submissions',
        ] as $table) {
            if (Schema::hasTable($table)) {
                $this->assertTrue(Schema::hasColumn($table, 'owner_admin_id'), $table.' missing owner_admin_id');
            }
        }
    }

    public function test_site_user_only_sees_own_knowledge_bases_in_same_site(): void
    {
        $site = $this->createSite('Shared Agent Site');
        $userOne = $this->createAdmin('resource_user_one', 'site_user');
        $userTwo = $this->createAdmin('resource_user_two', 'site_user');
        $site->members()->attach((int) $userOne->id, ['role' => 'member']);
        $site->members()->attach((int) $userTwo->id, ['role' => 'member']);

        KnowledgeBase::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $userOne->id,
            'name' => 'User One Knowledge',
            'description' => 'User one resource',
            'content' => 'User one content',
            'character_count' => 16,
            'used_task_count' => 0,
            'file_type' => 'markdown',
            'file_path' => '',
            'word_count' => 3,
            'usage_count' => 0,
        ]);
        KnowledgeBase::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $userTwo->id,
            'name' => 'User Two Knowledge',
            'description' => 'User two resource',
            'content' => 'User two content',
            'character_count' => 16,
            'used_task_count' => 0,
            'file_type' => 'markdown',
            'file_path' => '',
            'word_count' => 3,
            'usage_count' => 0,
        ]);

        app(CurrentSite::class)->set($site);

        $this->actingAs($userOne, 'admin');

        $this->assertSame(
            ['User One Knowledge'],
            KnowledgeBase::query()->orderBy('id')->pluck('name')->all()
        );
    }

    public function test_agent_admin_can_see_site_users_knowledge_bases_in_same_site(): void
    {
        $agent = $this->createAdmin('resource_agent', 'agent_admin');
        $site = $this->createSite('Agent Resource Site', $agent, 'agent');
        $userOne = $this->createAdmin('resource_agent_member_one', 'site_user');
        $userTwo = $this->createAdmin('resource_agent_member_two', 'site_user');
        $site->members()->attach((int) $userOne->id, ['role' => 'member']);
        $site->members()->attach((int) $userTwo->id, ['role' => 'member']);

        foreach ([[$userOne, 'Agent Member One Knowledge'], [$userTwo, 'Agent Member Two Knowledge']] as [$owner, $name]) {
            KnowledgeBase::query()->create([
                'site_id' => (int) $site->id,
                'owner_admin_id' => (int) $owner->id,
                'name' => $name,
                'description' => 'Agent visible resource',
                'content' => 'Agent visible content',
                'character_count' => 21,
                'used_task_count' => 0,
                'file_type' => 'markdown',
                'file_path' => '',
                'word_count' => 3,
                'usage_count' => 0,
            ]);
        }

        app(CurrentSite::class)->set($site);

        $this->actingAs($agent, 'admin');

        $this->assertSame(
            ['Agent Member One Knowledge', 'Agent Member Two Knowledge'],
            KnowledgeBase::query()->orderBy('id')->pluck('name')->all()
        );
    }

    public function test_owner_admin_id_is_filled_when_site_user_creates_resource(): void
    {
        $site = $this->createSite('Auto Owner Site');
        $user = $this->createAdmin('auto_owner_user', 'site_user');
        $site->members()->attach((int) $user->id, ['role' => 'member']);

        app(CurrentSite::class)->set($site);
        $this->actingAs($user, 'admin');

        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => 'Auto Owner Knowledge',
            'description' => 'Auto owner resource',
            'content' => 'Auto owner content',
            'character_count' => 18,
            'used_task_count' => 0,
            'file_type' => 'markdown',
            'file_path' => '',
            'word_count' => 3,
            'usage_count' => 0,
        ]);

        $this->assertSame((int) $site->id, (int) $knowledgeBase->site_id);
        $this->assertSame((int) $user->id, (int) $knowledgeBase->owner_admin_id);
    }

    private function createAdmin(string $username, string $role): Admin
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

    private function createSite(string $name, ?Admin $owner = null, string $mode = 'agent'): Site
    {
        $owner ??= $this->createAdmin(str($name)->slug('_').'_owner', 'agent_admin');

        $site = Site::query()->create([
            'owner_admin_id' => (int) $owner->id,
            'name' => $name,
            'status' => 'active',
            'customer_mode' => $mode,
            'agent_admin_id' => $mode === 'agent' ? (int) $owner->id : null,
        ]);
        $site->members()->attach((int) $owner->id, ['role' => 'owner']);

        return $site;
    }
}
