<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-07-29
 *
 * @Time: 15:41:12
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： McpSkillPackageBuilder.php
 *
 * @Description: 读取受版本控制的 ceying-geo Skill 源文件并生成用户可安装的 ZIP 下载包。
 */

namespace App\Services\Mcp;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use ZipArchive;

class McpSkillPackageBuilder
{
    public const SKILL_NAME = 'ceying-geo-content-operations';

    public const SKILL_VERSION = '1.0.0';

    public const MCP_SERVER_VERSION = '1.4.0';

    /**
     * @Name: metadata
     *
     * @Description: 返回用户侧展示所需的 Skill 名称、版本、MCP 兼容版本及旧品牌触发别名。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-29 15:41:12
     *
     * @UpdateTime: 2026-07-29 15:41:12
     *
     * @Return: array{name:string,version:string,mcp_server_version:string,aliases:array<int,string>,filename:string} Skill 元数据
     */
    public function metadata(): array
    {
        return [
            'name' => self::SKILL_NAME,
            'version' => self::SKILL_VERSION,
            'mcp_server_version' => self::MCP_SERVER_VERSION,
            'aliases' => ['策影 GEO', 'GEO', 'geo', 'GEOFlow', 'geoflow'],
            'filename' => self::SKILL_NAME.'-'.self::SKILL_VERSION.'.zip',
        ];
    }

    /**
     * @Name: build
     *
     * @Description: 将 resources 中的 Skill 源文件按固定根目录封装为临时 ZIP，失败时清理不完整文件。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-29 15:41:12
     *
     * @UpdateTime: 2026-07-29 15:41:12
     *
     * @Return: array{path:string,filename:string} 临时文件路径和下载文件名
     *
     * @Throws RuntimeException Skill 源目录缺失、临时目录不可写或 ZIP 创建失败
     */
    public function build(): array
    {
        $sourceDirectory = realpath(resource_path('skills/'.self::SKILL_NAME));
        if ($sourceDirectory === false || ! is_dir($sourceDirectory)) {
            throw new RuntimeException('ceying-geo Skill 源目录不存在');
        }

        $temporaryDirectory = storage_path('app/tmp/mcp-skills');
        if (! is_dir($temporaryDirectory)
            && ! mkdir($temporaryDirectory, 0755, true)
            && ! is_dir($temporaryDirectory)) {
            throw new RuntimeException('ceying-geo Skill 临时目录创建失败');
        }

        $metadata = $this->metadata();
        $path = $temporaryDirectory.'/'.uniqid('skill-', true).'-'.$metadata['filename'];
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ceying-geo Skill ZIP 创建失败');
        }

        $archiveOpen = true;

        try {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($sourceDirectory, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            /** @var SplFileInfo $file */
            foreach ($files as $file) {
                if (! $file->isFile() || $file->isLink()) {
                    continue;
                }

                $realPath = $file->getRealPath();
                if ($realPath === false || ! str_starts_with($realPath, $sourceDirectory.DIRECTORY_SEPARATOR)) {
                    throw new RuntimeException('ceying-geo Skill 包含无效文件路径');
                }

                $relativePath = str_replace('\\', '/', substr($realPath, strlen($sourceDirectory) + 1));
                $archivePath = self::SKILL_NAME.'/'.$relativePath;
                if (! $zip->addFile($realPath, $archivePath)) {
                    throw new RuntimeException('ceying-geo Skill 文件写入 ZIP 失败');
                }
            }

            if (! $zip->close()) {
                throw new RuntimeException('ceying-geo Skill ZIP 保存失败');
            }
            $archiveOpen = false;
        } catch (Throwable $exception) {
            if ($archiveOpen) {
                $zip->close();
            }
            @unlink($path);

            throw $exception;
        }

        return [
            'path' => $path,
            'filename' => $metadata['filename'],
        ];
    }
}
