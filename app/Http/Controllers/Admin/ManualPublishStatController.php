<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\ManualPublishStatEntry;
use App\Models\Site;
use App\Services\Admin\ManualPublishStatService;
use App\Support\AdminWeb;
use App\Support\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ManualPublishStatController extends Controller
{
    public function __construct(private readonly ManualPublishStatService $service) {}

    public function index(Request $request, CurrentSite $currentSite): View
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        $isSuperAdmin = $admin->isSuperAdmin();
        $site = $this->visibleSiteOrFail($admin, $request, $currentSite);
        $summary = $this->service->summaryForSite($site);
        $progressRows = $this->service->progressRowsForSite($site);
        $dailyChartRows = $this->service->dailyChartRowsForSite($site);
        $pieSegments = $this->service->pieSegmentsForSite($site);
        $entries = $isSuperAdmin
            ? $this->service->entriesForSite($site, (int) config('geoflow.admin_items_per_page', 20))
            : null;
        $chartMaxValue = max(
            1,
            (int) $dailyChartRows
                ->map(static fn (array $row): int => array_sum(array_map('intval', $row['values'] ?? [])))
                ->max()
        );

        return view('admin.manual-publish-stats.index', [
            'pageTitle' => '发布数据台账',
            'activeMenu' => 'manual_publish_stats',
            'adminSiteName' => AdminWeb::siteName(),
            'site' => $site,
            'summary' => $summary,
            'progressRows' => $progressRows,
            'dailyChartRows' => $dailyChartRows,
            'pieSegments' => $pieSegments,
            'entries' => $entries,
            'metricTypes' => ManualPublishStatEntry::typeKeys(),
            'metricLabels' => ManualPublishStatEntry::TYPE_LABELS,
            'chartMaxValue' => $chartMaxValue,
            'siteOptions' => $this->siteOptions($admin),
            'isSuperAdmin' => $isSuperAdmin,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin && $admin->isSuperAdmin(), 403);

        $payload = $request->validate([
            'site_id' => ['required', 'integer', Rule::exists('sites', 'id')],
            'metric_type' => ['required', 'string', Rule::in(ManualPublishStatEntry::typeKeys())],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'stat_date' => ['required', 'date'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);
        $site = Site::query()->findOrFail((int) $payload['site_id']);

        $this->service->createEntry(
            $site,
            (int) $admin->id,
            (string) $payload['metric_type'],
            (int) $payload['quantity'],
            (string) $payload['stat_date'],
            trim((string) ($payload['remark'] ?? ''))
        );

        return redirect()
            ->route('admin.manual-publish-stats.index', ['site_id' => (int) $site->id])
            ->with('message', '台账记录已添加');
    }

    public function destroy(Request $request, ManualPublishStatEntry $manual_publish_stat): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin && $admin->isSuperAdmin(), 403);

        $siteId = (int) $manual_publish_stat->site_id;

        $this->service->deleteEntry($manual_publish_stat);

        return redirect()
            ->route('admin.manual-publish-stats.index', ['site_id' => $siteId])
            ->with('message', '台账记录已删除');
    }

    private function visibleSiteOrFail(Admin $admin, Request $request, CurrentSite $currentSite): Site
    {
        if ($admin->isSuperAdmin()) {
            $siteId = (int) $request->query('site_id', 0);
            $keyword = trim((string) $request->query('keyword', ''));
            $selectedSite = $siteId > 0
                ? Site::query()->with('owner:id,username,display_name,mobile')->find($siteId)
                : null;

            if ($keyword !== '') {
                if ($selectedSite instanceof Site && $this->siteMatchesKeyword($selectedSite, $keyword)) {
                    return $selectedSite;
                }

                $matchedSite = $this->firstSiteMatchingKeyword($keyword);
                if ($matchedSite instanceof Site) {
                    return $matchedSite;
                }
            }

            if ($selectedSite instanceof Site) {
                return $selectedSite;
            }

            if ($siteId > 0) {
                abort(404);
            }
        }

        $site = $currentSite->get();
        abort_unless($site instanceof Site, 403);

        if (! $admin->isSuperAdmin()) {
            $belongsToSite = $site->members()
                ->where('admins.id', (int) $admin->id)
                ->exists();
            abort_unless($belongsToSite, 403);
        }

        return $site;
    }

    private function firstSiteMatchingKeyword(string $keyword): ?Site
    {
        $like = '%'.mb_strtolower($keyword).'%';

        return Site::query()
            ->with('owner:id,username,display_name,mobile')
            ->where(function (Builder $query) use ($like): void {
                $query
                    ->whereRaw("LOWER(COALESCE(name, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(domain, '')) LIKE ?", [$like])
                    ->orWhereHas('owner', function (Builder $ownerQuery) use ($like): void {
                        $ownerQuery
                            ->whereRaw("LOWER(COALESCE(username, '')) LIKE ?", [$like])
                            ->orWhereRaw("LOWER(COALESCE(display_name, '')) LIKE ?", [$like])
                            ->orWhereRaw("LOWER(COALESCE(mobile, '')) LIKE ?", [$like]);
                    });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private function siteMatchesKeyword(Site $site, string $keyword): bool
    {
        $site->loadMissing('owner:id,username,display_name,mobile');

        $owner = $site->owner;
        $haystack = mb_strtolower(implode(' ', array_filter([
            $site->name,
            $site->domain,
            $owner?->username,
            $owner?->display_name,
            $owner?->mobile,
        ], static fn ($value): bool => $value !== null && $value !== '')));

        return str_contains($haystack, mb_strtolower($keyword));
    }

    /**
     * @return Collection<int,Site>
     */
    private function siteOptions(Admin $admin): Collection
    {
        if (! $admin->isSuperAdmin()) {
            return collect();
        }

        return Site::query()
            ->with('owner:id,username,display_name,mobile')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'name', 'domain', 'owner_admin_id']);
    }
}
