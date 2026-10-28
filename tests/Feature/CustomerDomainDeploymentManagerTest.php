<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-10-28
 *
 * @Time: 16:42:03
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File：CustomerDomainDeploymentManagerTest.php
 *
 * @Description: 验证客户域名宿主机部署服务关闭和连接失败时的状态管理。
 */

namespace Tests\Feature;

use App\Models\Site;
use App\Services\CustomerDomain\CustomerDomainDeploymentException;
use App\Services\CustomerDomain\CustomerDomainDeploymentManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerDomainDeploymentManagerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @Name: test_disabled_deployer_keeps_manual_mode
     *
     * @Description: 未启用宿主机部署服务时保留现有手动部署兼容状态。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-28 16:42:03
     *
     * @UpdateTime: 2026-10-28 16:42:03
     *
     * @Return void 站点部署状态为 manual
     *
     * @Throws AssertionFailedError 部署状态与兼容行为不一致时抛出
     */
    public function test_disabled_deployer_keeps_manual_mode(): void
    {
        config(['geoflow.customer_domain_deployer.enabled' => false]);
        $site = Site::query()->create([
            'name' => 'Manual domain site',
            'domain' => 'manual.geo.xinzhidi.cn',
            'custom_domain' => 'manual.example.test',
            'status' => 'active',
        ]);

        app(CustomerDomainDeploymentManager::class)->synchronize($site);

        $this->assertSame('manual', $site->fresh()->custom_domain_deployment_status);
        $this->assertNull($site->fresh()->custom_domain_deployed_at);
    }

    /**
     * @Name: test_unavailable_socket_marks_site_failed_and_throws_safe_error
     *
     * @Description: Socket 不可用时记录失败状态并向后台返回不包含证书私钥的通用错误。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-28 16:42:03
     *
     * @UpdateTime: 2026-10-28 16:42:03
     *
     * @Return void 站点状态失败且异常信息不包含凭据
     *
     * @Throws AssertionFailedError 失败状态或错误信息泄露敏感数据时抛出
     */
    public function test_unavailable_socket_marks_site_failed_and_throws_safe_error(): void
    {
        config([
            'geoflow.customer_domain_deployer.enabled' => true,
            'geoflow.customer_domain_deployer.socket' => storage_path('framework/testing/missing-customer-domain-deployer.sock'),
            'geoflow.customer_domain_deployer.timeout_seconds' => 1,
        ]);
        $site = Site::query()->create([
            'name' => 'Failed deployment site',
            'domain' => 'failed.geo.xinzhidi.cn',
            'custom_domain' => 'failed.example.test',
            'custom_https_enabled' => true,
            'custom_https_certificate' => 'certificate-secret-content',
            'custom_https_private_key' => 'private-key-secret-content',
            'status' => 'active',
        ]);

        try {
            app(CustomerDomainDeploymentManager::class)->synchronize($site);
            $this->fail('An unavailable deployment socket must be reported.');
        } catch (CustomerDomainDeploymentException $exception) {
            $this->assertStringNotContainsString('certificate-secret-content', $exception->getMessage());
            $this->assertStringNotContainsString('private-key-secret-content', $exception->getMessage());
        }

        $savedSite = $site->fresh();
        $this->assertSame('failed', $savedSite->custom_domain_deployment_status);
        $this->assertNotSame('', (string) $savedSite->custom_domain_deployment_error);
        $this->assertNull($savedSite->custom_domain_deployed_at);
    }
}
