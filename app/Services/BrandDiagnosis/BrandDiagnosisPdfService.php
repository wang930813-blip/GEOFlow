<?php

namespace App\Services\BrandDiagnosis;

use App\Models\BrandDiagnosisRun;
use Illuminate\Support\Str;
use TCPDF;

class BrandDiagnosisPdfService
{
    private const LEFT = 18.0;

    private const TOP = 16.0;

    private const WIDTH = 174.0;

    private const BOTTOM = 278.0;

    private TCPDF $pdf;

    /**
     * @param  array<string,mixed>  $record
     */
    public function render(BrandDiagnosisRun $run, array $record, string $fileName): string
    {
        $this->pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdf->SetCreator('GEOFlow');
        $this->pdf->SetAuthor('GEOFlow');
        $this->pdf->SetTitle($fileName);
        $this->pdf->SetSubject('品牌诊断报告');
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);
        $this->pdf->SetMargins(self::LEFT, self::TOP, self::LEFT);
        $this->pdf->SetAutoPageBreak(false);
        $this->pdf->setFontSubsetting(true);
        $this->pdf->AddPage();

        $data = $this->prepareData($record);

        $y = self::TOP;
        $y = $this->drawHero($record, $fileName, $data, $y);
        $y = $this->drawSectionTitle('整体表现', '推荐率表示有明确品牌位次的有效回答占比；Top1 按位次为 1 计算，Top3/Top5 分别按位次不高于 3/5 计算，分母均为成功回答。', $y + 9);
        $y = $this->drawMetricCards($data['metrics'], $y);
        $y = $this->drawRankingMetricCards($data['analytics_metrics'], $y + 4);
        $y = $this->drawPlatformCards($data['platforms'], $y + 4);
        $y = $this->drawSectionTitle('AI 可见度分析', '展示本次诊断中竞品和目标品牌在 AI 回答与引用内容中的提及强度。', $y + 8);
        $y = $this->drawVisibility($data, $y);
        $this->ensureSpace(39, $y);
        $y = $this->drawSectionTitle('问题维度表现', '按问题类型拆分有效回答、目标品牌提及、推荐位次与平均排名。', $y + 8);
        $y = $this->drawDimensionRows($data['dimensions'], $y);
        $this->ensureSpace(39, $y);
        $y = $this->drawSectionTitle('竞品表现', '竞品指标以全部有效回答为分母，同一回答中的同一竞品只计一次。', $y + 8);
        $y = $this->drawCompetitorRows($data['analysis_competitors'], $y);
        $this->ensureSpace(39, $y);
        $y = $this->drawSectionTitle('信源平台分布', '引用域名按内容平台归类，展示引用次数、占比和覆盖域名数。', $y + 8);
        $y = $this->drawSourcePlatformRows($data['source_platforms'], $y);
        $y = $this->drawSectionTitle('引用源分析', '引用源代表 AI 回答中可追溯的网页、文章或搜索结果。', $y + 8);
        $y = $this->drawSources($data['sources'], $y);
        $y = $this->drawSectionTitle('AI 问题与对话明细', '展示本次用于诊断的问题、平台回答、提及品牌和引用记录。', $y + 8);
        $y = $this->drawConversations($data['conversations'], $y);
        $y = $this->drawSectionTitle('优化建议', '基于本次诊断数据生成的优先处理方向。', $y + 8);
        $this->drawRecommendations($data['recommendations'], $y);

