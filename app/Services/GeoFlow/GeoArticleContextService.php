<?php

namespace App\Services\GeoFlow;

use App\Models\GeoInclusionCheckResult;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KeywordQuestionVariant;
use App\Models\Task;
use App\Models\TitleLibrary;
use Illuminate\Support\Collection;

class GeoArticleContextService
{
    public function buildForTask(Task $task, string $keyword): string
    {
        $library = $this->resolveKeywordLibrary($task);
        if (! $library) {
            return '';
        }

        $lines = ['GEO article context:'];
        if (trim((string) ($library->company_name ?? '')) !== '') {
            $lines[] = '- Brand: '.trim((string) $library->company_name);
        }
        if (trim((string) ($library->domain_keyword ?? '')) !== '') {
            $lines[] = '- Domain keyword: '.trim((string) $library->domain_keyword);
        }
        if (trim((string) ($library->industry ?? '')) !== '') {
            $lines[] = '- Industry: '.trim((string) $library->industry);
        }
        if (trim((string) ($library->brand_description ?? '')) !== '') {
            $lines[] = '- Brand description: '.trim((string) $library->brand_description);
        }

        $questions = $this->loadQuestionVariants((int) $library->id, $keyword);
        if ($questions->isNotEmpty()) {
            $lines[] = '- User questions to answer:';
            foreach ($questions as $question) {
                $lines[] = '  - '.(string) $question->question;
            }
        }

        $gaps = $this->loadInclusionGaps((int) $library->id, $keyword);
        if ($gaps->isNotEmpty()) {
            $lines[] = '- Inclusion gaps to reinforce:';
            foreach ($gaps as $gap) {
                $status = [];
                if (! (bool) $gap->keyword_hit) {
                    $status[] = 'keyword not hit';
                }
                if (! (bool) $gap->brand_hit) {
                    $status[] = 'brand not hit';
                }

                $lines[] = '  - '.ucfirst((string) $gap->platform).': '.(string) $gap->question.' ('.implode(', ', $status).')';
            }
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    private function resolveKeywordLibrary(Task $task): ?KeywordLibrary
    {
        $titleLibraryId = (int) ($task->title_library_id ?? 0);
        if ($titleLibraryId <= 0) {
            return null;
        }

        $titleLibrary = TitleLibrary::query()
            ->whereKey($titleLibraryId)
            ->first(['id', 'keyword_library_id']);
        $keywordLibraryId = (int) ($titleLibrary?->keyword_library_id ?? 0);
        if ($keywordLibraryId <= 0) {
            return null;
        }

        return KeywordLibrary::query()->whereKey($keywordLibraryId)->first();
    }

    /**
     * @return Collection<int, KeywordQuestionVariant>
     */
    private function loadQuestionVariants(int $libraryId, string $keyword): Collection
    {
        $query = KeywordQuestionVariant::query()
            ->whereIn('keyword_id', Keyword::query()->select('id')->where('library_id', $libraryId));

        $keyword = trim($keyword);
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword, $libraryId): void {
                $builder->whereIn('keyword_id', Keyword::query()
                    ->select('id')
                    ->where('library_id', $libraryId)
                    ->where('keyword', $keyword)
                )->orWhere('question', 'like', '%'.$keyword.'%');
            });
        }

        return $query->orderByDesc('created_at')->limit(5)->get();
    }

    /**
     * @return Collection<int, GeoInclusionCheckResult>
     */
    private function loadInclusionGaps(int $libraryId, string $keyword): Collection
    {
        $query = GeoInclusionCheckResult::query()
            ->where('keyword_library_id', $libraryId)
            ->where('status', 'success')
            ->where(function ($builder): void {
                $builder->where('keyword_hit', false)
                    ->orWhere('brand_hit', false);
            });

        $keyword = trim($keyword);
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword): void {
                $builder->where('question', 'like', '%'.$keyword.'%')
                    ->orWhereIn('keyword_id', Keyword::query()->select('id')->where('keyword', $keyword));
            });
        }

        return $query->orderByDesc('checked_at')->limit(5)->get();
    }
}
