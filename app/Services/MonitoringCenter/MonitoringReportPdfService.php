<?php

/**
 * Created by Codex.
 * @Date: 2026-11-16
 * @Time: 16:16:41
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： MonitoringReportPdfService.php
 * @Description: 按监测中心在线报告的视觉规范原生绘制中文 PDF 报告。
 */

namespace App\Services\MonitoringCenter;

use TCPDF;

final class MonitoringReportPdfService
{
    private const PAGE_MARGIN = 18.0;

    private const PAGE_BOTTOM_MARGIN = 24.0;

    private const SECTION_BREAK_GUARD = 48.0;

    /**
     * @Name: render
     * @Description: 按网页版报告的标题、信息区、指标卡和数据章节生成 PDF，并附带可跳转目录。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 16:16:41
     * @UpdateTime: 2026-11-17 11:40:07
     * @Param array<string,mixed> $account 当前监测账号资料
     * @Param array<string,mixed>|null $site 当前授权站点资料
     * @Param array<string,mixed> $dashboard 监测中心报告数据及筛选条件
     * @Param string $title PDF 文档标题
     * @Return string PDF 二进制内容
     * @Throws \RuntimeException TCPDF 无法渲染文档时抛出异常
     */
    public function render(array $account, ?array $site, array $dashboard, string $title): string
    {
        $pdf = new class('P', 'mm', 'A3', true, 'UTF-8', false) extends TCPDF
        {
            public function Footer()
            {
                $this->SetY(-18);
                $this->SetDrawColor(218, 230, 222);
                $this->Line(20, $this->GetY(), $this->getPageWidth() - 20, $this->GetY());
                $this->SetFont('cid0cs', '', 11);
                $this->SetTextColor(113, 130, 120);
                $this->Cell($this->getPageWidth() - 100, 10, '生成引擎优化  ·  品牌诊断报告', 0, 0, 'L');
                $this->Cell(60, 10, $this->getAliasNumPage().' / '.$this->getAliasNbPages(), 0, 0, 'R');
            }
        };

        $pdf->SetCreator('生成引擎优化监测中心');
        $pdf->SetAuthor((string) ($account['display_name'] ?? $account['username'] ?? ''));
        $pdf->SetTitle($title);
        $pdf->SetSubject('品牌诊断报告');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->SetMargins(self::PAGE_MARGIN, self::PAGE_MARGIN, self::PAGE_MARGIN);
        $pdf->SetAutoPageBreak(true, self::PAGE_BOTTOM_MARGIN);
        $pdf->setFontSubsetting(true);
        $pdf->SetFont('cid0cs', '', 12);
        $pdf->SetDrawColor(227, 236, 230);
        $pdf->SetLineWidth(0.25);
        $pdf->AddPage();
        $pdf->writeHTML($this->reportHeaderHtml($account, $site, $dashboard), true, false, true, false, '');
        $tocEntries = [];
        $overviewLink = $pdf->AddLink();
        $overviewPage = $pdf->getPage();
        $overviewY = $pdf->GetY();
        $pdf->Bookmark('01  整体表现', 0, -1, '', 'B', [13, 157, 88]);
        $pdf->SetLink($overviewLink, $overviewY, $overviewPage);
        $tocEntries[] = [
            'title' => '01  整体表现',
            'page' => $overviewPage,
            'y' => $overviewY,
            'link' => $overviewLink,
        ];
        $this->writeOverview($pdf, $dashboard);

        $pdf->AddPage();
        $this->writeSection(
            $pdf,
            '02  平台表现',
            $this->platformSection((array) (($dashboard['latest_diagnosis']['platforms'] ?? []))),
            $tocEntries
        );
        $this->writeReportSections($pdf, $dashboard, $tocEntries);

        $pdf->addTOCPage();
        $tocPage = $pdf->getPage();
        $pdf->Bookmark('报告目录', 0, -1, '', 'B', [13, 157, 88]);
        $pdf->SetY(24);
        $pdf->writeHTML($this->tocHeading(), true, false, true, false, '');
        $pdf->SetY(74);
        $this->writeContents($pdf, $tocEntries);
        $pdf->endTOCPage();
        $pdf->movePage($tocPage, 2);

        foreach ($tocEntries as $entry) {
            $page = (int) $entry['page'];
            $pdf->SetLink((int) $entry['link'], (float) $entry['y'], $page >= 2 ? $page + 1 : $page);
        }

        return $pdf->Output($title.'.pdf', 'S');
    }

    /**
     * @Name: reportHeaderHtml
     * @Description: 按网页版顺序绘制品牌标题和三列报告信息，不额外创建封面。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 16:16:41
     * @UpdateTime: 2026-11-16 18:26:19
     * @Param array<string,mixed> $account 当前监测账号资料
     * @Param array<string,mixed>|null $site 当前授权站点资料
     * @Param array<string,mixed> $dashboard 监测中心报告数据及筛选条件
     * @Return string TCPDF 可渲染的报告标题和信息区 HTML
     * @Throws \RuntimeException TCPDF 无法处理报告标题时抛出异常
     */
    private function reportHeaderHtml(array $account, ?array $site, array $dashboard): string
    {
        $diagnosis = (array) ($dashboard['latest_diagnosis'] ?? []);
        $filters = (array) ($dashboard['filters'] ?? []);
        $selectedPlatform = (string) ($filters['platform'] ?? '');
        $selectedQuestionId = (int) ($filters['question_id'] ?? 0);
        $questionOptions = (array) ($filters['questions'] ?? []);
        $selectedQuestion = collect($questionOptions)->firstWhere('id', $selectedQuestionId);
        $startDate = is_string($filters['start_date'] ?? null) ? trim($filters['start_date']) : '';
        $endDate = is_string($filters['end_date'] ?? null) ? trim($filters['end_date']) : '';
        $platformLabel = $this->selectedPlatformLabel($filters, $selectedPlatform);
        $questionLabel = is_array($selectedQuestion) ? (string) ($selectedQuestion['question'] ?? '全部问题') : '全部问题';
        $generatedAt = now()->format('Y-m-d H:i:s');
        $completedAtValue = $diagnosis['completed_at'] ?? $diagnosis['created_at'] ?? null;
        $completedAt = $completedAtValue instanceof \DateTimeInterface
            ? $completedAtValue->format('Y-m-d H:i:s')
            : (is_string($completedAtValue) && trim($completedAtValue) !== '' ? trim($completedAtValue) : '未记录');
        $brand = (string) ($diagnosis['brand'] ?? '品牌诊断报告');
        $siteName = (string) ($site['name'] ?? '当前站点');

        return $this->cover($brand, $siteName, $completedAt)
            .$this->metadata([
                ['诊断记录', '#'.(string) ($diagnosis['id'] ?? '—')],
                ['账号', (string) ($account['username'] ?? '—')],
                ['平台范围', $platformLabel],
                ['问题范围', $questionLabel],
                ['诊断日期范围', ($startDate !== '' ? $startDate : '不限').' 至 '.($endDate !== '' ? $endDate : '不限')],
                ['报告生成时间', $generatedAt],
            ]);
    }

