<?php

namespace App\Http\Controllers\Admin\MediaDistribution;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteCreditAccount;
use App\Models\SiteCreditLedger;
use App\Services\MediaDistribution\SiteCreditService;
use App\Support\AdminWeb;
use App\Support\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CreditController extends Controller
{
    public function index(): View
    {
        $admin = auth('admin')->user();
        $isSuperAdmin = (bool) $admin?->isSuperAdmin();
        $sites = $isSuperAdmin
            ? Site::query()->with('owner:id,username,display_name')->orderBy('id')->get()
            : collect([app(CurrentSite::class)->get()])->filter();

        foreach ($sites as $site) {
            app(SiteCreditService::class)->accountForSite((int) $site->id);
        }

        $accounts = SiteCreditAccount::query()
            ->with('site:id,name')
            ->when(! $isSuperAdmin, fn ($query) => $query->where('site_id', app(CurrentSite::class)->id()))
            ->orderBy('site_id')
            ->get();

        $ledger = SiteCreditLedger::query()
            ->with('site:id,name')
            ->when(! $isSuperAdmin, fn ($query) => $query->where('site_id', app(CurrentSite::class)->id()))
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return view('admin.media-distribution.credits', [
            'pageTitle' => '媒体积分管理',
            'activeMenu' => 'media_distribution',
            'adminSiteName' => AdminWeb::siteName(),
            'accounts' => $accounts,
            'ledger' => $ledger,
            'isSuperAdmin' => $isSuperAdmin,
        ]);
    }

    public function recharge(Request $request, Site $site, SiteCreditService $credits): RedirectResponse
    {
        abort_unless(auth('admin')->user()?->isSuperAdmin(), 403);

        $payload = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        $credits->recharge((int) $site->id, (string) $payload['amount'], (int) auth('admin')->id(), trim((string) ($payload['remark'] ?? '')));

        return redirect()->route('admin.media-distribution.credits.index')->with('message', '站点积分已充值');
    }

    public function adjust(Request $request, Site $site, SiteCreditService $credits): RedirectResponse
    {
        abort_unless(auth('admin')->user()?->isSuperAdmin(), 403);

        $payload = $request->validate([
            'amount' => ['required', 'numeric', 'between:-999999,999999', 'not_in:0'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $credits->adjust((int) $site->id, (string) $payload['amount'], (int) auth('admin')->id(), trim((string) ($payload['remark'] ?? '')));
        } catch (\Throwable $e) {
            return back()->withErrors($e->getMessage())->withInput();
        }

        return redirect()->route('admin.media-distribution.credits.index')->with('message', '站点积分已调整');
    }
}
