<?php

/**
 * Created by Codex.
 * @Date: 2026-11-11
 * @Time: 10:46:30
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： MonitorWebsiteAdminController.php
 * @Description: 为已登录监测中心用户签发官网后台的一次性跳转凭证。
 */

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\Site;
use App\Services\WebsiteAdmin\WebsiteAdminAuthService;
use App\Support\CurrentSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MonitorWebsiteAdminController extends BaseApiController
{
    /**
     * Website admin SSO
     * 为当前监测中心账号签发一次性官网后台登录跳转地址。
     * @Url [POST] /api/v1/monitor/website-admin/sso
     *      登录 是
     *      无请求体参数
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:46:30
     * @UpdateTime: 2026-11-11 16:19:58
     *
     * @Return JsonResponse 一次性 SSO 地址和过期秒数
     * @Throws ApiException 当前账号不是站点所有者或站点不可用
     */
    public function issue(Request $request, WebsiteAdminAuthService $authService, CurrentSite $currentSite): JsonResponse
    {
        $admin = auth('admin')->user();
        $site = $currentSite->get();
        if (! $admin instanceof Admin || ! $site instanceof Site) {
            throw new ApiException('website_admin_unavailable', '当前监测账号没有可管理的官网站点', 403);
        }
        $websiteHost = (bool) $site->custom_https_enabled && trim((string) $site->custom_domain) !== ''
            ? (string) $site->custom_domain
            : (string) $site->domain;
        if (trim($websiteHost) === '') {
            throw new ApiException('website_domain_missing', '当前官网尚未分配可访问域名', 409);
        }

        try {
            $token = $authService->issueSsoToken($admin, $site);
        } catch (\RuntimeException $exception) {
            throw new ApiException('website_admin_forbidden', $exception->getMessage(), 403);
        }

        $websiteOrigin = 'https://'.$websiteHost;
        $isLocalWebsiteHost = app()->environment(['local', 'testing'])
            && ($websiteHost === 'localhost' || str_ends_with(strtolower($websiteHost), '.localhost'));
        if ($isLocalWebsiteHost) {
            $appUrl = (string) config('app.url', 'http://localhost');
            $scheme = strtolower((string) parse_url($appUrl, PHP_URL_SCHEME));
            $scheme = in_array($scheme, ['http', 'https'], true) ? $scheme : 'http';
            $port = parse_url($appUrl, PHP_URL_PORT);
            $defaultPort = $scheme === 'https' ? 443 : 80;
            $portSuffix = is_int($port) && $port !== $defaultPort ? ':'.$port : '';
            $websiteOrigin = $scheme.'://'.$websiteHost.$portSuffix;
        }

        return $this->success($request, [
            'url' => $websiteOrigin.'/admin/sso/'.rawurlencode($token),
            'expires_in' => 60,
        ]);
    }
}
