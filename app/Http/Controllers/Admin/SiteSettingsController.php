<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Models\SiteSetting;
use App\Services\CustomerDomain\CustomerDomainDeploymentException;
use App\Services\CustomerDomain\CustomerDomainDeploymentManager;
use App\Support\AdminBasePathManager;
use App\Support\AdminDisplaySettings;
use App\Support\AdminRegistrationSettings;
use App\Support\AdminWeb;
use App\Support\CurrentSite;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemeCatalog;
use App\Support\SiteDomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * 网站设置控制器。
 *
 * 对齐 bak/admin/site-settings.php 的核心能力：
 * 1. 读取并展示网站基础设置；
 * 2. 保存基础信息、SEO模板与统计代码；
 * 3. 维持键值对存储结构（site_settings）。
 */
class SiteSettingsController extends Controller
{
    public function __construct(
        private readonly SiteThemeCatalog $siteThemeCatalog,
        private readonly AdminRegistrationSettings $adminRegistrationSettings,
        private readonly CustomerDomainDeploymentManager $customerDomainDeploymentManager
    ) {}

    /**
     * 网站设置页面。
     */
    public function index(): View
    {
        $settings = $this->loadSettings();
        $currentSite = app(CurrentSite::class)->get();
        $canEditAdminDisplaySettings = auth('admin')->user()?->isSuperAdmin() === true;

        return view('admin.site-settings.index', [
            'pageTitle' => __('admin.site_settings.page_title'),
            'activeMenu' => 'site_settings',
            'adminSiteName' => AdminWeb::siteName(),
            'settings' => $settings,
            'systemDomain' => (string) ($currentSite?->domain ?? ''),
            'customDomain' => (string) ($currentSite?->custom_domain ?? ''),
            'customHttpsEnabled' => (bool) ($currentSite?->custom_https_enabled ?? false),
            'customHttpsCertificateConfigured' => trim((string) ($currentSite?->custom_https_certificate ?? '')) !== '',
            'customHttpsPrivateKeyConfigured' => trim((string) ($currentSite?->custom_https_private_key ?? '')) !== '',
            'customerDomainDeploymentEnabled' => $this->customerDomainDeploymentManager->enabled(),
            'customerDomainDeploymentStatus' => (string) ($currentSite?->custom_domain_deployment_status ?? 'manual'),
            'customerDomainDeploymentError' => (string) ($currentSite?->custom_domain_deployment_error ?? ''),
            'canEditAnalytics' => auth('admin')->user()?->isSuperAdmin() === true,
            'canEditAdminDisplaySettings' => $canEditAdminDisplaySettings,
            'adminDisplaySettings' => AdminDisplaySettings::all(),
            'registrationSettings' => $this->adminRegistrationSettings->all(),
            'registrationPlans' => $canEditAdminDisplaySettings
                ? PlatformPlan::query()
                    ->where('status', 'active')
                    ->whereIn('audience', ['direct', 'both'])
                    ->orderBy('sort_order')
                    ->orderByDesc('id')
                    ->get(['id', 'name', 'code', 'duration_days', 'audience'])
                : collect(),
            'canEditDomainSettings' => $this->canEditDomainSettings(),
            'canEditAdminBasePath' => $this->canEditAdminBasePath(),
            'availableThemes' => $this->siteThemeCatalog->all(),
            'homeCarouselSlides' => $this->parseHomeCarouselSlides((string) ($settings['home_carousel_slides'] ?? '[]')),
            'articleDetailAds' => $this->parseArticleDetailAds((string) ($settings['article_detail_ads'] ?? '[]')),
            'contactPayments' => $this->parseContactPayments((string) ($settings['contact_payments'] ?? '[]')),
        ]);
    }

