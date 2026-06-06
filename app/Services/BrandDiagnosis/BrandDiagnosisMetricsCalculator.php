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
        $brandName = trim($brandName);
        if ($brandName === '') {
            return 0;
        }

        return mb_substr_count(mb_strtolower($answer, 'UTF-8'), mb_strtolower($brandName, 'UTF-8'), 'UTF-8');
    }

    public function mentionRank(string $answer, string $brandName): int
    {
        $position = mb_stripos($answer, $brandName, 0, 'UTF-8');
        if ($position === false) {
            return 0;
        }

        $before = mb_substr($answer, 0, $position, 'UTF-8');
        $rankMarkers = preg_match_all('/(?:^|\D)([1-9][0-9]?)[\.\、\)]/u', $before, $matches);

        return $rankMarkers > 0 ? max(1, (int) end($matches[1])) : 1;
    }

    public function refreshRun(BrandDiagnosisRun $run): void
    {
        /** @var Collection<int,\App\Models\BrandDiagnosisResult> $results */
        $results = $run->results()
            ->where('status', 'success')
            ->get();

        $total = max(1, (int) $run->total_questions);
        $mentioned = $results->where('brand_mentioned', true);
        $mentionRate = (int) round(($mentioned->count() / $total) * 100);
        $mentionCount = (int) $mentioned->sum('mention_count');
        $averageRank = $mentioned->where('mention_rank', '>', 0)->avg('mention_rank') ?: 0;
        $sentimentRate = $mentioned->count() > 0
            ? (int) round(($mentioned->whereIn('sentiment', ['positive', 'neutral'])->count() / $mentioned->count()) * 100)
            : 0;

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
            'completed_questions' => $results->count(),
            'failed_questions' => $run->results()->where('status', 'failed')->count(),
            'brand_score' => $score,
            'mention_rate' => $mentionRate,
            'average_rank' => round((float) $averageRank, 2),
            'mention_count' => $mentionCount,
            'sentiment_rate' => $sentimentRate,
        ]);
    }
}
