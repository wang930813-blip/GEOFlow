<?php

namespace App\Http\Controllers\Admin\MediaDistribution;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMediaResourceSyncJob;
use App\Models\MediaApiSetting;
use App\Models\MediaResource;
use App\Models\MediaResourceSitePrice;
use App\Models\MediaResourceSyncRun;
use App\Models\Site;
use App\Support\AdminWeb;
use Illuminate\Http\JsonResponse;
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
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $like = '%'.$this->escapeLike(mb_strtolower($search)).'%';
            $rawPayloadSearchSqls = $this->rawPayloadSearchSqls($query->getModel()->getConnection()->getDriverName());
            $query->where(function ($builder) use ($like, $rawPayloadSearchSqls): void {
                $builder
                    ->whereRaw('LOWER(title) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(external_resource_id) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(category) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(remarks, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(case_link) LIKE ?', [$like]);
                foreach ($rawPayloadSearchSqls as $rawPayloadSearchSql) {
                    $builder->orWhereRaw($rawPayloadSearchSql, [$like]);
                }
            });
        }
        if (filled($request->query('category'))) {
            $query->where('category', (string) $request->query('category'));
        }
        $status = $request->query->has('status') ? (string) $request->query('status', '') : 'active';
        if ($status === 'all') {
            $status = '';
        }
        if (in_array($status, ['active', 'inactive'], true)) {
            $query->where('status', $status);
        }
        if (is_numeric($request->query('min_price'))) {
            $query->where('sale_price', '>=', (float) $request->query('min_price'));
        }
        if (is_numeric($request->query('max_price'))) {
            $query->where('sale_price', '<=', (float) $request->query('max_price'));
        }

        $setting = MediaApiSetting::query()->orderByDesc('id')->first();

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
            'search' => $search,
            'category' => (string) $request->query('category', ''),
            'status' => $status,
            'minPrice' => (string) $request->query('min_price', ''),
            'maxPrice' => (string) $request->query('max_price', ''),
            'priceMultiplier' => number_format((float) ($setting?->price_multiplier ?? 1), 2, '.', ''),
            'isSuperAdmin' => (bool) auth('admin')->user()?->isSuperAdmin(),
            'latestSyncRun' => MediaResourceSyncRun::query()->latest('id')->first(),
        ]);
    }

    public function sync(): RedirectResponse
    {
        $this->ensureSuperAdmin();
        $run = MediaResourceSyncRun::query()
            ->whereIn('status', ['pending', 'running'])
            ->latest('id')
            ->first();

        if (! $run) {
            $run = MediaResourceSyncRun::query()->create([
                'status' => 'pending',
                'started_by_admin_id' => (int) auth('admin')->id(),
            ]);

            ProcessMediaResourceSyncJob::dispatch((int) $run->id)->onQueue('distribution');
        }

        return redirect()
            ->route('admin.media-distribution.resources.index')
            ->with('message', '媒体资源同步任务已开始，请稍后查看进度');
    }

    public function syncStatus(): JsonResponse
    {
        $this->ensureSuperAdmin();

        $run = MediaResourceSyncRun::query()->latest('id')->first();

        return response()->json([
            'run' => $run ? $this->syncRunPayload($run) : null,
        ]);
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

    public function updatePriceMultiplier(Request $request): RedirectResponse
    {
        $this->ensureSuperAdmin();
        $payload = $request->validate([
            'price_multiplier' => ['required', 'numeric', 'min:0', 'max:9999'],
        ]);
        $multiplier = (float) $payload['price_multiplier'];

        $setting = MediaApiSetting::query()->orderByDesc('id')->first() ?? new MediaApiSetting();
        $setting->price_multiplier = number_format($multiplier, 2, '.', '');
        $setting->save();

        MediaResource::query()
            ->select(['id', 'cost_price'])
            ->orderBy('id')
            ->chunkById(200, function ($resources) use ($multiplier): void {
                foreach ($resources as $resource) {
                    $resource->forceFill([
                        'sale_price' => number_format(max(0, (float) $resource->cost_price * $multiplier), 2, '.', ''),
                    ])->save();
                }
            });

        return redirect()
            ->route('admin.media-distribution.resources.index')
            ->with('message', '积分价倍率已应用到全部媒体资源');
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

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @return array<string,mixed>
     */
    private function syncRunPayload(MediaResourceSyncRun $run): array
    {
        return [
            'id' => (int) $run->id,
            'status' => (string) $run->status,
            'current_source_type' => (string) $run->current_source_type,
            'current_page' => (int) $run->current_page,
            'website_synced' => (int) $run->website_synced,
            'zi_media_synced' => (int) $run->zi_media_synced,
            'total_synced' => (int) $run->total_synced,
            'last_error_message' => (string) ($run->last_error_message ?? ''),
            'started_at' => $run->started_at?->toDateTimeString(),
            'completed_at' => $run->completed_at?->toDateTimeString(),
        ];
    }

    /**
     * @return list<string>
     */
    private function rawPayloadSearchSqls(string $driver): array
    {
        $keys = ['title', 'media_name', 'name', 'site_name', 'account_name', 'category', 'field', 'remarks', 'remark'];

        $sql = [];
        foreach ($keys as $key) {
            $sql[] = match ($driver) {
                'mysql' => "LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.".$key."')), '')) LIKE ?",
                'pgsql' => "LOWER(COALESCE(raw_payload->>'".$key."', '')) LIKE ?",
                default => "LOWER(COALESCE(json_extract(raw_payload, '$.".$key."'), '')) LIKE ?",
            };
        }

        $sql[] = $driver === 'mysql'
            ? 'LOWER(COALESCE(CAST(raw_payload AS CHAR), \'\')) LIKE ?'
            : 'LOWER(COALESCE(CAST(raw_payload AS TEXT), \'\')) LIKE ?';

        return $sql;
    }
}