    /**
     * 保存网站基础设置。
     */
    public function update(Request $request): RedirectResponse
    {
        $currentAdminBasePath = AdminWeb::basePath();
        $canEditDomainSettings = $this->canEditDomainSettings();
        $canEditAdminBasePath = $this->canEditAdminBasePath();
        // 私钥不进入 Request 的旧输入闪存，避免校验失败时写入 Session 或回显到页面。
        $customHttpsPrivateKeyInput = trim((string) $request->input('custom_https_private_key', ''));
        $request->request->remove('custom_https_private_key');
        $rules = [
            'site_name' => ['required', 'string', 'max:120'],
            'site_subtitle' => ['nullable', 'string', 'max:255'],
            'site_description' => ['nullable', 'string'],
            'site_keywords' => ['nullable', 'string', 'max:500'],
            'copyright_info' => ['nullable', 'string', 'max:500'],
            'site_logo' => ['nullable', 'url', 'max:500'],
            'site_favicon' => ['nullable', 'url', 'max:500'],
            'custom_domain' => ['nullable', 'string', 'max:255'],
            // 兼容旧版页面提交，旧字段只映射到客户自定义域名，不会修改系统默认域名。
            'public_domain' => ['nullable', 'string', 'max:255'],
            'custom_https_enabled' => ['nullable', 'boolean'],
            'custom_https_certificate' => ['nullable', 'string', 'max:262144'],
            'custom_https_certificate_file' => ['nullable', 'file', 'max:256', 'extensions:pem,crt,cer,txt'],
            'custom_https_private_key_file' => ['nullable', 'file', 'max:256', 'extensions:pem,key,txt'],
            'analytics_code' => ['nullable', 'string'],
            'seo_title_template' => ['nullable', 'string', 'max:255'],
            'seo_description_template' => ['nullable', 'string', 'max:255'],
            'featured_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'home_carousel_slides' => ['nullable', 'array', 'max:3'],
            'home_carousel_slides.*.image_url' => ['nullable', 'string', 'max:500'],
            'home_carousel_slides.*.title' => ['nullable', 'string', 'max:120'],
            'home_carousel_slides.*.link_url' => ['nullable', 'string', 'max:500'],
            'home_carousel_slides.*.enabled' => ['nullable'],
            'contact_info' => ['nullable', 'string', 'max:1000'],
            'company_address' => ['nullable', 'string', 'max:500'],
            'site_remark' => ['nullable', 'string', 'max:2000'],
            'contact_payments' => ['nullable', 'array', 'max:10'],
            'contact_payments.*.type' => ['nullable', 'string', 'max:32'],
            'contact_payments.*.name' => ['nullable', 'string', 'max:120'],
            'contact_payments.*.qr_url' => ['nullable', 'string', 'max:500'],
            'contact_payments.*.account' => ['nullable', 'string', 'max:200'],
            'contact_payments.*.enabled' => ['nullable'],
            'admin_base_path' => [
                Rule::requiredIf($canEditAdminBasePath),
                'nullable',
                'string',
                'min:3',
                'max:48',
                'regex:/^[a-z0-9][a-z0-9_-]*[a-z0-9]$/',
                Rule::notIn(AdminBasePathManager::reservedSegments()),
            ],
        ];

        $validator = Validator::make($request->all(), $rules, [
            'site_name.required' => __('admin.site_settings.error.site_name_required'),
            'admin_base_path.required' => __('admin.site_settings.error.admin_base_path_required'),
            'admin_base_path.min' => __('admin.site_settings.error.admin_base_path_invalid'),
            'admin_base_path.max' => __('admin.site_settings.error.admin_base_path_invalid'),
            'admin_base_path.regex' => __('admin.site_settings.error.admin_base_path_invalid'),
            'admin_base_path.not_in' => __('admin.site_settings.error.admin_base_path_reserved'),
        ]);
        if ($validator->fails()) {
            return back()
                ->withErrors($validator)
                ->withInput($request->except(['custom_https_private_key', 'custom_https_private_key_file']));
        }

        $payload = $validator->validated();

        try {
            $newAdminBasePath = $canEditAdminBasePath
                ? AdminBasePathManager::normalize((string) $payload['admin_base_path'])
                : $currentAdminBasePath;
        } catch (\Throwable) {
            return back()->withErrors(['admin_base_path' => __('admin.site_settings.error.admin_base_path_invalid')])->withInput();
        }

        $currentSettings = $this->loadSettings();
        $canEditAnalytics = auth('admin')->user()?->isSuperAdmin() === true;
        $currentSite = app(CurrentSite::class)->get();
        $customDomainInput = $request->exists('custom_domain')
            ? (string) ($payload['custom_domain'] ?? '')
            : ($request->exists('public_domain')
                ? (string) ($payload['public_domain'] ?? '')
                : (string) ($currentSite?->custom_domain ?? ''));
        $customDomain = $canEditDomainSettings
            ? SiteDomain::normalize($customDomainInput)
            : (string) ($currentSite?->custom_domain ?? '');

        if ($canEditDomainSettings && $customDomain === '' && trim($customDomainInput) !== '') {
            return back()->withErrors(['custom_domain' => __('admin.site_settings.error.public_domain_invalid')])->withInput();
        }

        if ($canEditDomainSettings && $currentSite instanceof Site && $customDomain !== '') {
            $domainExists = Site::query()
                ->whereKeyNot($currentSite->id)
                ->where(function ($query) use ($customDomain): void {
                    $query->where('domain', $customDomain)
                        ->orWhere('custom_domain', $customDomain);
                })
                ->exists();
            if ($domainExists) {
                return back()->withErrors(['custom_domain' => __('admin.site_settings.error.public_domain_taken')])->withInput();
            }
        }

        $customHttps = $this->resolveCustomHttpsSettings(
            $request,
            $customHttpsPrivateKeyInput,
            $currentSite,
            $customDomain,
            $canEditDomainSettings
        );
        $domainConfigurationChanged = $currentSite instanceof Site && (
            (string) $currentSite->custom_domain !== $customDomain
            || (bool) $currentSite->custom_https_enabled !== $customHttps['custom_https_enabled']
            || (string) ($currentSite->custom_https_certificate ?? '') !== (string) ($customHttps['custom_https_certificate'] ?? '')
            || (string) ($currentSite->custom_https_private_key ?? '') !== (string) ($customHttps['custom_https_private_key'] ?? '')
        );
        $expectedDeploymentStatus = $this->customerDomainDeploymentManager->enabled()
            ? ($customDomain !== '' && $currentSite?->status === 'active' ? 'active' : 'removed')
            : 'manual';
        $domainDeploymentNeeded = $currentSite instanceof Site
            && ($domainConfigurationChanged || $currentSite->custom_domain_deployment_status !== $expectedDeploymentStatus);

        $settings = [
            'site_name' => trim((string) $payload['site_name']),
            'site_title' => trim((string) $payload['site_name']),
            'site_subtitle' => trim((string) ($payload['site_subtitle'] ?? '')),
            'site_description' => trim((string) ($payload['site_description'] ?? '')),
            'site_keywords' => trim((string) ($payload['site_keywords'] ?? '')),
            'copyright_info' => trim((string) ($payload['copyright_info'] ?? '')),
            'site_logo' => trim((string) ($payload['site_logo'] ?? '')),
            'site_favicon' => trim((string) ($payload['site_favicon'] ?? '')),
            'analytics_code' => $canEditAnalytics
                ? trim((string) ($payload['analytics_code'] ?? ''))
                : (string) ($currentSettings['analytics_code'] ?? ''),
            'seo_title_template' => trim((string) ($payload['seo_title_template'] ?? '')),
            'seo_description_template' => trim((string) ($payload['seo_description_template'] ?? '')),
            'featured_limit' => (string) ((int) ($payload['featured_limit'] ?? 6)),
            'per_page' => (string) ((int) ($payload['per_page'] ?? 12)),
            'home_carousel_slides' => (string) json_encode($this->normalizeHomeCarouselSlides($payload['home_carousel_slides'] ?? []), JSON_UNESCAPED_UNICODE),
            'contact_info' => trim((string) ($payload['contact_info'] ?? '')),
            'company_address' => trim((string) ($payload['company_address'] ?? '')),
            'site_remark' => trim((string) ($payload['site_remark'] ?? '')),
            'contact_payments' => (string) json_encode($this->normalizeContactPayments($payload['contact_payments'] ?? []), JSON_UNESCAPED_UNICODE),
            'admin_base_path' => $canEditAdminBasePath ? $newAdminBasePath : $currentAdminBasePath,
        ];

        foreach ($settings as $settingKey => $settingValue) {
            SiteSetting::query()->updateOrCreate(
                ['setting_key' => $settingKey],
                ['setting_value' => $settingValue]
            );
        }

        if ($canEditDomainSettings && $currentSite instanceof Site) {
            $currentSite->forceFill([
                'custom_domain' => $customDomain,
                ...$customHttps,
            ])->save();

            SiteSettingsBag::forget();

            if ($domainDeploymentNeeded) {
                try {
                    $currentSite->refresh();
                    if ($currentSite->status === 'active' && $currentSite->custom_domain !== '') {
                        $this->customerDomainDeploymentManager->synchronize($currentSite);
                    } else {
                        $this->customerDomainDeploymentManager->remove($currentSite);
                    }
                } catch (CustomerDomainDeploymentException $exception) {
                    return back()
                        ->withErrors(['custom_domain' => $exception->getMessage()])
                        ->withInput($request->except(['custom_https_private_key', 'custom_https_private_key_file']));
                }
            }
        }

        SiteSettingsBag::forget();

        if ($canEditAdminBasePath && $newAdminBasePath !== $currentAdminBasePath) {
            try {
                AdminBasePathManager::persist($newAdminBasePath);
            } catch (\Throwable $e) {
                return back()->withErrors([
                    'admin_base_path' => __('admin.site_settings.error.admin_base_path_save_failed', ['message' => $e->getMessage()]),
                ])->withInput();
            }

            $newAdminUrl = url('/'.$newAdminBasePath.'/site-settings');

            return redirect()->to($newAdminUrl)->with('message', __('admin.site_settings.message.saved_admin_base_path', ['url' => $newAdminUrl]));
        }

        return redirect()->route('admin.site-settings.index')->with('message', __('admin.site_settings.message.saved'));
    }

