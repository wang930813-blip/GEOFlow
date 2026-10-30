<?php

namespace App\Services\Site;

use App\Models\Article;
use App\Models\HostedSiteArticleAssignment;
use App\Support\CurrentSite as DomesticCurrentSite;
use App\Support\GeoFlow\ArticleWorkflow;
use App\Support\Site\CurrentSite;
use Illuminate\Database\Eloquent\Builder;

final class SiteScopedArticleQuery
{
    public function __construct(
        private readonly CurrentSite $currentSite,
        private readonly DomesticCurrentSite $domesticCurrentSite,
    ) {}

    /** @return Builder<Article> */
    public function query(): Builder
    {
        return $this->apply(Article::query());
    }

    /** @param Builder<Article> $query @return Builder<Article> */
    public function apply(Builder $query): Builder
    {
        return $this->applyForSiteKey($query, $this->currentSite->isHosted()
            ? 'hosted:'.$this->currentSite->profileId()
            : 'primary');
    }

    /** @return Builder<Article> */
    public function queryForSiteKey(string $siteKey): Builder
    {
        return $this->applyForSiteKey(Article::query(), $siteKey);
    }

    /** @param Builder<Article> $query @return Builder<Article> */
    public function applyForSiteKey(Builder $query, string $siteKey): Builder
    {
        if ($siteKey === 'primary') {
            $siteId = $this->domesticCurrentSite->id();

            return $query
                ->withoutGlobalScope('current_site')
                ->where(function (Builder $articles) use ($siteId): void {
                    $articles->whereNull('articles.site_id');
                    if ($siteId !== null && $siteId > 0) {
                        $articles->orWhere('articles.site_id', $siteId);
                    }
                })
                ->published()
                ->where(function (Builder $articles) use ($siteId): void {
                    $articles
                        ->whereNull('articles.task_id')
                        ->orWhereHas('task', function (Builder $task) use ($siteId): Builder {
                            $task->withoutGlobalScope('current_site')
                                ->where('publish_scope', '!=', 'distribution_only')
                                ->where(function (Builder $tasks) use ($siteId): void {
                                    $tasks->whereNull('tasks.site_id');
                                    if ($siteId !== null && $siteId > 0) {
                                        $tasks->orWhere('tasks.site_id', $siteId);
                                    }
                                });

                            return $task;
                        });
                });
        }

        if (! preg_match('/^hosted:([1-9][0-9]*)$/D', $siteKey, $matches)) {
            throw new \InvalidArgumentException('Invalid site key.');
        }

        $profileId = (int) $matches[1];

        return $query
            ->whereNull('articles.deleted_at')
            ->whereIn('articles.review_status', ArticleWorkflow::PUBLISHABLE_REVIEW_STATUSES)
            ->whereIn('articles.status', ['private', 'published'])
            ->whereHas('task', fn (Builder $task): Builder => $task->where('publish_scope', 'distribution_only'))
            ->whereHas('hostedSiteAssignment', function (Builder $assignment) use ($profileId): void {
                $assignment
                    ->where('hosted_site_profile_id', $profileId)
                    ->where('status', HostedSiteArticleAssignment::STATUS_PUBLISHED);
            });
    }
}
