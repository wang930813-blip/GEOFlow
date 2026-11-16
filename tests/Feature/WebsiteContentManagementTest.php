<?php

/**
 * Created by Codex.
 * @Date: 2026-11-11
 * @Time: 17:26:06
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： WebsiteContentManagementTest.php
 * @Description: 验证官网共用分类、产品、单页、SEO 输出和跨站点权限边界。
 */

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\AiModel;
use App\Models\Category;
use App\Models\Site;
use App\Models\WebsitePage;
use App\Models\WebsiteProduct;
use App\Models\WebsiteRole;
use App\Models\WebsiteUser;
use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class WebsiteContentManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @Name: test_owner_can_publish_products_pages_and_shared_categories_on_the_public_site
     * @Description: 创建文章与产品共用分类，发布产品及关于页面，并验证前台、站点地图和 GEO 文件输出。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 17:26:06
     * @UpdateTime: 2026-11-11 17:26:06
     * @Return void 验证官网内容管理与访客展示链路
     * @Throws AssertionFailedError 表单、发布状态、SEO 输出或前台内容与预期不符
     */
    public function test_owner_can_publish_products_pages_and_shared_categories_on_the_public_site(): void
    {
        [$owner, $site] = $this->createOwnerSite('content_owner_alpha', 'content-alpha.example.test');
        $this->signIn($site, $owner);

        $this->post('http://'.$site->domain.'/admin/categories', [
            'name' => '办公设备',
            'description' => '企业办公产品分类',
            'sort_order' => 3,
        ])->assertRedirect(route('website-admin.categories', absolute: false));
        $category = Category::query()->where('site_id', (int) $site->id)->where('owner_admin_id', (int) $owner->id)->firstOrFail();

        $this->post('http://'.$site->domain.'/admin/products', [
            'title' => '智能会议终端',
            'slug' => 'smart-meeting-terminal',
            'summary' => '面向企业会议空间的协作设备。',
            'content' => "## 产品介绍\n\n支持安全协作与高清会议。",
            'category_id' => (int) $category->id,
            'status' => 'published',
            'sort_order' => 1,
        ])->assertRedirect(route('website-admin.products', absolute: false));
        $product = WebsiteProduct::query()->where('site_id', (int) $site->id)->where('slug', 'smart-meeting-terminal')->firstOrFail();

        $this->post('http://'.$site->domain.'/admin/pages', [
            'title' => '关于我们',
            'slug' => 'about',
            'content' => "## 企业介绍\n\n这是一段公开的企业介绍。",
            'meta_title' => '关于我们 - 测试企业',
            'meta_description' => '测试企业介绍',
            'status' => 'published',
            'show_in_navigation' => '1',
        ])->assertRedirect(route('website-admin.pages', absolute: false));

        $this->get('http://'.$site->domain.'/admin/articles/create')
            ->assertOk()
            ->assertSee('data-wa-markdown-editor', false)
            ->assertSee('创建内容分类');
        $this->get('http://'.$site->domain.'/admin/categories')->assertOk()->assertSee('办公设备');
        $this->get('http://'.$site->domain.'/admin/products')->assertOk()->assertSee('智能会议终端');
        $this->get('http://'.$site->domain.'/admin/pages')->assertOk()->assertSee('关于我们');

        $this->get('http://'.$site->domain.'/products')->assertOk()->assertSee('智能会议终端');
        $this->get('http://'.$site->domain.'/product/smart-meeting-terminal')
            ->assertOk()
            ->assertSee('支持安全协作与高清会议。');
        $this->get('http://'.$site->domain.'/about')
            ->assertOk()
            ->assertSee('这是一段公开的企业介绍。')
            ->assertSee('<meta name="description" content="测试企业介绍">', false);

        $this->get('http://'.$site->domain.'/sitemap.xml')
            ->assertOk()
            ->assertSee('/product/smart-meeting-terminal')
            ->assertSee('/about');
        $this->get('http://'.$site->domain.'/llms.txt')
            ->assertOk()
            ->assertSee('智能会议终端')
            ->assertSee('关于我们');

        $this->delete('http://'.$site->domain.'/admin/categories/'.$category->id)
            ->assertSessionHasErrors('category');
        $this->assertDatabaseHas('website_products', [
            'id' => (int) $product->id,
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
        ]);
    }

    /**
     * @Name: test_category_slug_is_generated_and_kept_when_category_name_changes
     * @Description: 验证分类地址由系统生成、编辑名称不改变已有链接，分类按排序值从高到低展示。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 10:31:24
     * @UpdateTime: 2026-11-12 10:44:29
     * @Return void 验证分类地址和排序表单约束
     * @Throws AssertionFailedError 分类地址、表单字段或排序规则不符合预期
     */
    public function test_category_slug_is_generated_and_kept_when_category_name_changes(): void
    {
        [$owner, $site] = $this->createOwnerSite('content_owner_categories', 'content-categories.example.test');
        $this->signIn($site, $owner);
        $baseUrl = 'http://'.$site->domain.'/admin/categories';

        $this->get($baseUrl.'/create')
            ->assertOk()
            ->assertDontSee("name='slug'", false)
            ->assertSee('数值越大越靠前');

        $this->post($baseUrl, ['name' => '办公设备', 'slug' => 'manual-slug', 'sort_order' => 3])
            ->assertRedirect(route('website-admin.categories', absolute: false));
        $category = Category::query()->where('site_id', (int) $site->id)->firstOrFail();
        $originalSlug = (string) $category->slug;
        $this->assertNotSame('manual-slug', $originalSlug);
        $this->assertNotSame('', $originalSlug);

        $this->post($baseUrl, ['name' => '办公用品', 'sort_order' => 1])
            ->assertRedirect(route('website-admin.categories', absolute: false));
        $this->assertNotSame($originalSlug, (string) Category::query()->where('name', '办公用品')->firstOrFail()->slug);

        $this->put($baseUrl.'/'.$category->id, ['name' => '办公设备分类', 'slug' => 'changed-slug', 'sort_order' => 2])
            ->assertRedirect(route('website-admin.categories', absolute: false));
        $category->refresh();
        $this->assertSame($originalSlug, (string) $category->slug);
        $this->assertSame(2, $category->sort_order);
        $this->get($baseUrl.'/'.$category->id.'/edit')
            ->assertOk()
            ->assertDontSee("name='slug'", false);
        $this->get($baseUrl)
            ->assertOk()
            ->assertDontSee('网址标识')
            ->assertSeeInOrder(['办公设备分类', '办公用品']);
        $this->get('http://'.$site->domain.'/news')
            ->assertOk()
            ->assertSeeInOrder(['办公设备分类', '办公用品']);

        $longName = str_repeat('a', 100);
        $this->post($baseUrl, ['name' => $longName])->assertRedirect(route('website-admin.categories', absolute: false));
        $this->post($baseUrl, ['name' => $longName])->assertRedirect(route('website-admin.categories', absolute: false));
        $longSlugs = Category::query()->where('site_id', (int) $site->id)->where('name', $longName)->pluck('slug');
        $this->assertCount(2, $longSlugs);
        $this->assertNotSame($longSlugs[0], $longSlugs[1]);
        $this->assertLessThanOrEqual(100, strlen((string) $longSlugs[0]));
        $this->assertLessThanOrEqual(100, strlen((string) $longSlugs[1]));
    }

    /**
     * @Name: test_article_ai_mode_is_opt_in_and_uses_the_selected_site_model
     * @Description: 验证 AI 写作弹窗仅列出当前站点模型，并生成可编辑正文与发布属性。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 11:03:25
     * @UpdateTime: 2026-11-12 13:26:53
     * @Return void 验证文章 AI 生成模式、模型选择隔离及附属属性
     * @Throws AssertionFailedError 页面默认状态、模型隔离或选中模型未被使用时抛出
     */
    public function test_article_ai_mode_is_opt_in_and_uses_the_selected_site_model(): void
    {
        [$owner, $site] = $this->createOwnerSite('content_owner_ai', 'content-ai.example.test');
        $this->signIn($site, $owner);
        $this->post('http://'.$site->domain.'/admin/categories', ['name' => '企业动态', 'sort_order' => 10])
            ->assertRedirect();
        $category = Category::query()->where('site_id', (int) $site->id)->firstOrFail();
        $crypto = app(ApiKeyCrypto::class);
        $selectedModel = AiModel::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'name' => '文章写作模型',
            'version' => 'test',
            'api_key' => $crypto->encrypt('article-ai-key'),
            'model_id' => 'article-model-selected',
            'model_type' => 'chat',
            'api_url' => 'https://article-ai.example.test/v1',
            'failover_priority' => 10,
            'status' => 'active',
        ]);
        AiModel::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'name' => '默认优先模型',
            'version' => 'test',
            'api_key' => $crypto->encrypt('priority-ai-key'),
            'model_id' => 'priority-model',
            'model_type' => 'chat',
            'api_url' => 'https://priority-ai.example.test/v1',
            'failover_priority' => 1,
            'status' => 'active',
        ]);
        AiModel::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'name' => '站点嵌入模型',
            'version' => 'test',
            'api_key' => $crypto->encrypt('embedding-key'),
            'model_id' => 'site-embedding-model',
            'model_type' => 'embedding',
            'api_url' => 'https://embedding.example.test/v1',
            'status' => 'active',
        ]);
        [$otherOwner, $otherSite] = $this->createOwnerSite('content_owner_ai_other', 'content-ai-other.example.test');
        $otherModel = AiModel::query()->create([
            'site_id' => (int) $otherSite->id,
            'owner_admin_id' => (int) $otherOwner->id,
            'name' => '其他站点模型',
            'version' => 'test',
            'api_key' => $crypto->encrypt('other-site-key'),
            'model_id' => 'other-site-model',
            'model_type' => 'chat',
            'api_url' => 'https://other-ai.example.test/v1',
            'status' => 'active',
        ]);

        $this->get('http://'.$site->domain.'/admin/articles/create')
            ->assertOk()
            ->assertSee("id='wa-ai-open' type='button' aria-haspopup='dialog' aria-controls='wa-ai-dialog'", false)
            ->assertSee("id='wa-ai-dialog' aria-labelledby='wa-ai-dialog-title'", false)
            ->assertDontSee("id='wa-ai-mode'", false)
            ->assertSee("name='meta_description'", false)
            ->assertSee("name='keywords'", false)
            ->assertSee('文章写作模型 (article-model-selected)')
            ->assertSee('默认优先模型 (priority-model)')
            ->assertDontSee('站点嵌入模型')
            ->assertDontSee('其他站点模型');

        $this->postJson('http://'.$site->domain.'/admin/articles/generate', [
            'topic' => '企业官网内容优化',
            'model_id' => 999999,
        ])->assertUnprocessable()->assertJsonPath('message', '所选文章生成模型不可用，请重新选择');
        $this->postJson('http://'.$site->domain.'/admin/articles/generate', [
            'topic' => '企业官网内容优化',
            'model_id' => (int) $otherModel->id,
        ])->assertUnprocessable()->assertJsonPath('message', '所选文章生成模型不可用，请重新选择');

        $promptedModels = [];
        MarkdownContentWriterAgent::fake(function (string $prompt, mixed $attachments, mixed $provider, string $model) use (&$promptedModels, $category): string {
            $promptedModels[] = $model;

            return json_encode([
                'title' => '企业官网内容优化',
                'excerpt' => '官网内容优化方法概览。',
                'content' => "# 企业官网内容优化\n\n提供清晰、准确的官网内容。",
                'meta_description' => '了解企业官网内容优化方法。',
                'keywords' => '企业官网,内容优化',
                'category_id' => (int) $category->id,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        })->preventStrayPrompts();

        $this->postJson('http://'.$site->domain.'/admin/articles/generate', [
            'topic' => '企业官网内容优化',
            'model_id' => (int) $selectedModel->id,
            'requirements' => '面向企业网站负责人',
        ])->assertOk()
            ->assertJsonPath('content', "# 企业官网内容优化\n\n提供清晰、准确的官网内容。")
            ->assertJsonPath('title', '企业官网内容优化')
            ->assertJsonPath('excerpt', '官网内容优化方法概览。')
            ->assertJsonPath('meta_description', '了解企业官网内容优化方法。')
            ->assertJsonPath('keywords', '企业官网,内容优化')
            ->assertJsonPath('category_id', (int) $category->id);
        $this->assertSame(['article-model-selected'], $promptedModels);
    }

    /**
     * @Name: test_generated_article_draft_save_returns_edit_address_and_updates_same_record
     * @Description: 验证异步自动保存返回草稿编辑地址，再次保存更新同一篇文章。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 13:26:53
     * @UpdateTime: 2026-11-12 13:26:53
     * @Return void 验证文章草稿自动保存的创建及更新响应
     * @Throws AssertionFailedError 草稿状态、地址或文章归属不正确时抛出
     */
    public function test_generated_article_draft_save_returns_edit_address_and_updates_same_record(): void
    {
        [$owner, $site] = $this->createOwnerSite('content_owner_draft', 'content-draft.example.test');
        $this->signIn($site, $owner);
        $payload = [
            'title' => '企业官网内容优化',
            'excerpt' => '官网内容优化方法概览。',
            'content' => "# 企业官网内容优化\n\n提供清晰、准确的官网内容。",
            'meta_description' => '了解企业官网内容优化方法。',
            'keywords' => '企业官网,内容优化',
            'publish' => '0',
        ];

        $created = $this->postJson('http://'.$site->domain.'/admin/articles', $payload)
            ->assertCreated()
            ->assertJsonPath('status', 'draft');
        $articleId = (int) $created->json('id');
        $created->assertJsonPath('edit_url', '/admin/articles/'.$articleId.'/edit')
            ->assertJsonPath('update_url', '/admin/articles/'.$articleId);

        $this->putJson('http://'.$site->domain.'/admin/articles/'.$articleId, [
            ...$payload,
            'title' => '更新后的草稿标题',
        ])->assertOk()->assertJsonPath('id', $articleId)->assertJsonPath('status', 'draft');

        $this->assertSame(1, Article::query()->where('site_id', (int) $site->id)
            ->where('owner_admin_id', (int) $owner->id)->count());
        $this->assertDatabaseHas('articles', [
            'id' => $articleId,
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'title' => '更新后的草稿标题',
            'status' => 'draft',
        ]);
    }

    /**
     * @Name: test_article_cover_requires_cloud_storage_for_upload_and_accepts_existing_url
     * @Description: 验证云存储不可用时只接受封面地址并拒绝文件上传。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 15:43:52
     * @UpdateTime: 2026-11-12 17:48:25
     * @Return void 验证当前站点封面上传受云存储配置控制且地址可正常保存
     * @Throws AssertionFailedError 封面地址保存或文件上传拦截异常
     */
    public function test_article_cover_requires_cloud_storage_for_upload_and_accepts_existing_url(): void
    {
        [$owner, $site] = $this->createOwnerSite('content_cover_owner', 'content-cover.example.test');
        $this->signIn($site, $owner);
        $baseUrl = 'http://'.$site->domain.'/admin/articles';

        $this->get($baseUrl.'/create')->assertOk()
            ->assertDontSee("name='cover_file'", false)
            ->assertSee("name='cover_image'", false)
            ->assertDontSee("enctype='multipart/form-data'", false);
        $this->post($baseUrl, [
            'title' => '封面文章',
            'content' => '文章正文',
            'publish' => '0',
            'cover_file' => UploadedFile::fake()->image('cover.jpg', 1200, 675),
        ])->assertSessionHasErrors('cover_file');
        $this->assertDatabaseMissing('articles', ['site_id' => (int) $site->id, 'title' => '封面文章']);

        $this->post($baseUrl, [
            'title' => '封面文章',
            'content' => '文章正文',
            'publish' => '0',
            'cover_image' => 'https://images.example.test/original.jpg',
        ])->assertRedirect(route('website-admin.articles', absolute: false));
        $article = Article::query()->where('site_id', (int) $site->id)->where('title', '封面文章')->firstOrFail();
        $this->assertSame('https://images.example.test/original.jpg', (string) $article->cover_image);
        $this->get($baseUrl.'/'.$article->id.'/edit')->assertOk()->assertSee('https://images.example.test/original.jpg');

        $this->put($baseUrl.'/'.$article->id, [
            'title' => '封面文章',
            'content' => '文章正文',
            'publish' => '0',
            'cover_image' => 'https://images.example.test/replaced.jpg',
        ])->assertRedirect(route('website-admin.articles', absolute: false));
        $this->assertSame('https://images.example.test/replaced.jpg', (string) $article->fresh()->cover_image);

        $this->postJson($baseUrl, [
            'title' => '另一篇封面文章',
            'content' => '文章正文',
            'publish' => '0',
            'cover_image' => '/storage/articles/existing.webp',
        ])->assertCreated()->assertJsonPath('cover_image', '/storage/articles/existing.webp');
        $this->post($baseUrl, [
            'title' => '无效封面',
            'content' => '文章正文',
            'cover_file' => UploadedFile::fake()->create('document.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('cover_file');
        $this->assertDatabaseMissing('articles', ['site_id' => (int) $site->id, 'title' => '无效封面']);
    }

    /**
     * @Name: test_website_admin_navigation_separates_roles_and_administrators
     * @Description: 验证系统管理下角色与管理员各自独立展示，创建操作返回对应页面。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 14:20:30
     * @UpdateTime: 2026-11-12 14:38:59
     * @Return void 验证官网后台导航与权限管理页面归属
     * @Throws AssertionFailedError 菜单、页面隔离或操作返回地址不符合预期
     */
    public function test_website_admin_navigation_separates_roles_and_administrators(): void
    {
        [$owner, $site] = $this->createOwnerSite('content_owner_navigation', 'content-navigation.example.test');
        $this->signIn($site, $owner);
        $baseUrl = 'http://'.$site->domain.'/admin';

        $this->get($baseUrl.'/')->assertOk()
            ->assertSee('>概览</a>', false)
            ->assertSee('>内容</span>', false)
            ->assertSee('>分类管理</a>', false)
            ->assertSee('>系统管理</span>', false)
            ->assertSee('>权限角色</a>', false)
            ->assertSee('>管理员</a>', false)
            ->assertDontSee('>团队与权限</a>', false);

        $this->get($baseUrl.'/team/roles')->assertOk()
            ->assertSee('<h1>权限角色</h1>', false)
            ->assertSee('<h2>角色列表</h2>', false)
            ->assertSee('新建角色')
            ->assertDontSee('添加管理员')
            ->assertDontSee('GEO');
        $this->get($baseUrl.'/team/users')->assertOk()
            ->assertSee('<h1>管理员</h1>', false)
            ->assertSee('<h2>账号列表</h2>', false)
            ->assertDontSee('新建角色')
            ->assertDontSee('GEO');

        $this->post($baseUrl.'/team/roles', [
            'name' => '内容编辑',
            'permissions' => ['articles.read', 'articles.write'],
        ])->assertRedirect(route('website-admin.team.roles', absolute: false));
        $role = WebsiteRole::query()->where('site_id', (int) $site->id)->firstOrFail();

        $this->post($baseUrl.'/team/users', [
            'username' => 'site_editor',
            'display_name' => '内容编辑员',
            'password' => 'editor-secret-123',
            'website_role_id' => (int) $role->id,
        ])->assertRedirect(route('website-admin.team.users', absolute: false));
        $this->get($baseUrl.'/team/users')->assertOk()->assertSee('site_editor');
        $this->get($baseUrl.'/team/roles')->assertOk()->assertDontSee('site_editor');
    }

    /**
     * @Name: test_markdown_preview_strips_html_and_rejects_unsafe_links
     * @Description: 确认官网后台 Markdown 预览复用不允许原始 HTML 和危险链接的安全渲染器。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 17:26:06
     * @UpdateTime: 2026-11-11 17:26:06
     * @Return void 验证预览接口 HTML 过滤结果
     * @Throws AssertionFailedError 预览接口返回脚本或危险链接时抛出
     */
    public function test_markdown_preview_strips_html_and_rejects_unsafe_links(): void
    {
        [$owner, $site] = $this->createOwnerSite('content_owner_preview', 'content-preview.example.test');
        $this->signIn($site, $owner);

        $response = $this->postJson('http://'.$site->domain.'/admin/markdown/preview', [
            'content' => '<script>alert(1)</script>\n\n[不安全链接](javascript:alert(1))',
        ])->assertOk();

        $html = (string) $response->json('html');
        $this->assertStringNotContainsString('<script', strtolower($html));
        $this->assertStringNotContainsString('javascript:', strtolower($html));
    }

    /**
     * @Name: test_product_structured_data_escapes_script_delimiters
     * @Description: 防止产品名称中的标签边界打断详情页的结构化数据脚本。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 18:38:35
     * @UpdateTime: 2026-11-11 18:38:35
     * @Return void 验证产品结构化数据保留安全 JSON 转义
     * @Throws AssertionFailedError 产品名称被拼接为可执行脚本
     */
    public function test_product_structured_data_escapes_script_delimiters(): void
    {
        [$owner, $site] = $this->createOwnerSite('content_owner_json', 'content-json.example.test');
        WebsiteProduct::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'title' => '</script><script>alert(1)</script>',
            'slug' => 'safe-json-product',
            'content' => '产品正文',
            'status' => 'published',
        ]);

        $this->get('http://'.$site->domain.'/product/safe-json-product')
            ->assertOk()
            ->assertDontSee('</script><script>alert(1)</script>', false)
            ->assertSee('\\u003C', false);
    }

    /**
     * @Name: test_other_site_cannot_edit_a_product_by_its_id
     * @Description: 登录其他客户官网后，使用已知产品 ID 读取编辑页仍返回未找到。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 17:26:06
     * @UpdateTime: 2026-11-11 17:26:06
     * @Return void 验证产品管理接口严格限定站点和所有者
     * @Throws AssertionFailedError 跨站点 ID 被访问时抛出
     */
    public function test_other_site_cannot_edit_a_product_by_its_id(): void
    {
        [$ownerA, $siteA] = $this->createOwnerSite('content_owner_a', 'content-a.example.test');
        [$ownerB, $siteB] = $this->createOwnerSite('content_owner_b', 'content-b.example.test');
        $product = WebsiteProduct::query()->create([
            'site_id' => (int) $siteA->id,
            'owner_admin_id' => (int) $ownerA->id,
            'title' => 'A 站产品',
            'slug' => 'a-product',
            'content' => 'A 站产品介绍',
            'status' => 'draft',
        ]);

        $this->signIn($siteB, $ownerB);
        $this->get('http://'.$siteB->domain.'/admin/products/'.$product->id.'/edit')->assertNotFound();
        $this->assertDatabaseHas('website_products', [
            'id' => (int) $product->id,
            'site_id' => (int) $siteA->id,
            'owner_admin_id' => (int) $ownerA->id,
        ]);
    }

    /**
     * @Name: test_content_editor_cannot_change_published_content_without_publish_permission
     * @Description: 仅持有内容编辑权限的站点成员可以进入草稿表单，但不能修改或移除已发布内容。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 18:29:20
     * @UpdateTime: 2026-11-11 18:39:14
     * @Return void 验证文章、产品和单页的发布权限边界
     * @Throws AssertionFailedError 已发布内容被无发布权限成员修改或删除
     */
    public function test_content_editor_cannot_change_published_content_without_publish_permission(): void
    {
        [$owner, $site] = $this->createOwnerSite('content_owner_permissions', 'content-permissions.example.test');
        $this->signIn($site, $owner);

        $this->post('http://'.$site->domain.'/admin/articles', [
            'title' => '公开文章',
            'content' => '公开文章正文',
            'publish' => '1',
        ])->assertRedirect(route('website-admin.articles', absolute: false));
        $article = Article::query()->where('site_id', (int) $site->id)->firstOrFail();
        $product = WebsiteProduct::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'title' => '公开产品',
            'slug' => 'published-product',
            'content' => '公开产品正文',
            'status' => 'published',
        ]);
        $websitePage = WebsitePage::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'title' => '公开单页',
            'slug' => 'published-page',
            'content' => '公开单页正文',
            'status' => 'published',
        ]);
        $draft = WebsiteProduct::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'title' => '产品草稿',
            'slug' => 'draft-product',
            'content' => '草稿正文',
            'status' => 'draft',
        ]);
        $role = WebsiteRole::query()->create([
            'site_id' => (int) $site->id,
            'name' => '内容编辑',
            'permissions' => [
                'articles.read', 'articles.write',
                'products.read', 'products.write',
                'pages.read', 'pages.write',
            ],
        ]);
        $member = WebsiteUser::query()->create([
            'site_id' => (int) $site->id,
            'website_role_id' => (int) $role->id,
            'username' => 'content_editor',
            'display_name' => '内容编辑',
            'password' => 'member-password-123',
            'status' => 'active',
            'created_by_admin_id' => (int) $owner->id,
        ]);
        $this->withSession([
            'website_admin_site_id' => (int) $site->id,
            'website_admin_type' => 'user',
            'website_admin_id' => (int) $member->id,
        ]);
        $baseUrl = 'http://'.$site->domain.'/admin';

        $this->get($baseUrl.'/products/'.$draft->id.'/edit')->assertOk();
        $this->get($baseUrl.'/articles/'.$article->id.'/edit')->assertForbidden();
        $this->get($baseUrl.'/products/'.$product->id.'/edit')->assertForbidden();
        $this->get($baseUrl.'/pages/'.$websitePage->id.'/edit')->assertForbidden();
        $this->put($baseUrl.'/articles/'.$article->id, [
            'title' => '被修改的文章',
            'content' => '被修改的正文',
            'publish' => '0',
        ])->assertForbidden();
        $this->put($baseUrl.'/products/'.$product->id, [
            'title' => '被修改的产品',
            'content' => '被修改的正文',
            'status' => 'draft',
        ])->assertForbidden();
        $this->put($baseUrl.'/pages/'.$websitePage->id, [
            'title' => '被修改的单页',
            'content' => '被修改的正文',
            'status' => 'draft',
        ])->assertForbidden();
        $this->delete($baseUrl.'/articles/'.$article->id)->assertForbidden();
        $this->delete($baseUrl.'/products/'.$product->id)->assertForbidden();
        $this->delete($baseUrl.'/pages/'.$websitePage->id)->assertForbidden();

        $this->assertDatabaseHas('articles', ['id' => (int) $article->id, 'title' => '公开文章', 'status' => 'published', 'deleted_at' => null]);
        $this->assertDatabaseHas('website_products', ['id' => (int) $product->id, 'title' => '公开产品', 'status' => 'published', 'deleted_at' => null]);
        $this->assertDatabaseHas('website_pages', ['id' => (int) $websitePage->id, 'title' => '公开单页', 'status' => 'published', 'deleted_at' => null]);
    }

    /**
     * @Name: test_website_role_tree_separates_content_operations_and_prevents_granting_unowned_permissions
     * @Description: 验证权限树逐项授权以及受委托角色管理员无法授予自己没有的权限。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 15:06:54
     * @UpdateTime: 2026-11-12 15:06:54
     * @Return void 验证操作级授权和越权保护
     * @Throws AssertionFailedError 权限边界或表单树结构异常
     */
    public function test_website_role_tree_separates_content_operations_and_prevents_granting_unowned_permissions(): void
    {
        [$owner, $site] = $this->createOwnerSite('permission_tree_owner', 'permission-tree.example.test');
        $this->signIn($site, $owner);
        $baseUrl = 'http://'.$site->domain.'/admin';
        $this->get($baseUrl.'/team/roles')->assertOk()
            ->assertSee('data-wa-permission-tree', false)
            ->assertSee('value=\'articles.ai\'', false)
            ->assertSee('value=\'categories.delete\'', false);

        $role = WebsiteRole::query()->create([
            'site_id' => (int) $site->id,
            'name' => '内容录入',
            'permissions' => ['dashboard.read', 'categories.read', 'categories.create', 'articles.read', 'articles.create', 'roles.read', 'roles.create'],
        ]);
        $member = WebsiteUser::query()->create([
            'site_id' => (int) $site->id,
            'website_role_id' => (int) $role->id,
            'username' => 'permission_writer',
            'password' => 'member-password-123',
            'status' => 'active',
            'created_by_admin_id' => (int) $owner->id,
        ]);
        $this->withSession([
            'website_admin_site_id' => (int) $site->id,
            'website_admin_type' => 'user',
            'website_admin_id' => (int) $member->id,
        ]);

        $this->get($baseUrl.'/categories')->assertOk()->assertSee('新建分类');
        $this->post($baseUrl.'/categories', ['name' => '授权分类'])->assertRedirect();
        $category = Category::query()->where('site_id', (int) $site->id)->where('name', '授权分类')->firstOrFail();
        $this->get($baseUrl.'/categories/'.$category->id.'/edit')->assertForbidden();
        $this->delete($baseUrl.'/categories/'.$category->id)->assertForbidden();
        $this->post($baseUrl.'/articles', ['title' => '授权文章', 'content' => '文章正文', 'publish' => '0'])->assertRedirect();
        $article = Article::query()->where('site_id', (int) $site->id)->where('title', '授权文章')->firstOrFail();
        $this->put($baseUrl.'/articles/'.$article->id, ['title' => '修改文章', 'content' => '正文'])->assertForbidden();
        $this->delete($baseUrl.'/articles/'.$article->id)->assertForbidden();
        $this->postJson($baseUrl.'/articles/generate', ['topic' => '品牌介绍', 'model_id' => 1])->assertForbidden();
        $this->post($baseUrl.'/team/roles', ['name' => '越权角色', 'permissions' => ['products.delete']])->assertForbidden();
        $this->assertDatabaseMissing('website_roles', ['site_id' => (int) $site->id, 'name' => '越权角色']);
    }

    /**
     * @Name: test_website_settings_permission_saves_only_the_authorized_section
     * @Description: 验证仅有 SEO 设置权限的管理员不能通过附加字段覆盖基础信息。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 15:06:54
     * @UpdateTime: 2026-11-12 15:06:54
     * @Return void 验证网站设置分组的服务端授权
     * @Throws AssertionFailedError 未授权字段被修改或请求未被拒绝
     */
    public function test_website_settings_permission_saves_only_the_authorized_section(): void
    {
        [$owner, $site] = $this->createOwnerSite('permission_settings_owner', 'permission-settings.example.test');
        $role = WebsiteRole::query()->create([
            'site_id' => (int) $site->id,
            'name' => '搜索配置',
            'permissions' => ['dashboard.read', 'site.settings.read', 'site.settings.seo'],
        ]);
        $member = WebsiteUser::query()->create([
            'site_id' => (int) $site->id,
            'website_role_id' => (int) $role->id,
            'username' => 'seo_manager',
            'password' => 'member-password-123',
            'status' => 'active',
            'created_by_admin_id' => (int) $owner->id,
        ]);
        $this->withSession([
            'website_admin_site_id' => (int) $site->id,
            'website_admin_type' => 'user',
            'website_admin_id' => (int) $member->id,
        ]);
        $baseUrl = 'http://'.$site->domain.'/admin';

        $this->get($baseUrl.'/settings')->assertOk()->assertSee("data-wa-editable='false'", false);
        $this->put($baseUrl.'/settings', [
            'settings_tab' => 'seo',
            'seo_title_template' => '搜索标题',
            'site_name' => '伪造站点名称',
            'site_description' => '伪造网站简介',
        ])->assertRedirect();
        $this->assertDatabaseHas('site_settings', ['site_id' => (int) $site->id, 'setting_key' => 'seo_title_template', 'setting_value' => '搜索标题']);
        $this->assertDatabaseMissing('site_settings', ['site_id' => (int) $site->id, 'setting_key' => 'site_name', 'setting_value' => '伪造站点名称']);
        $this->put($baseUrl.'/settings', ['settings_tab' => 'basic', 'site_name' => '越权修改'])->assertForbidden();
    }

    /**
     * @Name: test_new_role_can_hide_dashboard_without_changing_legacy_role_access
     * @Description: 新角色可单独关闭概览，旧版只授权文章查看的角色仍可进入原有首页。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 15:23:35
     * @UpdateTime: 2026-11-12 15:23:35
     * @Return void 验证概览权限和旧角色兼容
     * @Throws AssertionFailedError 新角色或旧角色的入口行为异常
     */
    public function test_new_role_can_hide_dashboard_without_changing_legacy_role_access(): void
    {
        [$owner, $site] = $this->createOwnerSite('dashboard_permission_owner', 'dashboard-permission.example.test');
        $this->signIn($site, $owner);
        $baseUrl = 'http://'.$site->domain.'/admin';
        $this->post($baseUrl.'/team/roles', ['name' => '文章只读', 'permissions' => ['articles.read']])->assertRedirect();
        $role = WebsiteRole::query()->where('site_id', (int) $site->id)->where('name', '文章只读')->firstOrFail();
        $this->assertNotContains('dashboard.read', $role->permissions);

        $member = WebsiteUser::query()->create([
            'site_id' => (int) $site->id,
            'website_role_id' => (int) $role->id,
            'username' => 'articles_reader',
            'password' => 'member-password-123',
            'status' => 'active',
            'created_by_admin_id' => (int) $owner->id,
        ]);
        $this->withSession([
            'website_admin_site_id' => (int) $site->id,
            'website_admin_type' => 'user',
            'website_admin_id' => (int) $member->id,
        ]);
        $this->get($baseUrl.'/')->assertRedirect(route('website-admin.articles', absolute: false));
        $this->get($baseUrl.'/articles')->assertOk()->assertDontSee('>概览</a>', false);

        $role->update(['permissions' => ['articles.read']]);
        $this->get($baseUrl.'/')->assertOk();
    }

    /**
     * @Name: createOwnerSite
     * @Description: 建立具有活动状态和默认域名的测试站点及其不可变 GEO 所有者账号。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 17:26:06
     * @UpdateTime: 2026-11-11 17:26:06
     * @Param string $username 唯一 GEO 登录账号
     * @Param string $domain 测试站点默认域名
     * @Return array{0:Admin,1:Site} 测试账号与站点记录
     * @Throws QueryException 测试记录违反数据库约束时抛出
     */
    private function createOwnerSite(string $username, string $domain): array
    {
        $owner = Admin::query()->create([
            'username' => $username,
            'password' => 'website-secret-123',
            'email' => $username.'@example.test',
            'display_name' => $username,
            'role' => 'direct_admin',
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => (int) $owner->id,
            'name' => $username.' 官网',
            'domain' => $domain,
            'status' => 'active',
        ]);
        $site->members()->attach((int) $owner->id, ['role' => 'owner']);

        return [$owner, $site];
    }

    /**
     * @Name: signIn
     * @Description: 使用官网所有者账号建立指定站点的独立后台会话。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 17:26:06
     * @UpdateTime: 2026-11-11 17:26:06
     * @Param Site $site 当前测试站点
     * @Param Admin $owner 当前站点 GEO 所有者
     * @Return void 断言登录成功并保存网站后台会话
     * @Throws AssertionFailedError 登录未成功时抛出
     */
    private function signIn(Site $site, Admin $owner): void
    {
        $this->post('http://'.$site->domain.'/admin/login', [
            'username' => (string) $owner->username,
            'password' => 'website-secret-123',
        ])->assertRedirect(route('website-admin.dashboard', absolute: false));
    }
}