    /**
     * @Name: writeOverview
     * @Description: 绘制整体表现卡片、三类舆情占比及诊断摘要。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 17:39:08
     * @UpdateTime: 2026-11-25 18:19:32
     * @Param TCPDF $pdf 当前 PDF 文档实例
     * @Param array<string,mixed> $dashboard 当前筛选范围内的报告数据
     * @Return void
     * @Throws \RuntimeException TCPDF 无法处理概览内容时抛出异常
     */
    private function writeOverview(TCPDF $pdf, array $dashboard): void
    {
        $diagnosis = (array) ($dashboard['latest_diagnosis'] ?? []);
        $summary = (array) ($diagnosis['summary'] ?? []);
        $completedQuestions = (int) ($summary['completed_questions'] ?? 0);
        $successfulAnswers = (int) ($summary['successful_answer_count'] ?? 0);
        $totalQuestions = (int) ($summary['total_questions'] ?? 0);

        $pdf->writeHTML($this->sectionTitle(
            'OVERVIEW',
            '整体表现',
            $completedQuestions.' / '.$totalQuestions.' 条问题已完成'
        ), true, false, true, false, '');

        $this->writeMetrics($pdf, [
            ['label' => '品牌得分', 'value' => (string) ($summary['brand_score'] ?? 0), 'suffix' => '分', 'icon' => 'gauge', 'color' => [13, 157, 88], 'background' => [232, 251, 241]],
            ['label' => '品牌提及率', 'value' => (string) ($summary['mention_rate'] ?? 0), 'suffix' => '%', 'icon' => 'target', 'color' => [52, 121, 223], 'background' => [235, 243, 255]],
            ['label' => '平均提及排名', 'value' => (string) ($summary['average_rank'] ?? 0), 'suffix' => '名', 'icon' => 'rank', 'color' => [107, 91, 211], 'background' => [242, 239, 255]],
            ['label' => '品牌提及次数', 'value' => (string) ($summary['mention_count'] ?? 0), 'suffix' => '次', 'icon' => 'count', 'color' => [206, 134, 28], 'background' => [255, 246, 228]],
            [
                'label' => '舆情分布',
                'breakdown' => (array) ($summary['sentiment_breakdown'] ?? []),
                'icon' => 'sentiment',
                'color' => [22, 139, 155],
                'background' => [230, 249, 251],
            ],
        ]);

        $pdf->writeHTML($this->summaryDetails([
            ['诊断状态', ($diagnosis['status'] ?? '') === 'completed' ? '已完成' : (string) ($diagnosis['status'] ?? '未完成')],
            ['诊断问题', $totalQuestions.' 条'],
            ['成功回答', $successfulAnswers.' 条'],
            ['监测平台', count((array) ($diagnosis['platforms'] ?? [])).' 个'],
            ['引用来源', count((array) ($diagnosis['sources'] ?? [])).' 条'],
            ['作品引用', count((array) ($diagnosis['citations'] ?? [])).' 条'],
        ]), true, false, true, false, '');
    }

    /**
     * @Name: writeReportSections
     * @Description: 按网页版报告顺序绘制明细，并为多行数据区单独设置行高。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 16:58:09
     * @UpdateTime: 2026-11-21 09:35:28
     * @Param TCPDF $pdf 当前 PDF 文档实例
     * @Param array<string,mixed> $dashboard 当前筛选范围内的报告数据
     * @Return void
     * @Throws \RuntimeException TCPDF 无法绘制章节内容时抛出异常
     */
    private function writeReportSections(TCPDF $pdf, array $dashboard, array &$tocEntries): void
    {
        $diagnosis = (array) ($dashboard['latest_diagnosis'] ?? []);
        $filters = (array) ($dashboard['filters'] ?? []);
        $platformLabels = collect((array) ($filters['platforms'] ?? []))
            ->mapWithKeys(static fn (array $platform): array => [
                (string) ($platform['key'] ?? '') => (string) ($platform['label'] ?? $platform['key'] ?? ''),
            ])
            ->all();
        $analytics = (array) ($diagnosis['advanced_analytics'] ?? []);

        $this->writeSection(
            $pdf,
            '03  推荐位次表现',
            $this->rankingSection((array) ($analytics['metrics'] ?? [])),
            $tocEntries
        );
        $this->writeSection(
            $pdf,
            '04  问题维度表现',
            $this->dimensionSection((array) ($analytics['dimensions'] ?? [])),
            $tocEntries
        );
        $this->writeSection(
            $pdf,
            '05  竞品表现',
            $this->competitorSection((array) ($analytics['competitors'] ?? [])),
            $tocEntries
        );
        $this->writeSection(
            $pdf,
            '06  信源平台分布',
            $this->sourcePlatformSection((array) ($analytics['source_platforms'] ?? [])),
            $tocEntries
        );
        $this->writeSection(
            $pdf,
            '07  问题明细',
            $this->questionSection((array) ($diagnosis['questions'] ?? []), $platformLabels),
            $tocEntries,
            1.5
        );
        $this->writeSection(
            $pdf,
            '08  引用来源',
            $this->sourceSection((array) ($diagnosis['sources'] ?? [])),
            $tocEntries,
            1.5
        );
        $this->writeSection(
            $pdf,
            '09  AI 问题与对话明细',
            $this->conversationSection((array) ($diagnosis['conversations'] ?? [])),
            $tocEntries,
            1.5
        );
        $this->writeSection($pdf, '10  作品引用明细', $this->citationSection((array) ($diagnosis['citations'] ?? [])), $tocEntries);
        $this->writeSection($pdf, '11  诊断记录', $this->historySection((array) ($dashboard['diagnosis_records'] ?? [])), $tocEntries);
    }

