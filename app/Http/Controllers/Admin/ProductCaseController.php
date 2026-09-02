<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\ProductCase;
use App\Models\Site;
use App\Support\AdminWeb;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductCaseController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizedAdmin();

        $keyword = trim((string) $request->query('keyword', ''));
        $status = trim((string) $request->query('status', ''));
        $perPage = max(1, min(100, (int) config('geoflow.admin_items_per_page', 20)));

        $cases = ProductCase::query()
            ->with(['site:id,name,domain,owner_admin_id', 'owner:id,username,display_name'])
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($keyword, 'UTF-8')).'%';
                $query->where(function ($inner) use ($like): void {
                    $inner->whereRaw('LOWER(title) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(company_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(summary) LIKE ?', [$like]);
                });
            })
            ->when(in_array($status, $this->statusKeys(), true), fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return view('admin.product-cases.index', [
            'pageTitle' => '产品案例管理',
            'activeMenu' => 'product_cases_manage',
            'adminSiteName' => AdminWeb::siteName(),
            'cases' => $cases,
            'filters' => compact('keyword', 'status'),
            'statusLabels' => $this->statusLabels(),
        ]);
    }

    public function create(): View
    {
        $this->authorizedAdmin();

        return view('admin.product-cases.create', [
            'pageTitle' => '新增产品案例',
            'activeMenu' => 'product_cases_manage',
            'adminSiteName' => AdminWeb::siteName(),
            'case' => new ProductCase,
            'sites' => $this->siteOptions(),
            'admins' => $this->adminOptions(),
            'industryOptions' => $this->industryOptions(),
            'regionOptions' => $this->regionOptions(),
            'statusLabels' => $this->statusLabels(),
            'submitLabel' => '创建案例',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $admin = $this->authorizedAdmin();
        $payload = $this->validatedPayload($request);

        $case = ProductCase::query()->create($payload + [
            'created_by_admin_id' => (int) $admin->id,
            'updated_by_admin_id' => (int) $admin->id,
        ]);

        return redirect()
            ->route('admin.product-cases.index')
            ->with('message', '产品案例已创建：'.$case->title);
    }

    public function edit(ProductCase $productCase): View
    {
        $this->authorizedAdmin();

        return view('admin.product-cases.edit', [
            'pageTitle' => '编辑产品案例',
            'activeMenu' => 'product_cases_manage',
            'adminSiteName' => AdminWeb::siteName(),
            'case' => $productCase,
            'sites' => $this->siteOptions(),
            'admins' => $this->adminOptions(),
            'industryOptions' => $this->industryOptions($productCase),
            'regionOptions' => $this->regionOptions($productCase),
            'statusLabels' => $this->statusLabels(),
            'submitLabel' => '保存修改',
        ]);
    }

    public function update(Request $request, ProductCase $productCase): RedirectResponse
    {
        $admin = $this->authorizedAdmin();
        $payload = $this->validatedPayload($request, $productCase);
        $payload['updated_by_admin_id'] = (int) $admin->id;

        $productCase->update($payload);

        return redirect()
            ->route('admin.product-cases.index')
            ->with('message', '产品案例已更新');
    }

    public function toggleStatus(ProductCase $productCase): RedirectResponse
    {
        $admin = $this->authorizedAdmin();
        $nextStatus = $productCase->status === ProductCase::STATUS_PUBLISHED
            ? ProductCase::STATUS_HIDDEN
            : ProductCase::STATUS_PUBLISHED;

        $productCase->update([
            'status' => $nextStatus,
            'published_at' => $nextStatus === ProductCase::STATUS_PUBLISHED && ! $productCase->published_at
                ? now()
                : $productCase->published_at,
            'updated_by_admin_id' => (int) $admin->id,
        ]);

        return redirect()
            ->route('admin.product-cases.index')
            ->with('message', $nextStatus === ProductCase::STATUS_PUBLISHED ? '产品案例已发布' : '产品案例已隐藏');
    }

    public function destroy(ProductCase $productCase): RedirectResponse
    {
        $this->authorizedAdmin();
        $productCase->delete();

        return redirect()
            ->route('admin.product-cases.index')
            ->with('message', '产品案例已删除');
    }

    /**
     * @return array<string,mixed>
     */
    private function validatedPayload(Request $request, ?ProductCase $case = null): array
    {
        $slugUniqueRule = Rule::unique('product_cases', 'slug');
        if ($case instanceof ProductCase && $case->exists) {
            $slugUniqueRule->ignore($case->id);
        }

        $payload = $request->validate([
            'site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')],
            'owner_admin_id' => ['nullable', 'integer', Rule::exists('admins', 'id')],
            'title' => ['required', 'string', 'max:180'],
            'slug' => ['nullable', 'string', 'max:220', 'regex:/^[A-Za-z0-9_-]+$/', $slugUniqueRule],
            'company_name' => ['nullable', 'string', 'max:180'],
            'logo_url' => ['nullable', 'string', 'max:500'],
            'cover_url' => ['nullable', 'string', 'max:500'],
            'industry' => ['nullable', 'string', 'max:120', Rule::in($this->industryOptions($case))],
            'region' => ['nullable', 'string', 'max:120', Rule::in($this->regionOptions($case))],
            'summary' => ['nullable', 'string', 'max:1000'],
            'content' => ['nullable', 'string'],
            'customer_level' => ['nullable', 'string', 'max:80'],
            'started_at' => ['nullable', 'date'],
            'status' => ['required', Rule::in($this->statusKeys())],
            'sort_order' => ['nullable', 'integer', 'min:-999999', 'max:999999'],
            'published_at' => ['nullable', 'date'],
        ], [
            'title.required' => '请填写案例标题',
            'slug.regex' => '案例别名只能包含英文、数字、中横线和下划线',
            'status.in' => '案例状态不正确',
        ]);

        $site = null;
        $siteId = (int) ($payload['site_id'] ?? 0);
        if ($siteId > 0) {
            $site = Site::query()->find($siteId);
        }

        $ownerAdminId = (int) ($payload['owner_admin_id'] ?? 0);
        if ($ownerAdminId <= 0 && $site instanceof Site && (int) $site->owner_admin_id > 0) {
            $ownerAdminId = (int) $site->owner_admin_id;
        }

        $slug = trim((string) ($payload['slug'] ?? ''));
        if ($slug === '') {
            $slug = ProductCase::uniqueSlug((string) $payload['title'], $case);
        }

        $publishedAt = $this->nullableDateTime((string) ($payload['published_at'] ?? ''));
        if ((string) $payload['status'] === ProductCase::STATUS_PUBLISHED && $publishedAt === null) {
            $publishedAt = now();
        }

        $attributes = Arr::only($payload, [
            'title',
            'company_name',
            'logo_url',
            'cover_url',
            'industry',
            'region',
            'summary',
            'content',
            'customer_level',
            'status',
        ]);

        foreach ([
            'company_name',
            'logo_url',
            'cover_url',
            'industry',
            'region',
            'summary',
            'customer_level',
        ] as $stringKey) {
            $attributes[$stringKey] = (string) ($attributes[$stringKey] ?? '');
        }

        $attributes['slug'] = $slug;
        $attributes['site_id'] = $siteId > 0 ? $siteId : null;
        $attributes['owner_admin_id'] = $ownerAdminId > 0 ? $ownerAdminId : null;
        $attributes['started_at'] = $this->nullableDate((string) ($payload['started_at'] ?? ''));
        $attributes['published_at'] = $publishedAt;
        $attributes['sort_order'] = (int) ($payload['sort_order'] ?? 0);

        return $attributes;
    }

    private function industryOptions(?ProductCase $case = null): array
    {
        return $this->withCurrentOption([
            '服装',
            '化工',
            '玩具',
            '精细化学品',
            '食品、饮料',
            '机械及行业设备',
            '电子元器件',
            '礼品、工艺品、饰品',
            '通信产品',
            '其他',
            '二手设备',
            '五金、工具',
            '交通运输',
            '仪器仪表',
            '传媒、广电',
            '农业',
            '冶金矿产',
            '办公、文教',
            '包装',
            '医药、保养',
            '医药健康',
            '印刷',
            '商务服务',
            '安全、防护',
            '家居用品',
            '家用电器',
            '建筑、建材',
            '教育培训',
            '数码、电脑',
            '服装内衣',
            '服饰',
            '橡塑',
            '汽摩及配件',
            '照明工业',
            '环保',
            '电工电气',
            '纸业',
            '纺织、皮革',
            '能源',
            '航天航空',
            '运动、休闲',
            '鞋包配饰',
        ], (string) ($case?->industry ?? ''));
    }

    private function regionOptions(?ProductCase $case = null): array
    {
        return $this->withCurrentOption([
            '上海市',
            '苏州市',
            '深圳市',
            '成都市',
            '无锡市',
            '新乡市',
            '淄博市',
            '杭州市',
            '泉州市',
            '温州市',
            '福州市',
            '烟台市',
            '长春市',
            '北京市',
            '郑州市',
            '兰州市',
            '东莞市',
            '南京市',
            '贵阳市',
            '青岛市',
            '中山市',
            '广州市',
            '大连市',
            '常州市',
            '武汉市',
            '宁波市',
            '厦门市',
            '绵阳市',
            '南昌市',
            '济宁市',
            '佛山市',
            '临沂市',
            '威海市',
            '哈尔滨市',
            '金华市',
            '台州市',
            '合肥市',
            '其他市',
        ], (string) ($case?->region ?? ''));
    }

    /**
     * @param  list<string>  $options
     * @return list<string>
     */
    private function withCurrentOption(array $options, string $currentValue): array
    {
        $currentValue = trim($currentValue);

        if ($currentValue !== '' && ! in_array($currentValue, $options, true)) {
            array_unshift($options, $currentValue);
        }

        return $options;
    }

    private function nullableDate(string $value): ?Carbon
    {
        $value = trim($value);

        return $value === '' ? null : Carbon::parse($value)->startOfDay();
    }

    private function nullableDateTime(string $value): ?Carbon
    {
        $value = trim($value);

        return $value === '' ? null : Carbon::parse($value);
    }

    private function authorizedAdmin(): Admin
    {
        $admin = auth('admin')->user();
        abort_unless($admin instanceof Admin && $admin->isSuperAdmin(), 403);

        return $admin;
    }

    /**
     * @return list<string>
     */
    private function statusKeys(): array
    {
        return array_keys($this->statusLabels());
    }

    /**
     * @return array<string,string>
     */
    private function statusLabels(): array
    {
        return [
            ProductCase::STATUS_DRAFT => '草稿',
            ProductCase::STATUS_PUBLISHED => '已发布',
            ProductCase::STATUS_HIDDEN => '已隐藏',
        ];
    }

    private function siteOptions()
    {
        return Site::query()
            ->with('owner:id,username,display_name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'owner_admin_id', 'name', 'domain']);
    }

    private function adminOptions()
    {
        return Admin::query()
            ->select(['id', 'username', 'display_name', 'role', 'status'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }
}