    /**
     * 解析并校验站点自定义域名的 HTTPS 配置。
     *
     * @param  Request  $request  当前后台请求
     * @param  string  $privateKeyInput  页面粘贴的私钥内容
     * @param  Site|null  $currentSite  当前站点
     * @param  string  $customDomain  已归一化的客户自定义域名
     * @param  bool  $canEditDomainSettings  当前账号是否有域名配置权限
     * @return array{custom_https_enabled:bool,custom_https_certificate:string|null,custom_https_private_key:string|null} HTTPS 配置
     *
     * @throws ValidationException 证书缺失、格式错误、域名不匹配或私钥不匹配时抛出
     */
    private function resolveCustomHttpsSettings(
        Request $request,
        string $privateKeyInput,
        ?Site $currentSite,
        string $customDomain,
        bool $canEditDomainSettings
    ): array {
        if (! $canEditDomainSettings) {
            return [
                'custom_https_enabled' => (bool) ($currentSite?->custom_https_enabled ?? false),
                'custom_https_certificate' => $currentSite?->custom_https_certificate,
                'custom_https_private_key' => $currentSite?->custom_https_private_key,
            ];
        }

        if ($customDomain === '') {
            if ($request->boolean('custom_https_enabled')) {
                throw ValidationException::withMessages([
                    'custom_domain' => '启用 HTTPS 前请先绑定客户自定义域名',
                ]);
            }

            return [
                'custom_https_enabled' => false,
                'custom_https_certificate' => null,
                'custom_https_private_key' => null,
            ];
        }

        $enabled = $request->boolean('custom_https_enabled');
        if (! $enabled) {
            return [
                'custom_https_enabled' => false,
                'custom_https_certificate' => $currentSite?->custom_https_certificate,
                'custom_https_private_key' => $currentSite?->custom_https_private_key,
            ];
        }

        $certificate = $this->uploadedText($request, 'custom_https_certificate_file')
            ?: trim((string) $request->input('custom_https_certificate', ''))
            ?: trim((string) ($currentSite?->custom_https_certificate ?? ''));
        $privateKey = $this->uploadedText($request, 'custom_https_private_key_file')
            ?: $privateKeyInput
            ?: trim((string) ($currentSite?->custom_https_private_key ?? ''));

        if ($certificate === '') {
            throw ValidationException::withMessages([
                'custom_https_certificate' => '启用 HTTPS 时必须填写或上传证书内容',
            ]);
        }
        if ($privateKey === '') {
            throw ValidationException::withMessages([
                'custom_https_private_key' => '启用 HTTPS 时必须填写或上传私钥内容',
            ]);
        }
        if (strlen($certificate) > 262144) {
            throw ValidationException::withMessages([
                'custom_https_certificate' => 'HTTPS 证书文件不能超过 256 KB',
            ]);
        }
        if (strlen($privateKey) > 262144) {
            throw ValidationException::withMessages([
                'custom_https_private_key' => 'HTTPS 私钥文件不能超过 256 KB',
            ]);
        }

        $this->validateCertificatePair($certificate, $privateKey, $customDomain);

        return [
            'custom_https_enabled' => true,
            'custom_https_certificate' => $certificate,
            'custom_https_private_key' => $privateKey,
        ];
    }

