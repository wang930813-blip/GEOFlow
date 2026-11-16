<?php

/**
 * Created by Codex.
 * @Date: 2026-11-11
 * @Time: 10:46:30
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： WebsiteAdminController.php
 * @Description: 实现站点隔离的官网资料、文章及后台团队管理操作。
 */

namespace App\Http\Controllers\WebsiteAdmin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SiteSetting;
use App\Models\WebsitePage;
use App\Models\WebsiteProduct;
use App\Models\WebsiteRole;
use App\Models\WebsiteUser;
use App\Services\WebsiteAdmin\WebsiteArticleDraftService;
use App\Support\GeoFlow\ArticleWorkflow;
use App\Support\Site\ArticleHtmlPresenter;
use App\Support\Site\SiteSettingsBag;
use App\Support\WebsiteAdminContext;
use App\Support\WebsitePermissionCatalog;
use App\WebsitePlugins\WebsiteMediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class WebsiteAdminController extends Controller
{
    /**
     * @Name: dashboard
     * @Description: 展示当前租户站点、文章数量和 SEO 基础配置状态。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:46:30
     * @UpdateTime: 2026-11-12 16:29:58
     * @Param Request $request 当前已认证网站后台请求
     * @Return View|RedirectResponse 当前站点控制台首页或首个授权页面
     * @Throws AuthorizationException 无站点资料权限
     */
    public function dashboard(Request $request): View|RedirectResponse
    {
        $context = $this->context($request);
        if (! $context->allows('dashboard.read')) {
            foreach ([
                'categories.read' => 'website-admin.categories',
                'articles.read' => 'website-admin.articles',
                'products.read' => 'website-admin.products',
                'pages.read' => 'website-admin.pages',
                'site.design.read' => 'website-admin.design',
                'site.settings.read' => 'website-admin.settings',
                'plugins.read' => 'website-admin.plugins',
                'plugins.configure' => 'website-admin.plugins',
                'roles.read' => 'website-admin.team.roles',
                'users.read' => 'website-admin.team.users',
            ] as $permission => $route) {
                if ($context->allows($permission)) {
                    return redirect()->route($route);
                }
            }
            abort(403);
        }
        $settings = SiteSettingsBag::all();
        $query = Article::query()
            ->where('site_id', (int) $context->site->id)
            ->where('owner_admin_id', (int) $context->owner->id);
        $tenantProducts = WebsiteProduct::query()
            ->where('site_id', (int) $context->site->id)
            ->where('owner_admin_id', (int) $context->owner->id);
        $tenantPages = WebsitePage::query()
            ->where('site_id', (int) $context->site->id)
            ->where('owner_admin_id', (int) $context->owner->id);
        $seoFields = ['site_description', 'seo_title_template', 'seo_description_template', 'organization_name'];

        return $this->page('dashboard', '概览', [
            'publishedCount' => (clone $query)->published()->count(),
            'draftCount' => (clone $query)->where('status', 'draft')->count(),
            'articleCount' => (clone $query)->count(),
            'productCount' => (clone $tenantProducts)->count(),
            'publishedProductCount' => (clone $tenantProducts)->where('status', 'published')->count(),
            'pageCount' => (clone $tenantPages)->count(),
            'configuredSeoFields' => collect($seoFields)->filter(fn (string $key): bool => trim((string) ($settings[$key] ?? '')) !== '')->count(),
            'recentArticles' => (clone $query)->with('category')->latest('updated_at')->limit(8)->get(),
            'settings' => $settings,
        ], $context);
    }

    /**
     * @Name: settings
     * @Description: 显示当前站点基础资料、企业实体资料和搜索摘要配置。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:46:30
     * @UpdateTime: 2026-11-12 18:17:00
     * @Param Request $request 当前网站后台请求
     * @Param WebsiteMediaStorage $mediaStorage 当前站点云存储状态服务
     * @Return View 当前租户站点设置页
     * @Throws AuthorizationException 无站点设置权限
     */
    public function settings(Request $request, WebsiteMediaStorage $mediaStorage): View
    {
        $context = $this->context($request);
        $this->authorize($context, 'site.settings.read');

        return $this->page('settings', '网站设置', [
            'settings' => SiteSettingsBag::all(),
            'imageUploadAvailable' => $mediaStorage->isCloudStorageReady((int) $context->site->id),
        ], $context);
    }

    /**
     * @Name: saveSettings
     * @Description: 仅保存当前租户的网站基础信息和 SEO 元数据。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:46:30
     * @UpdateTime: 2026-11-13 09:16:55
     * @Param Request $request 官网资料表单
     * @Param WebsiteMediaStorage $mediaStorage 当前站点云存储上传服务
     * @Return RedirectResponse 返回资料设置页并显示保存结果
     * @Throws ValidationException 资料格式或字段长度不符合要求
     */
    public function saveSettings(Request $request, WebsiteMediaStorage $mediaStorage): RedirectResponse
    {
        $context = $this->context($request);
        $tab = (string) $request->input('settings_tab', $context->isOwner ? 'all' : 'basic');
        $fieldGroups = [
            'basic' => ['site_name', 'site_subtitle', 'site_description', 'site_keywords', 'site_logo', 'site_favicon'],
            'seo' => ['seo_title_template', 'seo_description_template'],
        ];
        if ($context->isOwner) {
            $fieldGroups['all'] = array_merge(...array_values($fieldGroups));
        }
        abort_unless(isset($fieldGroups[$tab]), 422);
        if ($tab !== 'all') {
            $this->authorize($context, 'site.settings.'.$tab);
        }
        $validated = $request->validate([
            'site_name' => [in_array($tab, ['basic', 'all'], true) ? 'required' : 'nullable', 'string', 'max:120'],
            'site_subtitle' => ['nullable', 'string', 'max:255'],
            'site_description' => ['nullable', 'string', 'max:2000'],
            'site_keywords' => ['nullable', 'string', 'max:500'],
            'site_logo' => ['nullable', 'string', 'max:1000', 'regex:/^(https?:\/\/|\/)[^\r\n]*$/i'],
            'site_favicon' => ['nullable', 'string', 'max:1000', 'regex:/^(https?:\/\/|\/)[^\r\n?#]*\.ico(?:[?#][^\r\n]*)?$/i'],
            'site_logo_file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'site_favicon_file' => ['nullable', 'file', 'mimes:ico', 'extensions:ico', 'max:5120'],
            'seo_title_template' => ['nullable', 'string', 'max:255'],
            'seo_description_template' => ['nullable', 'string', 'max:255'],
        ], [
            'site_favicon.regex' => '浏览器图标地址必须指向 .ico 文件。',
            'site_favicon_file.mimes' => '浏览器图标文件内容必须为 ICO 格式。',
            'site_favicon_file.extensions' => '浏览器图标文件扩展名必须为 .ico。',
        ]);

        $siteId = (int) $context->site->id;
        $imageUploads = [
            'site_logo_file' => ['setting' => 'site_logo', 'folder' => 'site-logo'],
            'site_favicon_file' => ['setting' => 'site_favicon', 'folder' => 'site-favicon'],
        ];
        $hasImageUpload = false;
        foreach (array_keys($imageUploads) as $field) {
            $hasImageUpload = $hasImageUpload || $request->hasFile($field);
        }
        if ($hasImageUpload && ! in_array($tab, ['basic', 'all'], true)) {
            throw ValidationException::withMessages([
                'site_logo_file' => '网站 Logo 和浏览器图标只能在基本信息中上传。',
            ]);
        }
        if ($hasImageUpload && ! $mediaStorage->isCloudStorageReady($siteId)) {
            throw ValidationException::withMessages([
                'site_logo_file' => '请先启用并完整配置云存储插件，再上传网站图片；也可以填写已有图片地址。',
            ]);
        }
        foreach ($imageUploads as $field => $upload) {
            if (! $request->hasFile($field)) {
                continue;
            }
            try {
                $validated[$upload['setting']] = $mediaStorage->uploadToCloud(
                    $request->file($field),
                    $siteId,
                    $upload['folder']
                );
            } catch (\Throwable) {
                throw ValidationException::withMessages([
                    $field => '图片上传失败，请检查云存储配置后重试。',
                ]);
            }
        }

        $allowedFields = $context->isOwner ? $fieldGroups['all'] : $fieldGroups[$tab];
        $validated = array_intersect_key($validated, array_flip($allowedFields));
        $ownerAdminId = (int) $context->owner->id;
        DB::transaction(function () use ($validated, $siteId, $ownerAdminId): void {
            foreach ($validated as $key => $value) {
                SiteSetting::query()->updateOrCreate(
                    ['site_id' => $siteId, 'setting_key' => $key],
                    [
                        'owner_admin_id' => $ownerAdminId,
                        'setting_value' => trim((string) ($value ?? '')),
                    ]
                );
            }
            if (isset($validated['site_name'])) {
                SiteSetting::query()->updateOrCreate(
                    ['site_id' => $siteId, 'setting_key' => 'site_title'],
                    [
                        'owner_admin_id' => $ownerAdminId,
                        'setting_value' => trim((string) $validated['site_name']),
                    ]
                );
            }
        });
        SiteSettingsBag::forget();

        return redirect()->route('website-admin.settings', in_array($tab, ['basic', 'all'], true) ? [] : ['tab' => $tab])
            ->with('status', '网站设置已保存');
    }

    /**
     * @Name: articles
     * @Description: 分页读取当前租户文章，不读取其他站点或账号的内容。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:46:30
     * @UpdateTime: 2026-11-11 10:46:30
     * @Param Request $request 当前网站后台请求
     * @Return View 当前租户文章列表
     * @Throws AuthorizationException 无文章查看权限
     */
    public function articles(Request $request): View
    {
        $context = $this->context($request);
        $this->authorize($context, 'articles.read');
        $articles = Article::query()->where('site_id', (int) $context->site->id)
            ->where('owner_admin_id', (int) $context->owner->id)
            ->with('category')->latest('updated_at')->paginate(20);

        return $this->page('articles.index', '文章管理', ['articles' => $articles], $context);
    }

    /**
     * @Name: createArticle
     * @Description: 显示文章编辑表单及当前站点分类。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:46:30
     * @UpdateTime: 2026-11-12 17:48:25
     * @Param Request $request 当前网站后台请求
     * @Param WebsiteArticleDraftService $draftService 当前站点可用对话模型查询服务
     * @Param WebsiteMediaStorage $mediaStorage 当前站点封面上传状态服务
     * @Return View 新建文章表单
     * @Throws AuthorizationException 无文章编辑权限
     */
    public function createArticle(
        Request $request,
        WebsiteArticleDraftService $draftService,
        WebsiteMediaStorage $mediaStorage
    ): View
    {
        $context = $this->context($request);
        $this->authorize($context, 'articles.create');

        return $this->page('articles.form', '新建文章', [
            'article' => new Article(['status' => 'draft']),
            'categories' => Category::query()->where('site_id', (int) $context->site->id)->where('owner_admin_id', (int) $context->owner->id)->orderByDesc('sort_order')->orderBy('name')->get(),
            'aiModels' => $draftService->availableModels($context->site, $context->owner),
            'coverUploadAvailable' => $mediaStorage->isCloudStorageReady((int) $context->site->id),
            'isCreate' => true,
        ], $context);
    }

    /**
     * @Name: editArticle
     * @Description: 只允许加载当前站点文章并显示编辑表单。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:46:30
     * @UpdateTime: 2026-11-12 17:48:25
     * @Param Request $request 当前网站后台请求
     * @Param WebsiteArticleDraftService $draftService 当前站点可用对话模型查询服务
     * @Param WebsiteMediaStorage $mediaStorage 当前站点封面上传状态服务
     * @Param int $article 文章主键
     * @Return View 当前租户文章编辑表单
     * @Throws ModelNotFoundException 文章不存在或不属于当前站点
     * @Throws HttpException 无权编辑已发布文章
     */
    public function editArticle(
        Request $request,
        WebsiteArticleDraftService $draftService,
        WebsiteMediaStorage $mediaStorage,
        int $article
    ): View
    {
        $context = $this->context($request);
        $this->authorize($context, 'articles.edit');
        $record = Article::query()->where('site_id', (int) $context->site->id)
            ->where('owner_admin_id', (int) $context->owner->id)
            ->findOrFail($article);
        abort_if($record->status === 'published' && ! $context->allows('articles.publish'), 403);

        return $this->page('articles.form', '编辑文章', [
            'article' => $record,
            'categories' => Category::query()->where('site_id', (int) $context->site->id)->where('owner_admin_id', (int) $context->owner->id)->orderByDesc('sort_order')->orderBy('name')->get(),
            'aiModels' => $draftService->availableModels($context->site, $context->owner),
            'coverUploadAvailable' => $mediaStorage->isCloudStorageReady((int) $context->site->id),
            'isCreate' => false,
        ], $context);
    }

    /**
     * @Name: saveArticle
     * @Description: 保存当前站点文章及封面，校验分类归属并沿用既有文章发布状态机。
     * @Url [POST|PUT] /admin/articles 或 /admin/articles/{article}
     *      登录 是
     *      cover_image string 可选 已有封面图片地址
     *      cover_file file 可选 新上传的封面图片，优先于图片地址
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:46:30
     * @UpdateTime: 2026-11-12 17:48:25
     * @Param Request $request 文章编辑表单
     * @Param WebsiteMediaStorage $mediaStorage 当前站点封面存储服务
     * @Param int|null $article 已有文章 ID；为空时新建
     * @Return RedirectResponse|JsonResponse 普通表单返回文章列表，异步保存返回草稿地址
     * @Throws ValidationException 字段无效或当前租户无发布权限
     * @Throws HttpException 无权修改已发布文章
     */
    public function saveArticle(Request $request, WebsiteMediaStorage $mediaStorage, ?int $article = null): RedirectResponse|JsonResponse
    {
        $context = $this->context($request);
        $this->authorize($context, $article === null ? 'articles.create' : 'articles.edit');
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'excerpt' => ['nullable', 'string', 'max:2000'],
            'cover_image' => ['nullable', 'string', 'max:1000', 'regex:/^(https?:\/\/|\/)[^\r\n]*$/i'],
            'cover_file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'content' => ['required', 'string', 'max:1000000'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'keywords' => ['nullable', 'string', 'max:1000'],
            'category_id' => ['nullable', 'integer'],
            'publish' => ['nullable', 'boolean'],
        ]);
        if ($request->hasFile('cover_file') && ! $mediaStorage->isCloudStorageReady((int) $context->site->id)) {
            throw ValidationException::withMessages([
                'cover_file' => '请先启用并完整配置云存储插件，再上传文章封面；也可以填写已有图片地址。',
            ]);
        }
        $category = isset($validated['category_id'])
            ? Category::query()->where('site_id', (int) $context->site->id)->where('owner_admin_id', (int) $context->owner->id)->find($validated['category_id'])
            : null;
        if (isset($validated['category_id']) && ! $category instanceof Category) {
            throw ValidationException::withMessages(['category_id' => '所选分类不属于当前官网']);
        }
        $publish = $request->boolean('publish');
        if ($publish && ! $context->allows('articles.publish')) {
            throw ValidationException::withMessages(['publish' => '当前账号没有发布权限']);
        }
        $record = $article !== null
            ? Article::query()->where('site_id', (int) $context->site->id)
                ->where('owner_admin_id', (int) $context->owner->id)
                ->findOrFail($article)
            : new Article();
        abort_if($record->status === 'published' && ! $context->allows('articles.publish'), 403);
        $category ??= $this->ensureCategory($context);
        $author = $this->ensureAuthor($context);
        $previousCover = (string) ($record->cover_image ?? '');
        $coverImage = trim((string) ($validated['cover_image'] ?? ''));
        $uploadedCover = false;
        if ($request->hasFile('cover_file')) {
            try {
                $coverImage = $mediaStorage->upload($request->file('cover_file'), (int) $context->site->id, 'articles');
                $uploadedCover = true;
            } catch (\Throwable) {
                throw ValidationException::withMessages(['cover_file' => '封面图片保存失败，请重新上传']);
            }
        }
        $state = ArticleWorkflow::normalizeState(
            $publish ? 'published' : 'draft',
            $publish ? 'approved' : 'pending',
            $publish ? now()->format('Y-m-d H:i:s') : null
        );
        $record->fill([
            'site_id' => (int) $context->site->id,
            'owner_admin_id' => (int) $context->owner->id,
            'title' => trim((string) $validated['title']),
            'slug' => $record->exists ? $record->slug : $this->uniqueSlug(),
            'excerpt' => trim((string) ($validated['excerpt'] ?? '')),
            'cover_image' => $coverImage,
            'content' => (string) $validated['content'],
            'meta_description' => trim((string) ($validated['meta_description'] ?? '')),
            'keywords' => trim((string) ($validated['keywords'] ?? '')),
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            ...$state,
        ]);
        try {
            $record->save();
        } catch (\Throwable $exception) {
            if ($uploadedCover) {
                $mediaStorage->deleteLocalIfUnused($coverImage, (int) $context->site->id);
            }
            throw $exception;
        }
        $ownedPrefix = '/storage/website-articles/'.(int) $context->site->id.'/';
        if ($previousCover !== '' && $previousCover !== $coverImage && str_starts_with($previousCover, $ownedPrefix)
            && ! Article::withoutGlobalScopes()->where('cover_image', $previousCover)->exists()) {
            Storage::disk('public')->delete(substr($previousCover, strlen('/storage/')));
        }

        if ($request->expectsJson()) {
            return response()->json([
                'id' => (int) $record->id,
                'status' => $record->status,
                'cover_image' => $coverImage,
                'edit_url' => route('website-admin.articles.edit', $record->id, false),
                'update_url' => route('website-admin.articles.update', $record->id, false),
            ], $article === null ? 201 : 200);
        }

        return redirect()->route('website-admin.articles')->with('status', $publish ? '文章已发布' : '文章草稿已保存');
    }

    /**
     * @Name: deleteArticle
     * @Description: 软删除当前站点文章，拒绝对其他站点文章执行操作。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:46:30
     * @UpdateTime: 2026-11-12 15:18:17
     * @Param Request $request 当前网站后台请求
     * @Param int $article 文章主键
     * @Return RedirectResponse 返回文章列表
     * @Throws ModelNotFoundException 文章不存在或不属于当前站点
     * @Throws HttpException 无权移除已发布文章
     */
    public function deleteArticle(Request $request, int $article): RedirectResponse
    {
        $context = $this->context($request);
        $this->authorize($context, 'articles.delete');
        $record = Article::query()->where('site_id', (int) $context->site->id)
            ->where('owner_admin_id', (int) $context->owner->id)
            ->findOrFail($article);
        abort_if($record->status === 'published' && ! $context->allows('articles.publish'), 403);
        $record->delete();

        return redirect()->route('website-admin.articles')->with('status', '文章已移入回收状态');
    }

    /**
     * @Name: generateDraft
     * @Description: 调用站点 GEO 所有者配置的模型生成尚未保存或发布的文章及附属属性。
     * @Url [POST] /admin/articles/generate
     *      登录 是
     *      topic string 必选 文章主题
     *      model_id integer 必选 当前站点可用对话模型 ID
     *      requirements string 可选 补充写作要求
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:46:30
     * @UpdateTime: 2026-11-12 15:18:17
     * @Param Request $request 包含主题及可选要求的 JSON 请求
     * @Param WebsiteArticleDraftService $draftService 官网 AI 草稿服务
     * @Return JsonResponse 生成的正文、标题、摘要、关键词及分类建议
     * @Throws ValidationException 参数无效或模型调用失败
     */
    public function generateDraft(Request $request, WebsiteArticleDraftService $draftService): JsonResponse
    {
        $context = $this->context($request);
        $this->authorize($context, 'articles.ai');
        $validated = $request->validate([
            'topic' => ['required', 'string', 'min:2', 'max:300'],
            'model_id' => ['required', 'integer', 'min:1'],
            'requirements' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $draft = $draftService->generate(
                $context->site,
                $context->owner,
                (int) $validated['model_id'],
                trim((string) $validated['topic']),
                trim((string) ($validated['requirements'] ?? ''))
            );
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($draft);
    }

    /**
     * @Name: previewMarkdown
     * @Description: 使用与官网正文相同的安全 Markdown 渲染器生成编辑预览，不保存或执行正文脚本。
     * @Url [POST] /admin/markdown/preview
     *      登录 是
     *      content string 必选 待预览的 Markdown 内容，最多 500000 字符
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-12 15:18:17
     * @Param Request $request Markdown 预览请求
     * @Return JsonResponse 已过滤不安全 HTML 的渲染结果
     * @Throws ValidationException 内容缺失或超过限制
     */
    public function previewMarkdown(Request $request): JsonResponse
    {
        $context = $this->context($request);
        abort_unless(
            $context->allows('articles.create') || $context->allows('articles.edit')
                || $context->allows('products.create') || $context->allows('products.edit')
                || $context->allows('pages.create') || $context->allows('pages.edit'),
            403
        );
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:500000'],
        ]);

        return response()->json([
            'html' => ArticleHtmlPresenter::markdownToHtml((string) $validated['content']),
        ]);
    }

    /**
     * @Name: team
     * @Description: 按角色或管理员入口分别展示当前站点权限管理内容，GEO 所有者账号不可编辑。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:46:30
     * @UpdateTime: 2026-11-12 15:18:17
     * @Param Request $request 当前网站后台请求
     * @Return View 当前租户的权限角色页或管理员页
     * @Throws AuthorizationException 团队权限仅站点所有者拥有
     */
    public function team(Request $request): View
    {
        $context = $this->context($request);
        $this->authorize($context, $request->routeIs('website-admin.team.users') ? 'users.read' : 'roles.read');
        $showUsers = $request->routeIs('website-admin.team.users');

        return $this->page('team', $showUsers ? '管理员' : '权限角色', [
            'roles' => WebsiteRole::query()->where('site_id', (int) $context->site->id)->withCount('users')->orderBy('name')->get(),
            'users' => $showUsers
                ? WebsiteUser::query()->where('site_id', (int) $context->site->id)->with('role')->orderBy('username')->get()
                : collect(),
            'permissionTree' => WebsitePermissionCatalog::tree(),
            'showUsers' => $showUsers,
        ], $context);
    }

    /**
     * @Name: createRole
     * @Description: 为当前站点创建权限白名单内的自定义后台角色。
     * @Url [POST] /admin/team/roles
     *      登录 是
     *      name string 必选 角色名称
     *      permissions array 可选 权限键列表
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:46:30
     * @UpdateTime: 2026-11-12 15:23:35
     * @Param Request $request 角色名称与权限
     * @Return RedirectResponse 返回权限角色页
     * @Throws ValidationException 角色名称冲突或权限键无效
     */
    public function createRole(Request $request): RedirectResponse
    {
        $context = $this->context($request);
        $this->authorize($context, 'roles.create');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('website_roles', 'name')->where('site_id', (int) $context->site->id)],
            'permissions' => ['nullable', 'array', 'max:'.count(WebsitePermissionCatalog::keys())],
            'permissions.*' => ['string', Rule::in(WebsitePermissionCatalog::acceptedKeys())],
        ]);
        $granted = WebsitePermissionCatalog::forStorage($validated['permissions'] ?? []);
        $this->authorizeGrantedPermissions($context, WebsitePermissionCatalog::expand($granted));
        WebsiteRole::query()->create([
            'site_id' => (int) $context->site->id,
            'name' => trim((string) $validated['name']),
            'permissions' => $granted,
        ]);

        return redirect()->route('website-admin.team.roles')->with('status', '后台角色已创建');
    }

    /**
     * @Name: updateRole
     * @Description: 更新当前站点角色名称和权限白名单。
     * @Url [PUT] /admin/team/roles/{role}
     *      登录 是
     *      name string 必选 角色名称
     *      permissions array 可选 权限键列表
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:46:30
     * @UpdateTime: 2026-11-12 15:23:35
     * @Param Request $request 角色更新内容
     * @Param int $role 当前站点角色 ID
     * @Return RedirectResponse 返回权限角色页
     * @Throws ValidationException 字段无效或角色越租户
     */
    public function updateRole(Request $request, int $role): RedirectResponse
    {
        $context = $this->context($request);
        $this->authorize($context, 'roles.edit');
        $record = WebsiteRole::query()->where('site_id', (int) $context->site->id)->findOrFail($role);
        $this->authorizeGrantedPermissions($context, WebsitePermissionCatalog::expand($record->permissions ?? []));
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('website_roles', 'name')->ignore($record->id)->where('site_id', (int) $context->site->id)],
            'permissions' => ['nullable', 'array', 'max:'.count(WebsitePermissionCatalog::keys())],
            'permissions.*' => ['string', Rule::in(WebsitePermissionCatalog::acceptedKeys())],
        ]);
        $granted = WebsitePermissionCatalog::forStorage($validated['permissions'] ?? []);
        $this->authorizeGrantedPermissions($context, WebsitePermissionCatalog::expand($granted));
        $record->update([
            'name' => trim((string) $validated['name']),
            'permissions' => $granted,
        ]);

        return redirect()->route('website-admin.team.roles')->with('status', '后台角色权限已更新');
    }

    /**
     * @Name: deleteRole
     * @Description: 删除当前站点未被成员使用的自定义角色。
     * @Url [DELETE] /admin/team/roles/{role}
     *      登录 是
     *      无请求体参数
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:46:30
     * @UpdateTime: 2026-11-12 15:18:17
     * @Param Request $request 当前网站后台请求
     * @Param int $role 当前站点角色 ID
     * @Return RedirectResponse 返回权限角色页
     * @Throws ValidationException 角色仍被成员使用
     */
    public function deleteRole(Request $request, int $role): RedirectResponse
    {
        $context = $this->context($request);
        $this->authorize($context, 'roles.delete');
        $record = WebsiteRole::query()->where('site_id', (int) $context->site->id)->findOrFail($role);
        $this->authorizeGrantedPermissions($context, WebsitePermissionCatalog::expand($record->permissions ?? []));
        if ($record->users()->exists()) {
            throw ValidationException::withMessages(['role' => '该角色仍绑定后台管理员，请先调整管理员角色']);
        }
        $record->delete();

        return redirect()->route('website-admin.team.roles')->with('status', '后台角色已删除');
    }

    /**
     * @Name: createUser
     * @Description: 为当前站点创建独立后台成员，GEO 所有者账号不属于可编辑成员表。
     * @Url [POST] /admin/team/users
     *      登录 是
     *      username string 必选 站点内唯一用户名
     *      display_name string 可选 成员展示名称
     *      password string 必选 至少 12 位登录密码
     *      website_role_id integer 必选 当前站点角色 ID
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:46:30
     * @UpdateTime: 2026-11-12 15:18:17
     * @Param Request $request 成员账号及角色信息
     * @Return RedirectResponse 返回管理员页
     * @Throws ValidationException 用户名冲突、密码弱或角色不属于当前站点
     */
    public function createUser(Request $request): RedirectResponse
    {
        $context = $this->context($request);
        $this->authorize($context, 'users.create');
        $validated = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:50', Rule::unique('website_users', 'username')->where('site_id', (int) $context->site->id)],
            'display_name' => ['nullable', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:12', 'max:1024'],
            'website_role_id' => ['required', 'integer', Rule::exists('website_roles', 'id')->where('site_id', (int) $context->site->id)],
        ]);
        $role = WebsiteRole::query()->where('site_id', (int) $context->site->id)->findOrFail($validated['website_role_id']);
        $this->authorizeGrantedPermissions($context, WebsitePermissionCatalog::expand($role->permissions ?? []));
        WebsiteUser::query()->create([
            'site_id' => (int) $context->site->id,
            'website_role_id' => (int) $validated['website_role_id'],
            'username' => trim((string) $validated['username']),
            'display_name' => trim((string) ($validated['display_name'] ?? '')),
            'password' => (string) $validated['password'],
            'status' => 'active',
            'created_by_admin_id' => (int) $context->owner->id,
        ]);

        return redirect()->route('website-admin.team.users')->with('status', '网站后台管理员已创建');
    }

    /**
     * @Name: updateUser
     * @Description: 更新当前站点成员展示名、角色和状态，不触碰 GEO 所有者账号。
     * @Url [PUT] /admin/team/users/{user}
     *      登录 是
     *      display_name string 可选 成员展示名称
     *      website_role_id integer 必选 当前站点角色 ID
     *      status string 必选 active 或 disabled
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:46:30
     * @UpdateTime: 2026-11-12 15:18:17
     * @Param Request $request 成员更新字段
     * @Param int $user 成员 ID
     * @Return RedirectResponse 返回管理员页
     * @Throws ValidationException 字段无效或成员不属于当前站点
     */
    public function updateUser(Request $request, int $user): RedirectResponse
    {
        $context = $this->context($request);
        $this->authorize($context, 'users.edit');
        $record = WebsiteUser::query()->where('site_id', (int) $context->site->id)->findOrFail($user);
        abort_if($record->id === $context->user?->id, 403);
        $this->authorizeGrantedPermissions($context, WebsitePermissionCatalog::expand($record->role?->permissions ?? []));
        $validated = $request->validate([
            'display_name' => ['nullable', 'string', 'max:100'],
            'website_role_id' => ['required', 'integer', Rule::exists('website_roles', 'id')->where('site_id', (int) $context->site->id)],
            'status' => ['required', Rule::in(['active', 'disabled'])],
        ]);
        $role = WebsiteRole::query()->where('site_id', (int) $context->site->id)->findOrFail($validated['website_role_id']);
        $this->authorizeGrantedPermissions($context, WebsitePermissionCatalog::expand($role->permissions ?? []));
        $record->update([
            'display_name' => trim((string) ($validated['display_name'] ?? '')),
            'website_role_id' => (int) $validated['website_role_id'],
            'status' => (string) $validated['status'],
        ]);

        return redirect()->route('website-admin.team.users')->with('status', '网站后台管理员已更新');
    }

    /**
     * @Name: deleteUser
     * @Description: 移除当前站点独立后台成员，GEO 所有者身份无法由该接口删除。
     * @Url [DELETE] /admin/team/users/{user}
     *      登录 是
     *      无请求体参数
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:46:30
     * @UpdateTime: 2026-11-12 15:18:17
     * @Param Request $request 当前网站后台请求
     * @Param int $user 成员 ID
     * @Return RedirectResponse 返回管理员页
     * @Throws ModelNotFoundException 成员不存在或不属于当前站点
     */
    public function deleteUser(Request $request, int $user): RedirectResponse
    {
        $context = $this->context($request);
        $this->authorize($context, 'users.delete');
        $record = WebsiteUser::query()->where('site_id', (int) $context->site->id)->findOrFail($user);
        abort_if($record->id === $context->user?->id, 403);
        $this->authorizeGrantedPermissions($context, WebsitePermissionCatalog::expand($record->role?->permissions ?? []));
        $record->delete();

        return redirect()->route('website-admin.team.users')->with('status', '网站后台管理员已移除');
    }

    /**
     * @Name: context
     * @Description: 从认证中间件写入的请求属性读取当前站点后台权限上下文。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:46:30
     * @UpdateTime: 2026-11-11 10:46:30
     * @Param Request $request 当前网站后台请求
     * @Return WebsiteAdminContext 已验证的站点与账号上下文
     * @Throws HttpException 缺失认证上下文时返回 403
     */
    private function context(Request $request): WebsiteAdminContext
    {
        $context = $request->attributes->get('website_admin');
        abort_unless($context instanceof WebsiteAdminContext, 403);

        return $context;
    }

    /**
     * @Name: authorize
     * @Description: 拒绝不具备指定站点权限的独立后台成员。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:46:30
     * @UpdateTime: 2026-11-12 15:18:17
     * @Param WebsiteAdminContext $context 当前站点权限上下文
     * @Param string $permission 所需权限键
     * @Return void 权限通过后继续执行
     * @Throws HttpException 无权限时返回 403
     */
    private function authorize(WebsiteAdminContext $context, string $permission): void
    {
        abort_unless($context->allows($permission), 403);
    }

    /**
     * @Name: authorizeGrantedPermissions
     * @Description: 受委托的管理员只能授予或调整自己已经拥有的权限，防止通过角色和账号提权。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 14:55:53
     * @UpdateTime: 2026-11-12 14:55:53
     * @Param WebsiteAdminContext $context 当前后台账号
     * @Param array $permissions 目标角色权限
     * @Return void 校验通过后继续
     * @Throws HttpException 目标角色包含当前账号未拥有的权限
     */
    private function authorizeGrantedPermissions(WebsiteAdminContext $context, array $permissions): void
    {
        foreach ($permissions as $permission) {
            $this->authorize($context, $permission);
        }
    }

    /**
     * @Name: page
     * @Description: 使用统一官网管理布局渲染页面并注入标题和站点权限上下文。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:46:30
     * @UpdateTime: 2026-11-11 10:46:30
     * @Param string $view 页面视图名称
     * @Param string $title 页面标题
     * @Param array<string,mixed> $data 页面业务数据
     * @Param WebsiteAdminContext $context 当前站点权限上下文
     * @Return View 已组装的 Blade 视图
     * @Throws InvalidArgumentException 页面模板不存在
     */
    private function page(string $view, string $title, array $data, WebsiteAdminContext $context): View
    {
        return view('website-admin.'.$view, [
            ...$data,
            'pageTitle' => $title,
            'context' => $context,
        ]);
    }

    /**
     * @Name: ensureCategory
     * @Description: 为尚无分类的当前站点创建默认分类，满足既有文章表的分类外键约束。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:46:30
     * @UpdateTime: 2026-11-11 10:46:30
     * @Param WebsiteAdminContext $context 当前站点权限上下文
     * @Return Category 当前站点默认分类
     * @Throws QueryException 分类数据无法写入数据库
     */
    private function ensureCategory(WebsiteAdminContext $context): Category
    {
        $slug = 'website-default-'.$context->site->id;
        $category = Category::query()
            ->where('site_id', (int) $context->site->id)
            ->where('owner_admin_id', (int) $context->owner->id)
            ->first();
        if ($category instanceof Category) {
            return $category;
        }

        return Category::query()->create([
            'site_id' => (int) $context->site->id,
            'owner_admin_id' => (int) $context->owner->id,
            'name' => '默认分类',
            'slug' => $slug,
            'description' => '',
        ]);
    }

    /**
     * @Name: ensureAuthor
     * @Description: 查找或创建当前站点 GEO 所有者的默认官网文章作者。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:46:30
     * @UpdateTime: 2026-11-11 10:46:30
     * @Param WebsiteAdminContext $context 当前站点权限上下文
     * @Return Author 当前站点默认文章作者
     * @Throws QueryException 作者数据无法写入数据库
     */
    private function ensureAuthor(WebsiteAdminContext $context): Author
    {
        $author = Author::query()
            ->where('site_id', (int) $context->site->id)
            ->where('owner_admin_id', (int) $context->owner->id)
            ->orderBy('id')
            ->first();
        if ($author instanceof Author) {
            return $author;
        }

        return Author::query()->create([
            'site_id' => (int) $context->site->id,
            'owner_admin_id' => (int) $context->owner->id,
            'name' => $context->displayName,
        ]);
    }

    /**
     * @Name: uniqueSlug
     * @Description: 以全局未删除文章集合检查随机 slug，满足 articles.slug 全局唯一约束。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:46:30
     * @UpdateTime: 2026-11-11 10:46:30
     * @Return string 全局唯一文章 URL 标识
     * @Throws RandomException 随机数源不可用
     */
    private function uniqueSlug(): string
    {
        do {
            $slug = Str::lower(Str::random(12));
        } while (Article::withoutGlobalScopes()->withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }

}