    /**
     * @Name: rankingSection
     * @Description: 展示推荐率及 Top1、Top3、Top5 命中回答数和占成功回答的百分比。
     * @Author: cdkay
     * @CreateTime: 2026-11-21 09:35:28
     * @UpdateTime: 2026-11-21 09:35:28
     * @Param array<string,mixed> $metrics 当前筛选范围的排名指标
     * @Return string 推荐位次表现区 HTML
     * @Throws \RuntimeException TCPDF 无法绘制排名数据时抛出异常
     */
    private function rankingSection(array $metrics): string
    {
        $answers = (int) ($metrics['answer_count'] ?? 0);
        $rows = [];
        foreach ([
            ['推荐率', 'recommendation_count', 'recommendation_rate'],
            ['Top1', 'top1_count', 'top1_rate'],
            ['Top3', 'top3_count', 'top3_rate'],
            ['Top5', 'top5_count', 'top5_rate'],
        ] as [$label, $countKey, $rateKey]) {
            $rows[] = [
                $label,
                (string) (int) ($metrics[$countKey] ?? 0),
                (string) ($metrics[$rateKey] ?? 0).'% ',
                (string) $answers,
            ];
        }

        return $this->sectionTitle('RECOMMENDATION RANKING', '推荐位次表现', $answers.' 条成功回答')
            .$this->table(['指标', '命中回答', '占成功回答比例', '成功回答数'], $rows, [24, 22, 28, 26]);
    }

    /**
     * @Name: dimensionSection
     * @Description: 按诊断问题类型展示提及率、推荐率、Top 排名和平均位次。
     * @Author: cdkay
     * @CreateTime: 2026-11-21 09:35:28
     * @UpdateTime: 2026-11-21 09:35:28
     * @Param list<array<string,mixed>> $dimensions 问题维度聚合数据
     * @Return string 问题维度表现区 HTML
     * @Throws \RuntimeException TCPDF 无法绘制维度数据时抛出异常
     */
    private function dimensionSection(array $dimensions): string
    {
        $rows = array_map(static fn (array $row): array => [
            (string) ($row['dimension'] ?? '未分类'),
            (string) ($row['question_count'] ?? 0),
            (string) ($row['answer_count'] ?? 0),
            (string) ($row['mention_rate'] ?? 0).'% ',
            (string) ($row['recommendation_rate'] ?? 0).'% ',
            (string) ($row['top1_rate'] ?? 0).'% ',
            (string) ($row['top3_rate'] ?? 0).'% ',
            (string) ($row['top5_rate'] ?? 0).'% ',
            isset($row['average_rank']) ? (string) $row['average_rank'].' 名' : '—',
        ], $dimensions);

        return $this->sectionTitle('QUESTION DIMENSIONS', '问题维度表现', count($dimensions).' 个维度')
            .$this->table(['问题维度', '问题数', '有效回答', '提及率', '推荐率', 'Top1', 'Top3', 'Top5', '平均位次'], $rows, [17, 9, 11, 10, 10, 8, 8, 8, 19]);
    }

    /**
     * @Name: competitorSection
     * @Description: 展示各竞品覆盖回答数、提及次数、推荐率、Top 排名及平均位次。
     * @Author: cdkay
     * @CreateTime: 2026-11-21 09:35:28
     * @UpdateTime: 2026-11-21 09:35:28
     * @Param list<array<string,mixed>> $competitors 竞品聚合数据
     * @Return string 竞品表现区 HTML
     * @Throws \RuntimeException TCPDF 无法绘制竞品数据时抛出异常
     */
    private function competitorSection(array $competitors): string
    {
        $rows = array_map(static fn (array $row): array => [
            (string) ($row['brand'] ?? '未命名竞品'),
            (string) ($row['answer_count'] ?? 0),
            (string) ($row['mention_count'] ?? 0),
            (string) ($row['recommendation_rate'] ?? 0).'% ',
            (string) ($row['top1_rate'] ?? 0).'% ',
            (string) ($row['top3_rate'] ?? 0).'% ',
            (string) ($row['top5_rate'] ?? 0).'% ',
            isset($row['average_rank']) ? (string) $row['average_rank'].' 名' : '—',
        ], $competitors);

        return $this->sectionTitle('COMPETITOR PERFORMANCE', '竞品表现', count($competitors).' 个品牌')
            .$this->table(['竞品', '出现回答', '提及次数', '推荐率', 'Top1', 'Top3', 'Top5', '平均位次'], $rows, [24, 14, 13, 13, 9, 9, 9, 9]);
    }

    /**
     * @Name: sourcePlatformSection
     * @Description: 按内容平台类别汇总信源出现次数、占比、域名覆盖及关联模型。
     * @Author: cdkay
     * @CreateTime: 2026-11-21 09:35:28
     * @UpdateTime: 2026-11-21 09:35:28
     * @Param list<array<string,mixed>> $platforms 信源平台聚合数据
     * @Return string 信源平台分布区 HTML
     * @Throws \RuntimeException TCPDF 无法绘制信源平台数据时抛出异常
     */
    private function sourcePlatformSection(array $platforms): string
    {
        $rows = array_map(static fn (array $row): array => [
            (string) ($row['platform'] ?? '其他站点'),
            (string) ($row['type'] ?? '其他'),
            (string) ($row['count'] ?? 0),
            (string) ($row['share'] ?? 0).'% ',
            (string) ($row['domain_count'] ?? 0),
            implode('、', (array) ($row['models'] ?? [])) ?: '—',
        ], $platforms);

        return $this->sectionTitle('CITATION SOURCE PLATFORMS', '信源平台分布', count($platforms).' 个平台')
            .$this->table(['信源平台', '类别', '出现次数', '信源占比', '覆盖域名数', '关联诊断模型'], $rows, [20, 16, 14, 14, 16, 16]);
    }