    /**
     * 读取上传的 PEM 文本文件。
     *
     * @param  Request  $request  当前后台请求
     * @param  string  $field  文件字段名
     * @return string 文件内容，读取失败时返回空字符串
     */
    private function uploadedText(Request $request, string $field): string
    {
        $file = $request->file($field);
        if (! $file || ! $file->isValid()) {
            return '';
        }

        $content = @file_get_contents($file->getRealPath());

        return is_string($content) ? trim($content) : '';
    }

    /**
     * 校验证书、私钥和客户域名的对应关系，并拒绝过期证书。
     *
     * @param  string  $certificate  PEM 证书内容
     * @param  string  $privateKey  PEM 私钥内容
     * @param  string  $customDomain  客户自定义域名
     *
     * @throws ValidationException 证书链、有效期、域名或密钥不符合要求时抛出
     */
    private function validateCertificatePair(string $certificate, string $privateKey, string $customDomain): void
    {
        if (! function_exists('openssl_x509_read') || ! function_exists('openssl_pkey_get_private')) {
            throw ValidationException::withMessages([
                'custom_https_certificate' => '服务器未启用 OpenSSL，暂时无法校验 HTTPS 证书',
            ]);
        }

        $certificateResource = @openssl_x509_read($certificate);
        if ($certificateResource === false) {
            throw ValidationException::withMessages([
                'custom_https_certificate' => 'HTTPS 证书格式无效，请填写 PEM 格式证书',
            ]);
        }

        $privateKeyResource = @openssl_pkey_get_private($privateKey);
        if ($privateKeyResource === false) {
            throw ValidationException::withMessages([
                'custom_https_private_key' => 'HTTPS 私钥格式无效，请填写 PEM 格式私钥',
            ]);
        }

        if (! @openssl_x509_check_private_key($certificateResource, $privateKeyResource)) {
            throw ValidationException::withMessages([
                'custom_https_private_key' => 'HTTPS 证书与私钥不匹配',
            ]);
        }

        $parsed = @openssl_x509_parse($certificateResource);
        if (! is_array($parsed)) {
            throw ValidationException::withMessages([
                'custom_https_certificate' => 'HTTPS 证书解析失败',
            ]);
        }

        $now = now()->timestamp;
        if ((int) ($parsed['validFrom_time_t'] ?? 0) > $now || (int) ($parsed['validTo_time_t'] ?? 0) < $now) {
            throw ValidationException::withMessages([
                'custom_https_certificate' => 'HTTPS 证书尚未生效或已经过期',
            ]);
        }

        $extensions = $parsed['extensions'] ?? [];
        $subjectAltName = (string) ($extensions['subjectAltName'] ?? '');
        $names = [];
        if (array_key_exists('subjectAltName', $extensions)) {
            foreach (preg_split('/,\s*/', $subjectAltName, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
                if (str_starts_with($entry, 'DNS:')) {
                    $names[] = trim(substr($entry, 4));
                }
            }
        } else {
            $commonName = (string) ($parsed['subject']['commonName'] ?? $parsed['subject']['CN'] ?? '');
            if ($commonName !== '') {
                $names[] = $commonName;
            }
        }

        foreach ($names as $name) {
            $name = strtolower(trim($name));
            $domain = strtolower($customDomain);
            if ($name === $domain || (str_starts_with($name, '*.') && str_ends_with($domain, substr($name, 1)) && substr_count($domain, '.') === substr_count($name, '.'))) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'custom_https_certificate' => 'HTTPS 证书不包含当前客户自定义域名',
        ]);
    }

