<?php

namespace App\Services\BrandDiagnosis;

use App\Models\BrandDiagnosisRun;
use Illuminate\Support\Collection;

class BrandDiagnosisMetricsCalculator
{
    public const SOURCE_UNIT_SEPARATOR = "\n---BRAND_DIAGNOSIS_SOURCE---\n";

    public function classifySentiment(string $answer): string
    {
        $negativeWords = ['风险', '不足', '负面', '投诉', '差评', '问题', '不推荐', '谨慎'];
        foreach ($negativeWords as $word) {
            if (mb_stripos($answer, $word, 0, 'UTF-8') !== false) {
                return 'negative';
            }
        }

        $positiveWords = ['正面', '推荐', '优势', '靠谱', '优秀', '领先', '可靠', '效果好'];
        foreach ($positiveWords as $word) {
            if (mb_stripos($answer, $word, 0, 'UTF-8') !== false) {
                return 'positive';
            }
        }

        return 'neutral';
    }

    public function mentionCount(string $answer, string $brandName): int
    {
        $aliases = collect($this->brandAliases($brandName))
            ->sortByDesc(static fn (string $alias): int => mb_strlen($alias, 'UTF-8'))
            ->values()
            ->all();
        if ($aliases === []) {
            return 0;
        }

        $pattern = '/'.implode('|', array_map(static fn (string $alias): string => preg_quote($alias, '/'), $aliases)).'/iu';

        return preg_match_all($pattern, $answer);
    }

    public function isSameBrand(string $brandName, string $targetBrandName): bool
    {
        $brandKey = $this->normalizeBrandName($brandName);
        if ($brandKey === '') {
            return false;
        }

        return collect($this->brandAliases($targetBrandName))
            ->map(fn (string $alias): string => $this->normalizeBrandName($alias))
            ->contains($brandKey);
    }

    public function mentionRank(string $answer, string $brandName): int
    {
        $position = false;
        foreach ($this->brandAliases($brandName) as $alias) {
            $aliasPosition = mb_stripos($answer, $alias, 0, 'UTF-8');
            if ($aliasPosition !== false && ($position === false || $aliasPosition < $position)) {
                $position = $aliasPosition;
            }
        }

        if ($position === false) {
            return 0;
        }
        $before = mb_substr($answer, 0, $position, 'UTF-8');
        $rankMarkers = preg_match_all('/(?:^|\D)([1-9][0-9]?)[\.\、\)]/u', $before, $matches);

        return $rankMarkers > 0 ? max(1, (int) end($matches[1])) : 0;
    }

    /**
     * @param  list<array<string,mixed>>  $brandMentions
     * @return list<array{brand:string,mention_count:int,mention_rank:int,sentiment:string,evidence:string,source_count:int,meta:array<string,mixed>}>
     */
    public function normalizeBrandMentions(array $brandMentions, string $answer, string $targetBrandName, string $sourceEvidence = '', string $question = ''): array
    {
        $normalized = [];
        $fallbackAnswer = $this->answerWithoutQuestionEcho($answer, $question);

        foreach ($brandMentions as $mention) {
            if (! is_array($mention)) {
                continue;
            }

            $brandName = trim((string) ($mention['brand'] ?? $mention['brand_name'] ?? ''));
            $brandKey = $this->normalizeBrandName($brandName);
            if ($brandKey === '') {
                continue;
            }
            $evidence = trim((string) ($mention['evidence'] ?? ''));
            $isTargetBrand = $this->isSameBrand($brandName, $targetBrandName);
            if ($isTargetBrand && ! $this->hasValidTargetEvidence($fallbackAnswer, $sourceEvidence, $brandName, $targetBrandName, $evidence)) {
                continue;
            }

            $mentionCount = $this->validatedMentionCount($fallbackAnswer, $sourceEvidence, $brandName, $isTargetBrand);
            if ($mentionCount <= 0) {
                continue;
            }
            $answerRank = $this->mentionRank($fallbackAnswer, $brandName);
            $reportedRank = max(0, (int) ($mention['mention_rank'] ?? 0));
            $mentionRank = $answerRank > 0 ? $answerRank : $reportedRank;
            $sentiment = $this->normalizeSentiment((string) ($mention['sentiment'] ?? 'neutral'));

            if (isset($normalized[$brandKey])) {
                $normalized[$brandKey]['mention_count'] = max((int) $normalized[$brandKey]['mention_count'], $mentionCount);
                if ($mentionRank > 0) {
                    $previousRank = (int) $normalized[$brandKey]['mention_rank'];
                    $normalized[$brandKey]['mention_rank'] = $previousRank > 0 ? min($previousRank, $mentionRank) : $mentionRank;
                }
                if ($normalized[$brandKey]['sentiment'] !== 'negative') {
                    $normalized[$brandKey]['sentiment'] = $sentiment;
                }

                continue;
            }

            $normalized[$brandKey] = [
                'brand' => $brandName,
                'mention_count' => $mentionCount,
                'mention_rank' => $mentionRank,
                'sentiment' => $sentiment,
                'evidence' => $evidence,
                'source_count' => max(0, (int) ($mention['source_count'] ?? 0)),
                'meta' => $mention,
            ];
        }

        $targetKey = $this->normalizeBrandName($targetBrandName);
        $targetMentionCount = $this->validatedMentionCount($fallbackAnswer, $sourceEvidence, $targetBrandName, true);
        if ($targetKey !== '' && $targetMentionCount > 0) {
            if (isset($normalized[$targetKey])) {
                $normalized[$targetKey]['mention_count'] = $targetMentionCount;
                if ((int) $normalized[$targetKey]['mention_rank'] <= 0) {
                    $normalized[$targetKey]['mention_rank'] = $this->mentionRank($fallbackAnswer, $targetBrandName);
                }
            } else {
                $normalized[$targetKey] = [
                    'brand' => $targetBrandName,
                    'mention_count' => $targetMentionCount,
                    'mention_rank' => $this->mentionRank($fallbackAnswer, $targetBrandName),
                    'sentiment' => $this->classifySentiment($fallbackAnswer),
                    'evidence' => '回答文本中提及目标品牌',
                    'source_count' => 0,
                    'meta' => ['fallback' => true],
                ];
            }
        }

        return array_values($normalized);
    }