    /**
     * @Name: writeSection
     * @Description: 在报告正文自然分页，并为章节添加目录和阅读器侧栏书签。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 16:58:09
     * @UpdateTime: 2026-11-17 15:41:05
     * @Param TCPDF $pdf 当前 PDF 文档实例
     * @Param string $title 章节名称
     * @Param string $html 章节正文 HTML
     * @Param float|null $cellHeightRatio 可选的单元格文本行高比例
     * @Return void
     * @Throws \RuntimeException TCPDF 无法绘制章节内容时抛出异常
     */
    private function writeSection(
        TCPDF $pdf,
        string $title,
        string $html,
        array &$tocEntries,
        ?float $cellHeightRatio = null
    ): void
    {
        $pageBottom = $pdf->getPageHeight() - self::PAGE_BOTTOM_MARGIN;
        if ($pdf->GetY() + self::SECTION_BREAK_GUARD > $pageBottom) {
            $pdf->AddPage();
        }

        $page = $pdf->getPage();
        $y = $pdf->GetY();
        $link = $pdf->AddLink();
        $pdf->SetLink($link, $y, $page);
        $pdf->Bookmark($title, 0, -1, '', 'B', [13, 157, 88]);
        $tocEntries[] = [
            'title' => $title,
            'page' => $page,
            'y' => $y,
            'link' => $link,
        ];
        $originalCellHeightRatio = $pdf->getCellHeightRatio();
        if ($cellHeightRatio !== null) {
            $pdf->setCellHeightRatio($cellHeightRatio);
        }

        try {
            $pdf->writeHTML($html, true, false, true, false, '');
        } finally {
            $pdf->setCellHeightRatio($originalCellHeightRatio);
        }
    }

    /**
     * @Name: writeContents
     * @Description: 按正文各章节实际起始页绘制带页码和跳转链接的目录行。
     * @Author: cdkay
     * @CreateTime: 2026-11-17 11:01:20
     * @UpdateTime: 2026-11-17 11:11:52
     * @Param TCPDF $pdf 当前 PDF 文档实例
     * @Param list<array{title:string,page:int,y:float,link:int}> $entries 正文目录条目
     * @Return void
     * @Throws \\RuntimeException TCPDF 无法绘制目录时抛出异常
     */
    private function writeContents(TCPDF $pdf, array $entries): void
    {
        $right = $pdf->getPageWidth() - self::PAGE_MARGIN;
        $numberWidth = 22.0;
        $rowHeight = 16.0;

        foreach ($entries as $entry) {
            $y = $pdf->GetY();
            $title = (string) $entry['title'];
            $link = (int) $entry['link'];

            $pdf->SetFont('cid0cs', '', 17);
            $pdf->SetTextColor(38, 58, 46);
            $pdf->SetXY(self::PAGE_MARGIN, $y);
            $pdf->Cell($right - self::PAGE_MARGIN - $numberWidth, $rowHeight, $title, 0, 0, 'L', false, $link);

            $titleWidth = $pdf->GetStringWidth($title, 'cid0cs', '', 17);
            $lineStart = min(self::PAGE_MARGIN + $titleWidth + 4, $right - $numberWidth - 16);
            $lineEnd = $right - $numberWidth - 4;
            if ($lineEnd > $lineStart) {
                $pdf->SetLineStyle([
                    'width' => 0.35,
                    'cap' => 'butt',
                    'join' => 'miter',
                    'dash' => '1,1',
                    'color' => [176, 202, 185],
                ]);
                $pdf->Line($lineStart, $y + 9, $lineEnd, $y + 9);
                $pdf->SetLineStyle([
                    'width' => 0.25,
                    'cap' => 'butt',
                    'join' => 'miter',
                    'dash' => 0,
                    'color' => [227, 236, 230],
                ]);
            }

            $page = (int) $entry['page'];
            $displayPage = $page >= 2 ? $page + 1 : $page;
            $pdf->SetFont('helvetica', '', 15);
            $pdf->SetTextColor(13, 157, 88);
            $pdf->SetXY($right - $numberWidth, $y);
            $pdf->Cell($numberWidth, $rowHeight, (string) $displayPage, 0, 0, 'R', false, $link);
            $pdf->SetY($y + $rowHeight);
        }
    }

    /**
     * @Name: selectedPlatformLabel
     * @Description: 将当前平台筛选键转换为报告使用的中文名称，并在选项缺失时保留筛选键。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 16:58:09
     * @UpdateTime: 2026-11-16 17:22:42
     * @Param array<string,mixed> $filters 报告筛选项
     * @Param string $selectedPlatform 当前平台筛选键
     * @Return string 筛选平台名称
     * @Throws 无
     */
    private function selectedPlatformLabel(array $filters, string $selectedPlatform): string
    {
        if ($selectedPlatform === '') {
            return '全部平台';
        }

        foreach ((array) ($filters['platforms'] ?? []) as $platform) {
            if (is_array($platform) && (string) ($platform['key'] ?? '') === $selectedPlatform) {
                return (string) ($platform['label'] ?? $selectedPlatform);
            }
        }

        return $selectedPlatform;
    }

    /**
     * @Name: cover
     * @Description: 按网页版报告绘制深绿色品牌标题栏和诊断上下文。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 16:16:41
    * @UpdateTime: 2026-11-17 12:00:02
     * @Param string $brand 被诊断品牌名称
     * @Param string $siteName 当前站点名称
     * @Param string $completedAt 诊断完成时间
     * @Return string 标题区 HTML
     * @Throws \RuntimeException TCPDF 无法处理标题内容时抛出异常
     */
    private function cover(string $brand, string $siteName, string $completedAt): string
    {
        return '<table width="100%" cellpadding="18" cellspacing="0" bgcolor="#0c241a">'
            .'<tr><td width="90%">'
            .'<font color="#80f4b3" size="11"><b>BRAND DIAGNOSIS REPORT</b></font><br /><br />'
            .'<font color="#ffffff" size="40"><b>'.$this->escape($brand).'</b></font><br /><br /><br />'
            .'<font color="#9bb5a7" size="16">AI 搜索可见度诊断 · '.$this->escape($siteName)
            .' · 完成时间 '.$this->escape($completedAt).'</font>'
            .'</td><td width="10%" align="center" valign="middle" bgcolor="#173b2b">'
            .'<font color="#80f4b3" size="16"><b>PDF</b></font><br />'
            .'</td></tr></table>';
    }

