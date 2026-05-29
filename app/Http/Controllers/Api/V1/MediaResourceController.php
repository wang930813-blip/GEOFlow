<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\MediaResource;
use App\Models\MediaResourceSitePrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaResourceController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $page = max(1, $request->integer('page', 1));
        $perPage = max(1, min(100, $request->integer('per_page', 50)));
        $siteId = $this->auth($request)->siteId ?? $request->integer('site_id', 0);
        $query = MediaResource::query()
            ->active()
            ->when($request->filled('source_type'), fn ($q) => $q->where('source_type', (string) $request->query('source_type')))
            ->when($request->filled('search'), function ($q) use ($request): void {
                $search = '%'.trim((string) $request->query('search')).'%';
                $q->where(function ($inner) use ($search): void {
                    $inner->where('title', 'like', $search)
                        ->orWhere('category', 'like', $search)
                        ->orWhere('remarks', 'like', $search);
                });
            });

        $total = (clone $query)->count();
        $resources = $query
            ->orderBy('sale_price')
            ->orderBy('id')
            ->forPage($page, $perPage)
            ->get();

        return $this->success($request, [
            'items' => $resources->map(fn (MediaResource $resource): array => $this->resourcePayload($resource, $siteId))->all(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    public function show(Request $request, int $resource): JsonResponse
    {
        $mediaResource = MediaResource::query()->whereKey($resource)->first();
        if (! $mediaResource instanceof MediaResource) {
            throw new ApiException('media_resource_not_found', '媒体资源不存在', 404);
        }

        return $this->success($request, $this->resourcePayload($mediaResource, $this->auth($request)->siteId ?? $request->integer('site_id', 0)));
    }

    private function resourcePayload(MediaResource $resource, int $siteId = 0): array
    {
        $salePrice = $resource->sale_price;
        if ($siteId > 0) {
            $sitePrice = MediaResourceSitePrice::query()
                ->where('site_id', $siteId)
                ->where('media_resource_id', (int) $resource->id)
                ->value('sale_price');
            if ($sitePrice !== null) {
                $salePrice = $sitePrice;
            }
        }

        return [
            'id' => (int) $resource->id,
            'source_type' => (string) $resource->source_type,
            'source_label' => $resource->sourceLabel(),
            'external_resource_id' => (string) $resource->external_resource_id,
            'title' => (string) $resource->title,
            'category' => (string) $resource->category,
            'remarks' => (string) $resource->remarks,
            'case_link' => (string) $resource->case_link,
            'status' => (string) $resource->status,
            'sale_price' => number_format((float) $salePrice, 2, '.', ''),
            'last_synced_at' => $resource->last_synced_at?->format('Y-m-d H:i:s'),
        ];
    }
}
