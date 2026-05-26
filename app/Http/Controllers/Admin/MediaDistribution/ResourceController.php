<?php

namespace App\Http\Controllers\Admin\MediaDistribution;

use App\Http\Controllers\Controller;
use App\Models\MediaResource;
use App\Models\MediaResourceSitePrice;
use App\Models\Site;
use App\Services\MediaDistribution\MediaResourceSyncService;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ResourceController extends Controller
{
    public function index(Request $request): View
    {
        $sourceType = (string) $request->query('source_type', '');
        if (! in_array($sourceType, [MediaResource::SOURCE_WEBSITE, MediaResource::SOURCE_ZI_MEDIA], true)) {
            $sourceType = '';
        }

        $query = MediaResource::query()->orderByDesc('status')->orderBy('sale_price')->orderBy('id');
        if ($sourceType !== '') {
            $query->where('source_type', $sourceType);
        }
        if (filled($request->query('search'))) {
            $query->where('title', 'like', '%'.(string) $request->query('search').'%');
        }
        if (filled($request->query('category'))) {
            $query->where('category', (string) $request->query('category'));
        }
        if (in_array((string) $request->query('status'), ['active', 'inactive'], true)) {
            $query->where('status', (string) $request->query('status'));
        }
        if (is_numeric($request->query('min_price'))) {
            $query->where('sale_price', '>=', (float) $request->query('min_price'));
        }
        if (is_numeric($request->query('max_price'))) {
            $query->where('sale_price', '<=', (float) $request->query('max_price'));
        }

        return view('admin.media-distribution.resources', [
            'pageTitle' => '分发媒体',
            'activeMenu' => 'media_distribution',
            'adminSiteName' => AdminWeb::siteName(),
            'resources' => $query->paginate(20)->withQueryString(),
            'categories' => MediaResource::query()
                ->where('category', '<>', '')
                ->distinct()
                ->orderBy('category')
                ->pluck('category'),
            'sites' => (bool) auth('admin')->user()?->isSuperAdmin()
                ? Site::query()->orderBy('id')->get(['id', 'name'])
                : collect(),
            'sourceType' => $sourceType,
            'search' => (string) $request->query('search', ''),
            'category' => (string) $request->query('category', ''),
            'status' => (string) $request->query('status', ''),
            'minPrice' => (string) $request->query('min_price', ''),
            'maxPrice' => (string) $request->query('max_price', ''),
            'isSuperAdmin' => (bool) auth('admin')->user()?->isSuperAdmin(),
        ]);
    }

    public function sync(MediaResourceSyncService $syncService): RedirectResponse
    {
        $this->ensureSuperAdmin();
        $result = $syncService->syncAll();

        return redirect()
            ->route('admin.media-distribution.resources.index')
            ->with('message', '媒体资源同步完成，共处理 '.$result['synced'].' 条');
    }

    public function updatePrice(Request $request, MediaResource $resource): RedirectResponse
    {
        $this->ensureSuperAdmin();
        $payload = $request->validate([
            'sale_price' => ['required', 'numeric', 'min:0', 'max:999999'],
        ]);

        $resource->update([
            'sale_price' => number_format((float) $payload['sale_price'], 2, '.', ''),
        ]);

        return redirect()->route('admin.media-distribution.resources.index')->with('message', '媒体销售价已更新');
    }

    public function updateSitePrice(Request $request, MediaResource $resource): RedirectResponse
    {
        $this->ensureSuperAdmin();
        $payload = $request->validate([
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'sale_price' => ['required', 'numeric', 'min:0', 'max:999999'],
        ]);

        MediaResourceSitePrice::query()->updateOrCreate(
            [
                'site_id' => (int) $payload['site_id'],
                'media_resource_id' => (int) $resource->id,
            ],
            [
                'sale_price' => number_format((float) $payload['sale_price'], 2, '.', ''),
            ]
        );

        return redirect()->route('admin.media-distribution.resources.index')->with('message', '站点专属媒体价格已更新');
    }

    private function ensureSuperAdmin(): void
    {
        if (! auth('admin')->user()?->isSuperAdmin()) {
            throw ValidationException::withMessages(['permission' => '仅超级管理员可执行该操作']);
        }
    }
}
