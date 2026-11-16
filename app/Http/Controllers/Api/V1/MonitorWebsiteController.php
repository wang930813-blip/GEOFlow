<?php

/**
 * Created by Codex.
 * @Date: 2026-11-11
 * @Time: 15:12:50
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： MonitorWebsiteController.php
 * @Description: 为监测平台提供当前授权官网的域名、HTTPS 与主题配置。
 */

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\Site;
use App\Models\SiteSetting;
use App\Services\CustomerDomain\CustomerDomainDeploymentException;
use App\Services\CustomerDomain\CustomerDomainDeploymentManager;
use App\Support\CurrentSite;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemeCatalog;
use App\Support\SiteDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MonitorWebsiteController extends BaseApiController
{
    /**
     * index
     * 读取当前监测账号授权官网的域名、HTTPS 与带封面的已安装主题配置。
     * @Url [GET] /api/v1/monitor/website
     *      登录 是
     *
     *      分页参数：
     *      无
     *
     *      筛选参数：
     *      无
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-11 11:45:00
     * @UpdateTime: 2026-11-11 15:22:45
     *
     * @Return JsonResponse 当前站点配置和主题封面地址，不包含证书私钥内容
     * @Throws ApiException 当前账号不是当前站点所有者或站点不可用
     */
    public function index(Request $request, CurrentSite $currentSite, SiteThemeCatalog $catalog): JsonResponse
    {
        [, $site] = $this->authorizedSite($request, $currentSite);
        $themeOptions = array_map(static fn (array $theme): array => [
            'id' => (string) $theme['id'],
            'name' => (string) $theme['name'],
            'version' => (string) $theme['version'],
            'description' => (string) $theme['description'],
            'cover_url' => (string) $theme['cover_url'],
        ], $catalog->all());
        $defaultThemeId = trim((string) config('geoflow.default_theme', ''));
        $defaultTheme = collect($themeOptions)->firstWhere('id', $defaultThemeId);

        return $this->success($request, [
            'site' => [
                'id' => (int) $site->id,
                'name' => (string) $site->name,
                'status' => (string) $site->status,
            ],
            'domain' => [
                'system_domain' => (string) $site->domain,
                'custom_domain' => (string) $site->custom_domain,
                'https_enabled' => (bool) $site->custom_https_enabled,
                'certificate_configured' => trim((string) $site->custom_https_certificate) !== '',
                'private_key_configured' => trim((string) $site->custom_https_private_key) !== '',
                'deployment_status' => (string) $site->custom_domain_deployment_status,
                'deployment_error' => (string) $site->custom_domain_deployment_error,
                'deployment_enabled' => app(CustomerDomainDeploymentManager::class)->enabled(),
            ],
            'theme' => [
                'active' => SiteSettingsBag::get('active_theme', ''),
                'default_cover_url' => (string) ($defaultTheme['cover_url'] ?? ''),
                'options' => $themeOptions,
            ],
        ]);
    }

    /**
     * update
     * 更新当前监测账号拥有的官网域名、HTTPS 证书及主题，写入前校验租户归属。
     * @Url [PUT] /api/v1/monitor/website
     *      登录 是
     *      custom_domain string 可选 客户自有域名，留空解除绑定
     *      custom_https_enabled boolean 可选 是否启用自有域名 HTTPS
     *      custom_https_certificate string 可选 PEM 证书，留空保留现有证书
     *      custom_https_private_key string 可选 PEM 私钥，留空保留现有私钥
     *      active_theme string 可选 已安装主题 ID，空字符串使用系统默认
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-11 11:45:00
     * @UpdateTime: 2026-11-11 15:22:45
     *
     * @Return JsonResponse 保存后的当前站点配置，不包含证书私钥内容
     * @Throws ApiException 未授权、域名冲突或宿主机证书部署失败
     */
    public function update(
        Request $request,
        CurrentSite $currentSite,
        SiteThemeCatalog $catalog,
        CustomerDomainDeploymentManager $deploymentManager
    ): JsonResponse {
        [$admin, $site] = $this->authorizedSite($request, $currentSite);
        $validated = $request->validate([
            'custom_domain' => ['sometimes', 'nullable', 'string', 'max:255'],
            'custom_https_enabled' => ['sometimes', 'boolean'],
            'custom_https_certificate' => ['sometimes', 'nullable', 'string', 'max:262144'],
            'custom_https_private_key' => ['sometimes', 'nullable', 'string', 'max:262144'],
            'active_theme' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        $privateKey = trim((string) ($validated['custom_https_private_key'] ?? ''));
        unset($validated['custom_https_private_key']);

        $domainInput = array_key_exists('custom_domain', $validated)
            ? trim((string) ($validated['custom_domain'] ?? ''))
            : trim((string) $site->custom_domain);
        $domain = $domainInput !== '' ? SiteDomain::normalize($domainInput) : '';
        if ($domainInput !== '' && $domain === '') {
            throw ValidationException::withMessages(['custom_domain' => '请输入有效域名']);
        }
        if ($domain !== '' && Site::query()
            ->where('id', '<>', (int) $site->id)
            ->where(fn ($query) => $query->where('domain', $domain)->orWhere('custom_domain', $domain))
            ->exists()) {
            throw ValidationException::withMessages(['custom_domain' => '该域名已绑定到其他站点']);
        }

        $httpsEnabled = array_key_exists('custom_https_enabled', $validated)
            ? $request->boolean('custom_https_enabled')
            : (bool) $site->custom_https_enabled;
        $certificateInput = trim((string) ($validated['custom_https_certificate'] ?? ''));
        $certificate = $certificateInput !== '' ? $certificateInput : (string) $site->custom_https_certificate;
        $privateKey = $privateKey !== '' ? $privateKey : (string) $site->custom_https_private_key;
        if ($httpsEnabled && $domain === '') {
            throw ValidationException::withMessages(['custom_domain' => '绑定自定义域名后才能启用 HTTPS']);
        }
        if ($httpsEnabled && ($certificate === '' || $privateKey === '')) {
            throw ValidationException::withMessages(['custom_https_certificate' => '启用 HTTPS 前需要同时配置 PEM 证书和私钥']);
        }
        if ($httpsEnabled && ! $this->certificateMatchesKey($certificate, $privateKey, $domain)) {
            throw ValidationException::withMessages(['custom_https_certificate' => '证书无效、已过期或与域名及私钥不匹配']);
        }

        $themeWasProvided = array_key_exists('active_theme', $validated);
        $selectedTheme = trim((string) ($validated['active_theme'] ?? ''));
        if ($themeWasProvided && $selectedTheme !== '' && ! in_array($selectedTheme, $catalog->ids(), true)) {
            throw ValidationException::withMessages(['active_theme' => '所选主题不存在或尚未安装']);
        }

        $before = [
            (string) $site->custom_domain,
            (bool) $site->custom_https_enabled,
            (string) $site->custom_https_certificate,
            (string) $site->custom_https_private_key,
        ];
        $site->forceFill([
            'custom_domain' => $domain,
            'custom_https_enabled' => $httpsEnabled,
            'custom_https_certificate' => $httpsEnabled ? $certificate : null,
            'custom_https_private_key' => $httpsEnabled ? $privateKey : null,
        ])->save();
        $domainChanged = $before !== [
            $domain,
            $httpsEnabled,
            $httpsEnabled ? $certificate : '',
            $httpsEnabled ? $privateKey : '',
        ];

        if ($themeWasProvided) {
            DB::transaction(function () use ($site, $admin, $selectedTheme): void {
                SiteSetting::query()->updateOrCreate(
                    ['site_id' => (int) $site->id, 'setting_key' => 'active_theme'],
                    ['owner_admin_id' => (int) $admin->id, 'setting_value' => $selectedTheme]
                );
            });
            SiteSettingsBag::forget();
        }

        if ($domainChanged) {
            try {
                $site->refresh();
                if ($domain !== '' && $site->status === 'active') {
                    $deploymentManager->synchronize($site);
                } else {
                    $deploymentManager->remove($site);
                }
            } catch (CustomerDomainDeploymentException $exception) {
                throw new ApiException('website_domain_deployment_failed', $exception->getMessage(), 422);
            }
        }

        return $this->index($request, $currentSite, $catalog);
    }

    /**
     * @Name: authorizedSite
     * @Description: 从 Bearer 认证和 CurrentSite 上下文中取得站点，并要求当前 GEO 账号为站点所有者。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 11:45:00
     * @UpdateTime: 2026-11-11 12:21:00
     * @Param Request $request 当前监测 API 请求
     * @Param CurrentSite $currentSite 认证中间件设置的当前站点
     * @Return array{0:Admin,1:Site} 已验证的账号与站点
     * @Throws ApiException 账号或当前站点不可用、当前账号不是站点所有者
     */
    private function authorizedSite(Request $request, CurrentSite $currentSite): array
    {
        $admin = auth('admin')->user();
        $site = $currentSite->get();
        if (! $admin instanceof Admin || ! $site instanceof Site || $site->status !== 'active') {
            throw new ApiException('website_unavailable', '当前监测账号没有可管理的官网站点', 403);
        }
        if ((int) $site->owner_admin_id !== (int) $admin->id) {
            throw new ApiException('website_forbidden', '只有当前官网所有者可以修改网站配置', 403);
        }

        return [$admin, $site];
    }

    /**
     * @Name: certificateMatchesKey
     * @Description: 检查 PEM 证书与私钥匹配、证书有效期及 SAN/CN 域名覆盖关系。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 11:45:00
     * @UpdateTime: 2026-11-11 12:21:00
     * @Param string $certificate PEM X.509 证书
     * @Param string $privateKey PEM 私钥
     * @Param string $domain 标准化自有域名
     * @Return bool 证书与域名、私钥是否匹配且未过期
     * @Throws 无 OpenSSL 解析失败时返回 false
     */
    private function certificateMatchesKey(string $certificate, string $privateKey, string $domain): bool
    {
        if (! function_exists('openssl_x509_read') || ! function_exists('openssl_pkey_get_private')) {
            return false;
        }

        $cert = @openssl_x509_read($certificate);
        $key = @openssl_pkey_get_private($privateKey);
        if ($cert === false || $key === false || ! openssl_x509_check_private_key($cert, $key)) {
            return false;
        }

        $parsed = openssl_x509_parse($cert);
        if ((int) ($parsed['validTo_time_t'] ?? 0) <= time()) {
            return false;
        }

        $subjectAltNames = (string) ($parsed['extensions']['subjectAltName'] ?? '');
        $names = array_map('trim', preg_split('/,\s*/', $subjectAltNames) ?: []);
        $names[] = (string) ($parsed['subject']['CN'] ?? '');
        foreach ($names as $name) {
            $name = strtolower(str_starts_with($name, 'DNS:') ? substr($name, 4) : $name);
            if ($name === $domain) {
                return true;
            }
            if (str_starts_with($name, '*.')) {
                $suffix = substr($name, 1);
                if (str_ends_with($domain, $suffix) && substr_count($domain, '.') === substr_count($name, '.')) {
                    return true;
                }
            }
        }

        return false;
    }
}
