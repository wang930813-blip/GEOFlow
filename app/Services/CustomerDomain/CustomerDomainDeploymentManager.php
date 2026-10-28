<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-10-28
 *
 * @Time: 16:08:47
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File：CustomerDomainDeploymentManager.php
 *
 * @Description: 通过 Unix Socket 请求宿主机安全部署客户域名 Nginx 配置。
 */

namespace App\Services\CustomerDomain;

use App\Models\Site;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

class CustomerDomainDeploymentManager
{
    /**
     * 判断宿主机证书部署服务是否已启用。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-28 16:08:47
     *
     * @UpdateTime: 2026-10-28 16:20:05
     *
     * @Return bool 部署服务是否启用
     *
     * @Throws 无
     */
    public function enabled(): bool
    {
        return (bool) config('geoflow.customer_domain_deployer.enabled', false);
    }

    /**
     * 同步站点自定义域名及证书到宿主机反向代理。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-28 16:08:47
     *
     * @UpdateTime: 2026-10-28 16:20:05
     *
     * @Param Site $site 已保存客户域名和证书的站点
     *
     * @Return void 部署成功时更新站点部署状态
     *
     * @Throws CustomerDomainDeploymentException Unix Socket 不可用或宿主机配置校验失败时抛出
     */
    public function synchronize(Site $site): void
    {
        if (! $this->enabled()) {
            $site->forceFill([
                'custom_domain_deployment_status' => 'manual',
                'custom_domain_deployment_error' => null,
                'custom_domain_deployed_at' => null,
            ])->save();

            return;
        }

        if (trim((string) $site->custom_domain) === '') {
            $this->remove($site);

            return;
        }

        $this->deploy('sync', $site);
    }

    /**
     * 移除指定站点在宿主机上的域名代理和证书。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-28 16:20:05
     *
     * @UpdateTime: 2026-10-28 16:20:05
     *
     * @Param Site $site 需要清理域名配置的站点
     *
     * @Return void 宿主机确认移除后返回
     *
     * @Throws CustomerDomainDeploymentException 部署服务未能安全移除配置时抛出
     */
    public function remove(Site $site): void
    {
        if (! $this->enabled()) {
            $site->forceFill([
                'custom_domain_deployment_status' => 'manual',
                'custom_domain_deployment_error' => null,
                'custom_domain_deployed_at' => null,
            ])->save();

            return;
        }

        $this->deploy('remove', $site);
    }

    /**
     * 调用宿主机执行指定站点的部署操作并更新状态。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-28 16:20:05
     *
     * @UpdateTime: 2026-10-28 16:20:05
     *
     * @Param string $action sync 或 remove 操作
     * @Param Site $site 当前站点记录
     *
     * @Return void 部署服务确认后返回
     *
     * @Throws CustomerDomainDeploymentException Socket 请求失败或宿主机拒绝配置时抛出
     */
    private function deploy(string $action, Site $site): void
    {
        $site->forceFill([
            'custom_domain_deployment_status' => 'pending',
            'custom_domain_deployment_error' => null,
            'custom_domain_deployed_at' => null,
        ])->save();

        try {
            $this->sendRequest($action, $site);
        } catch (Throwable $exception) {
            Log::error('Customer domain proxy deployment failed.', [
                'site_id' => (int) $site->id,
                'domain' => (string) $site->custom_domain,
                'exception' => $exception,
            ]);

            $site->forceFill([
                'custom_domain_deployment_status' => 'failed',
                'custom_domain_deployment_error' => '宿主机未能应用域名配置，请联系平台管理员检查部署服务日志。',
                'custom_domain_deployed_at' => null,
            ])->save();

            throw new CustomerDomainDeploymentException('客户域名代理部署失败，HTTPS 跳转已暂缓；请联系平台管理员检查宿主机部署服务。', 0, $exception);
        }

        $site->forceFill([
            'custom_domain_deployment_status' => $action === 'remove' ? 'removed' : 'active',
            'custom_domain_deployment_error' => null,
            'custom_domain_deployed_at' => now(),
        ])->save();
    }

    /**
     * 向宿主机部署服务发送受限的站点配置请求。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-28 16:08:47
     *
     * @UpdateTime: 2026-10-28 16:08:47
     *
     * @Param string $action sync 或 remove 操作
     * @Param Site $site 当前站点记录
     *
     * @Return void 宿主机确认应用配置后返回
     *
     * @Throws JsonException 请求或响应不是有效 JSON 时抛出
     * @Throws Throwable Socket 连接、写入、响应读取或部署服务返回失败时抛出
     */
    private function sendRequest(string $action, Site $site): void
    {
        $socketPath = trim((string) config('geoflow.customer_domain_deployer.socket', ''));
        if ($socketPath === '') {
            throw new CustomerDomainDeploymentException('未配置宿主机域名部署 Socket');
        }

        $timeout = (int) config('geoflow.customer_domain_deployer.timeout_seconds', 60);
        $socket = @stream_socket_client('unix://'.$socketPath, $errorCode, $errorMessage, $timeout);
        if (! is_resource($socket)) {
            throw new CustomerDomainDeploymentException('无法连接宿主机域名部署服务：'.$errorMessage, $errorCode);
        }

        stream_set_timeout($socket, $timeout);

        try {
            $request = [
                'action' => $action,
                'site_id' => (int) $site->id,
            ];

            if ($action === 'sync') {
                $request += [
                    'domain' => (string) $site->custom_domain,
                    'https_enabled' => (bool) $site->custom_https_enabled,
                    'certificate' => (bool) $site->custom_https_enabled ? (string) $site->custom_https_certificate : '',
                    'private_key' => (bool) $site->custom_https_enabled ? (string) $site->custom_https_private_key : '',
                ];
            }

            $payload = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
            $written = 0;
            $payloadLength = strlen($payload);
            while ($written < $payloadLength) {
                $count = fwrite($socket, substr($payload, $written));
                if (! is_int($count) || $count < 1) {
                    throw new CustomerDomainDeploymentException('向宿主机域名部署服务发送请求失败');
                }
                $written += $count;
            }

            $responseLine = fgets($socket, 1048577);
            if (! is_string($responseLine) || ! str_ends_with($responseLine, "\n")) {
                throw new CustomerDomainDeploymentException('宿主机域名部署服务未返回完整响应');
            }

            $response = json_decode($responseLine, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($response) || ($response['success'] ?? false) !== true) {
                throw new CustomerDomainDeploymentException('宿主机域名部署服务拒绝了配置请求');
            }
        } finally {
            fclose($socket);
        }
    }
}
