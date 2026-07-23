<?php

namespace App\Services\BrandDiagnosis;

use App\Models\BrandDiagnosisRun;

class BrandDiagnosisApiResultPresenter
{
    public function summary(BrandDiagnosisRun $run): array
    {
        return [
            'task_id' => (string) $run->api_task_key,
            'status' => $this->publicStatus((string) $run->status),
            'raw_status' => (string) $run->status,
            'brand_name' => (string) $run->brand_name,
            'models' => array_values((array) $run->platforms),
            'created_at' => $run->created_at?->format('Y-m-d H:i:s') ?? '',
        ];
    }

    public function detail(BrandDiagnosisRun $run): array
    {
        return array_merge($this->summary($run), [
            'progress' => [
                'total_questions' => (int) $run->total_questions,
                'completed_questions' => (int) $run->completed_questions,
                'failed_questions' => (int) $run->failed_questions,
            ],
            'error_message' => (string) ($run->error_message ?? ''),
            'brand_performance' => $this->brandPerformance($run),
            'questions' => $this->questions($run),
            'model_results' => $this->modelResults($run),
            'sources' => $this->sources($run),
            'rankings' => $this->brandRankings($run),
        ]);
    }

    private function publicStatus(string $status): string
    {
        return match ($status) {
            'completed' => 'completed',
            'failed' => 'failed',
            default => 'diagnosing',
        };
    }

    private function brandPerformance(BrandDiagnosisRun $run): array
    {
        return [
            'score' => (int) $run->brand_score,
            'mention_rate' => (int) $run->mention_rate,
            'average_rank' => $this->formatAverageRank((float) $run->average_rank),
            'mention_count' => (int) $run->mention_count,
            'sentiment_rate' => (int) $run->sentiment_rate,
        ];
    }

    private function questions(BrandDiagnosisRun $run): array
    {
        return $run->questions
            ->sortBy('sort_order')
            ->map(static fn ($question): array => [
                'id' => (int) $question->id,
                'question' => (string) $question->question,
                'type' => (string) $question->question_type,
                'core_term' => (string) ($question->core_term ?? ''),
                'sort_order' => (int) $question->sort_order,
                'status' => (string) $question->status,
            ])
            ->values()
            ->all();
    }

    private function modelResults(BrandDiagnosisRun $run): array
    {
        return $run->questions
            ->flatMap(static fn ($question) => $question->results->map(static fn ($result): array => [
                'question_id' => (int) $question->id,
                'platform' => (string) $result->platform,
                'status' => (string) $result->status,
                'answer' => (string) ($result->answer ?? ''),
                'brand_mentioned' => (bool) $result->brand_mentioned,
                'mention_count' => (int) $result->mention_count,
                'mention_rank' => (int) $result->mention_rank,
                'sentiment' => (string) $result->sentiment,
                'error_message' => (string) ($result->error_message ?? ''),
                'checked_at' => $result->checked_at?->format('Y-m-d H:i:s') ?? '',
            ]))
            ->values()
            ->all();
    }

    private function sources(BrandDiagnosisRun $run): array
    {
        return $run->sources
            ->map(static fn ($source): array => [
                'question_id' => (int) $source->question_id,
                'result_id' => (int) $source->result_id,
                'platform' => (string) $source->platform,
                'title' => (string) $source->title,
                'url' => (string) $source->url,
                'domain' => (string) $source->domain,
                'source_type' => (string) $source->source_type,
            ])
            ->values()
            ->all();
    }

    private function brandRankings(BrandDiagnosisRun $run): array
    {
        $mentions = $run->brandMentions
            ->groupBy(static fn ($mention): string => (string) $mention->brand_name)
            ->map(static fn ($items, string $brand): array => [
                'brand_name' => $brand,
                'mention_count' => (int) $items->sum('mention_count'),
                'best_rank' => (int) ($items->where('mention_rank', '>', 0)->min('mention_rank') ?: 0),
                'source_count' => (int) $items->sum('source_count'),
                'is_target_brand' => (bool) $items->contains(fn ($mention): bool => (bool) $mention->is_target_brand),
            ])
            ->values();

        return [
            'mention_count' => $mentions->sortByDesc('mention_count')->values()->all(),
            'average_rank' => $mentions->sortBy(static fn (array $item): int => $item['best_rank'] > 0 ? $item['best_rank'] : 999999)->values()->all(),
            'source_count' => $mentions->sortByDesc('source_count')->values()->all(),
        ];
    }

    private function formatAverageRank(float $rank): string
    {
        if ($rank <= 0) {
            return '0';
        }

        return rtrim(rtrim(number_format($rank, 2, '.', ''), '0'), '.');
    }
}
