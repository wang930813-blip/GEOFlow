<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\ManualPublishStatEntry;
use App\Models\Site;
use App\Services\Admin\ManualPublishStatService;
use App\Support\AdminWeb;
use App\Support\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ManualPublishStatController extends Controller
{
    public function __construct(private readonly ManualPublishStatService $service) {}

    public function index(Request $request, CurrentSite $currentSite): View
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin && $admin->isSuperAdmin(), 403);

        $site = $this->currentSiteOrFail($currentSite);
        $summary = $this->service->summaryForSite($site);
        $dailyChartRows = $this->service->dailyChartRowsForSite($site);
        $pieSegments = $this->service->pieSegmentsForSite($site);
        $entries = $this->service->entriesForSite($site, (int) config('geoflow.admin_items_per_page', 20));
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
            'dailyChartRows' => $dailyChartRows,
            'pieSegments' => $pieSegments,
            'entries' => $entries,
            'metricTypes' => ManualPublishStatEntry::typeKeys(),
            'metricLabels' => ManualPublishStatEntry::TYPE_LABELS,
            'chartMaxValue' => $chartMaxValue,
        ]);
    }

    public function store(Request $request, CurrentSite $currentSite): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin && $admin->isSuperAdmin(), 403);

        $site = $this->currentSiteOrFail($currentSite);
        $payload = $request->validate([
            'metric_type' => ['required', 'string', Rule::in(ManualPublishStatEntry::typeKeys())],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'stat_date' => ['required', 'date'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        $this->service->createEntry(
            $site,
            (int) $admin->id,
            (string) $payload['metric_type'],
            (int) $payload['quantity'],
            (string) $payload['stat_date'],
            trim((string) ($payload['remark'] ?? ''))
        );

        return redirect()
            ->route('admin.manual-publish-stats.index')
            ->with('message', '台账记录已添加');
    }

    public function destroy(Request $request, ManualPublishStatEntry $manual_publish_stat, CurrentSite $currentSite): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin && $admin->isSuperAdmin(), 403);

        $site = $this->currentSiteOrFail($currentSite);
        abort_unless((int) $manual_publish_stat->site_id === (int) $site->id, 404);

        $this->service->deleteEntry($manual_publish_stat);

        return redirect()
            ->route('admin.manual-publish-stats.index')
            ->with('message', '台账记录已删除');
    }

    private function currentSiteOrFail(CurrentSite $currentSite): Site
    {
        $site = $currentSite->get();
        abort_unless($site instanceof Site, 403);

        return $site;
    }
}