    /**
     * @Name: tocHeading
     * @Description: 绘制独立目录页标题，目录条目使用正文记录的实际页码和跳转链接。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 16:58:09
     * @UpdateTime: 2026-11-17 15:00:40
     * @Return string TCPDF 可渲染的目录页标题 HTML
     * @Throws \RuntimeException TCPDF 无法处理目录标题时抛出异常
     */
    private function tocHeading(): string
    {
        return '<table width="100%" cellpadding="0" cellspacing="0"><tr><td>'
            .'<font color="#0d9d58" size="11"><b>REPORT CONTENTS</b></font><br />'
            .'<font color="#17231e" size="38"><b>报告目录</b></font><br />'
            .'<font color="#718078" size="22">&nbsp;</font><br />'
            .'<font color="#718078" size="16">诊断结果与监测明细</font>'
            .'</td></tr></table>';
    }

    /**
     * @Name: metadata
     * @Description: 按网页版三列信息网格展示报告编号、账号和筛选范围。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 16:16:41
     * @UpdateTime: 2026-11-17 12:00:02
     * @Param list<array{0:string,1:string}> $items 报告元信息
     * @Return string 元信息区 HTML
     * @Throws \RuntimeException TCPDF 无法处理元信息时抛出异常
     */
    private function metadata(array $items): string
    {
        $html = '<table width="100%" cellpadding="14" cellspacing="0" bgcolor="#f7fbf8">';
        foreach (array_chunk($items, 3) as $row) {
            $html .= '<tr>';
            foreach ($row as [$label, $value]) {
                $html .= '<td width="33.3%"><font color="#819087" size="13">'.$this->escape($label).'</font><br />'
                    .'<font color="#33473b" size="16"><b>'.$this->escape($value).'</b></font></td>';
            }
            while (count($row) < 3) {
                $html .= '<td width="33.3%"></td>';
                $row[] = ['', ''];
            }
            $html .= '</tr>';
        }

        return $html.'</table>';
    }

    /**
     * @Name: sectionTitle
     * @Description: 使用网页版报告的英文眉题、中文标题和右侧统计生成统一章节标题。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 16:16:41
     * @UpdateTime: 2026-11-17 15:32:45
     * @Param string $eyebrow 英文章节标签
     * @Param string $title 中文章节标题
     * @Param string $detail 章节统计说明
     * @Return string 章节标题 HTML
     * @Throws \RuntimeException TCPDF 无法处理章节标题时抛出异常
     */
    private function sectionTitle(string $eyebrow, string $title, string $detail = ''): string
    {
        return '<br /><table width="100%" cellpadding="3" cellspacing="0"><tr>'
            .'<td width="70%"><font color="#0d9d58" size="13"><b>'.$this->escape($eyebrow).'</b></font><br />'
            .'<font color="#263a2e" size="29"><b>'.$this->escape($title).'</b></font></td>'
            .'<td width="30%" align="right"><font color="#829087" size="15">'.$this->escape($detail).'</font></td>'
            .'</tr></table><br /><br />';
    }

    /**
     * @Name: writeMetrics
     * @Description: 绘制指标卡和矢量图标，舆情卡内按行展示各类别占比。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 16:16:41
     * @UpdateTime: 2026-11-25 18:10:56
     * @Param TCPDF $pdf 当前 PDF 文档实例
     * @Param list<array{label:string,value?:string,suffix?:string,breakdown?:array<string,int|null>,icon:string,color:array{int,int,int},background:array{int,int,int}}> $metrics 核心指标
     * @Return void
     * @Throws \RuntimeException TCPDF 无法处理指标内容时抛出异常
     */
    private function writeMetrics(TCPDF $pdf, array $metrics): void
    {
        $cardHeight = 62.0;
        $cardGap = 4.0;
        $availableWidth = $pdf->getPageWidth() - (self::PAGE_MARGIN * 2);
        $cardWidth = ($availableWidth - ($cardGap * (count($metrics) - 1))) / count($metrics);
        $top = $pdf->GetY() + 2;
        $pageBottom = $pdf->getPageHeight() - self::PAGE_BOTTOM_MARGIN;

        if ($top + $cardHeight > $pageBottom) {
            $pdf->AddPage();
            $top = $pdf->GetY();
        }

        foreach ($metrics as $index => $metric) {
            $x = self::PAGE_MARGIN + (($cardWidth + $cardGap) * $index);
            $color = $metric['color'];
            $background = $metric['background'];

            $pdf->SetDrawColor(227, 236, 230);
            $pdf->SetFillColor(251, 253, 251);
            $pdf->RoundedRect($x, $top, $cardWidth, $cardHeight, 2.2, '1111', 'DF');
            $pdf->SetFillColor($background[0], $background[1], $background[2]);
            $pdf->RoundedRect($x + 6, $top + 7, 13, 13, 2, '1111', 'F');
            $this->drawMetricIcon($pdf, (string) $metric['icon'], $x + 12.5, $top + 13.5, $color);

            if (isset($metric['breakdown'])) {
                $pdf->SetFont('cid0cs', '', 14);
                $pdf->SetTextColor(83, 102, 91);
                $pdf->SetXY($x + 22, $top + 10);
                $pdf->Cell($cardWidth - 26, 7, $metric['label'], 0, 0, 'L');

                // 三行使用相同的起点和右对齐边界，无有效数据时保留各类别标签。
                $innerWidth = $cardWidth - 12;
                $rowY = $top + 24;
                foreach (['positive' => '正向', 'neutral' => '中性', 'negative' => '负向'] as $key => $label) {
                    $rate = $metric['breakdown'][$key] ?? null;
                    $pdf->SetTextColor(83, 102, 91);
                    $pdf->SetXY($x + 6, $rowY);
                    $pdf->Cell($innerWidth / 2, 8, $label, 0, 0, 'L');
                    $pdf->SetTextColor(30, 73, 48);
                    $pdf->Cell($innerWidth / 2, 8, $rate === null ? '—' : $rate.'%', 0, 0, 'R');
                    $rowY += 10;
                }

                continue;
            }

            $pdf->SetFont('cid0cs', '', 31);
            $pdf->SetTextColor($color[0], $color[1], $color[2]);
            $value = (string) $metric['value'];
            $valueWidth = $pdf->GetStringWidth($value, 'cid0cs', '', 31);
            $pdf->SetXY($x + 6, $top + 26);
            $pdf->Cell($valueWidth + 1, 13, $value, 0, 0, 'L');

            $pdf->SetFont('cid0cs', '', 14);
            $pdf->SetTextColor(119, 135, 125);
            $pdf->SetXY($x + 7 + $valueWidth, $top + 35);
            $pdf->Cell(max(12, $cardWidth - $valueWidth - 14), 7, (string) $metric['suffix'], 0, 0, 'L');

            $pdf->SetFont('cid0cs', '', 14);
            $pdf->SetTextColor(83, 102, 91);
            $pdf->SetXY($x + 6, $top + 51);
            $pdf->Cell($cardWidth - 12, 7, (string) $metric['label'], 0, 0, 'L');
        }

        $pdf->SetY($top + $cardHeight + 10);
        $pdf->SetFont('cid0cs', '', 12);
        $pdf->SetTextColor(23, 35, 30);
    }

