<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminWeb;
use Illuminate\View\View;

class BrandDiagnosisController extends Controller
{
    public function index(): View
    {
        return view('admin.brand-diagnosis.index', [
            'pageTitle' => '品牌诊断/报告',
            'activeMenu' => 'brand_diagnosis',
            'adminSiteName' => AdminWeb::siteName(),
            'models' => $this->models(),
            'questions' => $this->questions(),
            'mentionRateRanking' => $this->mentionRateRanking(),
            'mentionCountRanking' => $this->mentionCountRanking(),
            'averageRankings' => $this->averageRankings(),
            'sources' => $this->sources(),
            'conversations' => $this->conversations(),
            'diagnosisRecords' => $this->diagnosisRecords(),
        ]);
    }

    /**
     * @return list<array{name:string,key:string,initial:string,color:string,desc:string,deep:bool}>
     */
    private function models(): array
    {
        return [
            ['name' => '豆包', 'key' => 'doubao', 'initial' => '豆', 'color' => 'bg-blue-600', 'desc' => '网页问答', 'deep' => true],
            ['name' => '千问', 'key' => 'qianwen', 'initial' => '千', 'color' => 'bg-violet-600', 'desc' => '通义问答', 'deep' => true],
            ['name' => '文心一言', 'key' => 'wenxin', 'initial' => '文', 'color' => 'bg-emerald-600', 'desc' => '千帆搜索', 'deep' => false],
            ['name' => 'DeepSeek', 'key' => 'deepseek', 'initial' => 'DS', 'color' => 'bg-indigo-600', 'desc' => '深度推理', 'deep' => true],
        ];
    }

    /**
     * @return list<array{
     *     id:int,
     *     brand:string,
     *     status:string,
     *     created_at:string,
     *     expanded:bool,
     *     has_report:bool,
     *     metrics:array{score:int,mention_rate:int,average_rank:int,mention_count:int,sentiment_rate:int}
     * }>
     */
    private function diagnosisRecords(): array
    {
        return [
            [
                'id' => 1,
                'brand' => '策影GEO',
                'status' => '已完成',
                'created_at' => '2026-06-05 10:26:23',
                'expanded' => true,
                'has_report' => true,
                'metrics' => [
                    'score' => 0,
                    'mention_rate' => 0,
                    'average_rank' => 0,
                    'mention_count' => 0,
                    'sentiment_rate' => 0,
                ],
            ],
            [
                'id' => 2,
                'brand' => '策影GEO',
                'status' => '已完成',
                'created_at' => '2026-06-04 16:12:08',
                'expanded' => false,
                'has_report' => true,
                'metrics' => [
                    'score' => 12,
                    'mention_rate' => 8,
                    'average_rank' => 3,
                    'mention_count' => 4,
                    'sentiment_rate' => 75,
                ],
            ],
        ];
    }

    /**
     * @return list<array{text:string,type:string,rank:int}>
     */
    private function questions(): array
    {
        return [
            ['rank' => 1, 'text' => '企业 AI 搜索优化服务选哪家靠谱？', 'type' => '对比/选择'],
            ['rank' => 2, 'text' => '企业数字化营销升级服务找哪家？', 'type' => '推荐/建议'],
            ['rank' => 3, 'text' => 'AI 问答内容优化服务商哪家效果好？', 'type' => '推荐/建议'],
            ['rank' => 4, 'text' => '做企业品牌内容资产建设的服务商有哪些？', 'type' => '推荐/建议'],
            ['rank' => 5, 'text' => '品牌如何提升在 AI 搜索中的可见度？', 'type' => '方法/策略'],
        ];
    }

    /**
     * @return list<array{brand:string,rate:int}>
     */
    private function mentionRateRanking(): array
    {
        return [
            ['brand' => '泓动数据', 'rate' => 40],
            ['brand' => '蓝色光标', 'rate' => 40],
            ['brand' => '新榜', 'rate' => 20],
            ['brand' => '字节跳动', 'rate' => 20],
            ['brand' => '灵犀科技', 'rate' => 20],
            ['brand' => '奥美', 'rate' => 20],
            ['brand' => '智炬时代', 'rate' => 20],
            ['brand' => '思企互联', 'rate' => 20],
            ['brand' => '豆智', 'rate' => 20],
        ];
    }

    /**
     * @return list<array{brand:string,count:int}>
     */
    private function mentionCountRanking(): array
    {
        return [
            ['brand' => '泓动数据', 'count' => 5],
            ['brand' => '新榜', 'count' => 2],
            ['brand' => '智炬时代', 'count' => 2],
            ['brand' => '思企互联', 'count' => 2],
            ['brand' => 'Bynder', 'count' => 2],
            ['brand' => '蓝色光标', 'count' => 2],
            ['brand' => '字节跳动', 'count' => 1],
            ['brand' => '灵犀科技', 'count' => 1],
            ['brand' => '奥美', 'count' => 1],
        ];
    }

    /**
     * @return list<array{brand:string,rate:int,rank:string}>
     */
    private function averageRankings(): array
    {
        return [
            ['brand' => '奥美', 'rate' => 20, 'rank' => '1名'],
            ['brand' => '泓动数据', 'rate' => 40, 'rank' => '1名'],
            ['brand' => '知道AI', 'rate' => 20, 'rank' => '1名'],
            ['brand' => '蓝色光标', 'rate' => 40, 'rank' => '1.5名'],
            ['brand' => '利欧数字', 'rate' => 20, 'rank' => '2名'],
            ['brand' => '百度', 'rate' => 20, 'rank' => '3名'],
            ['brand' => '拾光宝盒', 'rate' => 20, 'rank' => '3名'],
            ['brand' => '智普战略咨询', 'rate' => 20, 'rank' => '4名'],
            ['brand' => '豆智', 'rate' => 20, 'rank' => '4名'],
        ];
    }

    /**
     * @return list<array{platform:string,category:string,title:string,questions:int,models:int,icon:string}>
     */
    private function sources(): array
    {
        return [
            ['platform' => '头条', 'category' => '门户/自媒体平台', 'title' => '2026年 AI 搜索优化公司精选榜单', 'questions' => 2, 'models' => 1, 'icon' => 'TT'],
            ['platform' => 'IT', 'category' => '社区/论坛/博客', 'title' => '2026年 AI 搜索优化可见度提升指南', 'questions' => 1, 'models' => 1, 'icon' => 'IT'],
            ['platform' => '官网', 'category' => '官网/独立站/行业垂直', 'title' => '2026年口碑好的 AI 搜索优化服务商', 'questions' => 1, 'models' => 1, 'icon' => 'HE'],
            ['platform' => '短视频', 'category' => '视频平台', 'title' => '2026 年 AI 搜索优化趋势解读', 'questions' => 1, 'models' => 1, 'icon' => 'DY'],
        ];
    }

    /**
     * @return list<array{question:string,brands:list<string>}>
     */
    private function conversations(): array
    {
        return [
            ['question' => '企业 AI 搜索优化服务选哪家靠谱？', 'brands' => ['泓动数据', '百度', '字节跳动']],
            ['question' => '企业数字化营销升级服务找哪家？', 'brands' => ['蓝色光标', '利欧数字', '珍岛集团']],
            ['question' => 'AI 问答内容优化服务商哪家效果好？', 'brands' => ['知道AI', '豆智', '小象传媒']],
            ['question' => '做企业品牌内容资产建设的服务商有哪些？', 'brands' => ['奥美', '蓝色光标', '拾光宝盒']],
        ];
    }
}
