<?php

namespace Tests\Unit;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\Site;
use App\Services\VideoGeneration\VideoContentDraftService;
use App\Support\CurrentSite;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VideoContentDraftServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_six_douyin_geo_topic_candidates_from_keyword_library(): void
    {
        [$admin, $site] = $this->siteContext('video_topic_owner');
        $library = $this->keywordLibrary($site, $admin);
        Keyword::query()->create([
            'library_id' => (int) $library->id,
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'keyword' => '企业团险服务商',
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => '企业保险知识库',
            'content' => '心有灵犀保险代理有限公司聚焦企业团险、雇主责任险、核保协同、理赔协同和用工风险场景。',
            'character_count' => 45,
        ]);
        $this->gpt55Model();

        Http::fake([
            'https://ai.example.test/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                ['style' => 'question', 'style_label' => '问题型', 'subject' => '企业团险服务商怎么选才靠谱'],
                ['style' => 'avoid_pitfall', 'style_label' => '避坑型', 'subject' => '企业团险别只盯着低价'],
                ['style' => 'how_to_choose', 'style_label' => '怎么选型', 'subject' => '选企业团险重点看哪三点'],
                ['style' => 'comparison', 'style_label' => '对比型', 'subject' => '团体意外险和雇主责任险怎么搭配'],
                ['style' => 'scenario', 'style_label' => '场景型', 'subject' => '高空作业人员保险怎么配'],
                ['style' => 'trend', 'style_label' => '趋势型', 'subject' => '企业用工风险为什么要提前规划'],
                            ], JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ],
            ]),
        ]);
        Http::preventStrayRequests();

        $result = app(VideoContentDraftService::class)->topicCandidates(
            $admin,
            $site,
            (int) $library->id,
            (int) $knowledgeBase->id
        );

        $this->assertSame('企业团险服务商', $result['keyword']);
        $this->assertCount(6, $result['candidates']);
        $this->assertSame(
            ['question', 'avoid_pitfall', 'how_to_choose', 'comparison', 'scenario', 'trend'],
            array_column($result['candidates'], 'style')
        );
        $this->assertSame('问题型', $result['candidates'][0]['style_label']);
        $this->assertSame('企业团险服务商怎么选才靠谱', $result['candidates'][0]['subject']);
    }

    public function test_generates_script_draft_for_selected_topic(): void
    {
        [$admin, $site] = $this->siteContext('video_script_owner');
        $library = $this->keywordLibrary($site, $admin);
        $this->gpt55Model();

        Http::fake([
            'https://ai.example.test/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                'subject' => '企业团险服务商怎么选才靠谱',
                'style' => 'question',
                'script' => "企业团险服务商怎么选？\n很多企业容易只看报价，却忽略核保、风控和理赔协同。\n第一，看方案是否贴合员工岗位风险；第二，看产品资源是否覆盖意外、医疗和雇主责任；第三，看后续服务是否能协助理赔。\n心有灵犀保险代理有限公司聚焦企业团险和企业用工风险场景。\n选企业团险，别只比价格，更要看能不能解决真实风险。",
                'cover_text' => '企业团险服务商怎么选',
                'publish_copy' => '企业团险服务商怎么选？除了价格，更要看方案、产品资源和理赔协同能力。',
                            ], JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ],
            ]),
        ]);
        Http::preventStrayRequests();

        $result = app(VideoContentDraftService::class)->scriptDraft($admin, $site, [
            'keyword_library_id' => (int) $library->id,
            'keyword' => '企业团险服务商',
            'style' => 'question',
            'subject' => '企业团险服务商怎么选才靠谱',
        ]);

        $this->assertSame('企业团险服务商怎么选才靠谱', $result['subject']);
        $this->assertSame('question', $result['style']);
        $this->assertStringContainsString('企业团险服务商怎么选', $result['script']);
        $this->assertStringContainsString('心有灵犀保险代理有限公司', $result['script']);
        $this->assertSame('企业团险服务商怎么选', $result['cover_text']);
        $this->assertStringContainsString('企业团险服务商怎么选', $result['publish_copy']);
    }

    public function test_requires_active_gpt55_chat_model(): void
    {
        [$admin, $site] = $this->siteContext('video_missing_model_owner');
        $library = $this->keywordLibrary($site, $admin);
        Keyword::query()->create([
            'library_id' => (int) $library->id,
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'keyword' => '企业团险服务商',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GPT-5.5');

        app(VideoContentDraftService::class)->topicCandidates($admin, $site, (int) $library->id, null);
    }

    /**
     * @return array{0:Admin,1:Site}
     */
    private function siteContext(string $username): array
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.test',
            'display_name' => $username,
            'role' => 'direct_admin',
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => $username.' Site',
            'domain' => $username.'.test',
            'status' => 'active',
            'customer_mode' => 'direct',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'owner']);
        app(CurrentSite::class)->set($site);
        Auth::guard('admin')->login($admin);

        return [$admin, $site];
    }

    private function keywordLibrary(Site $site, Admin $admin): KeywordLibrary
    {
        return KeywordLibrary::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => '企业保险关键词库',
            'description' => '企业保险内容方向',
            'company_name' => '心有灵犀保险代理有限公司',
            'domain_keyword' => '企业保险',
            'industry' => '保险服务',
            'brand_description' => '心有灵犀保险代理有限公司聚焦企业团险、团体意外险、雇主责任险和企业用工风险。',
            'status' => 'active',
            'keyword_count' => 1,
        ]);
    }

    private function gpt55Model(): AiModel
    {
        $crypto = app(ApiKeyCrypto::class);
        $model = AiModel::query()->withoutGlobalScope('current_site')->create([
            'name' => 'GPT-5.5',
            'site_id' => null,
            'owner_admin_id' => null,
            'version' => 'test',
            'api_key' => $crypto->encrypt('test-key'),
            'model_id' => 'gpt-5.5',
            'model_type' => 'chat',
            'api_url' => 'https://ai.example.test/v1',
            'failover_priority' => 1,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
        $model->syncOriginal();

        return $model;
    }
}
