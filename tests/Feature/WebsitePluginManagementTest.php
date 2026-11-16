<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-11-12
 *
 * @Time: 16:13:54
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： WebsitePluginManagementTest.php
 *
 * @Description: 验证官网云存储插件配置、租户隔离和文章封面上传接入。
 */

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Site;
use App\Models\WebsitePlugin;
use App\Support\WebsitePermissionCatalog;
use App\WebsitePlugins\PluginRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WebsitePluginManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @Name: test_cloud_storage_configuration_is_site_scoped_encrypted_and_single_active
     *
     * @Description: 验证云存储作为单个站点插件展示、加密保存且只能选择一个活动平台。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-12 16:13:54
     *
     * @UpdateTime: 2026-11-12 17:14:04
     *
     * @Return void 插件显示、配置加密和启用互斥均符合预期
     *
     * @Throws AssertionFailedError 页面、租户数据或密钥存储不符合预期
     */
    public function test_cloud_storage_configuration_is_site_scoped_encrypted_and_single_active(): void
    {
        $this->assertContains('plugins.read', WebsitePermissionCatalog::keys());
        $this->assertContains('plugins.configure', WebsitePermissionCatalog::keys());
        [$owner, $site] = $this->createOwnerSite('plugin_owner_alpha', 'plugin-alpha.example.test');
        [, $otherSite] = $this->createOwnerSite('plugin_owner_beta', 'plugin-beta.example.test');
        $this->signIn($site, $owner);

        $this->get('http://'.$site->domain.'/admin/plugins')
            ->assertOk()
            ->assertSee('插件列表')
            ->assertSee('云存储')
            ->assertSee('cloud-storage')
            ->assertSee('已安装')
            ->assertSee('版本号')
            ->assertSee('作者')
            ->assertDontSee('腾讯云 COS');

        $this->get('http://'.$site->domain.'/admin/plugins/cloud-storage')
            ->assertOk()
            ->assertSee('阿里云 OSS')
            ->assertSee("name='settings[access_key_id]'", false)
            ->assertDontSee("name='settings[secret_id]'", false);

        $this->put('http://'.$site->domain.'/admin/plugins/cloud-storage', [
            'provider' => 'aliyun-oss',
            'enabled' => '1',
            'settings' => [
                'region' => 'cn-hangzhou',
                'bucket' => 'alpha-assets',
                'access_key_id' => 'alpha-access-id',
                'access_key_secret' => 'alpha-private-secret',
                'public_url' => 'https://cdn-alpha.example.test',
            ],
        ])->assertRedirect(route('website-admin.plugins', absolute: false));

        $this->assertDatabaseHas('website_plugins', [
            'site_id' => (int) $site->id,
            'plugin_id' => 'cloud-storage',
            'enabled' => true,
        ]);
        $plugin = WebsitePlugin::query()->where('site_id', (int) $site->id)->where('plugin_id', 'cloud-storage')->firstOrFail();
        $this->assertSame('aliyun-oss', $plugin->settings['provider']);
        $this->assertSame('alpha-private-secret', $plugin->settings['providers']['aliyun-oss']['access_key_secret']);
        $encryptedSettings = (string) DB::table('website_plugins')->where('id', (int) $plugin->id)->value('settings');
        $this->assertStringNotContainsString('alpha-private-secret', $encryptedSettings);
        $this->get('http://'.$site->domain.'/admin/plugins')->assertDontSee('alpha-private-secret');

        $this->put('http://'.$site->domain.'/admin/plugins/cloud-storage', [
            'provider' => 'tencent-cos',
            'enabled' => '1',
            'settings' => [
                'region' => 'ap-guangzhou',
                'bucket' => 'alpha-assets-1250000000',
                'secret_id' => 'alpha-secret-id',
                'secret_key' => 'alpha-cos-private-secret',
                'public_url' => 'https://cos-alpha.example.test',
            ],
        ])->assertRedirect(route('website-admin.plugins', absolute: false));

        $this->assertDatabaseHas('website_plugins', [
            'site_id' => (int) $site->id,
            'plugin_id' => 'cloud-storage',
            'enabled' => true,
        ]);
        $plugin->refresh();
        $this->assertSame('tencent-cos', $plugin->settings['provider']);
        $this->assertSame(1, WebsitePlugin::query()->where('site_id', (int) $site->id)->where('enabled', true)->count());
        $this->assertDatabaseMissing('website_plugins', ['site_id' => (int) $site->id, 'plugin_id' => 'aliyun-oss']);
        $this->assertDatabaseMissing('website_plugins', ['site_id' => (int) $site->id, 'plugin_id' => 'tencent-cos']);
        $this->assertDatabaseMissing('website_plugins', ['site_id' => (int) $otherSite->id]);
    }

    /**
     * @Name: test_article_cover_upload_uses_the_enabled_site_storage_plugin
     *
     * @Description: 验证启用的阿里云 OSS 接收当前站点文章封面并返回其公开访问地址。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-12 16:13:54
     *
     * @UpdateTime: 2026-11-12 17:10:59
     *
     * @Return void 云上传请求使用站点隔离对象键且文章保存该云地址
     *
     * @Throws AssertionFailedError 请求签名、对象路径或文章封面地址异常
     */
    public function test_article_cover_upload_uses_the_enabled_site_storage_plugin(): void
    {
        Http::fake(['https://alpha-assets.oss-cn-hangzhou.aliyuncs.com/*' => Http::response('', 200)]);
        [$owner, $site] = $this->createOwnerSite('plugin_cover_owner', 'plugin-cover.example.test');
        $this->signIn($site, $owner);
        WebsitePlugin::query()->create([
            'site_id' => (int) $site->id,
            'plugin_id' => 'cloud-storage',
            'enabled' => true,
            'settings' => [
                'provider' => 'aliyun-oss',
                'providers' => [
                    'aliyun-oss' => [
                        'region' => 'cn-hangzhou',
                        'bucket' => 'alpha-assets',
                        'access_key_id' => 'alpha-access-id',
                        'access_key_secret' => 'alpha-private-secret',
                        'public_url' => 'https://cdn-alpha.example.test',
                    ],
                ],
            ],
        ]);

        $this->post('http://'.$site->domain.'/admin/articles', [
            'title' => '云存储封面文章',
            'content' => '正文内容',
            'publish' => '0',
            'cover_file' => UploadedFile::fake()->image('cover.png', 1200, 675),
        ])->assertRedirect(route('website-admin.articles', absolute: false));

        $article = Article::query()->where('site_id', (int) $site->id)->where('title', '云存储封面文章')->firstOrFail();
        $this->assertStringStartsWith('https://cdn-alpha.example.test/website-media/'.$site->id.'/articles/', (string) $article->cover_image);
        Http::assertSent(function (HttpRequest $request) use ($site): bool {
            $authorization = $request->header('Authorization');

            return $request->method() === 'PUT'
                && str_contains($request->url(), 'website-media/'.$site->id.'/articles/')
                && is_array($authorization)
                && str_starts_with((string) ($authorization[0] ?? ''), 'OSS alpha-access-id:');
        });
    }

    /**
     * @Name: test_registered_cloud_storage_plugins_use_their_provider_endpoints
     *
     * @Description: 验证阿里云、腾讯云和七牛云插件按各自接口地址发送文件上传请求。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-12 16:13:54
     *
     * @UpdateTime: 2026-11-12 16:29:58
     *
     * @Return void 三个插件均返回配置的 HTTPS 对象访问地址
     *
     * @Throws AssertionFailedError 提供商请求地址、方法或公开地址不符合约定
     */
    public function test_registered_cloud_storage_plugins_use_their_provider_endpoints(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $registry = app(PluginRegistry::class);
        $key = 'website-media/17/articles/plugin-check.png';
        $plugins = [
            [
                'id' => 'aliyun-oss',
                'settings' => [
                    'region' => 'cn-hangzhou',
                    'bucket' => 'alpha-assets',
                    'access_key_id' => 'alpha-access-id',
                    'access_key_secret' => 'alpha-secret',
                    'public_url' => 'https://cdn-alpha.example.test',
                ],
                'endpoint' => 'https://alpha-assets.oss-cn-hangzhou.aliyuncs.com/'.$key,
            ],
            [
                'id' => 'tencent-cos',
                'settings' => [
                    'region' => 'ap-guangzhou',
                    'bucket' => 'alpha-assets-1250000000',
                    'secret_id' => 'alpha-secret-id',
                    'secret_key' => 'alpha-secret-key',
                    'public_url' => 'https://cos-alpha.example.test',
                ],
                'endpoint' => 'https://alpha-assets-1250000000.cos.ap-guangzhou.myqcloud.com/'.$key,
            ],
            [
                'id' => 'qiniu-kodo',
                'settings' => [
                    'region' => 'z0',
                    'bucket' => 'alpha-assets',
                    'access_key' => 'alpha-access-key',
                    'secret_key' => 'alpha-secret-key',
                    'public_url' => 'https://kodo-alpha.example.test',
                ],
                'endpoint' => 'https://up.qiniup.com',
            ],
        ];

        foreach ($plugins as $plugin) {
            $url = $registry->make($plugin['id'])->upload(
                UploadedFile::fake()->image('plugin-check.png', 120, 80),
                $key,
                $plugin['settings']
            );
            $this->assertSame($plugin['settings']['public_url'].'/'.$key, $url);
        }

        Http::assertSentCount(3);
        foreach ($plugins as $plugin) {
            Http::assertSent(function (HttpRequest $request) use ($plugin): bool {
                return $request->url() === $plugin['endpoint'];
            });
        }
    }

    /**
     * @Name: createOwnerSite
     *
     * @Description: 建立官网插件测试使用的 GEO 所有者和隔离站点。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-12 16:13:54
     *
     * @UpdateTime: 2026-11-12 16:29:58
     *
     * @Param string $username 唯一测试账号名
     * @Param string $domain 测试站点域名
     *
     * @Return array{0:Admin,1:Site} 创建的站点所有者和站点
     *
     * @Throws QueryException 测试账号或站点无法写入数据库
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
     *
     * @Description: 使用测试站点所有者登录独立官网后台。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-12 16:13:54
     *
     * @UpdateTime: 2026-11-12 16:29:58
     *
     * @Param Site $site 当前测试站点
     * @Param Admin $owner 当前站点所有者
     *
     * @Return void 登录成功后建立网站后台会话
     *
     * @Throws AssertionFailedError 网站后台登录未成功
     */
    private function signIn(Site $site, Admin $owner): void
    {
        $this->post('http://'.$site->domain.'/admin/login', [
            'username' => (string) $owner->username,
            'password' => 'website-secret-123',
        ])->assertRedirect(route('website-admin.dashboard', absolute: false));
    }
}
