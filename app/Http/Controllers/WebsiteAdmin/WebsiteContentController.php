<?php

/**
 * Created by Codex.
 * @Date: 2026-11-11
 * @Time: 16:49:23
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： WebsiteContentController.php
 * @Description: 管理客户官网的共用分类、产品展示和单页内容。
 */

namespace App\Http\Controllers\WebsiteAdmin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Category;
use App\Models\WebsitePage;
use App\Models\WebsiteProduct;
use App\Support\WebsiteAdminContext;
use App\WebsitePlugins\WebsiteMediaStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class WebsiteContentController extends Controller
{
    /**
     * @Name: categories
     * @Description: 分页展示当前站点和所有者名下可被文章、产品共用的分类。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-12 14:18:43
     * @Param Request $request 当前网站后台请求
     * @Return View 当前站点分类列表
     * @Throws HttpException 当前账号没有分类查看权限
     */
    public function categories(Request $request): View
    {
        $context = $this->context($request);
        $this->authorize($context, 'categories.read');
        $categories = Category::query()
            ->where('site_id', (int) $context->site->id)
            ->where('owner_admin_id', (int) $context->owner->id)
            ->withCount([
                'articles as article_count' => fn ($query) => $query
                    ->where('site_id', (int) $context->site->id)
                    ->where('owner_admin_id', (int) $context->owner->id),
                'products as product_count' => fn ($query) => $query
                    ->where('site_id', (int) $context->site->id)
                    ->where('owner_admin_id', (int) $context->owner->id),
            ])
            ->orderByDesc('sort_order')
            ->orderBy('name')
            ->paginate(20);

        return $this->render('categories.index', '分类管理', compact('categories'), $context);
    }

    /**
     * @Name: createCategory
     * @Description: 展示新建分类表单，分类可同时用于文章和产品。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-12 15:18:17
     * @Param Request $request 当前网站后台请求
     * @Return View 新建分类表单
     * @Throws HttpException 当前账号没有分类编辑权限
     */
    public function createCategory(Request $request): View
    {
        $context = $this->context($request);
        $this->authorize($context, 'categories.create');

        return $this->render('categories.form', '新建分类', [
            'category' => new Category(['sort_order' => 0]),
            'isCreate' => true,
        ], $context);
    }

    /**
     * @Name: storeCategory
     * @Description: 创建当前租户的共用内容分类并生成全局唯一 URL 标识。
     * @Url [POST] /admin/categories
     *      登录 是
     *      name string 必选 分类名称
     *      description string 可选 分类介绍
     *      sort_order integer 可选 排序值
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-12 15:18:17
     * @Param Request $request 分类表单数据
     * @Return RedirectResponse 返回分类列表
     * @Throws ValidationException 分类字段无效
     */
    public function storeCategory(Request $request): RedirectResponse
    {
        $context = $this->context($request);
        $this->authorize($context, 'categories.create');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);
        $slug = $this->uniqueCategorySlug((string) $validated['name'], (int) $context->site->id);
        Category::query()->create([
            'site_id' => (int) $context->site->id,
            'owner_admin_id' => (int) $context->owner->id,
            'name' => trim((string) $validated['name']),
            'slug' => $slug,
            'description' => trim((string) ($validated['description'] ?? '')),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ]);

        return redirect()->route('website-admin.categories')->with('status', '内容分类已创建');
    }

    /**
     * @Name: editCategory
     * @Description: 仅加载当前站点和所有者拥有的分类并展示编辑表单。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-12 15:18:17
     * @Param Request $request 当前网站后台请求
     * @Param int $category 分类主键
     * @Return View 当前租户分类编辑表单
     * @Throws ModelNotFoundException 分类不存在或不属于当前租户
     */
    public function editCategory(Request $request, int $category): View
    {
        $context = $this->context($request);
        $this->authorize($context, 'categories.edit');

        return $this->render('categories.form', '编辑分类', [
            'category' => $this->categoryQuery($context)->findOrFail($category),
            'isCreate' => false,
        ], $context);
    }

    /**
     * @Name: updateCategory
     * @Description: 更新当前租户分类并保留已有网址标识，避免分类链接因更名失效。
     * @Url [PUT] /admin/categories/{category}
     *      登录 是
     *      name string 必选 分类名称
     *      description string 可选 分类介绍
     *      sort_order integer 可选 排序值
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-12 15:18:17
     * @Param Request $request 分类更新数据
     * @Param int $category 当前租户分类 ID
     * @Return RedirectResponse 返回分类列表
     * @Throws ValidationException 分类字段无效
     */
    public function updateCategory(Request $request, int $category): RedirectResponse
    {
        $context = $this->context($request);
        $this->authorize($context, 'categories.edit');
        $record = $this->categoryQuery($context)->findOrFail($category);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);
        $record->update([
            'name' => trim((string) $validated['name']),
            'description' => trim((string) ($validated['description'] ?? '')),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ]);

        return redirect()->route('website-admin.categories')->with('status', '内容分类已更新');
    }

    /**
     * @Name: deleteCategory
     * @Description: 删除当前租户未被文章或产品引用的分类，保留关联内容完整性。
     * @Url [DELETE] /admin/categories/{category}
     *      登录 是
     *      无请求体参数
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-12 15:18:17
     * @Param Request $request 当前网站后台请求
     * @Param int $category 当前租户分类 ID
     * @Return RedirectResponse 返回分类列表
     * @Throws ValidationException 分类仍被文章或产品使用
     */
    public function deleteCategory(Request $request, int $category): RedirectResponse
    {
        $context = $this->context($request);
        $this->authorize($context, 'categories.delete');
        $record = $this->categoryQuery($context)->findOrFail($category);
        $siteId = (int) $context->site->id;
        $ownerAdminId = (int) $context->owner->id;
        $usedByArticles = Article::query()->where('site_id', $siteId)->where('owner_admin_id', $ownerAdminId)->where('category_id', $record->id)->exists();
        $usedByProducts = WebsiteProduct::query()->where('site_id', $siteId)->where('owner_admin_id', $ownerAdminId)->where('category_id', $record->id)->exists();
        if ($usedByArticles || $usedByProducts) {
            throw ValidationException::withMessages(['category' => '该分类仍有关联文章或产品，请先调整内容分类']);
        }
        $record->delete();

        return redirect()->route('website-admin.categories')->with('status', '内容分类已删除');
    }

    /**
     * @Name: products
     * @Description: 分页展示当前租户官网产品并加载其共用分类。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-11 16:49:23
     * @Param Request $request 当前网站后台请求
     * @Return View 当前站点产品列表
     * @Throws HttpException 当前账号没有产品查看权限
     */
    public function products(Request $request): View
    {
        $context = $this->context($request);
        $this->authorize($context, 'products.read');
        $products = $this->productQuery($context)->with('category')->latest('updated_at')->paginate(20);

        return $this->render('products.index', '产品管理', compact('products'), $context);
    }

    /**
     * @Name: createProduct
     * @Description: 展示产品表单、相册预览和当前站点可用的云存储上传状态。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
    * @UpdateTime: 2026-11-13 09:26:42
     * @Param Request $request 当前网站后台请求
     * @Param WebsiteMediaStorage $mediaStorage 当前站点云存储状态服务
     * @Return View 新建产品表单
     * @Throws HttpException 当前账号没有产品编辑权限
     */
    public function createProduct(Request $request, WebsiteMediaStorage $mediaStorage): View
    {
        $context = $this->context($request);
        $this->authorize($context, 'products.create');

        return $this->render('products.form', '新增产品', [
            'product' => new WebsiteProduct(['status' => 'draft', 'sort_order' => 0]),
            'categories' => $this->categoryQuery($context)->orderByDesc('sort_order')->orderBy('name')->get(),
            'imageUploadAvailable' => $mediaStorage->isCloudStorageReady((int) $context->site->id),
            'isCreate' => true,
        ], $context);
    }

    /**
     * @Name: editProduct
     * @Description: 仅允许读取当前租户产品并展示相册预览及可用上传功能。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-13 09:26:42
     * @Param Request $request 当前网站后台请求
     * @Param int $product 产品主键
     * @Param WebsiteMediaStorage $mediaStorage 当前站点云存储状态服务
     * @Return View 当前租户产品编辑表单
     * @Throws ModelNotFoundException 产品不存在或不属于当前租户
     * @Throws HttpException 无权编辑已发布产品
     */
    public function editProduct(Request $request, int $product, WebsiteMediaStorage $mediaStorage): View
    {
        $context = $this->context($request);
        $this->authorize($context, 'products.edit');

        $record = $this->productQuery($context)->findOrFail($product);
        abort_if($record->status === 'published' && ! $context->allows('products.publish'), 403);

        return $this->render('products.form', '编辑产品', [
            'product' => $record,
            'categories' => $this->categoryQuery($context)->orderByDesc('sort_order')->orderBy('name')->get(),
            'imageUploadAvailable' => $mediaStorage->isCloudStorageReady((int) $context->site->id),
            'isCreate' => false,
        ], $context);
    }

    /**
     * @Name: storeProduct
     * @Description: 保存当前租户产品，校验分类归属及发布权限。
     * @Url [POST] /admin/products
     *      登录 是
     *      title string 必选 产品名称
     *      slug string 可选 产品 URL 标识，留空自动生成
     *      summary string 可选 产品摘要
     *      content string 必选 产品介绍 Markdown
     *      category_id integer 可选 共用分类 ID
     *      cover_image string 可选 产品封面地址
     *      cover_file file 可选 云存储封面图片
     *      gallery_urls_text string 可选 每行一个相册图片地址
     *      gallery_files array 可选 云存储相册图片，最多十张
     *      meta_title string 可选 搜索标题
     *      meta_description string 可选 搜索摘要
     *      status string 必选 draft 或 published
     *      is_featured boolean 可选 是否在首页推荐
     *      sort_order integer 可选 展示排序值
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-13 09:26:42
     * @Param Request $request 产品表单数据
     * @Param WebsiteMediaStorage $mediaStorage 当前站点云存储上传服务
     * @Return RedirectResponse 返回产品列表
     * @Throws ValidationException 产品数据无效、分类越租户或无发布权限
     */
    public function storeProduct(Request $request, WebsiteMediaStorage $mediaStorage): RedirectResponse
    {
        return $this->saveProduct($request, $mediaStorage);
    }

    /**
     * @Name: updateProduct
     * @Description: 更新当前租户产品并拒绝使用其他租户的产品或分类。
     * @Url [PUT] /admin/products/{product}
     *      登录 是
     *      title string 必选 产品名称
     *      slug string 可选 产品 URL 标识，留空保持原值
     *      summary string 可选 产品摘要
     *      content string 必选 产品介绍 Markdown
     *      category_id integer 可选 共用分类 ID
     *      cover_image string 可选 产品封面地址
     *      cover_file file 可选 云存储封面图片
     *      gallery_urls_text string 可选 每行一个相册图片地址
     *      gallery_files array 可选 云存储相册图片，最多十张
     *      meta_title string 可选 搜索标题
     *      meta_description string 可选 搜索摘要
     *      status string 必选 draft 或 published
     *      is_featured boolean 可选 是否在首页推荐
     *      sort_order integer 可选 展示排序值
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-13 09:26:42
     * @Param Request $request 产品更新数据
     * @Param int $product 当前租户产品 ID
     * @Param WebsiteMediaStorage $mediaStorage 当前站点云存储上传服务
     * @Return RedirectResponse 返回产品列表
     * @Throws ValidationException 产品数据无效、分类越租户或无发布权限
     */
    public function updateProduct(Request $request, int $product, WebsiteMediaStorage $mediaStorage): RedirectResponse
    {
        $context = $this->context($request);

        return $this->saveProduct($request, $mediaStorage, $this->productQuery($context)->findOrFail($product));
    }

    /**
     * @Name: deleteProduct
     * @Description: 软删除当前租户产品，其他站点同 ID 记录不会被触碰。
     * @Url [DELETE] /admin/products/{product}
     *      登录 是
     *      无请求体参数
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-12 15:18:17
     * @Param Request $request 当前网站后台请求
     * @Param int $product 当前租户产品 ID
     * @Return RedirectResponse 返回产品列表
     * @Throws ModelNotFoundException 产品不存在或不属于当前租户
     * @Throws HttpException 无权移除已发布产品
     */
    public function deleteProduct(Request $request, int $product): RedirectResponse
    {
        $context = $this->context($request);
        $this->authorize($context, 'products.delete');
        $record = $this->productQuery($context)->findOrFail($product);
        abort_if($record->status === 'published' && ! $context->allows('products.publish'), 403);
        $record->delete();

        return redirect()->route('website-admin.products')->with('status', '产品已移入回收状态');
    }

    /**
     * @Name: pages
     * @Description: 分页展示当前租户官网单页内容和发布状态。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-11 16:49:23
     * @Param Request $request 当前网站后台请求
     * @Return View 当前站点单页列表
     * @Throws HttpException 当前账号没有单页查看权限
     */
    public function pages(Request $request): View
    {
        $context = $this->context($request);
        $this->authorize($context, 'pages.read');
        $pages = $this->pageQuery($context)->latest('updated_at')->paginate(20);

        return $this->render('pages.index', '单页管理', compact('pages'), $context);
    }

    /**
     * @Name: createPage
     * @Description: 展示新增单页表单，适用于企业介绍、联系方式和自定义信息页。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-12 15:18:17
     * @Param Request $request 当前网站后台请求
     * @Return View 新建单页表单
     * @Throws HttpException 当前账号没有单页编辑权限
     */
    public function createPage(Request $request): View
    {
        $context = $this->context($request);
        $this->authorize($context, 'pages.create');

        return $this->render('pages.form', '新建单页', [
            'websitePage' => new WebsitePage(['status' => 'draft', 'sort_order' => 0]),
            'isCreate' => true,
        ], $context);
    }

    /**
     * @Name: editPage
     * @Description: 仅加载当前租户单页并展示编辑表单。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-12 15:18:17
     * @Param Request $request 当前网站后台请求
     * @Param int $page 单页主键
     * @Return View 当前租户单页编辑表单
     * @Throws ModelNotFoundException 页面不存在或不属于当前租户
     * @Throws HttpException 无权编辑已发布页面
     */
    public function editPage(Request $request, int $page): View
    {
        $context = $this->context($request);
        $this->authorize($context, 'pages.edit');

        $record = $this->pageQuery($context)->findOrFail($page);
        abort_if($record->status === 'published' && ! $context->allows('pages.publish'), 403);

        return $this->render('pages.form', '编辑单页', [
            'websitePage' => $record,
            'isCreate' => false,
        ], $context);
    }

    /**
     * @Name: storePage
     * @Description: 创建站点单页并校验站点内 URL 唯一性和发布权限。
     * @Url [POST] /admin/pages
     *      登录 是
     *      title string 必选 页面标题
     *      slug string 可选 页面 URL 标识，留空自动生成
     *      content string 必选 页面 Markdown 正文
     *      meta_title string 可选 搜索标题
     *      meta_description string 可选 搜索摘要
     *      status string 必选 draft 或 published
     *      show_in_navigation boolean 可选 是否显示在官网导航
     *      sort_order integer 可选 导航排序值
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-11 18:39:14
     * @Param Request $request 页面表单数据
     * @Return RedirectResponse 返回单页列表
     * @Throws ValidationException 页面内容无效、URL 冲突或无发布权限
     */
    public function storePage(Request $request): RedirectResponse
    {
        return $this->savePage($request);
    }

    /**
     * @Name: updatePage
     * @Description: 更新当前租户单页并保留其他站点相同 URL 标识的独立内容。
     * @Url [PUT] /admin/pages/{page}
     *      登录 是
     *      title string 必选 页面标题
     *      slug string 可选 页面 URL 标识，留空保持原值
     *      content string 必选 页面 Markdown 正文
     *      meta_title string 可选 搜索标题
     *      meta_description string 可选 搜索摘要
     *      status string 必选 draft 或 published
     *      show_in_navigation boolean 可选 是否显示在官网导航
     *      sort_order integer 可选 导航排序值
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-11 18:39:14
     * @Param Request $request 页面更新数据
     * @Param int $page 当前租户单页 ID
     * @Return RedirectResponse 返回单页列表
     * @Throws ValidationException 页面内容无效、URL 冲突或无发布权限
     */
    public function updatePage(Request $request, int $page): RedirectResponse
    {
        $context = $this->context($request);

        return $this->savePage($request, $this->pageQuery($context)->findOrFail($page));
    }

    /**
     * @Name: deletePage
     * @Description: 软删除当前租户单页，保留其他站点及历史内容。
     * @Url [DELETE] /admin/pages/{page}
     *      登录 是
     *      无请求体参数
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-12 15:18:17
     * @Param Request $request 当前网站后台请求
     * @Param int $page 当前租户单页 ID
     * @Return RedirectResponse 返回单页列表
     * @Throws ModelNotFoundException 页面不存在或不属于当前租户
     * @Throws HttpException 无权移除已发布页面
     */
    public function deletePage(Request $request, int $page): RedirectResponse
    {
        $context = $this->context($request);
        $this->authorize($context, 'pages.delete');
        $record = $this->pageQuery($context)->findOrFail($page);
        abort_if($record->status === 'published' && ! $context->allows('pages.publish'), 403);
        $record->delete();

        return redirect()->route('website-admin.pages')->with('status', '单页已移入回收状态');
    }

    /**
     * @Name: saveProduct
     * @Description: 验证当前租户产品及相册内容，并且只在云存储插件就绪时上传图片。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-13 09:33:32
     * @Param Request $request 产品表单请求
     * @Param WebsiteMediaStorage $mediaStorage 当前站点云存储上传服务
     * @Param WebsiteProduct|null $record 已有产品；为空时创建
     * @Return RedirectResponse 产品保存后的列表跳转
     * @Throws ValidationException 字段无效、关联分类越租户或账号不能发布
     * @Throws HttpException 无权修改已发布产品
     */
    private function saveProduct(Request $request, WebsiteMediaStorage $mediaStorage, ?WebsiteProduct $record = null): RedirectResponse
    {
        $context = $this->context($request);
        $this->authorize($context, $record === null ? 'products.create' : 'products.edit');
        abort_if($record?->status === 'published' && ! $context->allows('products.publish'), 403);
        $siteId = (int) $context->site->id;
        $ownerAdminId = (int) $context->owner->id;
        $slugRule = Rule::unique('website_products', 'slug')->where('site_id', $siteId);
        if ($record instanceof WebsiteProduct) {
            $slugRule->ignore($record->id);
        }
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slugRule],
            'summary' => ['nullable', 'string', 'max:1000'],
            'cover_image' => ['nullable', 'string', 'max:1000', 'regex:/^(https?:\/\/|\/)[^\r\n]*$/i'],
            'cover_file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'gallery_urls_text' => ['nullable', 'string', 'max:11000'],
            'gallery_files' => ['nullable', 'array', 'max:10'],
            'gallery_files.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'content' => ['required', 'string', 'max:1000000'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'category_id' => ['nullable', 'integer'],
            'status' => ['required', Rule::in(['draft', 'published'])],
            'is_featured' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);
        $categoryId = $validated['category_id'] ?? null;
        if ($categoryId !== null && ! $this->categoryQuery($context)->whereKey((int) $categoryId)->exists()) {
            throw ValidationException::withMessages(['category_id' => '所选分类不属于当前官网']);
        }
        if ($validated['status'] === 'published' && ! $context->allows('products.publish') && $record?->status !== 'published') {
            throw ValidationException::withMessages(['status' => '当前账号没有发布产品的权限']);
        }

        $gallerySource = (string) $request->input(
            'gallery_urls_text',
            implode("\n", (array) ($record?->gallery ?? []))
        );
        $galleryUrls = preg_split('/\r\n|\r|\n/', $gallerySource) ?: [];
        $galleryUrls = array_values(array_unique(array_filter(
            array_map('trim', $galleryUrls),
            static fn (string $url): bool => $url !== ''
        )));
        foreach ($galleryUrls as $galleryUrl) {
            if (preg_match('/^(https?:\/\/|\/)[^\r\n]*$/i', $galleryUrl) !== 1) {
                throw ValidationException::withMessages([
                    'gallery_urls_text' => '相册图片地址必须以 HTTP(S) 地址或站内路径填写。',
                ]);
            }
        }

        $galleryFiles = $request->file('gallery_files', []);
        $galleryFiles = is_array($galleryFiles) ? array_values(array_filter($galleryFiles)) : [];
        if (count($galleryUrls) + count($galleryFiles) > 10) {
            throw ValidationException::withMessages([
                'gallery_urls_text' => '产品相册最多保存十张图片。',
            ]);
        }
        if (($request->hasFile('cover_file') || $galleryFiles !== [])
            && ! $mediaStorage->isCloudStorageReady($siteId)) {
            $uploadErrorKey = $request->hasFile('cover_file') ? 'cover_file' : 'gallery_files';
            throw ValidationException::withMessages([
                $uploadErrorKey => '请先启用并完整配置云存储插件，再上传产品图片。',
            ]);
        }

        $slug = trim((string) ($validated['slug'] ?? ''));
        if ($slug === '') {
            $slug = $record instanceof WebsiteProduct
                ? (string) $record->slug
                : $this->uniqueProductSlug((string) $validated['title'], $siteId);
        }
        $coverImage = trim((string) ($validated['cover_image'] ?? ''));
        if ($request->hasFile('cover_file')) {
            try {
                $coverImage = $mediaStorage->uploadToCloud($request->file('cover_file'), $siteId, 'product-covers');
            } catch (\Throwable) {
                throw ValidationException::withMessages(['cover_file' => '封面图片上传失败，请检查云存储配置后重试。']);
            }
        }
        foreach ($galleryFiles as $galleryFile) {
            try {
                $galleryUrls[] = $mediaStorage->uploadToCloud($galleryFile, $siteId, 'product-gallery');
            } catch (\Throwable) {
                throw ValidationException::withMessages(['gallery_files' => '相册图片上传失败，请检查云存储配置后重试。']);
            }
        }

        $record ??= new WebsiteProduct();
        $record->fill([
            'site_id' => $siteId,
            'owner_admin_id' => $ownerAdminId,
            'category_id' => $categoryId,
            'title' => trim((string) $validated['title']),
            'slug' => $slug,
            'summary' => trim((string) ($validated['summary'] ?? '')),
            'cover_image' => $coverImage,
            'gallery' => array_values(array_unique($galleryUrls)),
            'content' => (string) $validated['content'],
            'meta_title' => trim((string) ($validated['meta_title'] ?? '')),
            'meta_description' => trim((string) ($validated['meta_description'] ?? '')),
            'status' => (string) $validated['status'],
            'is_featured' => $request->boolean('is_featured'),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ]);
        $record->save();

        return redirect()->route('website-admin.products')->with('status', '产品已保存');
    }

    /**
     * @Name: savePage
     * @Description: 统一处理单页新增和更新，并对网站导航及发布状态执行权限约束。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-12 15:18:17
     * @Param Request $request 单页表单请求
     * @Param WebsitePage|null $record 已有单页；为空时创建
     * @Return RedirectResponse 单页保存后的列表跳转
     * @Throws ValidationException 字段无效、URL 冲突或账号不能发布
     * @Throws HttpException 无权修改已发布页面
     */
    private function savePage(Request $request, ?WebsitePage $record = null): RedirectResponse
    {
        $context = $this->context($request);
        $this->authorize($context, $record === null ? 'pages.create' : 'pages.edit');
        abort_if($record?->status === 'published' && ! $context->allows('pages.publish'), 403);
        $siteId = (int) $context->site->id;
        $slugRule = Rule::unique('website_pages', 'slug')->where('site_id', $siteId);
        if ($record instanceof WebsitePage) {
            $slugRule->ignore($record->id);
        }
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slugRule],
            'content' => ['required', 'string', 'max:1000000'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'status' => ['required', Rule::in(['draft', 'published'])],
            'show_in_navigation' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);
        if ($validated['status'] === 'published' && ! $context->allows('pages.publish') && $record?->status !== 'published') {
            throw ValidationException::withMessages(['status' => '当前账号没有发布单页的权限']);
        }
        $slug = trim((string) ($validated['slug'] ?? ''));
        if ($slug === '') {
            $slug = $record instanceof WebsitePage
                ? (string) $record->slug
                : $this->uniquePageSlug((string) $validated['title'], $siteId);
        }
        $record ??= new WebsitePage();
        $record->fill([
            'site_id' => $siteId,
            'owner_admin_id' => (int) $context->owner->id,
            'title' => trim((string) $validated['title']),
            'slug' => $slug,
            'content' => (string) $validated['content'],
            'meta_title' => trim((string) ($validated['meta_title'] ?? '')),
            'meta_description' => trim((string) ($validated['meta_description'] ?? '')),
            'status' => (string) $validated['status'],
            'show_in_navigation' => $request->boolean('show_in_navigation'),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ]);
        $record->save();

        return redirect()->route('website-admin.pages')->with('status', '单页已保存');
    }

    /**
     * @Name: context
     * @Description: 读取认证中间件创建的当前站点与官网权限上下文。
     * @Param Request $request 当前网站后台请求
     * @Return WebsiteAdminContext 当前租户上下文
     * @Throws HttpException 未认证或上下文缺失时返回 403
     */
    private function context(Request $request): WebsiteAdminContext
    {
        $context = $request->attributes->get('website_admin');
        abort_unless($context instanceof WebsiteAdminContext, 403);

        return $context;
    }

    /**
     * @Name: authorize
     * @Description: 检查当前 GEO 所有者或网站成员是否持有指定内容管理权限。
     * @Param WebsiteAdminContext $context 当前租户权限上下文
     * @Param string $permission 需要的权限键
     * @Return void 权限通过后继续执行
     * @Throws HttpException 缺少权限时返回 403
     */
    private function authorize(WebsiteAdminContext $context, string $permission): void
    {
        abort_unless($context->allows($permission), 403);
    }

    /**
     * @Name: categoryQuery
     * @Description: 构造同时限定站点 ID 和站点所有者 ID 的分类查询。
     * @Param WebsiteAdminContext $context 当前租户权限上下文
     * @Return \Illuminate\Database\Eloquent\Builder<Category> 当前租户分类查询
     * @Throws 无
     */
    private function categoryQuery(WebsiteAdminContext $context)
    {
        return Category::query()
            ->where('site_id', (int) $context->site->id)
            ->where('owner_admin_id', (int) $context->owner->id);
    }

    /**
     * @Name: productQuery
     * @Description: 构造同时限定站点 ID 和账号所有者 ID 的产品查询。
     * @Param WebsiteAdminContext $context 当前租户权限上下文
     * @Return \Illuminate\Database\Eloquent\Builder<WebsiteProduct> 当前租户产品查询
     * @Throws 无
     */
    private function productQuery(WebsiteAdminContext $context)
    {
        return WebsiteProduct::query()
            ->where('site_id', (int) $context->site->id)
            ->where('owner_admin_id', (int) $context->owner->id);
    }

    /**
     * @Name: pageQuery
     * @Description: 构造同时限定站点 ID 和账号所有者 ID 的单页查询。
     * @Param WebsiteAdminContext $context 当前租户权限上下文
     * @Return \Illuminate\Database\Eloquent\Builder<WebsitePage> 当前租户页面查询
     * @Throws 无
     */
    private function pageQuery(WebsiteAdminContext $context)
    {
        return WebsitePage::query()
            ->where('site_id', (int) $context->site->id)
            ->where('owner_admin_id', (int) $context->owner->id);
    }

    /**
     * @Name: uniqueCategorySlug
     * @Description: 生成不超过分类表字段长度的全局唯一网址标识，兼容单列唯一约束。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-12 10:33:48
     * @Param string $name 分类名称
     * @Param int $siteId 当前站点 ID
     * @Return string 全局唯一分类 URL 标识
     * @Throws \Random\RandomException 随机标识生成失败
     */
    private function uniqueCategorySlug(string $name, int $siteId): string
    {
        $siteSuffix = '-'.$siteId;
        $base = trim(substr(Str::slug($name) ?: 'category', 0, 100 - strlen($siteSuffix) - 6), '-');
        $slug = $base.$siteSuffix;
        while (Category::query()->withoutGlobalScopes()->where('slug', $slug)->exists()) {
            $slug = $base.$siteSuffix.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }

    /**
     * @Name: uniqueProductSlug
     * @Description: 以英文标题生成站点唯一产品地址；中文标题使用站点隔离前缀和随机后缀。
     * @Param string $title 产品标题
     * @Param int $siteId 当前站点 ID
     * @Return string 当前站点唯一产品 URL 标识
     * @Throws \Random\RandomException 随机标识生成失败
     */
    private function uniqueProductSlug(string $title, int $siteId): string
    {
        $base = Str::slug($title) ?: 'product-'.$siteId;
        $slug = $base;
        while (WebsiteProduct::withTrashed()->where('site_id', $siteId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }

    /**
     * @Name: uniquePageSlug
     * @Description: 以英文标题生成站点唯一页面地址；中文标题使用站点隔离前缀和随机后缀。
     * @Param string $title 页面标题
     * @Param int $siteId 当前站点 ID
     * @Return string 当前站点唯一页面 URL 标识
     * @Throws \Random\RandomException 随机标识生成失败
     */
    private function uniquePageSlug(string $title, int $siteId): string
    {
        $base = Str::slug($title) ?: 'page-'.$siteId;
        $slug = $base;
        while (WebsitePage::withTrashed()->where('site_id', $siteId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }

    /**
     * @Name: render
     * @Description: 使用官网后台统一布局渲染客户内容管理页面。
     * @Param string $view 页面视图相对名
     * @Param string $title 页面标题
     * @Param array<string,mixed> $data 页面数据
     * @Param WebsiteAdminContext $context 当前租户上下文
     * @Return View 官网后台 Blade 页面
     * @Throws \InvalidArgumentException 视图文件不存在
     */
    private function render(string $view, string $title, array $data, WebsiteAdminContext $context): View
    {
        return view('website-admin.'.$view, [
            ...$data,
            'pageTitle' => $title,
            'context' => $context,
        ]);
    }
}