    /**
     * 保存模板设置。
     */
    public function updateAdminDisplay(Request $request): RedirectResponse
    {
        abort_unless(auth('admin')->user()?->isSuperAdmin() === true, 403);

        $payload = $request->validate([
            'admin_quick_start_eyebrow' => ['nullable', 'string', 'max:120'],
            'admin_quick_start_title' => ['nullable', 'string', 'max:200'],
            'admin_quick_start_subtitle' => ['nullable', 'string', 'max:500'],
            'admin_footer_brand' => ['nullable', 'string', 'max:120'],
            'admin_footer_version' => ['nullable', 'string', 'max:60'],
            'media_platform_1_label' => ['nullable', 'string', 'max:120'],
            'media_platform_2_label' => ['nullable', 'string', 'max:120'],
        ]);

        AdminDisplaySettings::update($payload);

        return redirect()->route('admin.site-settings.index')->with('message', __('admin.site_settings.message.saved'));
    }

    public function updateRegistration(Request $request): RedirectResponse
    {
        abort_unless(auth('admin')->user()?->isSuperAdmin() === true, 403);

        $payload = $request->validate([
            'admin_registration_enabled' => ['nullable'],
            'admin_registration_experience_plan_id' => [
                Rule::requiredIf($request->boolean('admin_registration_enabled')),
                'nullable',
                'integer',
                Rule::exists('platform_plans', 'id')->where(function ($query): void {
                    $query->where('status', 'active')->whereIn('audience', ['direct', 'both']);
                }),
            ],
        ]);

        $this->adminRegistrationSettings->update([
            'enabled' => $request->boolean('admin_registration_enabled'),
            'experience_plan_id' => (int) ($payload['admin_registration_experience_plan_id'] ?? 0),
        ]);

        return redirect()->route('admin.site-settings.index')->with('message', __('admin.site_settings.message.saved'));
    }

