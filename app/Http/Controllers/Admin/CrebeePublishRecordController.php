<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\CrebeePublishJobItem;
use App\Models\Site;
use App\Support\AdminWeb;
use App\Support\Crebee\SelfMediaPlatformCatalog;
use App\Support\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CrebeePublishRecordController extends Controller
{
    public function index(Request $request): View
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        $site = app(CurrentSite::class)->get();
        abort_unless($site instanceof Site, 403);

        $records = CrebeePublishJobItem::query()
            ->with([
                'job:id,site_id,owner_admin_id,agent_id,content_type,title,status,submitted_at,finished_at,created_at',
                'account:id,platform,account_name,crebee_account_id,avatar',
                'job.owner:id,username,display_name,role',
            ])
            ->whereHas('job', function (Builder $query) use ($admin, $site): void {
                $query->where('site_id', (int) $site->id);
                if (! $this->canViewSiteRecords($admin)) {
                    $query->where('owner_admin_id', (int) $admin->id);
                }
            })
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();

        return view('admin.crebee-publish-records.index', [
            'pageTitle' => '自媒体发布记录',
            'activeMenu' => 'crebee_publish_records',
            'adminSiteName' => AdminWeb::siteName(),
            'site' => $site,
            'records' => $records,
            'platformLabels' => SelfMediaPlatformCatalog::labels(),
        ]);
    }

    private function canViewSiteRecords(Admin $admin): bool
    {
        return $admin->isSuperAdmin() || $admin->isAgentAdmin();
    }
}