    public function refreshRun(BrandDiagnosisRun $run): void
    {
        /** @var Collection<int,\App\Models\BrandDiagnosisResult> $results */
        $results = $run->results()
            ->where('status', 'success')
            ->get();

        $total = max(1, $results->count());
        $targetMentions = $run->brandMentions()
            ->where('is_target_brand', true)
            ->get();

        if ($targetMentions->isEmpty()) {
            $targetResults = $results->where('brand_mentioned', true);
            $mentionRate = (int) round(($targetResults->count() / $total) * 100);
            $mentionCount = (int) $targetResults->sum('mention_count');
            $averageRank = $targetResults->where('mention_rank', '>', 0)->avg('mention_rank') ?: 0;
            $sentimentRate = $targetResults->count() > 0
                ? (int) round(($targetResults->whereIn('sentiment', ['positive', 'neutral'])->count() / $targetResults->count()) * 100)
                : 0;
        } else {
            $mentionedConversationCount = $targetMentions
                ->pluck('result_id')
                ->unique()
                ->count();
            $mentionRate = (int) round(($mentionedConversationCount / $total) * 100);
            $mentionCount = (int) $targetMentions->sum('mention_count');
            $averageRank = $targetMentions->where('mention_rank', '>', 0)->avg('mention_rank') ?: 0;
            $sentimentRate = $targetMentions->count() > 0
                ? (int) round(($targetMentions->whereIn('sentiment', ['positive', 'neutral'])->count() / $targetMentions->count()) * 100)
                : 0;
        }

        $completedQuestions = $results->pluck('question_id')->unique()->count();
        $failedQuestions = max(0, (int) $run->total_questions - $completedQuestions);

        $rankScore = $averageRank > 0
            ? max(0, 100 - (((float) $averageRank - 1) * 5))
            : 0;
        $score = (int) min(100, round(
            ($mentionRate * 0.75)
            + ($mentionCount * 0.1)
            + ($rankScore * 0.1)
            + ($sentimentRate * 0.05)
        ));

        $run->update([
            'completed_questions' => $completedQuestions,
            'failed_questions' => $failedQuestions,
            'brand_score' => $score,
            'mention_rate' => $mentionRate,
            'average_rank' => round((float) $averageRank, 2),
            'mention_count' => $mentionCount,
            'sentiment_rate' => $sentimentRate,
        ]);
    }

    private function normalizeBrandName(string $brandName): string
    {
        $brandName = trim($brandName);
        $brandName = preg_replace('/[（(].*?[）)]/u', '', $brandName) ?? $brandName;
        $brandName = preg_replace('/(股份)?有限公司$/u', '', $brandName) ?? $brandName;
        $brandName = preg_replace('/(人工智能|科技|技术|信息|网络|软件|数字|智能|成都|北京|上海|深圳|广州|杭州|有限公司|公司)+$/u', '', $brandName) ?? $brandName;

        return mb_strtolower(trim($brandName), 'UTF-8');
    }

    private function normalizeSentiment(string $sentiment): string
    {
        $sentiment = strtolower(trim($sentiment));

        return in_array($sentiment, ['positive', 'neutral', 'negative'], true) ? $sentiment : 'neutral';
    }