        return $this->pdf->Output($fileName, 'S');
    }

    /**
     * @param  array<string,mixed>  $record
     * @return array<string,mixed>
     */
    private function prepareData(array $record): array
    {
        $metrics = (array) ($record['metrics'] ?? []);
        $analytics = (array) ($record['analytics'] ?? []);
        $rankings = (array) ($record['rankings'] ?? []);
        $sources = collect(array_slice((array) ($record['sources'] ?? []), 0, 8))
            ->map(function ($source): array {
                $source = (array) $source;
                $source['logo_path'] = BrandDiagnosisPlatform::logoAbsolutePath((string) ($source['platform_key'] ?? ''));

                return $source;
            })
            ->values()
            ->all();
        $conversations = collect(array_slice((array) ($record['conversations'] ?? []), 0, 6))
            ->map(function ($conversation): array {
                $conversation = (array) $conversation;
                $conversation['platform_logo_path'] = BrandDiagnosisPlatform::logoAbsolutePath((string) ($conversation['platform_key'] ?? ''));

                return $conversation;
            })
            ->values()
            ->all();
        $platformData = (array) ($record['platform_data'] ?? []);
        $platformOptions = collect((array) ($record['platform_options'] ?? []))
            ->reject(static fn (array $option): bool => ($option['value'] ?? '') === 'all')
            ->values();

        $platforms = $platformOptions
            ->map(function (array $option) use ($platformData): array {
                $value = (string) ($option['value'] ?? '');

                return [
                    'label' => (string) ($option['label'] ?? $option['value'] ?? '平台'),
                    'logo_path' => BrandDiagnosisPlatform::logoAbsolutePath($value),
                    'metrics' => (array) ($platformData[$value]['metrics'] ?? []),
                    'analytics_metrics' => (array) ($platformData[$value]['analytics']['metrics'] ?? []),
                ];
            })
            ->values()
            ->all();

        $competitors = collect((array) ($rankings['mention_count'] ?? []))
            ->reject(static fn (array $row): bool => (bool) ($row['is_target_brand'] ?? false))
            ->take(8)
            ->values()
            ->all();

        return [
            'metrics' => [
                'score' => (int) ($metrics['score'] ?? 0),
                'mention_rate' => (int) ($metrics['mention_rate'] ?? 0),
                'average_rank' => (string) ($metrics['average_rank'] ?? '0'),
                'mention_count' => (int) ($metrics['mention_count'] ?? 0),
                'sentiment_rate' => (int) ($metrics['sentiment_rate'] ?? 0),
            ],
            'analytics_metrics' => (array) ($analytics['metrics'] ?? []),
            'dimensions' => (array) ($analytics['dimensions'] ?? []),
            'analysis_competitors' => (array) ($analytics['competitors'] ?? []),
            'source_platforms' => (array) ($analytics['source_platforms'] ?? []),
            'platform_labels' => $platformOptions->pluck('label')->implode('、') ?: '全部平台',
            'platforms' => $platforms,
            'competitors' => $competitors,
            'target_rate' => collect((array) ($rankings['mention_rate'] ?? []))->firstWhere('is_target_brand', true) ?? [],
            'target_count' => collect((array) ($rankings['mention_count'] ?? []))->firstWhere('is_target_brand', true) ?? [],
            'target_rank' => collect((array) ($rankings['average_rank'] ?? []))->firstWhere('is_target_brand', true) ?? [],
            'sources' => $sources,
            'conversations' => $conversations,
            'recommendations' => $this->recommendations($metrics, count($sources)),
        ];
    }

    /**
     * @param  array<string,mixed>  $record
     * @param  array<string,mixed>  $data
     */
    private function drawHero(array $record, string $fileName, array $data, float $y): float
    {
        $x = self::LEFT;
        $w = self::WIDTH;
        $h = 58.0;
        $heroTitleWidth = 108.0;

        $this->pdf->SetFillColor(3, 7, 22);
        $this->pdf->RoundedRect($x, $y, $w, $h, 2.5, '1111', 'F');

        $this->text('AI 搜索可见度诊断', $x + 7, $y + 6, 58, 5, 8, [254, 215, 170], 'B');
        $this->multiText((string) ($record['brand'] ?? '-'), $x + 7, $y + 13.5, $heroTitleWidth, 14, 17.5, [255, 255, 255], 'B');
        $this->multiText('本报告基于本次品牌诊断采集的问题、AI 平台回答、引用来源与品牌提及数据生成，用于判断品牌在 AI 搜索场景下的可见度、提及强度、排名位置和内容引用基础。', $x + 7, $y + 30.5, 104, 10, 7.2, [226, 232, 240]);

        $scorePanelWidth = 40.0;
        $scoreX = $x + 124;
        $scoreY = $y + 10;
        $this->pdf->SetFillColor(15, 23, 42);
        $this->pdf->SetDrawColor(30, 41, 59);
        $this->pdf->SetLineWidth(0.2);
        $this->pdf->RoundedRect($scoreX, $scoreY, $scorePanelWidth, 31, 4, '1111', 'DF');
        $this->pdf->SetDrawColor(249, 115, 22);
        $this->pdf->SetLineWidth(0.7);
        $this->pdf->Line($scoreX + 5, $scoreY + 7, $scoreX + $scorePanelWidth - 5, $scoreY + 7);
        $this->text('品牌得分', $scoreX + 5, $scoreY + 3.2, $scorePanelWidth - 10, 4, 6.3, [254, 215, 170], 'B', 'C');
        $this->text((string) $data['metrics']['score'], $scoreX + 5, $scoreY + 10.5, $scorePanelWidth - 10, 8, 18, [255, 255, 255], 'B', 'C');
        $this->text('/ 100', $scoreX + 5, $scoreY + 19.8, $scorePanelWidth - 10, 4, 6.3, [203, 213, 225], '', 'C');
        $this->text('综合表现', $scoreX + 5, $scoreY + 24.5, $scorePanelWidth - 10, 4, 5.8, [148, 163, 184], '', 'C');
        $this->pdf->SetLineWidth(0.2);

        $metaY = $y + 45;
        $this->heroMeta('报告文件', $fileName, $x + 7, $metaY, 58);
        $this->heroMeta('诊断时间', (string) ($record['created_at'] ?? '-'), $x + 72, $metaY, 40);
        $this->heroMeta('数据平台', (string) $data['platform_labels'], $x + 121, $metaY, 43);

        return $y + $h;
    }

    private function heroMeta(string $label, string $value, float $x, float $y, float $w): void
    {
        $this->text($label, $x, $y, $w, 3.5, 6, [148, 163, 184]);
        $this->text($this->short($value, 42), $x, $y + 4.2, $w, 4, 7.5, [255, 255, 255], 'B');
    }

    private function drawSectionTitle(string $title, string $desc, float $y): float
    {
        $this->ensureSpace(18, $y);
        $this->text($title, self::LEFT, $y, self::WIDTH, 6, 15, [15, 23, 42], 'B');
        $this->multiText($desc, self::LEFT, $y + 7, self::WIDTH, 8, 8, [71, 85, 105]);

        return $y + 17;
    }

    /**
     * @param  array<string,mixed>  $metrics
     */
    private function drawMetricCards(array $metrics, float $y): float
    {
        $cards = [
            ['label' => '品牌得分', 'value' => $metrics['score'], 'suffix' => '/100', 'color' => [249, 115, 22]],
            ['label' => '品牌提及率', 'value' => $metrics['mention_rate'], 'suffix' => '%', 'color' => [37, 99, 235]],
            ['label' => '平均提及排名', 'value' => $metrics['average_rank'], 'suffix' => '名', 'color' => [79, 70, 229]],
            ['label' => '品牌提及次数', 'value' => $metrics['mention_count'], 'suffix' => '次', 'color' => [5, 150, 105]],
            ['label' => '正面/中性倾向', 'value' => $metrics['sentiment_rate'], 'suffix' => '%', 'color' => [225, 29, 72]],
        ];
        $gap = 3.0;
        $cardW = (self::WIDTH - ($gap * 4)) / 5;
        $cardH = 24.0;

        foreach ($cards as $index => $card) {
            $x = self::LEFT + (($cardW + $gap) * $index);
            $this->card($x, $y, $cardW, $cardH);
            $this->text((string) $card['value'], $x + 4, $y + 6, $cardW - 8, 8, 19, $card['color'], 'B');
            $this->text((string) $card['suffix'], $x + 4 + min(17, strlen((string) $card['value']) * 4.3), $y + 10.2, 12, 4, 7, [71, 85, 105], 'B');
            $this->text((string) $card['label'], $x + 4, $y + 17.5, $cardW - 8, 4, 7.5, [51, 65, 85]);
        }

        return $y + $cardH;
    }

    /**
     * @Name: drawRankingMetricCards
     *
     * @Description: 在 PDF 整体表现区展示推荐率和 Top1/Top3/Top5，百分比分母统一为有效回答数。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-20 18:03:19
     *
     * @UpdateTime: 2026-11-20 18:15:47
     *
     * @Param: array<string,mixed> $metrics 基于成功回答计算的推荐位次指标
     *
     * @Param: float $y 指标卡片区域起始纵坐标
     *
     * @Return: float 卡片区域底部纵坐标
     *
     * @Throws: 无
     */
    private function drawRankingMetricCards(array $metrics, float $y): float
    {
        $cards = [
            ['label' => '推荐率', 'key' => 'recommendation_rate', 'count' => 'recommendation_count', 'color' => [5, 150, 105]],
            ['label' => 'Top1', 'key' => 'top1_rate', 'count' => 'top1_count', 'color' => [217, 119, 6]],
            ['label' => 'Top3', 'key' => 'top3_rate', 'count' => 'top3_count', 'color' => [79, 70, 229]],
            ['label' => 'Top5', 'key' => 'top5_rate', 'count' => 'top5_count', 'color' => [225, 29, 72]],
        ];
        $gap = 3.0;
        $cardW = (self::WIDTH - ($gap * 3)) / 4;
        $cardH = 25.0;
        $answerCount = (int) ($metrics['answer_count'] ?? 0);

        foreach ($cards as $index => $card) {
            $x = self::LEFT + (($cardW + $gap) * $index);
            $this->card($x, $y, $cardW, $cardH);
            $this->text(number_format((float) ($metrics[$card['key']] ?? 0), 1).'%', $x + 3, $y + 4.2, $cardW - 6, 7, 13, $card['color'], 'B');
            $this->text($card['label'], $x + 3, $y + 13, $cardW - 6, 4, 7.5, [51, 65, 85], 'B');
            $this->text(((int) ($metrics[$card['count']] ?? 0)).' / '.$answerCount.' 条有效回答', $x + 3, $y + 18.2, $cardW - 6, 3.5, 5.8, [100, 116, 139]);
        }

        return $y + $cardH;
    }

    /**
     * @param  list<array{label:string,metrics:array<string,mixed>}>  $platforms
     */
    private function drawPlatformCards(array $platforms, float $y): float
    {
        if ($platforms === []) {
            return $y;
        }

        $gap = 3.0;
        $cardW = (self::WIDTH - ($gap * 3)) / 4;
        $rowHeight = 32.0;

        foreach (array_chunk($platforms, 4) as $platformRow) {
            $this->ensureSpace($rowHeight, $y);
            foreach ($platformRow as $index => $platform) {
                $x = self::LEFT + (($cardW + $gap) * $index);
                $metrics = $platform['metrics'];
                $this->card($x, $y, $cardW, 28);
                $labelX = $x + 3;
                $labelWidth = $cardW - 21;
                if ($this->drawLogo((string) ($platform['logo_path'] ?? ''), $x + 3, $y + 3.1, 5.4)) {
                    $labelX = $x + 10;
                    $labelWidth = $cardW - 28;
                }
                $this->text($this->short((string) $platform['label'], 8), $labelX, $y + 4, $labelWidth, 4, 8, [15, 23, 42], 'B');
                $this->pill('平台', $x + $cardW - 16, $y + 3.5, 12);
                $metricW = ($cardW - 6) / 3;
                $this->text(((int) ($metrics['mention_rate'] ?? 0)).'%', $x + 3, $y + 11, $metricW, 4, 8.2, [234, 88, 12], 'B', 'C');
                $this->text((string) ((int) ($metrics['mention_count'] ?? 0)), $x + 3 + $metricW, $y + 11, $metricW, 4, 8.2, [15, 23, 42], 'B', 'C');
                $this->text((string) ($metrics['average_rank'] ?? '0'), $x + 3 + ($metricW * 2), $y + 11, $metricW, 4, 8.2, [15, 23, 42], 'B', 'C');
                $this->text('提及率', $x + 3, $y + 15.2, $metricW, 3.2, 5.6, [71, 85, 105], '', 'C');
                $this->text('次数', $x + 3 + $metricW, $y + 15.2, $metricW, 3.2, 5.6, [71, 85, 105], '', 'C');
                $this->text('排名', $x + 3 + ($metricW * 2), $y + 15.2, $metricW, 3.2, 5.6, [71, 85, 105], '', 'C');
                $analyticsMetrics = (array) ($platform['analytics_metrics'] ?? []);
                $rankMetrics = [
                    ['label' => '推荐', 'key' => 'recommendation_rate'],
                    ['label' => 'Top1', 'key' => 'top1_rate'],
                    ['label' => 'Top3', 'key' => 'top3_rate'],
                    ['label' => 'Top5', 'key' => 'top5_rate'],
                ];
                $rankMetricW = ($cardW - 6) / 4;
                foreach ($rankMetrics as $rankIndex => $rankMetric) {
                    $rankX = $x + 3 + ($rankMetricW * $rankIndex);
                    $this->text(number_format((float) ($analyticsMetrics[$rankMetric['key']] ?? 0), 1).'%', $rankX, $y + 20, $rankMetricW, 3.5, 5.8, [15, 23, 42], 'B', 'C');
                    $this->text($rankMetric['label'], $rankX, $y + 24, $rankMetricW, 3, 5.1, [71, 85, 105], '', 'C');
                }
            }
            $y += $rowHeight;
        }

        return $y;
    }

    /**
     * @Name: drawDimensionRows
     *
     * @Description: 输出问题维度指标表，空间不足时分页并重复表头，避免维度行被截断。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-20 18:03:19
     *
     * @UpdateTime: 2026-11-20 18:15:47
     *
     * @Param: list<array<string,mixed>> $rows 按问题类型聚合的表现数据
     *
     * @Param: float $y 表格起始纵坐标
     *
     * @Return: float 表格底部纵坐标
     *
     * @Throws: 无
     */
    private function drawDimensionRows(array $rows, float $y): float
    {
        $columns = [34, 15, 17, 20, 20, 15, 15, 15, 23];
        $labels = ['问题维度', '问题数', '有效回答', '提及率', '推荐率', 'Top1', 'Top3', 'Top5', '平均位次'];
        $rows = $rows ?: [['dimension' => '暂无有效回答']];
        $this->tableHeader(self::LEFT, $y, $columns, $labels);
        $y += 7;

        foreach ($rows as $row) {
            $this->ensureSpace(8, $y);
            if ($y === self::TOP) {
                $this->tableHeader(self::LEFT, $y, $columns, $labels);
                $y += 7;
            }
            $this->tableRow(self::LEFT, $y, $columns, [
                $this->short((string) ($row['dimension'] ?? '未分类'), 14),
                (string) (int) ($row['question_count'] ?? 0),
                (string) (int) ($row['answer_count'] ?? 0),
                number_format((float) ($row['mention_rate'] ?? 0), 1).'%',
                number_format((float) ($row['recommendation_rate'] ?? 0), 1).'%',
                number_format((float) ($row['top1_rate'] ?? 0), 1).'%',
                number_format((float) ($row['top3_rate'] ?? 0), 1).'%',
                number_format((float) ($row['top5_rate'] ?? 0), 1).'%',
                ($row['average_rank'] ?? null) !== null ? number_format((float) $row['average_rank'], 1).' 名' : '—',
            ]);
            $y += 7;
        }

        return $y;
    }

    /**
     * @Name: drawCompetitorRows
     *
     * @Description: 输出竞品提及覆盖和推荐位次表，竞品指标与总体报告共用有效回答分母。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-20 18:03:19
     *
     * @UpdateTime: 2026-11-20 18:15:47
     *
     * @Param: list<array<string,mixed>> $rows 按回答覆盖排序的竞品表现数据
     *
     * @Param: float $y 表格起始纵坐标
     *
     * @Return: float 表格底部纵坐标
     *
     * @Throws: 无
     */
    private function drawCompetitorRows(array $rows, float $y): float
    {
        $columns = [40, 18, 18, 23, 16, 16, 16, 27];
        $labels = ['竞品', '出现回答', '提及次数', '推荐率', 'Top1', 'Top3', 'Top5', '平均位次'];
        $rows = $rows ?: [['brand' => '本次未识别到竞品']];
        $this->tableHeader(self::LEFT, $y, $columns, $labels);
        $y += 7;

        foreach ($rows as $row) {
            $this->ensureSpace(8, $y);
            if ($y === self::TOP) {
                $this->tableHeader(self::LEFT, $y, $columns, $labels);
                $y += 7;
            }
            $this->tableRow(self::LEFT, $y, $columns, [
                $this->short((string) ($row['brand'] ?? '本次未识别到竞品'), 18),
                (string) (int) ($row['answer_count'] ?? 0),
                (string) (int) ($row['mention_count'] ?? 0),
                number_format((float) ($row['recommendation_rate'] ?? 0), 1).'%',
                number_format((float) ($row['top1_rate'] ?? 0), 1).'%',
                number_format((float) ($row['top3_rate'] ?? 0), 1).'%',
                number_format((float) ($row['top5_rate'] ?? 0), 1).'%',
                ($row['average_rank'] ?? null) !== null ? number_format((float) $row['average_rank'], 1).' 名' : '—',
            ]);
            $y += 7;
        }

        return $y;
    }

    /**
     * @Name: drawSourcePlatformRows
     *
     * @Description: 输出域名归类后的信源平台次数、占比、域名覆盖和关联模型列表。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-20 18:03:19
     *
     * @UpdateTime: 2026-11-20 18:15:47
     *
     * @Param: list<array<string,mixed>> $rows 按信源出现次数排序的平台分布数据
     *
     * @Param: float $y 表格起始纵坐标
     *
     * @Return: float 表格底部纵坐标
     *
     * @Throws: 无
     */
    private function drawSourcePlatformRows(array $rows, float $y): float
    {
        $columns = [34, 20, 19, 24, 18, 59];
        $labels = ['信源平台', '类别', '出现次数', '信源占比', '覆盖域名', '诊断模型'];
        $rows = $rows ?: [['platform' => '暂无可归类的信源']];
        $this->tableHeader(self::LEFT, $y, $columns, $labels);
        $y += 7;

        foreach ($rows as $row) {
            $this->ensureSpace(8, $y);
            if ($y === self::TOP) {
                $this->tableHeader(self::LEFT, $y, $columns, $labels);
                $y += 7;
            }
            $models = implode('、', (array) ($row['models'] ?? []));
            $this->tableRow(self::LEFT, $y, $columns, [
                $this->short((string) ($row['platform'] ?? '暂无可归类的信源'), 14),
                $this->short((string) ($row['type'] ?? '其他'), 8),
                (string) (int) ($row['count'] ?? 0),
                number_format((float) ($row['share'] ?? 0), 1).'%',
                (string) (int) ($row['domain_count'] ?? 0),
                $this->short($models !== '' ? $models : '—', 26),
            ]);
            $y += 7;
        }

        return $y;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function drawVisibility(array $data, float $y): float
    {
        $this->ensureSpace(56, $y);
        $leftW = 108.0;
        $rightW = self::WIDTH - $leftW - 5;
        $this->card(self::LEFT, $y, $leftW, 54);
        $this->text('竞品提及次数 Top 8', self::LEFT + 4, $y + 4, 80, 5, 9, [15, 23, 42], 'B');

        $rows = (array) $data['competitors'];
        if ($rows === []) {
            $this->text('暂无竞品提及数据', self::LEFT + 4, $y + 25, $leftW - 8, 5, 8, [100, 116, 139], '', 'C');
        }

        $max = max([1, ...array_map(static fn (array $row): int => (int) ($row['count'] ?? 0), $rows)]);
        foreach ($rows as $index => $row) {
            $rowY = $y + 12 + ($index * 4.6);
            $count = (int) ($row['count'] ?? 0);
            $barW = max(4, (($leftW - 62) * $count) / $max);
            $this->text((string) ($row['display_rank'] ?? ($index + 1)), self::LEFT + 4, $rowY, 8, 3.5, 6.5, [100, 116, 139]);
            $this->text($this->short((string) ($row['brand'] ?? '-'), 14), self::LEFT + 12, $rowY, 34, 3.5, 7, [51, 65, 85], 'B');
            $this->pdf->SetFillColor(226, 232, 240);
            $this->pdf->RoundedRect(self::LEFT + 48, $rowY + 1.2, $leftW - 62, 1.6, 0.8, '1111', 'F');
            $this->pdf->SetFillColor(37, 99, 235);
            $this->pdf->RoundedRect(self::LEFT + 48, $rowY + 1.2, $barW, 1.6, 0.8, '1111', 'F');
            $this->text((string) $count, self::LEFT + $leftW - 12, $rowY, 8, 3.5, 7, [29, 78, 216], 'B', 'R');
        }

        $x = self::LEFT + $leftW + 5;
        $this->targetBox($x, $y, $rightW, '目标品牌提及率排名', (string) ($data['target_rate']['display_rank'] ?? '99+'), (($data['target_rate']['rate'] ?? 0).' % 提及率'));
        $this->targetBox($x, $y + 18, $rightW, '目标品牌提及次数排名', (string) ($data['target_count']['display_rank'] ?? '99+'), (($data['target_count']['count'] ?? 0).' 次提及'));
        $this->targetBox($x, $y + 36, $rightW, '目标品牌平均提及排名', (string) ($data['target_rank']['display_rank'] ?? '99+'), ('平均第 '.($data['target_rank']['rank'] ?? '0').' 名'));

        return $y + 54;
    }

    private function targetBox(float $x, float $y, float $w, string $label, string $value, string $desc): void
    {
        $this->pdf->SetFillColor(255, 247, 237);
        $this->pdf->SetDrawColor(254, 215, 170);
        $this->pdf->RoundedRect($x, $y, $w, 15, 2, '1111', 'DF');
        $this->text($label, $x + 4, $y + 3, $w - 8, 3.5, 6.8, [194, 65, 12], 'B');
        $this->text($value, $x + 4, $y + 7.4, 17, 5, 13, [234, 88, 12], 'B');
        $this->text($desc, $x + 22, $y + 8.5, $w - 26, 3.5, 7, [124, 45, 18]);
    }

    /**
     * @param  list<array<string,mixed>>  $sources
     */
    private function drawSources(array $sources, float $y): float
    {
        $this->ensureSpace(44, $y);
        $columns = [74, 34, 25, 41];
        $x = self::LEFT;
        $this->tableHeader($x, $y, $columns, ['标题', '来源域名', '平台', 'URL']);
        $y += 6.5;
        $rows = $sources ?: [['title' => '暂无引用来源数据', 'category' => '-', 'models' => '-', 'url' => '-']];
        foreach (array_slice($rows, 0, 7) as $row) {
            $this->tableRow($x, $y, $columns, [
                $this->short((string) ($row['title'] ?? '-'), 30),
                $this->short((string) ($row['category'] ?? '-'), 16),
                $this->short((string) ($row['models'] ?? $row['platform'] ?? '-'), 10),
                $this->short((string) ($row['url'] ?? '-'), 23),
            ]);
            $y += 6;
        }

        return $y;
    }

    /**
     * @param  list<array<string,mixed>>  $conversations
     */
    private function drawConversations(array $conversations, float $y): float
    {
        if ($conversations === []) {
            $this->ensureSpace(14, $y);
            $this->card(self::LEFT, $y, self::WIDTH, 12);
            $this->text('暂无 AI 对话记录', self::LEFT + 5, $y + 4, self::WIDTH - 10, 4, 8, [100, 116, 139]);

            return $y + 12;
        }

        foreach (array_slice($conversations, 0, 4) as $conversation) {
            $this->ensureSpace(28, $y);
            $h = 24.0;
            $this->card(self::LEFT, $y, self::WIDTH, $h);
            $platformPillX = self::LEFT + 5;
            if ($this->drawLogo((string) ($conversation['platform_logo_path'] ?? ''), self::LEFT + 5, $y + 3.7, 5.2)) {
                $platformPillX = self::LEFT + 12;
            }
            $this->pill((string) ($conversation['platform'] ?? 'AI'), $platformPillX, $y + 4, 18, [255, 247, 237], [194, 65, 12]);
            $questionX = $platformPillX + 21;
            $this->text($this->short((string) ($conversation['question'] ?? '-'), 42), $questionX, $y + 4, self::LEFT + self::WIDTH - $questionX - 5, 4, 8, [15, 23, 42], 'B');
            $this->multiText($this->short((string) ($conversation['answer'] ?: '暂无回答内容。'), 118), self::LEFT + 5, $y + 10, self::WIDTH - 10, 8, 7.2, [51, 65, 85]);
            $brands = implode('、', array_slice((array) ($conversation['brands'] ?? []), 0, 8));
            $this->text('提及品牌：'.($brands !== '' ? $brands : '未提及品牌'), self::LEFT + 5, $y + 19, self::WIDTH - 10, 3.5, 6.5, [100, 116, 139]);
            $y += $h + 3;
        }

        return $y;
    }

    /**
     * @param  list<string>  $recommendations
     */
    private function drawRecommendations(array $recommendations, float $y): void
    {
        foreach (array_slice($recommendations, 0, 5) as $index => $recommendation) {
            $this->ensureSpace(16, $y);
            $this->card(self::LEFT, $y, self::WIDTH, 14);
            $this->pdf->SetFillColor(249, 115, 22);
            $this->pdf->RoundedRect(self::LEFT + 5, $y + 4.2, 6, 6, 1.2, '1111', 'F');
            $this->text((string) ($index + 1), self::LEFT + 5, $y + 4.7, 6, 4, 7, [255, 255, 255], 'B', 'C');
            $this->multiText($recommendation, self::LEFT + 14, $y + 3.8, self::WIDTH - 19, 7, 7.4, [51, 65, 85]);
            $y += 16;
        }
    }

    private function card(float $x, float $y, float $w, float $h): void
    {
        $this->pdf->SetFillColor(255, 255, 255);
        $this->pdf->SetDrawColor(226, 232, 240);
        $this->pdf->SetLineWidth(0.18);
        $this->pdf->RoundedRect($x, $y, $w, $h, 2, '1111', 'DF');
    }

    /**
     * @param  list<float|int>  $widths
     * @param  list<string>  $labels
     */
    private function tableHeader(float $x, float $y, array $widths, array $labels): void
    {
        $this->pdf->SetFillColor(248, 250, 252);
        $this->pdf->SetDrawColor(226, 232, 240);
        $cursor = $x;
        foreach ($labels as $index => $label) {
            $w = (float) $widths[$index];
            $this->pdf->Rect($cursor, $y, $w, 6.5, 'DF');
            $this->text($label, $cursor + 2, $y + 1.6, $w - 4, 3.5, 7, [71, 85, 105], 'B');
            $cursor += $w;
        }
    }

    /**
     * @param  list<float|int>  $widths
     * @param  list<string>  $values
     */
    private function tableRow(float $x, float $y, array $widths, array $values): void
    {
        $this->pdf->SetFillColor(255, 255, 255);
        $this->pdf->SetDrawColor(226, 232, 240);
        $cursor = $x;
        foreach ($values as $index => $value) {
            $w = (float) $widths[$index];
            $this->pdf->Rect($cursor, $y, $w, 6, 'D');
            $this->text($value, $cursor + 2, $y + 1.5, $w - 4, 3.4, 6.6, [51, 65, 85]);
            $cursor += $w;
        }
    }

    private function drawLogo(string $path, float $x, float $y, float $size): bool
    {
        if ($path === '' || ! is_file($path)) {
            return false;
        }

        if ($this->pngHasAlphaChannel($path) && ! $this->canTcpdfHandlePngAlpha()) {
            return false;
        }

        $this->pdf->Image($path, $x, $y, $size, $size);

        return true;
    }

    private function canTcpdfHandlePngAlpha(): bool
    {
        return extension_loaded('gd') || extension_loaded('imagick');
    }

    private function pngHasAlphaChannel(string $path): bool
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'png') {
            return false;
        }

        $header = file_get_contents($path, false, null, 0, 65536);
        if (! is_string($header) || strlen($header) < 29 || ! str_starts_with($header, "\x89PNG\r\n\x1a\n")) {
            return false;
        }

        $colorType = ord($header[25]);

        return in_array($colorType, [4, 6], true) || str_contains($header, 'tRNS');
    }

    private function pill(string $text, float $x, float $y, float $w, array $fill = [241, 245, 249], array $color = [71, 85, 105]): void
    {
        $this->pdf->SetFillColor($fill[0], $fill[1], $fill[2]);
        $this->pdf->RoundedRect($x, $y, $w, 4.2, 1, '1111', 'F');
        $this->text($text, $x, $y + 0.8, $w, 2.8, 5.8, $color, 'B', 'C');
    }

    private function ensureSpace(float $needed, float &$y): void
    {
        if ($y + $needed <= self::BOTTOM) {
            return;
        }

        $this->pdf->AddPage();
        $y = self::TOP;
    }

    private function text(string $text, float $x, float $y, float $w, float $h, float $size, array $color, string $style = '', string $align = 'L'): void
    {
        $this->pdf->SetFont('cid0cs', $style, $size);
        $this->pdf->SetTextColor($color[0], $color[1], $color[2]);
        $this->pdf->SetXY($x, $y);
        $this->pdf->Cell($w, $h, $text, 0, 0, $align, false, '', 0, false, 'T', 'M');
    }

    private function multiText(string $text, float $x, float $y, float $w, float $h, float $size, array $color, string $style = '', string $align = 'L'): void
    {
        $this->pdf->SetFont('cid0cs', $style, $size);
        $this->pdf->SetTextColor($color[0], $color[1], $color[2]);
        $this->pdf->MultiCell($w, $h, $text, 0, $align, false, 0, $x, $y, true, 0, false, true, $h, 'T', true);
    }

    private function short(string $value, int $limit): string
    {
        return Str::of($value)->squish()->limit($limit, '...')->toString();
    }

    /**
     * @param  array<string,mixed>  $metrics
     * @return list<string>
     */
    private function recommendations(array $metrics, int $sourceCount): array
    {
        $items = [];
        $mentionRate = (int) ($metrics['mention_rate'] ?? 0);
        $mentionCount = (int) ($metrics['mention_count'] ?? 0);
        $averageRank = (float) ($metrics['average_rank'] ?? 0);
        $sentimentRate = (int) ($metrics['sentiment_rate'] ?? 0);

        if ($mentionRate <= 0) {
            $items[] = '目标品牌当前未被 AI 回答或引用来源有效提及，优先补充品牌介绍页、案例页、FAQ 与第三方可检索内容。';
        } elseif ($mentionRate < 30) {
            $items[] = '目标品牌提及率偏低，建议围绕高频问题建设可被引用的品牌内容，并增加行业词、场景词覆盖。';
        }

        if ($mentionCount < 3) {
            $items[] = '目标品牌提及次数较少，建议在权威来源、案例报道和对比型内容中稳定出现品牌全称与核心服务描述。';
        }

        if ($averageRank <= 0 || $averageRank > 3) {
            $items[] = '目标品牌平均排名未进入靠前位置，建议补充竞品对比、差异化卖点和可验证结果，提升被推荐优先级。';
        }

        if ($sentimentRate < 80) {
            $items[] = '情感倾向仍有优化空间，建议增加正向案例、客户评价、资质背书和风险澄清内容。';
        }

        if ($sourceCount < 3) {
            $items[] = '引用来源样本不足，建议增加可被搜索引擎和 AI 平台抓取的公开页面与媒体报道。';
        }

        return $items ?: ['当前诊断表现相对稳定，建议保持内容更新频率，并持续监控新问题、新竞品和引用来源变化。'];
    }
}
