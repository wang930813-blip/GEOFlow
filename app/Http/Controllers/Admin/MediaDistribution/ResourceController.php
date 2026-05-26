<?php

namespace App\Http\Controllers\Admin\MediaDistribution;

use App\Http\Controllers\Controller;
use App\Models\MediaResource;
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

        return view('admin.media-distribution.resources', [
            'pageTitle' => '分发媒体',
            'activeMenu' => 'media_distribution',
            'adminSiteName' => AdminWeb::siteName(),
            'resources' => $query->paginate(20)->withQueryString(),
            'sourceType' => $sourceType,
            'search' => (string) $request->query('search', ''),
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

    private function ensureSuperAdmin(): void
    {
        if (! auth('admin')->user()?->isSuperAdmin()) {
            throw ValidationException::withMessages(['permission' => '仅超级管理员可执行该操作']);
        }
    }
}