    /**
     * @return list<string>
     */
    private function brandAliases(string $brandName): array
    {
        $brandName = trim($brandName);
        if ($brandName === '') {
            return [];
        }

        $aliases = [$brandName];
        $withoutParentheses = preg_replace('/[（(].*?[）)]/u', '', $brandName);
        if (is_string($withoutParentheses) && trim($withoutParentheses) !== '') {
            $aliases[] = trim($withoutParentheses);
        }

        $shortName = preg_replace('/(股份)?有限公司$/u', '', trim((string) $withoutParentheses));
        if (is_string($shortName) && trim($shortName) !== '') {
            $aliases[] = trim($shortName);
        }

        $shortName = preg_replace('/(人工智能|科技|技术|信息|网络|软件|数字|智能|公司)+$/u', '', trim((string) $shortName));
        if (is_string($shortName) && trim($shortName) !== '') {
            $aliases[] = trim($shortName);
        }

        return collect($aliases)
            ->map(static fn (string $alias): string => trim($alias))
            ->filter(static fn (string $alias): bool => $alias !== '')
            ->unique(static fn (string $alias): string => mb_strtolower($alias, 'UTF-8'))
            ->values()
            ->all();
    }

    private function hasValidTargetEvidence(string $answer, string $sourceEvidence, string $brandName, string $targetBrandName, string $evidence): bool
    {
        $invalidEvidenceWords = ['目标品牌', '诊断输入', '用户输入', '输入本身', '未提及', '没有提及', '未在回答', '未在引用', '不是回答', '不是引用'];
        foreach ($invalidEvidenceWords as $word) {
            if ($evidence !== '' && mb_stripos($evidence, $word, 0, 'UTF-8') !== false) {
                return false;
            }
        }

        $haystack = $answer."\n".$sourceEvidence;
        $aliases = array_values(array_unique(array_merge($this->brandAliases($targetBrandName), $this->brandAliases($brandName))));
        foreach ($aliases as $alias) {
            if (mb_stripos($haystack, $alias, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    private function validatedMentionCount(string $answer, string $sourceEvidence, string $brandName, bool $isTargetBrand): int
    {
        $answerMentioned = $this->mentionCount($answer, $brandName) > 0;
        if ($isTargetBrand && $answerMentioned && $this->onlyNegatedMentions($answer, $brandName)) {
            $answerMentioned = false;
        }

        $sourceCount = $this->sourceMentionUnitCount($sourceEvidence, $brandName);

        return ($answerMentioned ? 1 : 0) + $sourceCount;
    }

    private function sourceMentionUnitCount(string $evidence, string $brandName): int
    {
        $units = str_contains($evidence, self::SOURCE_UNIT_SEPARATOR)
            ? explode(self::SOURCE_UNIT_SEPARATOR, $evidence)
            : (preg_split('/\R/u', $evidence) ?: []);

        return collect($units)
            ->map(static fn (string $unit): string => trim((string) preg_replace('/\s+/u', ' ', $unit)))
            ->filter(static fn (string $unit): bool => $unit !== '')
            ->unique(static fn (string $unit): string => mb_strtolower($unit, 'UTF-8'))
            ->filter(fn (string $unit): bool => $this->mentionCount($unit, $brandName) > 0)
            ->count();
    }

    private function onlyNegatedMentions(string $answer, string $brandName): bool
    {
        $aliases = collect($this->brandAliases($brandName))
            ->sortByDesc(static fn (string $alias): int => mb_strlen($alias, 'UTF-8'))
            ->values();
        if ($aliases->isEmpty()) {
            return false;
        }

        $found = false;
        foreach ($aliases as $alias) {
            $offset = 0;
            while (($position = mb_stripos($answer, $alias, $offset, 'UTF-8')) !== false) {
                $found = true;
                $contextStart = max(0, $position - 24);
                $context = mb_substr($answer, $contextStart, mb_strlen($alias, 'UTF-8') + 96, 'UTF-8');
                if (! $this->isNegatedContext($context)) {
                    return false;
                }
                $offset = $position + max(1, mb_strlen($alias, 'UTF-8'));
            }
        }

        return $found;
    }

    private function isNegatedContext(string $context): bool
    {
        foreach (['未检索到', '没有检索到', '未发现', '没有发现', '未找到', '没有找到', '暂无', '无法确认', '无法核实', '资料较少', '公开资料较少', '未提到', '没有提到', '未被提及', '没有被提及', '未能确认', '暂未被推荐', '未被推荐', '不在推荐', '未收录为推荐', '暂未收录', '暂未出现'] as $word) {
            if (mb_stripos($context, $word, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    private function answerWithoutQuestionEcho(string $answer, string $question): string
    {
        $question = trim($question);
        if ($question === '') {
            return $answer;
        }

        $variants = collect([$question, trim($question, " \t\n\r\0\x0B？?")])
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values();

        foreach ($variants as $variant) {
            $answer = str_replace($variant, '', $answer);
        }

        return $answer;
    }
}