    /**
     * @Name: drawMetricIcon
     * @Description: 用 TCPDF 矢量线条绘制各指标图标，不依赖外部图片或额外字体。
     * @Author: cdkay
     * @CreateTime: 2026-11-17 10:19:49
     * @UpdateTime: 2026-11-17 14:16:10
     * @Param TCPDF $pdf 当前 PDF 文档实例
     * @Param string $type 指标图标类型
     * @Param float $centerX 图标中心横坐标
     * @Param float $centerY 图标中心纵坐标
     * @Param array{int,int,int} $color 图标线条颜色
     * @Return void
     * @Throws RuntimeException TCPDF 无法绘制矢量图标时抛出异常
     */
    private function drawMetricIcon(TCPDF $pdf, string $type, float $centerX, float $centerY, array $color): void
    {
        $pdf->SetDrawColor($color[0], $color[1], $color[2]);
        $pdf->SetFillColor($color[0], $color[1], $color[2]);
        $pdf->SetLineWidth(0.8);

        switch ($type) {
            case 'gauge':
                $gaugeBaseY = $centerY + 2.2;
                $pdf->Line($centerX - 3.3, $gaugeBaseY, $centerX - 2.5, $centerY);
                $pdf->Line($centerX - 2.5, $centerY, $centerX - 1.5, $centerY - 1.8);
                $pdf->Line($centerX - 1.5, $centerY - 1.8, $centerX, $centerY - 2.3);
                $pdf->Line($centerX, $centerY - 2.3, $centerX + 1.5, $centerY - 1.8);
                $pdf->Line($centerX + 1.5, $centerY - 1.8, $centerX + 2.5, $centerY);
                $pdf->Line($centerX + 2.5, $centerY, $centerX + 3.3, $gaugeBaseY);
                $pdf->Line($centerX - 3.3, $gaugeBaseY, $centerX + 3.3, $gaugeBaseY);
                $pdf->Line($centerX, $gaugeBaseY, $centerX + 1.8, $centerY + 0.3);
                break;
            case 'target':
                $pdf->Circle($centerX, $centerY, 3.2, 0, 360, 'D');
                $pdf->Circle($centerX, $centerY, 1.6, 0, 360, 'D');
                $pdf->Circle($centerX, $centerY, 0.45, 0, 360, 'F');
                break;
            case 'rank':
                $pdf->Line($centerX - 3.5, $centerY + 3, $centerX - 3.5, $centerY - 3.5);
                $pdf->Line($centerX - 3.5, $centerY + 3, $centerX + 3.5, $centerY + 3);
                $pdf->Line($centerX - 2.5, $centerY + 1, $centerX - 0.5, $centerY - 1);
                $pdf->Line($centerX - 0.5, $centerY - 1, $centerX + 1.2, $centerY + 0.5);
                $pdf->Line($centerX + 1.2, $centerY + 0.5, $centerX + 3, $centerY - 2.5);
                break;
            case 'count':
                $pdf->Rect($centerX - 3.5, $centerY + 0.5, 1.5, 2.5, 'DF');
                $pdf->Rect($centerX - 0.75, $centerY - 1.5, 1.5, 4.5, 'DF');
                $pdf->Rect($centerX + 2, $centerY - 3.5, 1.5, 6.5, 'DF');
                break;
            case 'sentiment':
                $pdf->Line($centerX - 4, $centerY, $centerX - 2, $centerY);
                $pdf->Line($centerX - 2, $centerY, $centerX - 1, $centerY - 2.5);
                $pdf->Line($centerX - 1, $centerY - 2.5, $centerX + 0.5, $centerY + 2.5);
                $pdf->Line($centerX + 0.5, $centerY + 2.5, $centerX + 1.7, $centerY - 1);
                $pdf->Line($centerX + 1.7, $centerY - 1, $centerX + 2.5, $centerY);
                $pdf->Line($centerX + 2.5, $centerY, $centerX + 4, $centerY);
                break;
        }

        $pdf->SetLineWidth(0.25);
    }

    /**
     * @Name: summaryDetails
     * @Description: 将诊断问题数、平台数及引用数绘制为两行辅助摘要。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 16:16:41
     * @UpdateTime: 2026-11-17 14:39:32
     * @Param list<array{0:string,1:string}> $items 诊断摘要项目
     * @Return string 诊断摘要 HTML
     * @Throws \RuntimeException TCPDF 无法处理摘要时抛出异常
     */
    private function summaryDetails(array $items): string
    {
        $html = '<br /><table width="100%" cellpadding="11" cellspacing="0" bgcolor="#ffffff">';
        foreach (array_chunk($items, 3) as $row) {
            $html .= '<tr>';
            foreach ($row as [$label, $value]) {
                $html .= '<td width="33.3%" valign="top"><table width="100%" cellpadding="4" cellspacing="0">'
                    .'<tr><td><font color="#819087" size="13">'.$this->escape($label).'</font></td></tr>'
                    .'<tr><td><font color="#33473b" size="16"><b>'.$this->escape($value).'</b></font></td></tr>'
                    .'</table></td>';
            }
            $html .= '</tr>';
        }

        return $html.'</table>';
    }

