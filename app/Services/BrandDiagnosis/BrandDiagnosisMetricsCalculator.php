<?php

namespace App\Services\BrandDiagnosis;

use App\Models\BrandDiagnosisRun;
use Illuminate\Support\Collection;

class BrandDiagnosisMetricsCalculator
{
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

        return $rankMarkers > 0 ? max(1, (int) end($matches[1])) : 1;
    }

    /**
     * @param  list<array<string,mixed>>  $brandMentions
     * @return list<array{brand:string,mention_count:int,mention_rank:int,sentiment:string,evidence:string,source_count:int,meta:array<string,mixed>}>
     */
    public function normalizeBrandMentions(array $brandMentions, string $answer, string $targetBrandName, string $sourceEvidence = '', string $question = ''): array
    {
        $normalized = [];

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
            if ($isTargetBrand && ! $this->hasValidTargetEvidence($answer, $sourceEvidence, $brandName, $targetBrandName, $evidence)) {
                continue;
            }

            $mentionCount = max(1, (int) ($mention['mention_count'] ?? 1));
            $mentionRank = max(0, (int) ($mention['mention_rank'] ?? 0));
            $sentiment = $this->normalizeSentiment((string) ($mention['sentiment'] ?? 'neutral'));

            if (isset($normalized[$brandKey])) {
                $normalized[$brandKey]['mention_count'] += $mentionCount;
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
        $fallbackAnswer = $this->answerWithoutQuestionEcho($answer, $question);
        $targetMentionCount = $this->mentionCount($fallbackAnswer, $targetBrandName);
        if ($targetKey !== '' && $targetMentionCount > 0) {
            if (isset($normalized[$targetKey])) {
                $normalized[$targetKey]['mention_count'] = max((int) $normalized[$targetKey]['mention_count'], $targetMentionCount);
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

        $haystack = $answer."\n".$sourceEvidence."\n".$evidence;
        $aliases = array_values(array_unique(array_merge($this->brandAliases($targetBrandName), $this->brandAliases($brandName))));
        foreach ($aliases as $alias) {
            if (mb_stripos($haystack, $alias, 0, 'UTF-8') !== false) {
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
