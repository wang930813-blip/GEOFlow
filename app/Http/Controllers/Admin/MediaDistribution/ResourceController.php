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
use App\Support\MediaDistribution\MediaPlatform;
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
        $platformId = (int) $request->query('platform_id', 0);
        if (! in_array($platformId, MediaPlatform::ids(), true)) {
            $platformId = 0;
        }

        $query = MediaResource::query()->orderByDesc('status')->orderBy('sale_price')->orderBy('id');
        if ($platformId > 0) {
            $query->where('platform_id', $platformId);
        }
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

        $priceMultiplierPlatformId = $platformId > 0 ? $platformId : MediaPlatform::CEYING_MEDIA_1;
        $setting = MediaApiSetting::query()
            ->where('platform_id', $priceMultiplierPlatformId)
            ->orderByDesc('id')
            ->first();

        return view('admin.media-distribution.resources', [
            'pageTitle' => '分发媒体',
            'activeMenu' => 'media_distribution',
            'adminSiteName' => AdminWeb::siteName(),
            'resources' => $query->paginate(20)->withQueryString(),
            'packageCards' => $this->packageCards(),
            'sites' => (bool) auth('admin')->user()?->isSuperAdmin()
                ? Site::query()->orderBy('id')->get(['id', 'name'])
                : collect(),
            'platformId' => $platformId,
            'platforms' => MediaPlatform::labels(),
            'sourceType' => $sourceType,
            'search' => $search,
            'status' => $status,
            'minPrice' => (string) $request->query('min_price', ''),
            'maxPrice' => (string) $request->query('max_price', ''),
            'priceMultiplier' => number_format((float) ($setting?->price_multiplier ?? 1), 2, '.', ''),
            'isSuperAdmin' => (bool) auth('admin')->user()?->isSuperAdmin(),
            'latestSyncRuns' => MediaResourceSyncRun::query()
                ->whereIn('id', MediaResourceSyncRun::query()
                    ->selectRaw('MAX(id)')
                    ->groupBy('platform_id'))
                ->get()
                ->keyBy('platform_id'),
        ]);
    }

    public function sync(Request $request): RedirectResponse
    {
        $this->ensureSuperAdmin();
        $platformId = (int) $request->input('platform_id', MediaPlatform::CEYING_MEDIA_1);
        if (! in_array($platformId, MediaPlatform::ids(), true)) {
            throw ValidationException::withMessages(['platform_id' => '媒体平台不正确']);
        }

        $run = MediaResourceSyncRun::query()
            ->where('platform_id', $platformId)
            ->whereIn('status', ['pending', 'running'])
            ->latest('id')
            ->first();

        if (! $run) {
            $run = MediaResourceSyncRun::query()->create([
                'platform_id' => $platformId,
                'status' => 'pending',
                'started_by_admin_id' => (int) auth('admin')->id(),
            ]);

            ProcessMediaResourceSyncJob::dispatch((int) $run->id)->onQueue('distribution');
        }

        return redirect()
            ->route('admin.media-distribution.resources.index')
            ->with('message', MediaPlatform::label($platformId).'资源同步任务已开始，请稍后查看进度');
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
            'platform_id' => ['nullable', 'integer'],
        ]);
        $multiplier = (float) $payload['price_multiplier'];
        $platformId = (int) ($payload['platform_id'] ?? MediaPlatform::CEYING_MEDIA_1);
        if (! in_array($platformId, MediaPlatform::ids(), true)) {
            throw ValidationException::withMessages(['platform_id' => '媒体平台不正确']);
        }

        $setting = MediaApiSetting::query()
            ->where('platform_id', $platformId)
            ->orderByDesc('id')
            ->first() ?? new MediaApiSetting(['platform_id' => $platformId]);
        $setting->platform_id = $platformId;
        $setting->price_multiplier = number_format($multiplier, 2, '.', '');
        $setting->save();

        MediaResource::query()
            ->select(['id', 'cost_price'])
            ->where('platform_id', $platformId)
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
            ->with('message', MediaPlatform::label($platformId).'积分价倍率已应用');
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
            'platform_id' => (int) $run->platform_id,
            'platform_label' => MediaPlatform::label((int) $run->platform_id),
            'status' => (string) $run->status,
            'current_source_type' => (string) $run->current_source_type,
            'current_page' => (int) $run->current_page,
            'website_synced' => (int) $run->website_synced,
            'zi_media_synced' => (int) $run->zi_media_synced,
            'total_synced' => (int) $run->total_synced,
            'last_error_message' => $run->displayLastErrorMessage(),
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

    /**
     * @return list<array{
     *     key:string,
     *     heading:string,
     *     resource:MediaResource,
     *     size:int,
     *     published_url_type:string,
     *     media_entries:list<array{name:string,url:string}>
     * }>
     */
    private function packageCards(): array
    {
        $cards = [];
        foreach ($this->packageConfigs() as $key => $packageConfig) {
            $packageTitle = trim((string) ($packageConfig['title'] ?? ''));
            if ($packageTitle === '') {
                continue;
            }

            $resource = MediaResource::query()
                ->where('platform_id', (int) ($packageConfig['platform_id'] ?? MediaPlatform::CEYING_MEDIA_1))
                ->where('title', $packageTitle)
                ->where('status', 'active')
                ->orderByDesc('id')
                ->first();

            if (! $resource) {
                continue;
            }

            $cards[] = [
                'key' => $key,
                'heading' => trim((string) ($packageConfig['heading'] ?? '媒体套餐发布')) ?: '媒体套餐发布',
                'resource' => $resource,
                'size' => $this->packageSize($resource, $packageConfig),
                'published_url_type' => $this->packagePublishedUrlType($resource, $packageConfig),
                'media_entries' => $this->packageMediaEntries($packageConfig),
            ];
        }

        return $cards;
    }

    /**
     * @param  array<string,mixed>  $packageConfig
     */
    private function packageSize(MediaResource $resource, array $packageConfig): int
    {
        $payloadSize = (int) $resource->apiField(['package_size', 'media_count', 'resource_count'], '0');

        return $payloadSize > 0 ? $payloadSize : (int) ($packageConfig['size'] ?? 100);
    }

    /**
     * @param  array<string,mixed>  $packageConfig
     */
    private function packagePublishedUrlType(MediaResource $resource, array $packageConfig): string
    {
        $payloadType = trim($resource->apiField(['publish_url_type', 'published_url_type', 'result_url_type'], ''));

        return $payloadType !== '' ? $payloadType : (string) ($packageConfig['published_url_type'] ?? 'docs 文档链接');
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function packageConfigs(): array
    {
        $configs = [
            'default' => (array) config('media_distribution.package', []),
            'b2b' => (array) config('media_distribution.b2b_package', []),
        ];

        return array_filter($configs, static fn (array $config): bool => trim((string) ($config['title'] ?? '')) !== '');
    }

    /**
     * @param  array<string,mixed>  $packageConfig
     * @return list<array{name:string,url:string}>
     */
    private function packageMediaEntries(array $packageConfig): array
    {
        $entries = [];

        $configuredEntries = $packageConfig['media_entries'] ?? [];
        if (is_array($configuredEntries)) {
            foreach ($configuredEntries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $name = trim((string) ($entry['name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $entries[] = [
                    'name' => $name,
                    'url' => trim((string) ($entry['url'] ?? '')),
                ];
            }
        }

        $names = $packageConfig['media_names'] ?? [];
        if (is_array($names)) {
            foreach ($names as $name) {
                $name = trim((string) $name);
                if ($name !== '') {
                    $entries[] = ['name' => $name, 'url' => ''];
                }
            }
        }

        $mediaList = trim((string) ($packageConfig['media_list'] ?? ''));
        if ($mediaList !== '') {
            foreach (preg_split('/\R/u', $mediaList) ?: [] as $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }

                if (preg_match('/^(.*?)\s+(https?:\/\/\S+)$/u', $line, $matches) === 1) {
                    $entries[] = [
                        'name' => trim((string) $matches[1]),
                        'url' => trim((string) $matches[2]),
                    ];
                    continue;
                }

                $entries[] = ['name' => $line, 'url' => ''];
            }
        }

        return $entries;
    }
}
