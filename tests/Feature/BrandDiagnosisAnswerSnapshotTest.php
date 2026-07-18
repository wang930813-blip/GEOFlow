<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BrandDiagnosisQuestion;
use App\Models\BrandDiagnosisResult;
use App\Models\BrandDiagnosisRun;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandDiagnosisAnswerSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_snapshot_token_returns_branded_not_found_page_without_authentication(): void
    {
        $this->get('/brand-diagnosis/snapshot/'.str_repeat('A', 48))
            ->assertNotFound()
            ->assertSee('快照不存在');
    }

    public function test_brand_diagnosis_result_receives_a_public_snapshot_token_when_created(): void
    {
        $result = $this->createResult();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{48}$/', (string) $result->snapshot_token);
    }

    public function test_public_snapshot_renders_the_question_answer_platform_and_sources_without_authentication(): void
    {
        $result = $this->createResult([
            'raw_response' => ['id' => 'provider-secret-response-id'],
        ]);
        $result->sources()->create([
            'owner_admin_id' => (int) $result->owner_admin_id,
            'run_id' => (int) $result->run_id,
            'question_id' => (int) $result->question_id,
            'platform' => 'wenxin',
            'title' => '科研服务参考资料',
            'url' => 'https://example.com/research-guide',
            'domain' => 'example.com',
            'source_type' => 'web_search_result',
        ]);
        $result->sources()->create([
            'owner_admin_id' => (int) $result->owner_admin_id,
            'run_id' => (int) $result->run_id,
            'question_id' => (int) $result->question_id,
            'platform' => 'wenxin',
            'title' => 'Unsafe source',
            'url' => 'javascript:alert(1)',
            'domain' => '',
            'source_type' => 'web_search_result',
        ]);

        $response = $this->get('/brand-diagnosis/snapshot/'.$result->snapshot_token);

        $response
            ->assertOk()
            ->assertSee('class="brand"', false)
            ->assertSee('class="shell"', false)
            ->assertSee('class="card"', false)
            ->assertSee('class="question-bubble"', false)
            ->assertSee('class="continue-btn"', false)
            ->assertDontSee('class="voucher"', false)
            ->assertSee('学术易')
            ->assertSee('文心一言')
            ->assertSee('国内科研选题辅导平台哪些好？')
            ->assertSee('学术易提供科研选题、文献梳理和论文写作指导服务。')
            ->assertSee('科研服务参考资料')
            ->assertSee('https://example.com/research-guide', false)
            ->assertSee('href="https://chat.baidu.com/"', false)
            ->assertSee('和文心一言继续聊')
            ->assertDontSee('Unsafe source')
            ->assertDontSee('provider-secret-response-id');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_public_snapshot_continue_button_prefers_the_official_conversation_url(): void
    {
        $officialUrl = 'https://chat.baidu.com/csaitab/history/share?share_id=snapshot-test';
        $result = $this->createResult([
            'official_share_url' => $officialUrl,
        ]);

        $this->get('/brand-diagnosis/snapshot/'.$result->snapshot_token)
            ->assertOk()
            ->assertSee('href="'.$officialUrl.'"', false)
            ->assertSee('和文心一言继续聊');
    }

    public function test_public_and_admin_snapshots_hide_internal_brand_mentions_payload_for_all_supported_models(): void
    {
        foreach (['doubao', 'deepseek', 'qianwen', 'wenxin'] as $platform) {
            $result = $this->createResult([
                'platform' => $platform,
                'answer' => '{"answer":"学术易在该问题中被模型正常推荐。","brand_mentions":[{"brand":"学术易","mention_count":1,"mention_rank":1,"sentiment":"positive","evidence":"内部提及依据"}]}',
            ]);

            $this->get('/brand-diagnosis/snapshot/'.$result->snapshot_token)
                ->assertOk()
                ->assertSee('学术易在该问题中被模型正常推荐。')
                ->assertDontSee('"brand_mentions"', false)
                ->assertDontSee('内部提及依据');

            $this->get(route('admin.snapshot-voucher.show', ['id' => (int) $result->id]))
                ->assertOk()
                ->assertSee('学术易在该问题中被模型正常推荐。')
                ->assertDontSee('"brand_mentions"', false)
                ->assertDontSee('内部提及依据');
        }
    }

    public function test_snapshot_strips_brand_mentions_json_suffix_after_plain_answer_text(): void
    {
        $result = $this->createResult([
            'platform' => 'deepseek',
            'answer' => '根据榜单，三角洲双人协作陪玩可以优先考虑青锋护航组，综合评分9.7/10，适合需要路线教学和协作配合的玩家。", "brand_mentions": [{"brand":"青锋护航组","mention_count":1,"mention_rank":1,"sentiment":"positive","evidence":"内部提及依据"}]}',
        ]);

        $this->get(route('admin.snapshot-voucher.show', ['id' => (int) $result->id]))
            ->assertOk()
            ->assertSee('根据榜单，三角洲双人协作陪玩可以优先考虑青锋护航组')
            ->assertSee('综合评分9.7/10')
            ->assertDontSee('"brand_mentions"', false)
            ->assertDontSee('内部提及依据');
    }

    public function test_snapshot_strips_malformed_brand_mentions_json_suffix_after_plain_answer_text(): void
    {
        $result = $this->createResult([
            'platform' => 'deepseek',
            'answer' => '根据榜单，三角洲双人协作陪玩可以优先考虑青锋护航组，综合评分9.7/10。", "brand_mentions": [{"brand":"青锋护航组","mention_count":1,"mention_rank":1,"sentiment":"positive","evidence":"内部提及依据"',
        ]);

        $this->get(route('admin.snapshot-voucher.show', ['id' => (int) $result->id]))
            ->assertOk()
            ->assertSee('根据榜单，三角洲双人协作陪玩可以优先考虑青锋护航组')
            ->assertSee('综合评分9.7/10')
            ->assertDontSee('"brand_mentions"', false)
            ->assertDontSee('内部提及依据');
    }

    public function test_snapshot_strips_generic_internal_json_suffix_fields_after_plain_answer_text(): void
    {
        $result = $this->createResult([
            'platform' => 'qianwen',
            'answer' => '快照应该只展示这段自然语言回答，保留用户能读懂的结论。", "sources": [{"title":"内部来源","url":"https://internal.example"}], "meta": {"debug":"不要展示"}, "sentiment": "positive"',
        ]);

        $this->get(route('admin.snapshot-voucher.show', ['id' => (int) $result->id]))
            ->assertOk()
            ->assertSee('快照应该只展示这段自然语言回答')
            ->assertSee('保留用户能读懂的结论')
            ->assertDontSee('"sources"', false)
            ->assertDontSee('内部来源')
            ->assertDontSee('不要展示')
            ->assertDontSee('debug')
            ->assertDontSee('"sentiment"', false);
    }

    public function test_public_snapshot_freezes_answer_and_sources_after_first_capture(): void
    {
        $result = $this->createResult();
        $result->sources()->create([
            'owner_admin_id' => (int) $result->owner_admin_id,
            'run_id' => (int) $result->run_id,
            'question_id' => (int) $result->question_id,
            'platform' => 'wenxin',
            'title' => 'Original source',
            'url' => 'https://example.com/original',
            'domain' => 'example.com',
            'source_type' => 'web_search_result',
        ]);

        $url = '/brand-diagnosis/snapshot/'.$result->snapshot_token;
        $this->get($url)
            ->assertOk()
            ->assertSee('学术易提供科研选题、文献梳理和论文写作指导服务。')
            ->assertSee('Original source');

        $this->assertNotNull($result->fresh()->snapshot_payload);

        BrandDiagnosisRun::query()->withoutGlobalScopes()->whereKey($result->run_id)->update(['brand_name' => 'Changed brand']);
        BrandDiagnosisQuestion::query()->withoutGlobalScopes()->whereKey($result->question_id)->update(['question' => 'Changed question']);
        BrandDiagnosisResult::query()->withoutGlobalScopes()->whereKey($result->id)->update(['answer' => 'Changed answer']);
        $result->sources()->delete();
        $result->sources()->create([
            'owner_admin_id' => (int) $result->owner_admin_id,
            'run_id' => (int) $result->run_id,
            'question_id' => (int) $result->question_id,
            'platform' => 'wenxin',
            'title' => 'Changed source',
            'url' => 'https://example.com/changed',
            'domain' => 'example.com',
            'source_type' => 'web_search_result',
        ]);

        $this->get($url)
            ->assertOk()
            ->assertSee('学术易')
            ->assertSee('国内科研选题辅导平台哪些好？')
            ->assertSee('学术易提供科研选题、文献梳理和论文写作指导服务。')
            ->assertSee('Original source')
            ->assertDontSee('Changed brand')
            ->assertDontSee('Changed question')
            ->assertDontSee('Changed answer')
            ->assertDontSee('Changed source');
    }

    public function test_authenticated_user_from_another_site_can_open_a_public_snapshot(): void
    {
        $result = $this->createResult();
        $result->sources()->create([
            'owner_admin_id' => (int) $result->owner_admin_id,
            'run_id' => (int) $result->run_id,
            'question_id' => (int) $result->question_id,
            'platform' => 'wenxin',
            'title' => 'Cross-site source',
            'url' => 'https://example.com/cross-site',
            'domain' => 'example.com',
            'source_type' => 'web_search_result',
        ]);
        $viewer = Admin::query()->create([
            'username' => 'snapshot_other_site',
            'password' => 'secret-123',
            'email' => 'snapshot-other-site@example.com',
            'display_name' => 'Snapshot Other Site',
            'role' => 'site_user',
            'status' => 'active',
        ]);

        $this->actingAs($viewer, 'admin')
            ->get('/brand-diagnosis/snapshot/'.$result->snapshot_token)
            ->assertOk()
            ->assertSee('学术易')
            ->assertSee('国内科研选题辅导平台哪些好？')
            ->assertSee('Cross-site source');
    }

    public function test_failed_result_without_an_answer_does_not_expose_or_capture_a_snapshot(): void
    {
        $result = $this->createResult([
            'answer' => null,
            'status' => 'failed',
            'error_message' => 'Provider request failed.',
        ]);
        $owner = Admin::query()->findOrFail((int) $result->owner_admin_id);

        $this->actingAs($owner, 'admin')
            ->withSession(['current_site_id' => (int) $result->site_id])
            ->get('/geo_admin/brand-diagnosis/'.$result->run_id.'/report')
            ->assertOk()
            ->assertDontSee('/brand-diagnosis/snapshot/'.$result->snapshot_token, false);

        $this->get('/brand-diagnosis/snapshot/'.$result->snapshot_token)
            ->assertNotFound()
            ->assertSee('快照不存在');
        $this->assertNull($result->fresh()->snapshot_payload);
    }

    public function test_super_admin_can_open_the_official_link_batch_editor(): void
    {
        $result = $this->createResult();
        $superAdmin = Admin::query()->create([
            'username' => 'snapshot_super_admin',
            'password' => 'secret-123',
            'email' => 'snapshot-super@example.com',
            'display_name' => 'Snapshot Super Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->get('/geo_admin/brand-diagnosis/'.$result->run_id.'/official-links')
            ->assertOk()
            ->assertSee('官方链接管理')
            ->assertSee('国内科研选题辅导平台哪些好？')
            ->assertSee('name="official_links['.$result->id.']"', false)
            ->assertSee('/brand-diagnosis/snapshot/'.$result->snapshot_token, false);
    }

    public function test_official_link_batch_editor_can_filter_by_model_and_question(): void
    {
        $firstResult = $this->createResult();
        $secondQuestion = BrandDiagnosisQuestion::query()->create([
            'site_id' => (int) $firstResult->site_id,
            'owner_admin_id' => (int) $firstResult->owner_admin_id,
            'run_id' => (int) $firstResult->run_id,
            'question' => 'How does Doubao answer the second question?',
            'question_type' => 'brand',
            'sort_order' => 2,
            'status' => 'completed',
        ]);
        BrandDiagnosisResult::query()->create([
            'site_id' => (int) $firstResult->site_id,
            'owner_admin_id' => (int) $firstResult->owner_admin_id,
            'run_id' => (int) $firstResult->run_id,
            'question_id' => (int) $secondQuestion->id,
            'platform' => 'doubao',
            'answer' => 'Doubao answer for the second question.',
            'brand_mentioned' => false,
            'mention_count' => 0,
            'mention_rank' => 0,
            'sentiment' => 'neutral',
            'status' => 'success',
            'checked_at' => now(),
        ]);
        $superAdmin = Admin::query()->create([
            'username' => 'snapshot_filter_super',
            'password' => 'secret-123',
            'email' => 'snapshot-filter-super@example.com',
            'display_name' => 'Snapshot Filter Super',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $html = $this->actingAs($superAdmin, 'admin')
            ->get('/geo_admin/brand-diagnosis/'.$firstResult->run_id.'/official-links')
            ->assertOk()
            ->assertSee('id="official-link-platform-filter"', false)
            ->assertSee('id="official-link-question-filter"', false)
            ->assertSee('data-official-link-row', false)
            ->assertSee('data-platform-key="wenxin"', false)
            ->assertSee('data-platform-key="doubao"', false)
            ->assertSee('data-question-text="How does Doubao answer the second question?"', false)
            ->getContent();

        $this->assertStringContainsString('applyOfficialLinkFilters()', $html);
        $this->assertStringContainsString('official-link-empty-filter-state', $html);
    }

    public function test_non_super_admin_cannot_update_official_links(): void
    {
        $result = $this->createResult();
        $owner = Admin::query()->findOrFail((int) $result->owner_admin_id);

        $this->actingAs($owner, 'admin')
            ->put('/geo_admin/brand-diagnosis/'.$result->run_id.'/official-links', [
                'official_links' => [
                    $result->id => 'https://chat.baidu.com/example-share',
                ],
            ])
            ->assertForbidden();

        $this->assertNull($result->fresh()->official_share_url);
    }

    public function test_super_admin_can_save_multiple_official_links_in_one_request(): void
    {
        $firstResult = $this->createResult([
            'official_share_url' => 'https://chat.baidu.com/old-share',
        ]);
        $secondResult = BrandDiagnosisResult::query()->create([
            'owner_admin_id' => (int) $firstResult->owner_admin_id,
            'run_id' => (int) $firstResult->run_id,
            'question_id' => (int) $firstResult->question_id,
            'platform' => 'qianwen',
            'answer' => '千问模型回答。',
            'brand_mentioned' => false,
            'mention_count' => 0,
            'mention_rank' => 0,
            'sentiment' => 'neutral',
            'status' => 'success',
            'checked_at' => now(),
        ]);
        $superAdmin = Admin::query()->create([
            'username' => 'snapshot_batch_super',
            'password' => 'secret-123',
            'email' => 'snapshot-batch-super@example.com',
            'display_name' => 'Snapshot Batch Super',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->put('/geo_admin/brand-diagnosis/'.$firstResult->run_id.'/official-links', [
                'official_links' => [
                    $firstResult->id => '',
                    $secondResult->id => 'https://www.tongyi.com/qianwen/share/example',
                ],
            ])
            ->assertRedirect('/geo_admin/brand-diagnosis/'.$firstResult->run_id.'/official-links')
            ->assertSessionHas('message');

        $this->assertNull($firstResult->fresh()->official_share_url);
        $this->assertSame('https://www.tongyi.com/qianwen/share/example', $secondResult->fresh()->official_share_url);
        $this->assertSame((int) $superAdmin->id, (int) $secondResult->fresh()->official_share_updated_by);
        $this->assertNotNull($secondResult->fresh()->official_share_updated_at);
    }

    public function test_official_link_must_match_the_results_platform_domain(): void
    {
        $result = $this->createResult();
        $superAdmin = Admin::query()->create([
            'username' => 'snapshot_domain_super',
            'password' => 'secret-123',
            'email' => 'snapshot-domain-super@example.com',
            'display_name' => 'Snapshot Domain Super',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->from('/geo_admin/brand-diagnosis/'.$result->run_id.'/official-links')
            ->put('/geo_admin/brand-diagnosis/'.$result->run_id.'/official-links', [
                'official_links' => [
                    $result->id => 'https://chat.baidu.com@evil.example/share',
                ],
            ])
            ->assertRedirect('/geo_admin/brand-diagnosis/'.$result->run_id.'/official-links')
            ->assertSessionHasErrors('official_links.'.$result->id);

        $this->assertNull($result->fresh()->official_share_url);
    }

    public function test_unchanged_official_link_keeps_its_existing_audit_metadata(): void
    {
        $result = $this->createResult();
        $originalUpdater = Admin::query()->create([
            'username' => 'snapshot_original_updater',
            'password' => 'secret-123',
            'email' => 'snapshot-original-updater@example.com',
            'display_name' => 'Snapshot Original Updater',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $originalUpdatedAt = now()->subDay()->startOfSecond();
        $result->forceFill([
            'official_share_url' => 'https://chat.baidu.com/existing-share',
            'official_share_updated_by' => (int) $originalUpdater->id,
            'official_share_updated_at' => $originalUpdatedAt,
        ])->save();
        $newSuperAdmin = Admin::query()->create([
            'username' => 'snapshot_new_updater',
            'password' => 'secret-123',
            'email' => 'snapshot-new-updater@example.com',
            'display_name' => 'Snapshot New Updater',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->actingAs($newSuperAdmin, 'admin')
            ->put('/geo_admin/brand-diagnosis/'.$result->run_id.'/official-links', [
                'official_links' => [
                    $result->id => 'https://chat.baidu.com/existing-share',
                ],
            ])
            ->assertRedirect('/geo_admin/brand-diagnosis/'.$result->run_id.'/official-links');

        $result->refresh();
        $this->assertSame((int) $originalUpdater->id, (int) $result->official_share_updated_by);
        $this->assertTrue($result->official_share_updated_at->equalTo($originalUpdatedAt));
    }

    public function test_brand_diagnosis_report_exposes_snapshot_and_official_links_with_super_admin_management_entry(): void
    {
        $result = $this->createResult([
            'official_share_url' => 'https://chat.baidu.com/csaitab/history/share?share_id=example',
        ]);
        $superAdmin = Admin::query()->create([
            'username' => 'snapshot_report_super',
            'password' => 'secret-123',
            'email' => 'snapshot-report-super@example.com',
            'display_name' => 'Snapshot Report Super',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->get('/geo_admin/brand-diagnosis/'.$result->run_id.'/report')
            ->assertOk()
            ->assertSee('/brand-diagnosis/snapshot/'.$result->snapshot_token, false)
            ->assertSee('https://chat.baidu.com/csaitab/history/share?share_id=example', false)
            ->assertSee('/geo_admin/brand-diagnosis/'.$result->run_id.'/official-links', false);
    }

    public function test_site_user_can_view_snapshot_and_official_links_but_not_the_management_entry(): void
    {
        $result = $this->createResult([
            'official_share_url' => 'https://chat.baidu.com/csaitab/history/share?share_id=user-visible',
        ]);
        $owner = Admin::query()->findOrFail((int) $result->owner_admin_id);

        $this->actingAs($owner, 'admin')
            ->withSession(['current_site_id' => (int) $result->site_id])
            ->get('/geo_admin/brand-diagnosis/'.$result->run_id.'/report')
            ->assertOk()
            ->assertSee('/brand-diagnosis/snapshot/'.$result->snapshot_token, false)
            ->assertSee('https://chat.baidu.com/csaitab/history/share?share_id=user-visible', false)
            ->assertDontSee('/geo_admin/brand-diagnosis/'.$result->run_id.'/official-links', false);
    }

    public function test_agent_admin_cannot_open_the_official_link_editor(): void
    {
        $result = $this->createResult();
        $agent = Admin::query()->create([
            'username' => 'snapshot_agent_admin',
            'password' => 'secret-123',
            'email' => 'snapshot-agent@example.com',
            'display_name' => 'Snapshot Agent',
            'role' => 'agent_admin',
            'status' => 'active',
        ]);

        $this->actingAs($agent, 'admin')
            ->get('/geo_admin/brand-diagnosis/'.$result->run_id.'/official-links')
            ->assertForbidden();
    }

    public function test_official_link_batch_update_rejects_results_from_another_diagnosis(): void
    {
        $firstResult = $this->createResult();
        $otherResult = $this->createResult();
        $superAdmin = Admin::query()->create([
            'username' => 'snapshot_scope_super',
            'password' => 'secret-123',
            'email' => 'snapshot-scope-super@example.com',
            'display_name' => 'Snapshot Scope Super',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->put('/geo_admin/brand-diagnosis/'.$firstResult->run_id.'/official-links', [
                'official_links' => [
                    $firstResult->id => 'https://chat.baidu.com/valid-share',
                    $otherResult->id => 'https://chat.baidu.com/cross-run-share',
                ],
            ])
            ->assertStatus(422);

        $this->assertNull($firstResult->fresh()->official_share_url);
        $this->assertNull($otherResult->fresh()->official_share_url);
    }

    private function createResult(array $attributes = []): BrandDiagnosisResult
    {
        $admin = Admin::query()->create([
            'username' => 'snapshot_admin_'.uniqid(),
            'password' => 'secret-123',
            'email' => uniqid().'@example.com',
            'display_name' => 'Snapshot Admin',
            'role' => 'site_user',
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => 'Snapshot Site '.uniqid(),
            'status' => 'active',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'owner']);
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'owner_admin_id' => (int) $admin->id,
            'brand_name' => '学术易',
            'platforms' => ['wenxin'],
            'status' => 'completed',
            'total_questions' => 1,
            'completed_questions' => 1,
            'failed_questions' => 0,
        ]);
        $question = BrandDiagnosisQuestion::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'run_id' => (int) $run->id,
            'question' => '国内科研选题辅导平台哪些好？',
            'question_type' => '选择',
            'sort_order' => 1,
            'status' => 'completed',
        ]);

        return BrandDiagnosisResult::query()->create(array_merge([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'run_id' => (int) $run->id,
            'question_id' => (int) $question->id,
            'platform' => 'wenxin',
            'answer' => '学术易提供科研选题、文献梳理和论文写作指导服务。',
            'brand_mentioned' => true,
            'mention_count' => 1,
            'mention_rank' => 1,
            'sentiment' => 'positive',
            'status' => 'success',
            'checked_at' => now(),
        ], $attributes));
    }
}
