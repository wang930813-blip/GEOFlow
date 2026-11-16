<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-11-12
 *
 * @Time: 16:04:59
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： WebsiteMediaStorage.php
 *
 * @Description: 为官网上传入口选择当前站点启用的存储插件。
 */

namespace App\WebsitePlugins;

use App\Models\WebsitePlugin;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class WebsiteMediaStorage
{
    public function __construct(private readonly PluginRegistry $registry) {}

    /**
     * @Name: isCloudStorageReady
     * @Description: 检查当前站点的云存储插件是否启用且所选平台必填配置完整。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 17:48:25
     * @UpdateTime: 2026-11-12 17:48:25
     * @Param int $siteId 当前站点 ID
     * @Return bool 当前站点是否可以上传图片到云存储
     * @Throws 无 配置解密或平台标识无效时按未就绪处理
     */
    public function isCloudStorageReady(int $siteId): bool
    {
        try {
            return $this->enabledCloudStorageConfiguration($siteId) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @Name: upload
     *
     * @Description: 使用当前站点启用的云存储插件保存文件，无启用插件时沿用原有本地存储位置。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-12 16:04:59
     *
     * @UpdateTime: 2026-11-12 17:48:25
     *
     * @Param UploadedFile $file 已通过业务校验的上传文件
     * @Param int $siteId 当前租户站点 ID
     * @Param string $folder 站点对象键下的业务目录
     *
     * @Return string 本地或云存储公开访问地址
     *
     * @Throws RuntimeException 插件上传失败或本地文件无法保存
     */
    public function upload(UploadedFile $file, int $siteId, string $folder): string
    {
        if (! preg_match('/^[a-z][a-z0-9-]*$/', $folder)) {
            throw new RuntimeException('上传目录参数无效');
        }

        $configuration = $this->enabledCloudStorageConfiguration($siteId);
        if ($configuration !== null) {
            $extension = strtolower((string) $file->extension());
            $key = 'website-media/'.$siteId.'/'.$folder.'/'.Str::uuid().'.'.$extension;

            return $this->registry->make($configuration['provider'])
                ->upload($file, $key, $configuration['settings']);
        }

        $path = $file->storePublicly('website-articles/'.$siteId, 'public');
        if (! is_string($path) || $path === '') {
            throw new RuntimeException('图片保存失败');
        }

        return '/storage/'.$path;
    }

    /**
     * @Name: uploadToCloud
     * @Description: 仅使用当前站点已启用且配置完整的云存储插件上传文件，不允许回退到本地磁盘。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 18:17:00
     * @UpdateTime: 2026-11-12 18:29:59
     * @Param UploadedFile $file 已通过业务校验的图片或图标文件
     * @Param int $siteId 当前租户站点 ID
     * @Param string $folder 站点对象键下的业务目录
     * @Return string 云存储公开访问地址
     * @Throws RuntimeException 云存储未就绪或插件上传失败
     */
    public function uploadToCloud(UploadedFile $file, int $siteId, string $folder): string
    {
        if (! preg_match('/^[a-z][a-z0-9-]*$/', $folder)) {
            throw new RuntimeException('上传目录参数无效');
        }

        $configuration = $this->enabledCloudStorageConfiguration($siteId);
        if ($configuration === null) {
            throw new RuntimeException('当前站点云存储插件未启用或配置不完整');
        }

        $extension = $folder === 'site-favicon'
            ? 'ico'
            : strtolower((string) $file->extension());
        $key = 'website-media/'.$siteId.'/'.$folder.'/'.Str::uuid().'.'.$extension;

        return $this->registry->make($configuration['provider'])
            ->upload($file, $key, $configuration['settings']);
    }

    /**
     * @Name: enabledCloudStorageConfiguration
     * @Description: 读取统一插件记录或旧供应商记录，并确认启用平台的必填字段均已保存。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 17:48:25
     * @UpdateTime: 2026-11-12 17:48:25
     * @Param int $siteId 当前站点 ID
     * @Return array{provider:string,settings:array<string,mixed>}|null 已启用且完整的平台配置
     * @Throws 无 配置无效、缺失或未启用时返回 null
     */
    private function enabledCloudStorageConfiguration(int $siteId): ?array
    {
        $plugin = WebsitePlugin::query()
            ->where('site_id', $siteId)
            ->where('plugin_id', 'cloud-storage')
            ->first();
        if ($plugin instanceof WebsitePlugin) {
            if (! $plugin->enabled) {
                return null;
            }

            $settings = is_array($plugin->settings) ? $plugin->settings : [];
            $providerId = $settings['provider'] ?? null;
            $providerSettings = is_array($settings['providers'] ?? null) ? $settings['providers'] : [];
            $credentials = is_string($providerId) ? ($providerSettings[$providerId] ?? null) : null;
            if (! is_string($providerId) || ! is_array($credentials)) {
                return null;
            }

            try {
                $provider = $this->registry->getProvider($providerId);
            } catch (\InvalidArgumentException) {
                return null;
            }

            return $this->hasRequiredSettings($provider, $credentials)
                ? ['provider' => $providerId, 'settings' => $credentials]
                : null;
        }

        $activeLegacyProviders = WebsitePlugin::query()
            ->where('site_id', $siteId)
            ->whereIn('plugin_id', array_keys($this->registry->providers()))
            ->where('enabled', true)
            ->orderBy('id')
            ->get();
        foreach ($activeLegacyProviders as $legacyPlugin) {
            try {
                $provider = $this->registry->getProvider($legacyPlugin->plugin_id);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $credentials = is_array($legacyPlugin->settings) ? $legacyPlugin->settings : [];
            if ($this->hasRequiredSettings($provider, $credentials)) {
                return ['provider' => $legacyPlugin->plugin_id, 'settings' => $credentials];
            }
        }

        return null;
    }

    /**
     * @Name: hasRequiredSettings
     * @Description: 根据平台清单检查启用云存储所需的非空配置项。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 17:48:25
     * @UpdateTime: 2026-11-12 17:48:25
     * @Param array $provider 云存储平台清单
     * @Param array $settings 当前平台站点配置
     * @Return bool 所有必填项是否均为非空字符串
     * @Throws 无
     */
    private function hasRequiredSettings(array $provider, array $settings): bool
    {
        foreach ($provider['fields'] ?? [] as $fieldKey => $field) {
            if (($field['required'] ?? false)
                && (! is_string($settings[$fieldKey] ?? null) || trim($settings[$fieldKey]) === '')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @Name: deleteLocalIfUnused
     *
     * @Description: 仅删除当前站点命名空间下的本地媒体文件，不触及外部地址。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-12 16:04:59
     *
     * @UpdateTime: 2026-11-12 16:29:58
     *
     * @Param string $url 待清理媒体地址
     * @Param int $siteId 当前租户站点 ID
     *
     * @Return void 完成本地文件清理
     *
     * @Throws 无
     */
    public function deleteLocalIfUnused(string $url, int $siteId): void
    {
        $prefixes = ['/storage/website-articles/'.$siteId.'/', '/storage/website-media/'.$siteId.'/'];
        foreach ($prefixes as $prefix) {
            if (str_starts_with($url, $prefix)) {
                Storage::disk('public')->delete(substr($url, strlen('/storage/')));

                return;
            }
        }
    }
}