    /**
     * @Name: platformSection
     * @Description: 绘制平台有效回答、提及次数、提及率、排名和情绪分布明细。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 16:16:41
     * @UpdateTime: 2026-11-16 17:22:42
     * @Param list<array<string,mixed>> $platforms 各平台诊断指标
     * @Return string 平台表现区 HTML
     * @Throws \RuntimeException TCPDF 无法处理平台数据时抛出异常
     */
    private function platformSection(array $platforms): string
    {
        $rows = [];
        foreach ($platforms as $platform) {
            $rank = (float) ($platform['average_rank'] ?? 0);
            $rows[] = [
                (string) ($platform['platform'] ?? '未知平台'),
                (string) ($platform['total'] ?? 0),
                (string) ($platform['mentioned'] ?? 0),
                (string) ($platform['mention_rate'] ?? 0).'%',
                $rank > 0 ? rtrim(rtrim(number_format($rank, 2, '.', ''), '0'), '.').' 名' : '—',
                (string) ($platform['positive_rate'] ?? 0).'%',
                (string) ($platform['neutral_rate'] ?? 0).'%',
                (string) ($platform['negative_rate'] ?? 0).'%',
            ];
        }

        return $this->sectionTitle('PLATFORM PERFORMANCE', '平台表现', count($platforms).' 个平台')
            .$this->table(['平台', '有效回答', '提及回答', '提及率', '平均排名', '正向', '中性', '负向'], $rows, [15, 10, 10, 10, 15, 13, 13, 14]);
    }

    /**
     * @Name: questionSection
     * @Description: 按网页版问题表格的字段顺序呈现问题、状态、有效回答、品牌提及率和各平台提及率。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 16:16:41
     * @UpdateTime: 2026-11-17 09:59:34
     * @Param list<array<string,mixed>> $questions 诊断问题数据
     * @Param array<string,string> $platformLabels 平台键到显示名称的映射
     * @Return string 问题明细区 HTML
     * @Throws \RuntimeException TCPDF 无法处理问题数据时抛出异常
     */
    private function questionSection(array $questions, array $platformLabels): string
    {
        $rows = [];
        foreach ($questions as $index => $question) {
            $rates = [];
            foreach ((array) ($question['platform_rates'] ?? []) as $key => $rate) {
                $label = $platformLabels[(string) $key] ?? (string) $key;
                $rates[] = $label.' '.(string) (int) $rate.'%';
            }
            $status = (string) ($question['status'] ?? '') === 'completed' ? '已完成' : (string) ($question['status'] ?? '未完成');
            $rows[] = [
                str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).'  '.(string) ($question['question'] ?? '未记录问题'),
                $status,
                (string) ($question['result_count'] ?? 0),
                (string) ($question['mention_rate'] ?? 0).'%',
                $rates !== [] ? implode(' · ', $rates) : '暂无平台数据',
            ];
        }

