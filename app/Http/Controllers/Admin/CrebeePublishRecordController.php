<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\CrebeePublishJobItem;
use App\Models\SelfMediaPublishJobItem;
use App\Models\Site;
use App\Support\AdminDataScope;
use App\Support\AdminWeb;
use App\Support\Crebee\SelfMediaPlatformCatalog;
use App\Support\SelfMedia\SelfMediaPlatformCatalog as AiToEarnPlatformCatalog;
use App\Support\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CrebeePublishRecordController extends Controller
{
    public function __construct(private readonly AdminDataScope $adminDataScope) {}

    public function index(Request $request): View
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        $site = app(CurrentSite::class)->get();
        abort_unless($admin->isSuperAdmin() || $site instanceof Site || $admin->isAgentAdmin(), 403);
        $perPage = max(1, min(100, (int) config('geoflow.admin_items_per_page', 20)));

        if ((bool) config('aitoearn.enabled', false)) {
            return $this->aiToEarnIndex($admin, $site, $perPage);
        }

        $records = CrebeePublishJobItem::query()
            ->with([
                'job:id,site_id,owner_admin_id,agent_id,content_type,title,status,submitted_at,finished_at,created_at',
                'account:id,platform,account_name,crebee_account_id,avatar',
                'job.owner:id,username,display_name,role',
            ])
            ->whereHas('job', function (Builder $query) use ($admin, $site): void {
                if ($admin->isSuperAdmin()) {
                    return;
                }

                if ($site instanceof Site) {
                    $query->where('site_id', (int) $site->id);
                } else {
                    $this->adminDataScope->applySiteScope($query, $admin, 'crebee_publish_jobs.site_id');
                }

                if (! $this->canViewSiteRecords($admin)) {
                    $query->where('owner_admin_id', (int) $admin->id);
                }
            })
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return view('admin.crebee-publish-records.index', [
            'pageTitle' => '自媒体发布记录',
            'activeMenu' => 'crebee_publish_records',
            'adminSiteName' => AdminWeb::siteName(),
            'site' => $site,
            'records' => $records,
            'platformLabels' => SelfMediaPlatformCatalog::labels(),
            'scopeLabel' => $admin->isSuperAdmin()
                ? '全部站点'
                : ($site instanceof Site ? (string) $site->name : '代理下属用户'),
        ]);
    }

    private function canViewSiteRecords(Admin $admin): bool
    {
        return $admin->isSuperAdmin() || $admin->isAgentAdmin();
    }

    private function aiToEarnIndex(Admin $admin, ?Site $site, int $perPage): View
    {
        $records = SelfMediaPublishJobItem::query()
            ->with([
                'job:id,site_id,owner_admin_id,provider,content_type,title,status,submitted_at,finished_at,created_at',
                'account:id,platform,account_name,external_account_id,avatar',
                'job.owner:id,username,display_name,role',
            ])
            ->whereHas('job', function (Builder $query) use ($admin, $site): void {
                if ($admin->isSuperAdmin()) {
                    return;
                }

                if ($site instanceof Site) {
                    $query->where('site_id', (int) $site->id);
                } else {
                    $this->adminDataScope->applySiteScope($query, $admin, 'self_media_publish_jobs.site_id');
                }

                if (! $this->canViewSiteRecords($admin)) {
                    $query->where('owner_admin_id', (int) $admin->id);
                }
            })
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return view('admin.self-media-publish-records.index', [
            'pageTitle' => '自媒体发布记录',
            'activeMenu' => 'crebee_publish_records',
            'adminSiteName' => AdminWeb::siteName(),
            'site' => $site,
            'records' => $records,
            'platformLabels' => AiToEarnPlatformCatalog::labels(),
            'scopeLabel' => $admin->isSuperAdmin()
                ? '全部站点'
                : ($site instanceof Site ? (string) $site->name : '代理下属用户'),
        ]);
    }
}
