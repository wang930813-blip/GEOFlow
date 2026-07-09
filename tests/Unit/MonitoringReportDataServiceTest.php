<?php

namespace Tests\Unit;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\BrandDiagnosisBrandMention;
use App\Models\BrandDiagnosisQuestion;
use App\Models\BrandDiagnosisResult;
use App\Models\BrandDiagnosisRun;
use App\Models\BrandDiagnosisSource;
use App\Models\Category;
use App\Models\GeoInclusionCheckResult;
use App\Models\GeoInclusionCheckRun;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KeywordQuestionVariant;
use App\Models\KnowledgeBase;
use App\Models\Site;
use App\Services\MonitoringCenter\MonitoringReportDataService;
use App\Support\CurrentSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitoringReportDataServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_enterprise_report_uses_current_site_data_and_resolves_company_from_latest_knowledge_base(): void
    {
        [$admin, $site] = $this->createAdminWithSite('monitoring_enterprise_user', 'site_user', '默认站点名称');
        [, $otherSite] = $this->createAdminWithSite('monitoring_enterprise_other', 'site_user', '其他站点');

        app(CurrentSite::class)->set($site);

        KnowledgeBase::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => '旧知识库',
            'content' => '企业名称：旧公司',
            'created_at' => now()->subDays(2),
        ]);
        KnowledgeBase::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => '最新知识库',
            'content' => '公司名称：星河智能科技有限公司'."\n".'主营：AI 搜索优化与内容增长',
            'created_at' => now()->subDay(),
        ]);

        $current = $this->seedSearchData($admin, $site, [
            'company' => '星河智能科技有限公司',
            'question' => '星河智能科技有限公司适合做 AI 搜索优化吗？',
            'keyword' => 'AI 搜索优化',
            'competitor' => '蓝海智能科技有限公司',
            'platform' => 'doubao',
            'sourceTitle' => '星河智能案例报道',
            'articleTitle' => '星河智能 AI 搜索优化实践',
        ]);
        $this->seedSearchData($otherSite->owner, $otherSite, [
            'company' => '其他公司',
            'question' => '其他公司问题不应出现',
            'keyword' => '其他关键词',
            'competitor' => '其他竞品',
            'platform' => 'deepseek',
            'sourceTitle' => '其他来源',
            'articleTitle' => '其他文章',
        ]);

        $report = app(MonitoringReportDataService::class)->enterpriseReport($admin, $site);

        $this->assertSame('星河智能科技有限公司', $report['context']['company_name']);
        $this->assertSame(now()->format('Y-m-d'), $report['context']['date']);
        $this->assertSame(1, $report['summary']['distillation_word_count']['actual']);
        $this->assertSame(1, $report['summary']['search_report_count']['actual']);
        $this->assertSame(1, $report['summary']['model_collection_total']['actual']);
        $this->assertSame([
            ['name' => '豆包', 'value' => 1],
            ['name' => '千问', 'value' => 0],
            ['name' => 'DeepSeek', 'value' => 0],
            ['name' => '元宝', 'value' => 0],
            ['name' => '文心一言', 'value' => 0],
        ], $report['model_collection']);
        $this->assertSame([
            ['label' => '今日新增', 'value' => 1],
            ['label' => '较昨日', 'value' => 1],
        ], $report['metrics'][0]['sub_items']);
        $this->assertSame([
            ['label' => '较30日', 'value' => 1],
            ['label' => '较30日', 'value' => 1],
        ], $report['metrics'][1]['sub_items']);
        $this->assertSame([
            ['label' => '总平台数', 'value' => 5],
        ], $report['metrics'][2]['sub_items']);
        $this->assertSame(['站内跳转曝光', '联系方式曝光'], $report['metrics'][3]['value_labels']);
        $this->assertSame(1, $report['metrics'][3]['value']);
        $this->assertSame(0, $report['metrics'][3]['secondary_value']);

        $this->assertSame($current['question']->question, $report['distillation_words'][0]['word']);
        $this->assertSame($current['question']->question, $report['search_rows'][0]['question']);
        $this->assertSame('豆包', $report['search_rows'][0]['platform']);
        $this->assertSame('星河智能科技有限公司', $report['search_rows'][0]['target']);
        $this->assertSame(now()->toDateString(), $report['search_rows'][0]['date']);
        $this->assertSame('星河智能科技有限公司在回答中被提及，并引用了行业资料。', $report['search_rows'][0]['answer']);
        $this->assertSame('https://www.doubao.com/chat/', $report['search_rows'][0]['platform_url']);
        $this->assertStringEndsWith('/article/'.rawurlencode((string) $current['article']->slug), $report['search_rows'][0]['official_url']);
        $this->assertSame('星河智能案例报道', $report['search_rows'][0]['sources'][0]['title']);
        $this->assertSame('星河智能 AI 搜索优化实践', $report['search_rows'][0]['related_articles'][0]['title']);
        $this->assertStringEndsWith('/article/'.rawurlencode((string) $current['article']->slug), $report['search_rows'][0]['related_articles'][0]['url']);

        $flatJson = json_encode($report, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('其他公司问题不应出现', $flatJson);
        $this->assertStringNotContainsString('其他竞品', $flatJson);
        $this->assertStringNotContainsString('其他文章', $flatJson);
    }

    public function test_industry_report_builds_competition_platform_and_sentiment_data_for_current_site_only(): void
    {
        [$admin, $site] = $this->createAdminWithSite('monitoring_industry_user', 'site_user', '星河站点');
        [, $otherSite] = $this->createAdminWithSite('monitoring_industry_other', 'site_user', '隔壁站点');

        app(CurrentSite::class)->set($site);

        KnowledgeBase::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => '企业知识库',
            'content' => '企业名称：星河智能科技有限公司',
            'created_at' => now(),
        ]);

        $this->seedSearchData($admin, $site, [
            'company' => '星河智能科技有限公司',
            'question' => 'AI 搜索优化服务商怎么选？',
            'keyword' => 'AI 搜索优化',
            'competitor' => '蓝海智能科技有限公司',
            'platform' => 'doubao',
            'sourceTitle' => '星河行业分析',
            'articleTitle' => '星河智能品牌曝光分析',
        ]);
        $this->seedSearchData($otherSite->owner, $otherSite, [
            'company' => '其他公司',
            'question' => '其他行业问题',
            'keyword' => '其他关键词',
            'competitor' => '不应出现的竞品',
            'platform' => 'qianwen',
            'sourceTitle' => '其他行业来源',
            'articleTitle' => '其他行业文章',
        ]);

        $report = app(MonitoringReportDataService::class)->industryReport($admin, $site);

        $this->assertSame('星河智能科技有限公司', $report['context']['company_name']);
        $this->assertSame(1, $report['summary'][0]['actual']);
        $this->assertSame(1, $report['summary'][1]['actual']);
        $this->assertSame(1, $report['summary'][2]['actual']);
        $this->assertSame(1, $report['summary'][3]['actual']);

        $this->assertSame('星河智能科技有限公司', $report['brand_profile']['company_name']);
        $this->assertSame('蓝海智能科技有限公司', $report['competitors'][0]['brand_name']);
        $this->assertSame('豆包', $report['platforms'][0]['platform']);
        $this->assertSame(100.0, $report['platforms'][0]['top_rank_rates']['top2']);
        $this->assertSame(['doubao', 'deepseek', 'yuanbao', 'wenxin', 'qianwen'], array_column($report['platforms'], 'platform_key'));
        $this->assertSame(0, $report['platforms'][1]['analysis_count']);
        $this->assertSame(0.0, $report['platforms'][1]['top_rank_rates']['top1']);
        $this->assertSame(100.0, $report['sentiment']['overall']['positive_rate']);
        $this->assertSame(['doubao', 'deepseek', 'yuanbao', 'wenxin', 'qianwen'], array_column($report['sentiment']['platforms'], 'platform_key'));
        $this->assertSame(0.0, $report['sentiment']['platforms'][1]['positive_rate']);
        $this->assertArrayHasKey('platform_rates', $report['competitors'][0]);
        $this->assertSame(100.0, $report['competitors'][0]['platform_rates']['doubao']);
        $this->assertSame(0.0, $report['competitors'][0]['platform_rates']['deepseek']);
        $this->assertSame('豆包', $report['sentiment']['platforms'][0]['platform']);

        $flatJson = json_encode($report, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('不应出现的竞品', $flatJson);
        $this->assertStringNotContainsString('其他行业问题', $flatJson);
    }

    public function test_industry_report_cleans_invalid_encoding_from_brand_profile_text(): void
    {
        [$admin, $site] = $this->createAdminWithSite('monitoring_industry_encoding_user', 'site_user', '编码站点');

        app(CurrentSite::class)->set($site);

        KeywordLibrary::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => '编码关键词库',
            'company_name' => '重庆异荣竞业电竞科技有限公司',
            'domain_keyword' => "线上陪玩服务�",
            'industry' => "游戏陪练\xB1服务 - 综合电竞服务品? - 专业陪玩",
            'brand_description' => "电竞服务�说明",
            'status' => 'active',
            'keyword_count' => 1,
        ]);

        $report = app(MonitoringReportDataService::class)->industryReport($admin, $site);
        $profileText = implode(' ', array_merge(
            $report['brand_profile']['brand_names'],
            $report['brand_profile']['core_services'],
            [$report['brand_profile']['description']]
        ));

        $this->assertTrue(mb_check_encoding($profileText, 'UTF-8'));
        $this->assertStringNotContainsString('�', $profileText);
        $this->assertStringNotContainsString('?', $profileText);
    }

    public function test_industry_report_removes_replacement_characters_from_entire_payload(): void
    {
        [$admin, $site] = $this->createAdminWithSite('monitoring_industry_payload_encoding_user', 'site_user', '编码站点');

        app(CurrentSite::class)->set($site);

        $this->seedSearchData($admin, $site, [
            'company' => '重庆异荣竞业电竞科技有限公司',
            'question' => '电竞服务怎么选？',
            'keyword' => "电竞服务�",
            'competitor' => "综合电竞服务品�",
            'platform' => 'doubao',
            'sourceTitle' => "引用资料�标题",
            'articleTitle' => "行业文章�标题",
        ]);

        $report = app(MonitoringReportDataService::class)->industryReport($admin, $site);
        $payload = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        $this->assertIsString($payload);
        $this->assertStringNotContainsString('�', $payload);
    }

    public function test_enterprise_report_keeps_static_widget_fallbacks_when_only_company_context_exists(): void
    {
        [$admin, $site] = $this->createAdminWithSite('monitoring_empty_enterprise_user', 'site_user', '空数据站点');

        app(CurrentSite::class)->set($site);

        KnowledgeBase::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => '企业知识库',
            'content' => '公司名称：星河智能科技有限公司',
            'created_at' => now(),
        ]);

        $report = app(MonitoringReportDataService::class)->enterpriseReport($admin, $site);

        $this->assertSame('星河智能科技有限公司', $report['context']['company_name']);
        $this->assertSame([], $report['model_collection']);
        $this->assertSame([], $report['metrics']);
        $this->assertCount(11, $report['platform_filters']);
        $this->assertSame(0, $report['platform_filters'][0]['total']);
        $this->assertSame([], $report['search_rows']);
        $this->assertSame([], $report['distillation_words']);
        $this->assertCount(30, $report['trend']['last_30']);
        $this->assertCount(7, $report['trend']['last_7']);
        $this->assertSame(now()->subDays(29)->toDateString(), $report['trend']['last_30'][0]['date']);
        $this->assertSame(now()->toDateString(), $report['trend']['last_30'][29]['date']);
        $this->assertSame(now()->subDays(6)->toDateString(), $report['trend']['last_7'][0]['date']);
        $this->assertSame(now()->toDateString(), $report['trend']['last_7'][6]['date']);
        $this->assertSame(0, $report['trend']['last_30'][29]['created']);
        $this->assertSame(0, $report['trend']['last_30'][29]['published']);
    }

    public function test_enterprise_search_report_platform_filters_include_default_platform_terminals_with_zero_counts(): void
    {
        [$admin, $site] = $this->createAdminWithSite('monitoring_search_platform_filters', 'site_user', 'search report site');

        app(CurrentSite::class)->set($site);

        $diagnosisRun = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => 'Search Report Brand',
            'platforms' => ['doubao', 'deepseek', 'tencent_yuanbao'],
            'status' => 'completed',
            'total_questions' => 1,
            'completed_questions' => 1,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
            'completed_at' => now(),
        ]);

        $diagnosisQuestion = BrandDiagnosisQuestion::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'run_id' => (int) $diagnosisRun->id,
            'question' => 'How should a GEO service provider be selected?',
            'question_type' => 'choice',
            'sort_order' => 1,
            'status' => 'completed',
        ]);

        foreach (['doubao', 'deepseek', 'tencent_yuanbao'] as $platform) {
            BrandDiagnosisResult::query()->create([
                'site_id' => (int) $site->id,
                'owner_admin_id' => (int) $admin->id,
                'run_id' => (int) $diagnosisRun->id,
                'question_id' => (int) $diagnosisQuestion->id,
                'platform' => $platform,
                'answer' => 'Search result mentions Search Report Brand.',
                'brand_mentioned' => true,
                'mention_count' => 1,
                'mention_rank' => 1,
                'sentiment' => 'positive',
                'status' => 'success',
                'checked_at' => now(),
            ]);
        }

        $report = app(MonitoringReportDataService::class)->enterpriseReport($admin, $site);

        $this->assertCount(11, $report['platform_filters']);
        $this->assertSame([
            'key' => 'all',
            'platform_key' => 'all',
            'name' => '全部',
            'terminal' => '全部',
            'total' => 3,
        ], $report['platform_filters'][0]);

        $totals = collect($report['platform_filters'])->mapWithKeys(
            fn (array $filter): array => [$filter['platform_key'].'|'.$filter['terminal'] => $filter['total']]
        );

        $this->assertSame(1, $totals['doubao|PC']);
        $this->assertSame(1, $totals['deepseek|PC']);
        $this->assertSame(1, $totals['yuanbao|PC']);
        $this->assertSame(0, $totals['doubao|移动']);
        $this->assertSame(0, $totals['qianwen|PC']);
        $this->assertSame(0, $totals['qianwen|移动']);
        $this->assertSame(0, $totals['yuanbao|移动']);
        $this->assertSame(0, $totals['wenxin|PC']);
        $this->assertSame(0, $totals['wenxin|移动']);
    }

    public function test_enterprise_search_report_uses_result_created_time_and_dash_when_target_brand_is_not_mentioned(): void
    {
        [$admin, $site] = $this->createAdminWithSite('monitoring_search_no_brand_target', 'site_user', 'search no brand site');

        app(CurrentSite::class)->set($site);
        $createdAt = now()->subDays(10)->setTime(8, 30, 15);

        $diagnosisRun = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => 'Search Report Brand',
            'platforms' => ['deepseek'],
            'status' => 'completed',
            'total_questions' => 1,
            'completed_questions' => 1,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
            'completed_at' => now()->subDays(10),
        ]);

        $diagnosisQuestion = BrandDiagnosisQuestion::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'run_id' => (int) $diagnosisRun->id,
            'question' => 'Which providers are mentioned?',
            'question_type' => 'choice',
            'sort_order' => 1,
            'status' => 'completed',
        ]);

        $result = BrandDiagnosisResult::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'run_id' => (int) $diagnosisRun->id,
            'question_id' => (int) $diagnosisQuestion->id,
            'platform' => 'deepseek',
            'answer' => 'Search result does not mention the target brand.',
            'brand_mentioned' => false,
            'mention_count' => 0,
            'mention_rank' => 0,
            'sentiment' => 'neutral',
            'status' => 'success',
            'checked_at' => now(),
        ]);
        $result->forceFill(['created_at' => $createdAt])->save();

        $report = app(MonitoringReportDataService::class)->enterpriseReport($admin, $site);

        $this->assertSame('-', $report['search_rows'][0]['target']);
        $this->assertSame($createdAt->toDateString(), $report['search_rows'][0]['date']);
        $this->assertSame($createdAt->format('Y-m-d H:i:s'), $report['search_rows'][0]['time']);
    }

    public function test_enterprise_search_report_uses_chat_baidu_for_wenxin_platform_link(): void
    {
        [$admin, $site] = $this->createAdminWithSite('monitoring_wenxin_platform_url', 'site_user', 'wenxin platform site');

        app(CurrentSite::class)->set($site);

        $this->seedSearchData($admin, $site, [
            'company' => 'Wenxin Platform Brand',
            'question' => 'Which AI search platform should be used?',
            'keyword' => 'AI search',
            'competitor' => 'Other Brand',
            'platform' => 'wenxin',
            'sourceTitle' => 'Wenxin Source',
            'articleTitle' => 'Wenxin Article',
        ]);

        $report = app(MonitoringReportDataService::class)->enterpriseReport($admin, $site);

        $this->assertSame('https://chat.baidu.com/', $report['search_rows'][0]['platform_url']);
    }

    public function test_enterprise_report_lists_all_default_platforms_even_when_not_collected(): void
    {
        [$admin, $site] = $this->createAdminWithSite('monitoring_zero_collection_platforms', 'site_user', '零收录站点');

        app(CurrentSite::class)->set($site);

        $library = KeywordLibrary::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => '零收录关键词库',
            'company_name' => '星河智能科技有限公司',
            'domain_keyword' => 'AI 搜索优化',
            'status' => 'active',
            'keyword_count' => 1,
        ]);
        $keyword = Keyword::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'library_id' => (int) $library->id,
            'keyword' => 'AI 搜索优化',
        ]);
        $question = KeywordQuestionVariant::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'keyword_id' => (int) $keyword->id,
            'question' => '星河智能科技有限公司适合做 AI 搜索优化吗？',
        ]);
        $run = GeoInclusionCheckRun::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'keyword_library_id' => (int) $library->id,
            'platforms' => ['doubao', 'qianwen', 'deepseek'],
            'status' => 'completed',
            'total_checks' => 3,
            'completed_checks' => 3,
        ]);

        GeoInclusionCheckResult::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'run_id' => (int) $run->id,
            'keyword_library_id' => (int) $library->id,
            'keyword_id' => (int) $keyword->id,
            'question_variant_id' => (int) $question->id,
            'platform' => 'doubao',
            'question' => (string) $question->question,
            'answer' => '回答提到星河智能科技有限公司',
            'keyword_hit' => true,
            'brand_hit' => true,
            'status' => 'success',
            'checked_at' => now(),
        ]);
        GeoInclusionCheckResult::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'run_id' => (int) $run->id,
            'keyword_library_id' => (int) $library->id,
            'keyword_id' => (int) $keyword->id,
            'question_variant_id' => (int) $question->id,
            'platform' => 'qianwen',
            'question' => (string) $question->question,
            'answer' => '',
            'keyword_hit' => false,
            'brand_hit' => false,
            'status' => 'failed',
            'error_message' => '未收录',
            'checked_at' => now(),
        ]);

        $report = app(MonitoringReportDataService::class)->enterpriseReport($admin, $site);

        $this->assertSame(1, $report['summary']['model_collection_total']['actual']);
        $this->assertSame([
            ['name' => '豆包', 'value' => 1],
            ['name' => '千问', 'value' => 0],
            ['name' => 'DeepSeek', 'value' => 0],
            ['name' => '元宝', 'value' => 0],
            ['name' => '文心一言', 'value' => 0],
        ], $report['model_collection']);
    }

    /**
     * @return array{0:Admin,1:Site}
     */
    private function createAdminWithSite(string $username, string $role, string $siteName): array
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => $username,
            'role' => $role,
            'status' => 'active',
        ]);

        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => $siteName,
            'domain' => $username.'.example.test',
            'status' => 'active',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'owner']);

        return [$admin, $site];
    }

    /**
     * @param  array{company:string,question:string,keyword:string,competitor:string,platform:string,sourceTitle:string,articleTitle:string}  $data
     * @return array{question:KeywordQuestionVariant,article:Article}
     */
    private function seedSearchData(Admin $admin, Site $site, array $data): array
    {
        $library = KeywordLibrary::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => $data['company'].' 关键词库',
            'company_name' => $data['company'],
            'domain_keyword' => $data['keyword'],
            'industry' => 'AI 搜索优化',
            'brand_description' => $data['company'].'提供 AI 搜索优化服务',
            'status' => 'active',
            'keyword_count' => 1,
        ]);

        $keyword = Keyword::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'library_id' => (int) $library->id,
            'keyword' => $data['keyword'],
        ]);

        $question = KeywordQuestionVariant::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'keyword_id' => (int) $keyword->id,
            'question' => $data['question'],
            'created_at' => now()->subHour(),
        ]);

        $inclusionRun = GeoInclusionCheckRun::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'keyword_library_id' => (int) $library->id,
            'platforms' => [$data['platform']],
            'status' => 'completed',
            'total_checks' => 1,
            'completed_checks' => 1,
        ]);

        GeoInclusionCheckResult::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'run_id' => (int) $inclusionRun->id,
            'keyword_library_id' => (int) $library->id,
            'keyword_id' => (int) $keyword->id,
            'question_variant_id' => (int) $question->id,
            'platform' => $data['platform'],
            'question' => $data['question'],
            'answer' => '回答提到 '.$data['company'],
            'keyword_hit' => true,
            'brand_hit' => true,
            'status' => 'success',
            'checked_at' => now()->subMinutes(30),
        ]);

        $diagnosisRun = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => $data['company'],
            'platforms' => [$data['platform']],
            'status' => 'completed',
            'total_questions' => 1,
            'completed_questions' => 1,
            'failed_questions' => 0,
            'brand_score' => 80,
            'mention_rate' => 100,
            'average_rank' => 2,
            'mention_count' => 1,
            'sentiment_rate' => 100,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
            'completed_at' => now(),
        ]);

        $diagnosisQuestion = BrandDiagnosisQuestion::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'run_id' => (int) $diagnosisRun->id,
            'question' => $data['question'],
            'question_type' => '选择',
            'sort_order' => 1,
            'status' => 'completed',
        ]);

        $diagnosisResult = BrandDiagnosisResult::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'run_id' => (int) $diagnosisRun->id,
            'question_id' => (int) $diagnosisQuestion->id,
            'platform' => $data['platform'],
            'answer' => $data['company'].'在回答中被提及，并引用了行业资料。',
            'brand_mentioned' => true,
            'mention_count' => 1,
            'mention_rank' => 2,
            'sentiment' => 'positive',
            'status' => 'success',
            'checked_at' => now()->subMinutes(20),
        ]);

        BrandDiagnosisBrandMention::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'run_id' => (int) $diagnosisRun->id,
            'question_id' => (int) $diagnosisQuestion->id,
            'result_id' => (int) $diagnosisResult->id,
            'platform' => $data['platform'],
            'brand_name' => $data['company'],
            'mention_count' => 1,
            'mention_rank' => 2,
            'sentiment' => 'positive',
            'source_count' => 1,
            'is_target_brand' => true,
            'evidence' => '回答中提到 '.$data['company'],
        ]);

        BrandDiagnosisBrandMention::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'run_id' => (int) $diagnosisRun->id,
            'question_id' => (int) $diagnosisQuestion->id,
            'result_id' => (int) $diagnosisResult->id,
            'platform' => $data['platform'],
            'brand_name' => $data['competitor'],
            'mention_count' => 1,
            'mention_rank' => 1,
            'sentiment' => 'neutral',
            'source_count' => 1,
            'is_target_brand' => false,
            'evidence' => '回答中提到 '.$data['competitor'],
        ]);

        BrandDiagnosisSource::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'run_id' => (int) $diagnosisRun->id,
            'question_id' => (int) $diagnosisQuestion->id,
            'result_id' => (int) $diagnosisResult->id,
            'platform' => $data['platform'],
            'title' => $data['sourceTitle'],
            'url' => 'https://example.test/'.md5($data['sourceTitle']),
            'domain' => 'example.test',
            'source_type' => 'url_citation',
        ]);

        $category = Category::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => '默认分类 '.$site->id,
            'slug' => 'category-'.$site->id,
        ]);
        $author = Author::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => '作者 '.$site->id,
        ]);
        $article = Article::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'title' => $data['articleTitle'],
            'slug' => 'article-'.md5($data['articleTitle']),
            'content' => $data['company'].' '.$data['question'],
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'original_keyword' => $data['keyword'],
            'keywords' => $data['keyword'],
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now()->subMinutes(10),
        ]);

        return ['question' => $question, 'article' => $article];
    }
}
