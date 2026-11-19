<?php

/**
 * Created by Codex.
 * @Date: 2026-11-18
 * @Time: 16:07:55
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： MonitorWebsiteContentController.php
 * @Description: 为新版监测中心提供账号和站点隔离的官网文章分类及文章管理接口。
 */

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Site;
use App\Models\WebsiteProduct;
use App\Support\Site\ArticleHtmlPresenter;
use App\Support\GeoFlow\ArticleWorkflow;
use App\WebsitePlugins\WebsiteMediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MonitorWebsiteContentController extends BaseApiController
{
    /**
     * 官网文章与分类列表
     * 返回当前登录账号官网下的文章分类和分页文章数据。
     * @Url [GET] /api/v1/monitor/website/content
     *      登录 是
     *
     *      分页参数：
     *      page integer 可选 文章页码
     *      per_page integer 可选 每页文章数，最大 100
     *
     *      筛选参数：
     *      search string 可选 文章标题关键词
     *      category_id integer 可选 分类编号
     *      status string 可选 draft、published 或 private
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-18 16:07:55
     * @UpdateTime: 2026-11-18 16:30:36
     *
     * @Return JsonResponse 当前站点分类、文章列表和分页信息
     * @Throws ApiException 监测中心凭证无效或站点不属于当前账号
     */
    public function index(Request $request): JsonResponse
    {
        ['admin' => $admin, 'site' => $site] = $this->monitorContext($request);
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'nullable', 'string', 'in:draft,published,private'],
        ]);
        $siteId = (int) $site->id;
        $ownerAdminId = (int) $admin->id;
        $categories = $this->categoryQuery($siteId, $ownerAdminId)
            ->withCount([
                'articles as article_count' => fn ($query) => $query
                    ->where('site_id', $siteId)
                    ->where('owner_admin_id', $ownerAdminId),
                'products as product_count' => fn ($query) => $query
                    ->where('site_id', $siteId)
                    ->where('owner_admin_id', $ownerAdminId),
            ])
            ->orderByDesc('sort_order')
            ->orderBy('name')
            ->get()
            ->map(static fn (Category $category): array => [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
                'slug' => (string) $category->slug,
                'description' => (string) $category->description,
                'sort_order' => (int) $category->sort_order,
                'article_count' => (int) $category->article_count,
                'product_count' => (int) $category->product_count,
            ])->all();

        $query = $this->articleQuery($siteId, $ownerAdminId)->with('category:id,name');
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.$this->escapeLike(mb_strtolower($search)).'%';
            $query->whereRaw('LOWER(title) LIKE ?', [$like]);
        }
        if ((int) ($filters['category_id'] ?? 0) > 0) {
            $query->where('category_id', (int) $filters['category_id']);
        }
        if (($filters['status'] ?? '') !== '') {
            $query->where('status', (string) $filters['status']);
        }

        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 20);
        $total = (clone $query)->count();
        $articles = $query
            ->select([
                'id', 'title', 'slug', 'excerpt', 'cover_image', 'category_id',
                'keywords', 'meta_description', 'status', 'review_status', 'view_count',
                'published_at', 'created_at', 'updated_at',
            ])
            ->orderByDesc('updated_at')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (Article $article): array => $this->articlePayload($article, false))
            ->all();

        return $this->success($request, [
            'categories' => $categories,
            'articles' => [
                'items' => $articles,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => (int) ceil($total / $perPage),
                ],
            ],
        ]);
    }

    /**
     * 创建官网文章分类
     * 创建当前站点可供文章和产品共用的内容分类。
     * @Url [POST] /api/v1/monitor/website/categories
     *      登录 是
     *      name string 必选 分类名称
     *      description string 可选 分类介绍
     *      sort_order integer 可选 排序值，数值越大越靠前
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-18 16:07:55
     * @UpdateTime: 2026-11-18 16:07:55
     *
     * @Return JsonResponse 新建的官网分类
     * @Throws ValidationException 分类字段不符合要求
     */
    public function storeCategory(Request $request): JsonResponse
    {
        ['admin' => $admin, 'site' => $site] = $this->monitorContext($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ]);
        $category = Category::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => trim((string) $validated['name']),
            'slug' => $this->uniqueCategorySlug((string) $validated['name']),
            'description' => trim((string) ($validated['description'] ?? '')),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ]);

        return $this->success($request, $this->categoryPayload($category), 201);
    }

    /**
     * 更新官网文章分类
     * 只更新当前站点和当前账号拥有的分类。
     * @Url [PUT] /api/v1/monitor/website/categories/{category}
     *      登录 是
     *      name string 必选 分类名称
     *      description string 可选 分类介绍
     *      sort_order integer 可选 排序值，数值越大越靠前
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-18 16:07:55
     * @UpdateTime: 2026-11-18 16:07:55
     *
     * @Return JsonResponse 更新后的官网分类
     * @Throws ApiException 分类不属于当前账号或站点
     * @Throws ValidationException 分类字段不符合要求
     */
    public function updateCategory(Request $request, int $category): JsonResponse
    {
        ['admin' => $admin, 'site' => $site] = $this->monitorContext($request);
        $record = $this->categoryQuery((int) $site->id, (int) $admin->id)->find($category);
        if (! $record instanceof Category) {
            throw new ApiException('website_category_not_found', '文章分类不存在', 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ]);
        $record->update([
            'name' => trim((string) $validated['name']),
            'description' => trim((string) ($validated['description'] ?? '')),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ]);

        return $this->success($request, $this->categoryPayload($record->refresh()));
    }

    /**
     * 删除官网文章分类
     * 仅删除未被当前站点文章或产品使用的分类。
     * @Url [DELETE] /api/v1/monitor/website/categories/{category}
     *      登录 是
     *      无请求体参数
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-18 16:07:55
     * @UpdateTime: 2026-11-18 16:07:55
     *
     * @Return JsonResponse 删除结果
     * @Throws ApiException 分类不存在或仍被内容使用
     */
    public function destroyCategory(Request $request, int $category): JsonResponse
    {
        ['admin' => $admin, 'site' => $site] = $this->monitorContext($request);
        $record = $this->categoryQuery((int) $site->id, (int) $admin->id)->find($category);
        if (! $record instanceof Category) {
            throw new ApiException('website_category_not_found', '文章分类不存在', 404);
        }

        $siteId = (int) $site->id;
        $ownerAdminId = (int) $admin->id;
        $hasArticles = $this->articleQuery($siteId, $ownerAdminId)->where('category_id', $record->id)->exists();
        $hasProducts = WebsiteProduct::query()
            ->where('site_id', $siteId)
            ->where('owner_admin_id', $ownerAdminId)
            ->where('category_id', $record->id)
            ->exists();
        if ($hasArticles || $hasProducts) {
            throw ValidationException::withMessages([
                'category' => '该分类仍有关联文章或产品，请先调整内容分类。',
            ]);
        }

        $record->delete();

        return $this->success($request, ['deleted' => true]);
    }

    /**
     * 读取官网文章
     * 返回当前账号拥有的指定文章完整编辑内容。
     * @Url [GET] /api/v1/monitor/website/articles/{article}
     *      登录 是
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-18 16:07:55
     * @UpdateTime: 2026-11-18 16:07:55
     *
     * @Return JsonResponse 当前账号文章详情
     * @Throws ApiException 文章不属于当前账号或站点
     */
    public function showArticle(Request $request, int $article): JsonResponse
    {
        ['admin' => $admin, 'site' => $site] = $this->monitorContext($request);
        $record = $this->articleQuery((int) $site->id, (int) $admin->id)
            ->with('category:id,name')
            ->find($article);
        if (! $record instanceof Article) {
            throw new ApiException('website_article_not_found', '官网文章不存在', 404);
        }

        return $this->success($request, $this->articlePayload($record));
    }

    /**
     * 上传官网文章封面
     * 将封面图片上传到当前站点已启用且配置完整的云存储插件。
     * @Url [POST] /api/v1/monitor/website/articles/cover-image
     *      登录 是
     *      cover_file file 必选 JPG、PNG 或 WebP 图片，最大 5 MB
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-18 18:21:47
     * @UpdateTime: 2026-11-18 18:21:47
     *
     * @Return JsonResponse 云存储公开访问地址
     * @Throws ValidationException 文件格式无效、云存储未就绪或上传失败
     */
    public function uploadArticleCover(Request $request, WebsiteMediaStorage $mediaStorage): JsonResponse
    {
        ['site' => $site] = $this->monitorContext($request);
        $validated = $request->validate([
            'cover_file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $siteId = (int) $site->id;
        if (! $mediaStorage->isCloudStorageReady($siteId)) {
            throw ValidationException::withMessages([
                'cover_file' => '请先启用并完整配置云存储插件，再上传文章封面；也可以填写已有图片地址。',
            ]);
        }

        try {
            $url = $mediaStorage->uploadToCloud($validated['cover_file'], $siteId, 'article-covers');
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'cover_file' => '封面图片上传失败，请检查云存储配置后重试。',
            ]);
        }

        return $this->success($request, ['url' => $url]);
    }

    /**
     * 上传官网文章正文图片
     * 将正文图片上传到当前站点已启用且配置完整的云存储插件，供 Markdown 正文引用。
     * @Url [POST] /api/v1/monitor/website/articles/images
     *      登录 是
     *      image_file file 必选 JPG、PNG、WebP 或 GIF 图片，最大 5 MB
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-19 09:33:18
     * @UpdateTime: 2026-11-19 09:45:10
     *
     * @Return JsonResponse 云存储公开访问地址
     * @Throws ValidationException 文件格式无效、云存储未就绪或上传失败
     */
    public function uploadArticleImage(Request $request, WebsiteMediaStorage $mediaStorage): JsonResponse
    {
        ['site' => $site] = $this->monitorContext($request);
        $validated = $request->validate([
            'image_file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);
        $siteId = (int) $site->id;
        if (! $mediaStorage->isCloudStorageReady($siteId)) {
            throw ValidationException::withMessages([
                'image_file' => '请先启用并完整配置云存储插件，再插入正文图片。',
            ]);
        }

        try {
            $url = $mediaStorage->uploadToCloud($validated['image_file'], $siteId, 'article-images');
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'image_file' => '正文图片上传失败，请检查云存储配置后重试。',
            ]);
        }

        return $this->success($request, ['url' => $url]);
    }

    /**
     * 创建官网文章
     * 创建当前站点文章并沿用既有文章发布状态机。
     * @Url [POST] /api/v1/monitor/website/articles
     *      登录 是
     *      title string 必选 文章标题
     *      category_id integer 可选 当前站点文章分类编号
     *      excerpt string 可选 文章摘要，最多 200 字；为空时从正文提取
     *      cover_image string 可选 封面图片地址
     *      content string 必选 Markdown 正文
     *      keywords string 可选 SEO 关键词
     *      status string 可选 draft、published 或 private
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-18 16:07:55
     * @UpdateTime: 2026-11-19 09:45:10
     *
     * @Return JsonResponse 新建的官网文章
     * @Throws ValidationException 字段无效或分类不属于当前账号
     */
    public function storeArticle(Request $request): JsonResponse
    {
        $article = $this->saveArticle($request);

        return $this->success($request, $this->articlePayload($article), 201);
    }

    /**
     * 更新官网文章
     * 更新当前站点文章并保留文章唯一网址标识。
     * @Url [PUT] /api/v1/monitor/website/articles/{article}
     *      登录 是
     *      title string 必选 文章标题
     *      category_id integer 可选 当前站点文章分类编号
     *      excerpt string 可选 文章摘要，最多 200 字；为空时从正文提取
     *      cover_image string 可选 封面图片地址
     *      content string 必选 Markdown 正文
     *      keywords string 可选 SEO 关键词
     *      status string 可选 draft、published 或 private
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-18 16:07:55
     * @UpdateTime: 2026-11-19 09:45:10
     *
     * @Return JsonResponse 更新后的官网文章
     * @Throws ApiException 文章不属于当前账号或站点
     * @Throws ValidationException 字段无效或分类不属于当前账号
     */
    public function updateArticle(Request $request, int $article): JsonResponse
    {
        ['admin' => $admin, 'site' => $site] = $this->monitorContext($request);
        $record = $this->articleQuery((int) $site->id, (int) $admin->id)->find($article);
        if (! $record instanceof Article) {
            throw new ApiException('website_article_not_found', '官网文章不存在', 404);
        }

        return $this->success($request, $this->articlePayload($this->saveArticle($request, $record)));
    }

    /**
     * 删除官网文章
     * 将当前账号文章软删除，保留既有投稿和审计关联。
     * @Url [DELETE] /api/v1/monitor/website/articles/{article}
     *      登录 是
     *      无请求体参数
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-18 16:07:55
     * @UpdateTime: 2026-11-19 09:45:10
     *
     * @Return JsonResponse 删除结果
     * @Throws ApiException 文章不属于当前账号或站点
     */
    public function destroyArticle(Request $request, int $article): JsonResponse
    {
        ['admin' => $admin, 'site' => $site] = $this->monitorContext($request);
        $record = $this->articleQuery((int) $site->id, (int) $admin->id)->find($article);
        if (! $record instanceof Article) {
            throw new ApiException('website_article_not_found', '官网文章不存在', 404);
        }

        $record->delete();

        return $this->success($request, ['deleted' => true]);
    }

    /**
     * 保存当前账号官网文章，限定分类归属并自动建立必需的作者和默认分类。
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-18 16:07:55
     * @UpdateTime: 2026-11-19 09:45:10
     * @Param Request $request 文章字段及监测中心身份
     * @Param Article|null $record 当前文章；为空时创建
     * @Return Article 已保存文章
     * @Throws ValidationException 字段无效或所选分类不属于当前站点
     */
    private function saveArticle(Request $request, ?Article $record = null): Article
    {
        ['admin' => $admin, 'site' => $site] = $this->monitorContext($request);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'excerpt' => ['sometimes', 'nullable', 'string', 'max:200'],
            'cover_image' => ['sometimes', 'nullable', 'string', 'max:1000', 'regex:/^(https?:\/\/|\/)[^\r\n]*$/i'],
            'content' => ['required', 'string', 'max:1000000'],
            'keywords' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', 'string', 'in:draft,published,private'],
        ]);

        $siteId = (int) $site->id;
        $ownerAdminId = (int) $admin->id;
        $categoryId = (int) ($validated['category_id'] ?? 0);
        $category = $categoryId > 0
            ? $this->categoryQuery($siteId, $ownerAdminId)->find($categoryId)
            : null;
        if ($categoryId > 0 && ! $category instanceof Category) {
            throw ValidationException::withMessages([
                'category_id' => '所选分类不属于当前官网。',
            ]);
        }
        $category ??= $this->ensureCategory($site, $admin);
        $author = $this->ensureAuthor($site, $admin);
        $record ??= new Article();
        $excerpt = trim((string) ($validated['excerpt'] ?? $record->excerpt ?? ''));
        if ($excerpt === '') {
            $excerpt = ArticleHtmlPresenter::excerptFromMarkdown((string) $validated['content'], 200);
        }
        $status = (string) ($validated['status'] ?? ($record->status ?: 'draft'));
        $published = $status === 'published';
        $state = ArticleWorkflow::normalizeState(
            $status,
            $published ? 'approved' : 'pending',
            $published ? now()->format('Y-m-d H:i:s') : null
        );
        $record->fill([
            'site_id' => $siteId,
            'owner_admin_id' => $ownerAdminId,
            'title' => trim((string) $validated['title']),
            'slug' => $record->exists ? $record->slug : $this->uniqueArticleSlug(),
            'excerpt' => $excerpt,
            'cover_image' => trim((string) ($validated['cover_image'] ?? '')),
            'content' => (string) $validated['content'],
            'keywords' => trim((string) ($validated['keywords'] ?? '')),
            'meta_description' => $excerpt,
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            ...$state,
        ])->save();

        return $record->refresh()->load('category:id,name');
    }

    /**
     * 返回文章内容时始终应用账号及站点双重条件。
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-18 16:07:55
     * @UpdateTime: 2026-11-18 16:07:55
     * @Param int $siteId 当前站点编号
     * @Param int $ownerAdminId 当前站点所有者编号
     * @Return \Illuminate\Database\Eloquent\Builder<Article> 当前租户文章查询
     * @Throws 无
     */
    private function articleQuery(int $siteId, int $ownerAdminId)
    {
        return Article::query()
            ->where('site_id', $siteId)
            ->where('owner_admin_id', $ownerAdminId);
    }

    /**
     * 返回分类内容时始终应用账号及站点双重条件。
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-18 16:07:55
     * @UpdateTime: 2026-11-18 16:07:55
     * @Param int $siteId 当前站点编号
     * @Param int $ownerAdminId 当前站点所有者编号
     * @Return \Illuminate\Database\Eloquent\Builder<Category> 当前租户分类查询
     * @Throws 无
     */
    private function categoryQuery(int $siteId, int $ownerAdminId)
    {
        return Category::query()
            ->where('site_id', $siteId)
            ->where('owner_admin_id', $ownerAdminId);
    }

    /**
     * 从认证中间件上下文读取账号与站点，并拒绝非站点所有者管理官网内容。
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-18 16:07:55
     * @UpdateTime: 2026-11-18 16:07:55
     * @Param Request $request 当前监测中心 API 请求
     * @Return array{admin:Admin,site:Site} 当前账号及其官网站点
     * @Throws ApiException 身份无效、站点不可用或账号不是站点所有者
     */
    private function monitorContext(Request $request): array
    {
        $identity = $request->attributes->get('monitor_auth');
        $admin = is_array($identity) ? ($identity['admin'] ?? null) : null;
        $site = is_array($identity) ? ($identity['site'] ?? null) : null;
        if (! $admin instanceof Admin || ! $site instanceof Site) {
            throw new ApiException('unauthorized', '监测中心登录凭证无效', 401);
        }
        if ($site->status !== 'active' || (int) $site->owner_admin_id !== (int) $admin->id) {
            throw new ApiException('website_forbidden', '当前账号没有管理该官网的权限', 403);
        }

        return ['admin' => $admin, 'site' => $site];
    }

    /**
     * 为新文章创建当前站点默认分类，满足文章分类外键约束。
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-18 16:07:55
     * @UpdateTime: 2026-11-18 16:07:55
     * @Param Site $site 当前站点
     * @Param Admin $admin 当前站点所有者
     * @Return Category 可用于文章的默认分类
     * @Throws \Illuminate\Database\QueryException 分类写入失败
     */
    private function ensureCategory(Site $site, Admin $admin): Category
    {
        $category = $this->categoryQuery((int) $site->id, (int) $admin->id)->orderBy('id')->first();
        if ($category instanceof Category) {
            return $category;
        }

        return Category::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => '默认分类',
            'slug' => $this->uniqueCategorySlug('默认分类'),
            'description' => '',
            'sort_order' => 0,
        ]);
    }

    /**
     * 为新文章查找或创建当前站点作者。
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-18 16:07:55
     * @UpdateTime: 2026-11-18 16:07:55
     * @Param Site $site 当前站点
     * @Param Admin $admin 当前站点所有者
     * @Return Author 当前站点文章作者
     * @Throws \Illuminate\Database\QueryException 作者写入失败
     */
    private function ensureAuthor(Site $site, Admin $admin): Author
    {
        $author = Author::query()
            ->where('site_id', (int) $site->id)
            ->where('owner_admin_id', (int) $admin->id)
            ->orderBy('id')
            ->first();
        if ($author instanceof Author) {
            return $author;
        }

        return Author::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => trim((string) $site->name) ?: '网站编辑',
        ]);
    }

    /**
     * 生成全局唯一分类网址标识，兼容中文分类名和数据库唯一索引。
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-18 16:07:55
     * @UpdateTime: 2026-11-18 16:07:55
     * @Param string $name 分类名称
     * @Return string 唯一分类网址标识
     * @Throws \Random\RandomException 随机数生成失败
     */
    private function uniqueCategorySlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        while (Category::withoutGlobalScopes()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }

    /**
     * 生成全局未删除文章集合中尚未使用的网址标识。
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-18 16:07:55
     * @UpdateTime: 2026-11-18 16:07:55
     * @Return string 全局唯一文章网址标识
     * @Throws \Random\RandomException 随机数生成失败
     */
    private function uniqueArticleSlug(): string
    {
        do {
            $slug = Str::lower(Str::random(12));
        } while (Article::withoutGlobalScopes()->withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }

    /**
     * 将分类模型转换为不含其他租户数据的接口字段。
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-18 16:07:55
     * @UpdateTime: 2026-11-18 16:07:55
     * @Param Category $category 当前账号分类
     * @Return array<string,int|string> 分类摘要
     * @Throws 无
     */
    private function categoryPayload(Category $category): array
    {
        return [
            'id' => (int) $category->id,
            'name' => (string) $category->name,
            'slug' => (string) $category->slug,
            'description' => (string) $category->description,
            'sort_order' => (int) $category->sort_order,
        ];
    }

    /**
     * 将文章模型转换为监测中心编辑表单和列表需要的字段。
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-18 16:07:55
     * @UpdateTime: 2026-11-18 16:30:36
     * @Param Article $article 当前账号文章
     * @Param bool $includeContent 是否包含较大的 Markdown 正文
     * @Return array<string,int|string|null> 文章摘要与编辑内容
     * @Throws 无
     */
    private function articlePayload(Article $article, bool $includeContent = true): array
    {
        $payload = [
            'id' => (int) $article->id,
            'title' => (string) $article->title,
            'slug' => (string) $article->slug,
            'excerpt' => (string) $article->excerpt,
            'cover_image' => (string) $article->cover_image,
            'content' => (string) $article->content,
            'category_id' => (int) $article->category_id,
            'category_name' => (string) ($article->category?->name ?? ''),
            'keywords' => (string) $article->keywords,
            'meta_description' => (string) $article->meta_description,
            'status' => (string) $article->status,
            'review_status' => (string) $article->review_status,
            'view_count' => (int) $article->view_count,
            'published_at' => $article->published_at?->format('Y-m-d H:i:s'),
            'created_at' => $article->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $article->updated_at?->format('Y-m-d H:i:s'),
        ];

        if (! $includeContent) {
            unset($payload['content']);
        }

        return $payload;
    }

    /**
     * 转义 LIKE 查询中的通配符，保持标题关键词筛选为字面匹配。
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-18 16:07:55
     * @UpdateTime: 2026-11-18 16:07:55
     * @Param string $value 用户输入的关键词
     * @Return string 已转义的 SQL LIKE 内容
     * @Throws 无
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