        return $this->sectionTitle('QUESTION COVERAGE', '问题明细', count($questions).' 条问题')
            .$this->table(['诊断问题', '状态', '有效回答', '品牌提及率', '平台提及率'], $rows, [36, 12, 12, 14, 26]);
    }

    /**
     * @Name: sourceSection
     * @Description: 按网页版引用来源表格展示来源域名与完整来源地址，并保留可点击的安全外链。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 16:16:41
     * @UpdateTime: 2026-11-17 09:59:34
     * @Param list<array<string,mixed>> $sources AI 引用来源
     * @Return string 引用来源区 HTML
     * @Throws \RuntimeException TCPDF 无法处理来源内容时抛出异常
     */
    private function sourceSection(array $sources): string
    {
        $rows = [];
        foreach ($sources as $source) {
            $url = $this->safeUrl((string) ($source['url'] ?? ''));
            $domain = (string) ($source['domain'] ?? '—');
            $link = $url !== ''
                ? '<a href="'.$this->escape($url).'" style="color:#087640">'.$this->escape($url).'</a>'
                : '—';
            $rows[] = [
                (string) ($source['name'] ?? '未命名来源'),
                $this->sourceTypeLabel((string) ($source['type'] ?? '内容')),
                (string) ($source['citations'] ?? 0),
                (string) ($source['share'] ?? 0).'% ',
                $domain,
                $link,
            ];
        }

        return $this->sectionTitle('AI CITATION SOURCES', '引用来源', count($sources).' 条来源')
            .$this->table(['来源内容', '类型', '引用次数', '占比', '域名', '来源地址'], $rows, [26, 12, 9, 9, 15, 29]);
    }

    /**
     * @Name: conversationSection
     * @Description: 逐条绘制平台回答、问题、回答状态、提及率和完整回答文本。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 16:16:41
     * @UpdateTime: 2026-11-17 15:53:52
     * @Param list<array<string,mixed>> $conversations AI 平台回答记录
     * @Return string 对话明细区 HTML
     * @Throws \RuntimeException TCPDF 无法处理回答内容时抛出异常
     */
    private function conversationSection(array $conversations): string
    {
        $html = $this->sectionTitle('AI CONVERSATIONS', 'AI 问题与对话明细', count($conversations).' 条回答');
        foreach ($conversations as $conversation) {
            $status = (string) ($conversation['status'] ?? '') === 'success' ? '已完成' : (string) ($conversation['status'] ?? '未完成');
            $answer = trim((string) ($conversation['answer'] ?? '')) ?: '未记录回答内容。';
            $answer = str_replace(['**', '__', '~~', '`'], '', $answer);
            $answer = nl2br($this->escape($answer), false);
            $html .= '<table width="100%" border="0" cellpadding="0" cellspacing="0">'
                .'<tr><td width="0.5%" bgcolor="#20a85d"></td><td width="1.5%" bgcolor="#fbfdfb"></td><td width="98%" cellpadding="11" bgcolor="#fbfdfb">'
                .'<font color="#187a46" size="14"><b>'.$this->escape((string) ($conversation['platform'] ?? '未知平台'))
                .'</b></font><font color="#8a9890" size="13">　'.$this->escape((string) ($conversation['time'] ?? '时间未记录'))
                .'　'.$this->escape($status).'</font></td></tr>'
                .'<tr><td width="0.5%" bgcolor="#20a85d"></td><td width="1.5%" bgcolor="#ffffff"></td><td width="98%" cellpadding="11" bgcolor="#ffffff">'
                .'<font color="#304438" size="23"><b>'.$this->escape((string) ($conversation['question'] ?? '未记录问题'))
                .'</b></font><br /><font color="#728279" size="14">AI 回答 · 品牌提及率 '.(int) ($conversation['rate'] ?? 0).'%</font>'
                .'<br /><br />'
                .'</td></tr>'
                .'<tr><td width="0.5%" bgcolor="#20a85d"></td><td width="1.5%" bgcolor="#ffffff"></td><td width="98%" cellpadding="11" bgcolor="#ffffff">'
                .'<font color="#42564a" size="16">'.$answer.'</font></td></tr></table><br /><br />';
        }

        return $html;
    }

    /**
     * @Name: citationSection
     * @Description: 绘制诊断作品引用明细，并将来源地址输出为 PDF 可点击链接。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 16:16:41
     * @UpdateTime: 2026-11-17 09:59:34
     * @Param list<array<string,mixed>> $citations 作品引用记录
     * @Return string 作品引用区 HTML
     * @Throws \RuntimeException TCPDF 无法处理引用数据时抛出异常
     */
    private function citationSection(array $citations): string
    {
        $rows = [];
        foreach ($citations as $citation) {
            $url = $this->safeUrl((string) ($citation['url'] ?? ''));
            $link = $url !== ''
                ? '<a href="'.$this->escape($url).'" style="color:#087640">'.$this->escape($url).'</a>'
                : '—';
            $title = $this->escape((string) ($citation['title'] ?? '未命名作品'));
            $rows[] = [
                $url !== '' ? '<a href="'.$this->escape($url).'" style="color:#28553a">'.$title.'</a>' : $title,
                (string) ($citation['category'] ?? '内容'),
                (string) ($citation['platform'] ?? '—'),
                (string) ($citation['time'] ?? '—'),
                $link,
            ];
        }

        return $this->sectionTitle('CONTENT CITATIONS', '作品引用明细', count($citations).' 条记录')
            .$this->table(['作品或来源', '类型', '引用平台', '诊断时间', '来源地址'], $rows, [27, 12, 13, 17, 31]);
    }

    /**
     * @Name: historySection
     * @Description: 输出当前账号筛选范围内的历史品牌诊断记录，便于对比品牌表现变化。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 16:16:41
     * @UpdateTime: 2026-11-16 16:26:50
     * @Param list<array<string,mixed>> $records 品牌诊断历史记录
     * @Return string 诊断历史区 HTML
     * @Throws \RuntimeException TCPDF 无法处理历史记录时抛出异常
     */
    private function historySection(array $records): string
    {
        $rows = [];
        foreach ($records as $record) {
            $summary = (array) ($record['summary'] ?? []);
            $rows[] = [
                (string) ($record['brand'] ?? '未命名品牌'),
                '#'.(string) ($record['id'] ?? '—'),
                (string) ($record['completed_at'] ?? '未记录'),
                (string) ($summary['brand_score'] ?? 0),
                (string) ($summary['mention_rate'] ?? 0).'%',
                (string) ($summary['average_rank'] ?? 0),
                (string) ($summary['completed_questions'] ?? 0).' / '.(string) ($summary['total_questions'] ?? 0),
            ];
        }

        return $this->sectionTitle('DIAGNOSIS HISTORY', '诊断记录', count($records).' 条记录')
            .$this->table(['品牌', '记录', '完成时间', '品牌得分', '提及率', '平均排名', '完成问题'], $rows, [16, 10, 20, 12, 12, 14, 16]);
    }

    /**
     * @Name: table
     * @Description: 采用网页版浅绿表头、隔行底色和加大行距呈现明细表格。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 16:16:41
     * @UpdateTime: 2026-11-17 11:11:52
     * @Param list<string> $headers 表头内容
     * @Param list<list<string>> $rows 单元格内容
     * @Param list<int> $widths 列宽百分比，合计为 100
     * @Return string 表格 HTML
     * @Throws \RuntimeException TCPDF 无法处理表格数据时抛出异常
     */
    private function table(array $headers, array $rows, array $widths): string
    {
        $html = '<table width="100%" border="0" cellpadding="9" cellspacing="0"><thead><tr bgcolor="#f1f8f3">';
        foreach ($headers as $index => $header) {
            $html .= '<th width="'.$widths[$index].'%" align="left"><font color="#466052" size="14"><b>'.$this->escape($header).'</b></font></th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $rowIndex => $row) {
            $html .= '<tr bgcolor="'.($rowIndex % 2 === 0 ? '#ffffff' : '#f7faf8').'">';
            foreach ($row as $index => $cell) {
                $content = $this->isHtmlCell($cell) ? $cell : $this->escape($cell);
                $html .= '<td width="'.$widths[$index].'%"><font color="#47594e" size="15">'.$content.'</font></td>';
            }
            $html .= '</tr>';
        }
        if ($rows === []) {
            $html .= '<tr><td colspan="'.count($headers).'" cellpadding="14" bgcolor="#fbfdfb"><font color="#829087" size="14">当前筛选范围内暂无明细</font></td></tr>';
        }

        return $html.'</tbody></table><br />';
    }

    /**
     * @Name: escape
     * @Description: 转义来自诊断文本、账号资料和来源地址的 HTML 字符，防止报告文本被解释为标签。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 16:16:41
     * @UpdateTime: 2026-11-16 16:26:50
     * @Param string $value 原始文本
     * @Return string UTF-8 HTML 安全文本
     * @Throws \ValueError 输入编码无效时由 PHP 字符串函数抛出异常
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @Name: safeUrl
     * @Description: 仅允许 HTTP 和 HTTPS 外链进入 PDF 锚点，拒绝脚本协议和相对地址。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 16:16:41
     * @UpdateTime: 2026-11-16 16:26:50
     * @Param string $value 待检查的来源地址
     * @Return string 合法绝对 URL，不合法时返回空字符串
     * @Throws 无
     */
    private function safeUrl(string $value): string
    {
        $url = trim($value);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : '';
    }

    /**
     * @Name: isHtmlCell
     * @Description: 判断表格单元格是否包含由 safeUrl 生成的可信链接标记。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 16:16:41
     * @UpdateTime: 2026-11-16 16:26:50
     * @Param string $value 表格单元格文本
     * @Return bool 单元格是否由服务端安全构建为 HTML
     * @Throws 无
     */
    private function isHtmlCell(string $value): bool
    {
        return str_starts_with($value, '<a href="https://') || str_starts_with($value, '<a href="http://');
    }

    /**
     * @Name: sourceTypeLabel
     * @Description: 将来源数据中的内部类型键转换为报告使用的简明中文名称。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 17:16:56
     * @UpdateTime: 2026-11-16 17:22:42
     * @Param string $type 来源内部类型键
     * @Return string 来源类型显示名称
     * @Throws 无
     */
    private function sourceTypeLabel(string $type): string
    {
        return match ($type) {
            'url_citation' => '网页引用',
            'web_search_result' => '网页搜索',
            default => $type !== '' ? $type : '内容',
        };
    }
}
