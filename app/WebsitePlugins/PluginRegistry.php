<?php

/**
 * Created by Codex.
 * @Date: 2026-11-12
 * @Time: 17:03:13
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： PluginRegistry.php
 * @Description: 发现系统插件及其内部云存储驱动。
 */

namespace App\WebsitePlugins;

use App\WebsitePlugins\Contracts\CloudStoragePlugin;
use InvalidArgumentException;

final class PluginRegistry
{
    /**
     * @Name: all
     * @Description: 读取系统插件清单，并将云存储驱动作为单个插件的可选平台。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 16:13:54
     * @UpdateTime: 2026-11-12 17:16:45
     * @Return array<string,array<string,mixed>> 按插件标识索引的已安装插件清单
     * @Throws 无
     */
    public function all(): array
    {
        $plugins = [];
        foreach (glob(app_path('WebsitePlugins/*/manifest.json')) ?: [] as $path) {
            $directory = basename(dirname($path));
            $manifest = json_decode((string) file_get_contents($path), true);
            if (! is_array($manifest)
                || ! preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $directory)
                || ! preg_match('/^[a-z][a-z0-9-]*$/', (string) ($manifest['id'] ?? ''))
                || ! is_string($manifest['name'] ?? null)
                || ! is_string($manifest['description'] ?? null)
                || ($manifest['type'] ?? null) !== 'plugin'
                || ($manifest['api_version'] ?? null) !== 1
                || ! is_string($manifest['version'] ?? null)
                || ! preg_match('/^\d+\.\d+\.\d+$/', $manifest['version'])
                || ! is_string($manifest['author'] ?? null)
                || trim($manifest['author']) === '') {
                continue;
            }

            if ($manifest['id'] === 'cloud-storage') {
                $providers = $this->providers();
                if ($providers === []) {
                    continue;
                }
                $manifest['providers'] = $providers;
            }
            $plugins[$manifest['id']] = $manifest;
        }

        ksort($plugins);

        return $plugins;
    }

    /**
     * @Name: providers
     * @Description: 发现并校验云存储插件内部可切换的平台驱动及其表单字段。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 16:13:54
     * @UpdateTime: 2026-11-12 17:16:45
     * @Return array<string,array<string,mixed>> 按平台标识索引的云存储驱动
     * @Throws 无
     */
    public function providers(): array
    {
        $providers = [];
        foreach (glob(app_path('WebsitePlugins/*/manifest.json')) ?: [] as $path) {
            $directory = basename(dirname($path));
            if ($directory === 'CloudStorage') {
                continue;
            }

            $manifest = json_decode((string) file_get_contents($path), true);
            if (! is_array($manifest)
                || ! preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $directory)
                || ! preg_match('/^[a-z][a-z0-9-]*$/', (string) ($manifest['id'] ?? ''))
                || ! is_string($manifest['name'] ?? null)
                || ! is_string($manifest['description'] ?? null)
                || ($manifest['type'] ?? null) !== 'cloud-storage'
                || ($manifest['api_version'] ?? null) !== 1
                || ! is_string($manifest['version'] ?? null)
                || ! preg_match('/^\d+\.\d+\.\d+$/', $manifest['version'])
                || ! is_array($manifest['fields'] ?? null)
                || ! $this->validFields($manifest['fields'])) {
                continue;
            }

            $class = 'App\\WebsitePlugins\\'.$directory.'\\Plugin';
            if (! is_subclass_of($class, CloudStoragePlugin::class)) {
                continue;
            }

            $providers[$manifest['id']] = [...$manifest, 'class' => $class];
        }

        ksort($providers);

        return $providers;
    }

    /**
     * @Name: get
     * @Description: 按唯一插件标识读取已安装的系统插件及其可用平台。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 16:13:54
     * @UpdateTime: 2026-11-12 17:16:45
     * @Param string $id 插件唯一标识
     * @Return array<string,mixed> 插件清单及平台定义
     * @Throws InvalidArgumentException 插件未安装或清单无效
     */
    public function get(string $id): array
    {
        return $this->all()[$id] ?? throw new InvalidArgumentException('插件未安装或不可用');
    }

    /**
     * @Name: getProvider
     * @Description: 按平台标识读取云存储插件内部驱动，供配置和兼容逻辑使用。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 17:03:13
     * @UpdateTime: 2026-11-12 17:16:45
     * @Param string $id 云存储平台标识
     * @Return array<string,mixed> 平台字段清单与驱动入口
     * @Throws InvalidArgumentException 平台未安装或清单无效
     */
    public function getProvider(string $id): array
    {
        return $this->providers()[$id] ?? throw new InvalidArgumentException('云存储平台未安装或不可用');
    }

    /**
     * @Name: make
     * @Description: 通过容器实例化已登记的云存储平台驱动。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 16:13:54
     * @UpdateTime: 2026-11-12 17:16:45
     * @Param string $id 云存储平台标识
     * @Return CloudStoragePlugin 已安装平台驱动实例
     * @Throws InvalidArgumentException 平台未安装或清单无效
     */
    public function make(string $id): CloudStoragePlugin
    {
        return app($this->getProvider($id)['class']);
    }

    /**
     * @Name: validFields
     * @Description: 限制平台清单的配置字段类型，拒绝不完整或不安全的输入结构。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 16:13:54
     * @UpdateTime: 2026-11-12 17:16:45
     * @Param array $fields 平台配置字段清单
     * @Return bool 字段清单是否符合插件接口规范
     * @Throws 无
     */
    private function validFields(array $fields): bool
    {
        if ($fields === []) {
            return false;
        }

        foreach ($fields as $key => $field) {
            if (! is_string($key)
                || ! preg_match('/^[a-z][a-z0-9_]*$/', $key)
                || ! is_array($field)
                || ! is_string($field['label'] ?? null)
                || ! in_array($field['type'] ?? null, ['text', 'password', 'url', 'select'], true)
                || ! is_bool($field['required'] ?? false)
                || ! is_bool($field['secret'] ?? false)
                || (($field['type'] ?? null) === 'password' && ! ($field['secret'] ?? false))) {
                return false;
            }
            if (($field['type'] ?? null) === 'select'
                && (! is_array($field['options'] ?? null) || $field['options'] === [])) {
                return false;
            }
        }

        return true;
    }
}
