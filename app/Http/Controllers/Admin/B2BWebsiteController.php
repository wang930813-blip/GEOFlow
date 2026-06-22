<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminB2BWebsiteOpening;
use App\Support\AdminDashboard\B2BIndustryWebsiteCatalog;
use App\Support\AdminWeb;
use App\Support\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class B2BWebsiteController extends Controller
{
    public function index(B2BIndustryWebsiteCatalog $b2bCatalog): View
    {
        return view('admin.b2b-websites.index', [
            'pageTitle' => 'B2B行业网站',
            'activeMenu' => 'b2b_websites',
            'adminSiteName' => AdminWeb::siteName(),
            'b2bWebsites' => $this->buildB2BWebsites($b2bCatalog),
        ]);
    }

    public function open(string $websiteKey, B2BIndustryWebsiteCatalog $b2bCatalog): RedirectResponse
    {
        abort_unless($b2bCatalog->exists($websiteKey), 404);

        $siteId = (int) (app(CurrentSite::class)->id() ?? 0);
        $adminId = (int) (auth('admin')->id() ?? 0);
        abort_unless($siteId > 0 && $adminId > 0, 403);

        AdminB2BWebsiteOpening::query()->withoutGlobalScopes()->firstOrCreate([
            'site_id' => $siteId,
            'owner_admin_id' => $adminId,
            'website_key' => $websiteKey,
        ]);

        return redirect()->route('admin.b2b-websites.index')->with('message', 'B2B行业网站已开通');
    }

    /**
     * @return list<array{key: string, name: string, logo: string, opened: bool}>
     */
    private function buildB2BWebsites(B2BIndustryWebsiteCatalog $b2bCatalog): array
    {
        $siteId = (int) (app(CurrentSite::class)->id() ?? 0);
        $adminId = (int) (auth('admin')->id() ?? 0);

        $openedKeys = $siteId > 0 && $adminId > 0 && Schema::hasTable('admin_b2b_website_openings')
            ? AdminB2BWebsiteOpening::query()
                ->withoutGlobalScopes()
                ->where('site_id', $siteId)
                ->where('owner_admin_id', $adminId)
                ->pluck('website_key')
                ->all()
            : [];

        $openedKeyMap = array_fill_keys($openedKeys, true);

        return array_map(
            fn (array $website): array => $website + ['opened' => isset($openedKeyMap[$website['key']])],
            $b2bCatalog->all()
        );
    }
}
