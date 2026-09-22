<?php

/**
 * Created by Codex.
 * @Date: 2026-09-21
 * @Time: 16:29:24
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File：ManagementProductCaseController.php
 * @Description: 管理工具中的产品案例查询、编辑、发布和删除控制器
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\ProductCase;
use App\Support\AdminDataScope;
use App\Support\AdminWeb;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ManagementProductCaseController extends Controller
{
    public function __construct(private readonly AdminDataScope $adminDataScope) {}

    /**
     * 查看当前管理员可管理的产品案例。
     * @Url GET /geo_admin/management-tools/product-cases
     *      登录 是
     *
     *      分页参数：
     *      keyword string 可选 标题、品牌或摘要关键词
     *      status string 可选 案例状态
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:29:24
     * @UpdateTime: 2026-09-21 18:41:12
     *
     * @Return View 产品案例管理页面
     * @Throws 403 当前未登录后台管理员
     */
    public function index(Request $request): View
    {
        $admin = $this->currentAdmin();
        $keyword = trim((string) $request->query('keyword', ''));
        $status = trim((string) $request->query('status', ''));
        $perPage = max(1, min(100, (int) config('geoflow.admin_items_per_page', 20)));

        $cases = $this->visibleCaseQuery($admin)
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

        return view('admin.management-tools.product-cases.index', [
            'pageTitle' => '案例管理',
            'activeMenu' => 'management_tools',
            'adminSiteName' => AdminWeb::siteName(),
            'cases' => $cases,
            'filters' => compact('keyword', 'status'),
            'statusLabels' => $this->statusLabels(),
        ]);
    }

    /**
     * 查看产品案例编辑页面。
     * @Url GET /geo_admin/management-tools/product-cases/{productCase}/edit
     *      登录 是
     *      productCase int 必选 产品案例 ID
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:29:24
     * @UpdateTime: 2026-09-21 18:56:14
     *
     * @Return View 产品案例编辑页面
     * @Throws 404 案例不存在或不属于当前管理员可见范围
     */
    public function edit(int $productCase): View
    {
        $case = $this->findVisibleCase($this->currentAdmin(), $productCase);

        return view('admin.management-tools.product-cases.edit', [
            'pageTitle' => '编辑案例',
            'activeMenu' => 'management_tools',
            'adminSiteName' => AdminWeb::siteName(),
            'case' => $case,
            'industryOptions' => ProductCase::industryOptions((string) $case->industry),
            'regionOptions' => ProductCase::regionOptions((string) $case->region),
            'statusLabels' => $this->statusLabels(),
            'action' => route('admin.management-tools.product-cases.update', ['productCase' => (int) $case->id]),
        ]);
    }

    /**
     * 保存管理工具中的产品案例。
     * @Url PUT /geo_admin/management-tools/product-cases/{productCase}
     *      登录 是
     *      productCase int 必选 产品案例 ID
     *      title string 必选 案例标题
     *      slug string 可选 案例别名
     *      company_name string 可选 公司或品牌名称
     *      logo_url string 可选 Logo 地址
     *      cover_url string 可选 封面地址
     *      industry string 可选 行业
     *      region string 可选 地区
     *      summary string 可选 摘要
     *      content string 可选 案例正文
     *      customer_level string 可选 客户等级
     *      started_at date 可选 服务开始日期
     *      status string 必选 展示状态
     *      sort_order integer 可选 排序值
     *      published_at datetime 可选 发布时间
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:29:24
     * @UpdateTime: 2026-09-21 18:56:14
     *
     * @Return RedirectResponse 返回案例管理页面
     * @Throws 422 参数校验失败
     * @Throws 404 案例不存在或不属于当前管理员可见范围
     */
    public function update(Request $request, int $productCase): RedirectResponse
    {
        $admin = $this->currentAdmin();
        $case = $this->findVisibleCase($admin, $productCase);
        $payload = $this->validatedPayload($request, $case);

        $case->update($payload + [
            'updated_by_admin_id' => (int) $admin->id,
        ]);

        return redirect()
            ->route('admin.management-tools.product-cases.index')
            ->with('message', '产品案例已更新');
    }

    /**
     * 切换产品案例发布状态。
     * @Url POST /geo_admin/management-tools/product-cases/{productCase}/toggle-status
     *      登录 是
     *      productCase int 必选 产品案例 ID
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:29:24
     * @UpdateTime: 2026-09-21 16:29:24
     *
     * @Return RedirectResponse 返回案例管理页面
     * @Throws 404 案例不存在或不属于当前管理员可见范围
     */
    public function toggleStatus(int $productCase): RedirectResponse
    {
        $admin = $this->currentAdmin();
        $case = $this->findVisibleCase($admin, $productCase);
        $nextStatus = $case->status === ProductCase::STATUS_PUBLISHED
            ? ProductCase::STATUS_HIDDEN
            : ProductCase::STATUS_PUBLISHED;

        $case->update([
            'status' => $nextStatus,
            'published_at' => $nextStatus === ProductCase::STATUS_PUBLISHED ? now() : null,
            'updated_by_admin_id' => (int) $admin->id,
        ]);

        return redirect()
            ->route('admin.management-tools.product-cases.index')
            ->with('message', $nextStatus === ProductCase::STATUS_PUBLISHED ? '产品案例已发布' : '产品案例已下架');
    }

    /**
     * 删除产品案例。
     * @Url DELETE /geo_admin/management-tools/product-cases/{productCase}
     *      登录 是
     *      productCase int 必选 产品案例 ID
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:29:24
     * @UpdateTime: 2026-09-21 16:29:24
     *
     * @Return RedirectResponse 返回案例管理页面
     * @Throws 404 案例不存在或不属于当前管理员可见范围
     */
    public function destroy(int $productCase): RedirectResponse
    {
        $case = $this->findVisibleCase($this->currentAdmin(), $productCase);
        $case->delete();

        return redirect()
            ->route('admin.management-tools.product-cases.index')
            ->with('message', '产品案例已删除');
    }

    /**
     * 校验并规范案例编辑数据。
     *
     * @param  Request $request
     * @param  ProductCase $case
     * @return array<string,mixed>
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:29:24
     * @UpdateTime: 2026-09-21 16:29:24
     * @Throws \Illuminate\Validation\ValidationException 参数不符合业务约束
     */
    private function validatedPayload(Request $request, ProductCase $case): array
    {
        $slugUniqueRule = Rule::unique('product_cases', 'slug')->ignore($case->id);
        $payload = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'slug' => ['nullable', 'string', 'max:220', 'regex:/^[A-Za-z0-9_-]+$/', $slugUniqueRule],
            'company_name' => ['nullable', 'string', 'max:180'],
            'logo_url' => ['nullable', 'string', 'max:500'],
            'cover_url' => ['nullable', 'string', 'max:500'],
            'industry' => ['nullable', 'string', 'max:120', Rule::in(ProductCase::industryOptions((string) $case->industry))],
            'region' => ['nullable', 'string', 'max:120', Rule::in(ProductCase::regionOptions((string) $case->region))],
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
        $attributes['started_at'] = $this->nullableDate((string) ($payload['started_at'] ?? ''));
        $attributes['published_at'] = $publishedAt;
        $attributes['sort_order'] = (int) ($payload['sort_order'] ?? 0);

        return $attributes;
    }

    /**
     * 查询当前管理员可见的产品案例。
     *
     * @param  Admin $admin
     * @return \Illuminate\Database\Eloquent\Builder<ProductCase>
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:29:24
     * @UpdateTime: 2026-09-21 16:29:24
     */
    private function visibleCaseQuery(Admin $admin)
    {
        $query = ProductCase::query();
        $this->adminDataScope->applySiteScope($query, $admin, 'product_cases.site_id');

        return $query;
    }

    /**
     * 获取当前管理员可见的单个案例。
     *
     * @param  Admin $admin
     * @param  int $productCaseId
     * @return ProductCase
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:29:24
     * @UpdateTime: 2026-09-21 16:29:24
     */
    private function findVisibleCase(Admin $admin, int $productCaseId): ProductCase
    {
        return $this->visibleCaseQuery($admin)->whereKey($productCaseId)->firstOrFail();
    }

    /**
     * 获取当前后台管理员。
     *
     * @return Admin
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:29:24
     * @UpdateTime: 2026-09-21 16:29:24
     */
    private function currentAdmin(): Admin
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof Admin && $admin->isSuperAdmin(), 403);

        return $admin;
    }

    /**
     * 转换日期字段。
     *
     * @param  string $value
     * @return Carbon|null
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:29:24
     * @UpdateTime: 2026-09-21 16:29:24
     */
    private function nullableDate(string $value): ?Carbon
    {
        $value = trim($value);

        return $value === '' ? null : Carbon::parse($value)->startOfDay();
    }

    /**
     * 转换日期时间字段。
     *
     * @param  string $value
     * @return Carbon|null
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:29:24
     * @UpdateTime: 2026-09-21 16:29:24
     */
    private function nullableDateTime(string $value): ?Carbon
    {
        $value = trim($value);

        return $value === '' ? null : Carbon::parse($value);
    }

    /**
     * 返回状态键。
     *
     * @return list<string>
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:29:24
     * @UpdateTime: 2026-09-21 16:29:24
     */
    private function statusKeys(): array
    {
        return array_keys($this->statusLabels());
    }

    /**
     * 返回状态名称。
     *
     * @return array<string,string>
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:29:24
     * @UpdateTime: 2026-09-21 16:29:24
     */
    private function statusLabels(): array
    {
        return [
            ProductCase::STATUS_DRAFT => '草稿',
            ProductCase::STATUS_PUBLISHED => '已发布',
            ProductCase::STATUS_HIDDEN => '已下架',
        ];
    }
}
