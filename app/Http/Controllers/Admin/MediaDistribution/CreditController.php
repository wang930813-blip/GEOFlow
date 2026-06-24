<?php

namespace App\Http\Controllers\Admin\MediaDistribution;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminCreditAccount;
use App\Models\AdminCreditLedger;
use App\Models\MediaSubmission;
use App\Models\Site;
use App\Services\MediaDistribution\AdminCreditService;
use App\Support\AdminWeb;
use App\Support\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CreditController extends Controller
{
    public function index(): View
    {
        $admin = auth('admin')->user();
        $isSuperAdmin = (bool) $admin?->isSuperAdmin();
        $currentSite = app(CurrentSite::class)->get();

        $this->ensureVisibleAccounts($admin, $currentSite, $isSuperAdmin);

        $accounts = AdminCreditAccount::query()
            ->with(['admin:id,username,display_name,deleted_at', 'site:id,name'])
            ->whereHas('admin')
            ->whereHas('site')
            ->when(! $isSuperAdmin, fn ($query) => $query
                ->where('admin_id', (int) ($admin?->id ?? 0))
                ->where('site_id', (int) ($currentSite?->id ?? 0)))
            ->orderBy('admin_id')
            ->orderBy('site_id')
            ->get();

        $ledger = AdminCreditLedger::query()
            ->with(['admin:id,username,display_name,deleted_at', 'site:id,name'])
            ->whereHas('admin')
            ->whereHas('site')
            ->when(! $isSuperAdmin, fn ($query) => $query
                ->where('admin_id', (int) ($admin?->id ?? 0))
                ->where('site_id', (int) ($currentSite?->id ?? 0)))
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

    public function recharge(Request $request, AdminCreditAccount $account, AdminCreditService $credits): RedirectResponse
    {
        abort_unless(auth('admin')->user()?->isSuperAdmin(), 403);
        $this->authorizeAccount($account);

        $payload = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        $credits->grant(
            (int) $account->admin_id,
            (int) $account->site_id,
            (string) $payload['amount'],
            (int) auth('admin')->id(),
            trim((string) ($payload['remark'] ?? ''))
        );

        return redirect()->route('admin.media-distribution.credits.index')->with('message', '账号积分已充值');
    }

    public function adjust(Request $request, AdminCreditAccount $account, AdminCreditService $credits): RedirectResponse
    {
        abort_unless(auth('admin')->user()?->isSuperAdmin(), 403);
        $this->authorizeAccount($account);

        $payload = $request->validate([
            'amount' => ['required', 'numeric', 'between:-999999,999999', 'not_in:0'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $credits->adjust(
                (int) $account->admin_id,
                (int) $account->site_id,
                (string) $payload['amount'],
                (int) auth('admin')->id(),
                trim((string) ($payload['remark'] ?? ''))
            );
        } catch (\Throwable $e) {
            return back()->withErrors($e->getMessage())->withInput();
        }

        return redirect()->route('admin.media-distribution.credits.index')->with('message', '账号积分已调整');
    }

    public function export(): StreamedResponse
    {
        $admin = auth('admin')->user();
        $isSuperAdmin = (bool) $admin?->isSuperAdmin();
        $query = AdminCreditLedger::query()
            ->with(['admin:id,username,display_name,deleted_at', 'site:id,name'])
            ->whereHas('admin')
            ->whereHas('site')
            ->when(! $isSuperAdmin, fn ($query) => $query
                ->where('admin_id', (int) ($admin?->id ?? 0))
                ->where('site_id', (int) app(CurrentSite::class)->id()))
            ->orderByDesc('id');

        return response()->stream(function () use ($query, $isSuperAdmin): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $isSuperAdmin
                ? ['ID', '用户[站点]', '类型', '变动积分', '余额', '冻结', '备注', '时间']
                : ['ID', '类型', '变动积分', '余额', '冻结', '备注', '时间']);
            $query->chunk(200, function ($rows) use ($out, $isSuperAdmin): void {
                foreach ($rows as $row) {
                    $data = [
                        $row->id,
                        $row->type,
                        $row->amount,
                        $row->balance_after,
                        $row->frozen_after,
                        $row->remark,
                        $row->created_at?->format('Y-m-d H:i:s'),
                    ];
                    if ($isSuperAdmin) {
                        array_splice($data, 1, 0, [$this->accountDisplayName($row)]);
                    }
                    fputcsv($out, $data);
                }
            });
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="admin-credit-ledger.csv"',
        ]);
    }

    public function consumptionExport(): StreamedResponse
    {
        abort_unless(auth('admin')->user()?->isSuperAdmin(), 403);

        $query = AdminCreditLedger::query()
            ->with(['admin:id,username,display_name,deleted_at', 'site:id,name'])
            ->whereHas('admin')
            ->whereHas('site')
            ->whereIn('type', ['deduct', 'refund'])
            ->orderByDesc('id');

        return response()->stream(function () use ($query): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['ID', '用户[站点]', '类型', '变动积分', '余额', '订单号', '文章标题', '备注', '时间']);
            $query->chunk(200, function ($rows) use ($out): void {
                foreach ($rows as $row) {
                    $submission = $row->submission_id
                        ? MediaSubmission::withoutGlobalScope('current_site')->whereKey((int) $row->submission_id)->first()
                        : null;
                    fputcsv($out, [
                        $row->id,
                        $this->accountDisplayName($row),
                        $row->type,
                        $row->amount,
                        $row->balance_after,
                        $submission?->external_order_nid,
                        $submission?->title_snapshot,
                        $row->remark,
                        $row->created_at?->format('Y-m-d H:i:s'),
                    ]);
                }
            });
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="admin-consumption-records.csv"',
        ]);
    }

    private function ensureVisibleAccounts(?Admin $admin, ?Site $currentSite, bool $isSuperAdmin): void
    {
        $creditService = app(AdminCreditService::class);

        if ($isSuperAdmin) {
            Site::query()
                ->with('members:id')
                ->orderBy('id')
                ->chunkById(100, function ($sites) use ($creditService): void {
                    foreach ($sites as $site) {
                        foreach ($site->members as $member) {
                            $creditService->accountForAdmin((int) $member->id, (int) $site->id);
                        }
                    }
                });

            return;
        }

        if ($admin instanceof Admin && $currentSite instanceof Site) {
            $creditService->accountForAdmin((int) $admin->id, (int) $currentSite->id);
        }
    }

    private function authorizeAccount(AdminCreditAccount $account): void
    {
        abort_unless($account->admin()->exists() && $account->site()->exists(), 404);
    }

    private function accountDisplayName(AdminCreditAccount|AdminCreditLedger $record): string
    {
        $admin = $record->admin;
        $site = $record->site;
        $adminName = trim((string) ($admin?->display_name ?: $admin?->username));
        $siteName = trim((string) ($site?->name ?? ''));

        return ($adminName !== '' ? $adminName : '-').'['.($siteName !== '' ? $siteName : '-').']';
    }
}
