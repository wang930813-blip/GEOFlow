<?php

/**
 * Created by Codex.
 * @Date: 2026-11-12
 * @Time: 16:04:59
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： CloudStoragePlugin.php
 * @Description: 定义站点云存储插件的上传契约。
 */

namespace App\WebsitePlugins\Contracts;

use Illuminate\Http\UploadedFile;

interface CloudStoragePlugin
{
    /**
     * @Name: upload
     * @Description: 上传已通过业务校验的文件，成功后返回公开 HTTPS 地址，失败时抛出异常。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 16:04:59
     * @UpdateTime: 2026-11-12 16:04:59
     * @Param UploadedFile $file 已验证的文件
     * @Param string $key 站点隔离的对象键
     * @Param array $settings 当前站点插件配置
     * @Return string 公开文件地址
     * @Throws RuntimeException 云服务拒绝上传或配置无效
     */
    public function upload(UploadedFile $file, string $key, array $settings): string;
}