    public function updateTheme(Request $request): RedirectResponse
    {
        $selectedTheme = trim((string) $request->input('active_theme', ''));
        $availableThemeIds = array_map(
            static fn (array $theme): string => (string) $theme['id'],
            $this->siteThemeCatalog->all()
        );

        if ($selectedTheme !== '' && ! in_array($selectedTheme, $availableThemeIds, true)) {
            return back()->withErrors(__('admin.site_settings.theme.invalid_selection'));
        }

        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'active_theme'],
            ['setting_value' => $selectedTheme]
        );

        SiteSettingsBag::forget();

        if ($selectedTheme === '') {
            return redirect()->route('admin.site-settings.index')->with('message', __('admin.site_settings.theme.message.default_enabled'));
        }

        return redirect()->route('admin.site-settings.index')->with('message', __('admin.site_settings.theme.message.activated', ['name' => $selectedTheme]));
    }

    /**
     * 保存文章详情页广告位设置。
     */
    public function updateArticleDetailAds(Request $request): RedirectResponse
    {
        $postedAds = $request->input('ads', []);
        if (! is_array($postedAds)) {
            $postedAds = [];
        }

        $ads = [];
        foreach ($postedAds as $index => $postedAd) {
            if (! is_array($postedAd)) {
                continue;
            }

            $name = trim((string) ($postedAd['name'] ?? ''));
            $badge = trim((string) ($postedAd['badge'] ?? ''));
            $title = trim((string) ($postedAd['title'] ?? ''));
            $copy = trim((string) ($postedAd['copy'] ?? ''));
            $buttonText = trim((string) ($postedAd['button_text'] ?? ''));
            $buttonUrl = $this->normalizeCtaTargetUrl((string) ($postedAd['button_url'] ?? ''));
            $enabled = ! empty($postedAd['enabled']);
            $id = trim((string) ($postedAd['id'] ?? ''));

            if ($name === '' && $badge === '' && $title === '' && $copy === '' && $buttonText === '' && $buttonUrl === '') {
                continue;
            }

            if ($copy === '' || $buttonText === '' || $buttonUrl === '') {
                return back()->withErrors(__('admin.site_settings.ads.validation_required', ['index' => ((int) $index + 1)]));
            }

            $ads[] = [
                'id' => $id !== '' ? $id : uniqid('article_ad_', true),
                'name' => $name !== '' ? $name : __('admin.site_settings.ads.default_name', ['index' => (count($ads) + 1)]),
                'badge' => $badge,
                'title' => $title,
                'copy' => $copy,
                'button_text' => $buttonText,
                'button_url' => $buttonUrl,
                'enabled' => $enabled,
            ];
        }

        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'article_detail_ads'],
            ['setting_value' => (string) json_encode($ads, JSON_UNESCAPED_UNICODE)]
        );

        SiteSettingsBag::forget();

        return redirect()->route('admin.site-settings.index')->with('message', __('admin.site_settings.ads.saved'));
    }

    /**
     * @return array{
     *   site_name:string,
     *   site_subtitle:string,
     *   site_description:string,
     *   site_keywords:string,
     *   copyright_info:string,
     *   site_logo:string,
     *   site_favicon:string,
     *   analytics_code:string,
     *   seo_title_template:string,
     *   seo_description_template:string,
     *   featured_limit:string,
     *   per_page:string,
     *   admin_base_path:string,
     *   active_theme:string,
     *   home_carousel_slides:string,
     *   article_detail_ads:string,
     *   contact_info:string,
     *   company_address:string,
     *   site_remark:string,
     *   contact_payments:string
     * }
     */
    private function loadSettings(): array
    {
        $defaults = [
            'site_name' => '策影GEO',
            'site_subtitle' => '',
            'site_description' => '基于AI的智能内容生成与发布平台',
            'site_keywords' => 'AI内容生成,GEO优化,智能发布,内容管理',
            'copyright_info' => '© 2026 策影GEO. All rights reserved.',
            'site_logo' => '',
            'site_favicon' => '',
            'analytics_code' => '',
            'seo_title_template' => '{title} - {site_name}',
            'seo_description_template' => '{description}',
            'featured_limit' => '6',
            'per_page' => '12',
            'admin_base_path' => AdminWeb::basePath(),
            'active_theme' => (string) config('geoflow.default_theme', ''),
            'home_carousel_slides' => '[]',
            'article_detail_ads' => '[]',
            'contact_info' => '',
            'company_address' => '',
            'site_remark' => '',
            'contact_payments' => '[]',
        ];

        $stored = SiteSetting::query()
            ->select(['setting_key', 'setting_value'])
            ->whereIn('setting_key', array_keys($defaults))
            ->get()
            ->pluck('setting_value', 'setting_key')
            ->all();

        foreach ($defaults as $key => $defaultValue) {
            if (! array_key_exists($key, $stored)) {
                $stored[$key] = $defaultValue;
            }
        }

        return [
            'site_name' => (string) $stored['site_name'],
            'site_subtitle' => (string) $stored['site_subtitle'],
            'site_description' => (string) $stored['site_description'],
            'site_keywords' => (string) $stored['site_keywords'],
            'copyright_info' => (string) $stored['copyright_info'],
            'site_logo' => (string) $stored['site_logo'],
            'site_favicon' => (string) $stored['site_favicon'],
            'analytics_code' => (string) $stored['analytics_code'],
            'seo_title_template' => (string) $stored['seo_title_template'],
            'seo_description_template' => (string) $stored['seo_description_template'],
            'featured_limit' => (string) $stored['featured_limit'],
            'per_page' => (string) $stored['per_page'],
            'admin_base_path' => AdminWeb::basePath(),
            'active_theme' => (string) ($stored['active_theme'] !== '' ? $stored['active_theme'] : config('geoflow.default_theme', '')),
            'home_carousel_slides' => (string) $stored['home_carousel_slides'],
            'article_detail_ads' => (string) $stored['article_detail_ads'],
            'contact_info' => (string) $stored['contact_info'],
            'company_address' => (string) $stored['company_address'],
            'site_remark' => (string) $stored['site_remark'],
            'contact_payments' => (string) $stored['contact_payments'],
        ];
    }

    private function canEditDomainSettings(): bool
    {
        $admin = auth('admin')->user();
        $currentSite = app(CurrentSite::class)->get();

        if (! $admin instanceof Admin || ! $currentSite instanceof Site) {
            return false;
        }

        if ($admin->isSuperAdmin()) {
            return true;
        }

        // 域名属于当前站点配置，仅允许当前站点负责人或成员修改，避免跨站点越权绑定。
        return (int) $currentSite->owner_admin_id === (int) $admin->id
            || $currentSite->members()->whereKey($admin->id)->exists();
    }

    private function canEditAdminBasePath(): bool
    {
        $admin = auth('admin')->user();

        return ! ($admin?->isDirectAdmin() === true || $admin?->isSiteUser() === true);
    }

    /**
     * @return array<int, array{
     *   id:string,
     *   name:string,
     *   badge:string,
     *   title:string,
     *   copy:string,
     *   button_text:string,
     *   button_url:string,
     *   enabled:bool
     * }>
     */
    private function parseArticleDetailAds(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $ads = [];
        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }

            $ads[] = [
                'id' => trim((string) ($item['id'] ?? '')),
                'name' => trim((string) ($item['name'] ?? '')),
                'badge' => trim((string) ($item['badge'] ?? '')),
                'title' => trim((string) ($item['title'] ?? '')),
                'copy' => trim((string) ($item['copy'] ?? '')),
                'button_text' => trim((string) ($item['button_text'] ?? '')),
                'button_url' => trim((string) ($item['button_url'] ?? '')),
                'enabled' => ! empty($item['enabled']),
            ];
        }

        return $ads;
    }

    /**
     * @return array<int, array{image_url:string,title:string,link_url:string,enabled:bool}>
     */
    private function parseHomeCarouselSlides(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $slides = [];
        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }

            $slides[] = [
                'image_url' => trim((string) ($item['image_url'] ?? '')),
                'title' => trim((string) ($item['title'] ?? '')),
                'link_url' => trim((string) ($item['link_url'] ?? '')),
                'enabled' => ! empty($item['enabled']),
            ];

            if (count($slides) >= 3) {
                break;
            }
        }

        return $slides;
    }

    /**
     * @return array<int, array{image_url:string,title:string,link_url:string,enabled:bool}>
     */
    private function normalizeHomeCarouselSlides(mixed $postedSlides): array
    {
        if (! is_array($postedSlides)) {
            return [];
        }

        $slides = [];
        foreach ($postedSlides as $postedSlide) {
            if (! is_array($postedSlide)) {
                continue;
            }

            $imageUrl = $this->normalizePublicImageUrl((string) ($postedSlide['image_url'] ?? ''));
            $title = trim((string) ($postedSlide['title'] ?? ''));
            $linkUrl = $this->normalizeCtaTargetUrl((string) ($postedSlide['link_url'] ?? ''));
            $enabled = ! empty($postedSlide['enabled']);

            if ($imageUrl === '' && $title === '' && $linkUrl === '') {
                continue;
            }

            if ($imageUrl === '') {
                continue;
            }

            $slides[] = [
                'image_url' => $imageUrl,
                'title' => $title,
                'link_url' => $linkUrl,
                'enabled' => $enabled,
            ];

            if (count($slides) >= 3) {
                break;
            }
        }

        return $slides;
    }

    /**
     * 首页海报图允许站内相对路径与 http(s) 图片地址；其它协议直接忽略，避免把无效资源写入前台。
     */
    private function normalizePublicImageUrl(string $url): string
    {
        $normalized = trim($url);
        if ($normalized === '') {
            return '';
        }

        if (str_starts_with($normalized, '/') && ! str_starts_with($normalized, '//')) {
            return $normalized;
        }

        if (preg_match('#^https?://#i', $normalized) === 1) {
            return $normalized;
        }

        return '';
    }

    /**
     * 归一化广告按钮链接，兼容相对路径与完整 URL。
     */
    private function normalizeCtaTargetUrl(string $url): string
    {
        $normalized = trim($url);
        if ($normalized === '') {
            return '';
        }

        if (str_starts_with($normalized, '/')) {
            return $normalized;
        }

        if (preg_match('#^https?://#i', $normalized) === 1) {
            return $normalized;
        }

        return '/'.ltrim($normalized, '/');
    }

    /**
     * @return array<int, array{type:string,name:string,qr_url:string,account:string,enabled:bool}>
     */
    private function parseContactPayments(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $items = [];
        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }

            $items[] = [
                'type' => trim((string) ($item['type'] ?? '')),
                'name' => trim((string) ($item['name'] ?? '')),
                'qr_url' => trim((string) ($item['qr_url'] ?? '')),
                'account' => trim((string) ($item['account'] ?? '')),
                'enabled' => ! empty($item['enabled']),
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array{type:string,name:string,qr_url:string,account:string,enabled:bool}>
     */
    private function normalizeContactPayments(mixed $posted): array
    {
        if (! is_array($posted)) {
            return [];
        }

        $items = [];
        foreach ($posted as $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = trim((string) ($row['type'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            $qrUrl = $this->normalizePublicImageUrl((string) ($row['qr_url'] ?? ''));
            $account = trim((string) ($row['account'] ?? ''));
            $enabled = ! empty($row['enabled']);

            if ($type === '' && $name === '' && $qrUrl === '' && $account === '') {
                continue;
            }

            $items[] = [
                'type' => $type,
                'name' => $name,
                'qr_url' => $qrUrl,
                'account' => $account,
                'enabled' => $enabled,
            ];

            if (count($items) >= 10) {
                break;
            }
        }

        return $items;
    }
}
