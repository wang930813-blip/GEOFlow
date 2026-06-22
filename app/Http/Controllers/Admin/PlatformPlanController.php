<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminPlanSubscription;
use App\Models\PlatformPlan;
use App\Models\SitePlanSubscription;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PlatformPlanController extends Controller
{
    public function index(): View
    {
        return view('admin.platform-plans.index', [
            'pageTitle' => '平台规格',
            'activeMenu' => 'platform_plans',
            'adminSiteName' => AdminWeb::siteName(),
            'plans' => PlatformPlan::query()
                ->with('entitlements')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
            'resourceCatalog' => PlatformPlan::visibleResourceCatalog(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validatedPayload($request);

        DB::transaction(function () use ($payload): void {
            $plan = PlatformPlan::query()->create([
                'name' => $payload['name'],
                'code' => $payload['code'],
                'audience' => $payload['audience'],
                'duration_days' => $payload['duration_days'],
                'price' => $payload['price'],
                'market_price' => $payload['market_price'],
                'description' => $payload['description'],
                'status' => $payload['status'],
                'sort_order' => $payload['sort_order'],
                'created_by' => auth('admin')->id(),
            ]);

            $this->syncEntitlements($plan, $payload['resources']);
        });

        return redirect()->route('admin.platform-plans.index')->with('message', '规格已创建');
    }

    public function show(PlatformPlan $plan): View
    {
        $plan->load('entitlements');

        return view('admin.platform-plans.show', [
            'pageTitle' => '规格详情',
            'activeMenu' => 'platform_plans',
            'adminSiteName' => AdminWeb::siteName(),
            'plan' => $plan,
            'resourceCatalog' => PlatformPlan::visibleResourceCatalog(),
            'siteSubscriptionCount' => SitePlanSubscription::query()->where('plan_id', (int) $plan->id)->count(),
            'adminSubscriptionCount' => AdminPlanSubscription::query()->where('plan_id', (int) $plan->id)->count(),
        ]);
    }

    public function edit(PlatformPlan $plan): View
    {
        $plan->load('entitlements');

        return view('admin.platform-plans.edit', [
            'pageTitle' => '编辑规格',
            'activeMenu' => 'platform_plans',
            'adminSiteName' => AdminWeb::siteName(),
            'plan' => $plan,
            'resourceCatalog' => PlatformPlan::visibleResourceCatalog(),
        ]);
    }

    public function update(PlatformPlan $plan, Request $request): RedirectResponse
    {
        $payload = $this->validatedPayload($request, $plan);

        DB::transaction(function () use ($plan, $payload): void {
            $plan->update([
                'name' => $payload['name'],
                'code' => $payload['code'],
                'audience' => $payload['audience'],
                'duration_days' => $payload['duration_days'],
                'price' => $payload['price'],
                'market_price' => $payload['market_price'],
                'description' => $payload['description'],
                'status' => $payload['status'],
                'sort_order' => $payload['sort_order'],
            ]);

            $this->syncEntitlements($plan, $payload['resources']);
        });

        return redirect()->route('admin.platform-plans.index')->with('message', '规格已更新');
    }

    public function destroy(PlatformPlan $plan): RedirectResponse
    {
        $isReferenced = SitePlanSubscription::query()->where('plan_id', (int) $plan->id)->exists()
            || AdminPlanSubscription::query()->where('plan_id', (int) $plan->id)->exists();

        if ($isReferenced) {
            return back()->withErrors('该规格已有开通记录，不能删除；如需下架，请将状态改为停用。');
        }

        $plan->delete();

        return redirect()->route('admin.platform-plans.index')->with('message', '规格已删除');
    }

    /**
     * @return array{
     *   name:string,code:string,audience:string,duration_days:int,price:string|null,market_price:string|null,
     *   description:string,status:string,sort_order:int,
     *   resources:array<string,array{enabled:bool,quota_value:int,quota_period:string,unit:string}>
     * }
     */
    private function validatedPayload(Request $request, ?PlatformPlan $plan = null): array
    {
        $request->merge([
            'code' => Str::of((string) $request->input('code', ''))->lower()->replaceMatches('/[^a-z0-9_-]+/', '_')->trim('_')->toString(),
            'resources' => $this->normalizeResourceInputs((array) $request->input('resources', [])),
        ]);

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'required',
                'string',
                'max:80',
                'regex:/^[a-z0-9_-]+$/',
                Rule::unique('platform_plans', 'code')->ignore($plan?->id),
            ],
            'audience' => ['required', Rule::in(['agent', 'direct', 'both'])],
            'duration_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'market_price' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'resources' => ['nullable', 'array'],
            'resources.*.enabled' => ['nullable'],
            'resources.*.quota_value' => ['nullable', 'integer', 'min:0', 'max:999999999'],
        ], [
            'name.required' => '请填写规格名称',
            'code.required' => '请填写规格编码',
            'code.regex' => '规格编码只能包含小写字母、数字、下划线和短横线',
            'code.unique' => '规格编码已存在',
            'duration_days.required' => '请填写服务时长',
        ]);

        $catalog = PlatformPlan::visibleResourceCatalog();
        $resources = [];
        $enabledResourceKeys = [];
        $resourceErrors = [];
        foreach ($catalog as $key => $meta) {
            $input = (array) data_get($payload, 'resources.'.$key, []);
            $enabled = (bool) ($input['enabled'] ?? false);
            if ($key === PlatformPlan::RESOURCE_TEAM_MEMBERS && ($payload['audience'] ?? '') === 'direct') {
                $enabled = false;
            }
            $quotaValue = (int) ($input['quota_value'] ?? 0);

            if ($enabled) {
                $enabledResourceKeys[] = $key;

                if ($quotaValue <= 0) {
                    $resourceErrors['resources.'.$key.'.quota_value'] = '套餐项数量必须大于 0';
                }
            }

            $resources[$key] = [
                'enabled' => $enabled,
                'quota_value' => $quotaValue,
                'quota_period' => 'cycle',
                'unit' => (string) $meta['unit'],
            ];
        }

        if ($enabledResourceKeys === []) {
            $resourceErrors['resources'] = '请选择套餐项';
        }

        if ($resourceErrors !== []) {
            throw ValidationException::withMessages($resourceErrors);
        }

        return [
            'name' => trim((string) $payload['name']),
            'code' => trim((string) $payload['code']),
            'audience' => (string) $payload['audience'],
            'duration_days' => (int) $payload['duration_days'],
            'price' => isset($payload['price']) && $payload['price'] !== '' ? number_format((float) $payload['price'], 2, '.', '') : null,
            'market_price' => isset($payload['market_price']) && $payload['market_price'] !== '' ? number_format((float) $payload['market_price'], 2, '.', '') : null,
            'description' => trim((string) ($payload['description'] ?? '')),
            'status' => (string) $payload['status'],
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
            'resources' => $resources,
        ];
    }

    /**
     * @param  array<string,mixed>  $resources
     * @return array<string,mixed>
     */
    private function normalizeResourceInputs(array $resources): array
    {
        foreach ($resources as $key => $resource) {
            if (! is_array($resource)) {
                continue;
            }

            $quotaValue = $resource['quota_value'] ?? null;
            if (is_scalar($quotaValue)) {
                $quotaValue = trim((string) $quotaValue);
                if (preg_match('/^\d+$/', $quotaValue) === 1) {
                    $resource['quota_value'] = ltrim($quotaValue, '0') ?: '0';
                    $resources[$key] = $resource;
                }
            }
        }

        return $resources;
    }

    /**
     * @param  array<string,array{enabled:bool,quota_value:int,quota_period:string,unit:string}>  $resources
     */
    private function syncEntitlements(PlatformPlan $plan, array $resources): void
    {
        foreach ($resources as $key => $resource) {
            $plan->entitlements()->updateOrCreate(
                ['resource_key' => $key],
                [
                    'enabled' => $resource['enabled'],
                    'quota_value' => $resource['quota_value'],
                    'quota_period' => $resource['quota_period'],
                    'unit' => $resource['unit'],
                    'meta' => [],
                ]
            );
        }
    }
}
