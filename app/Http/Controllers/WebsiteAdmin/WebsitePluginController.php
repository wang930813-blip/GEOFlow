<?php

/**
 * Created by Codex.
 * @Date: 2026-11-12
 * @Time: 17:03:13
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： WebsitePluginController.php
 * @Description: 展示网站插件清单并管理站点级云存储配置。
 */

namespace App\Http\Controllers\WebsiteAdmin;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\WebsitePlugin;
use App\Support\WebsiteAdminContext;
use App\WebsitePlugins\PluginRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class WebsitePluginController extends Controller
{
    /**
     * @Name: index
     * @Description: 按当前站点展示系统安装的插件、唯一标识及站点启用状态。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 16:13:54
     * @UpdateTime: 2026-11-12 17:16:45
     * @Param Request $request 当前站点后台请求
     * @Param PluginRegistry $registry 系统插件注册表
     * @Return View 当前站点插件列表页面
     * @Throws HttpException 请求用户缺少插件查看权限时返回 403
     */
    public function index(Request $request, PluginRegistry $registry): View
    {
        $context = $this->context($request);
        abort_unless($context->allows('plugins.read') || $context->allows('plugins.configure'), 403);

        $plugins = [];
        foreach ($registry->all() as $pluginId => $manifest) {
            $records = $this->recordsForSite((int) $context->site->id, $manifest);
            $record = $records->get($pluginId);
            $enabled = $record instanceof WebsitePlugin
                ? $record->enabled
                : ($pluginId === 'cloud-storage' && $this->legacyEnabled($records, $manifest));
            $configurationRoute = $manifest['configuration_route'] ?? null;

            $plugins[] = [
                'id' => $pluginId,
                'manifest' => $manifest,
                'installed' => true,
                'enabled' => $enabled,
                'configureUrl' => is_string($configurationRoute) ? route($configurationRoute) : null,
            ];
        }

        return view('website-admin.plugins', [
            'pageTitle' => '插件列表',
            'context' => $context,
            'plugins' => $plugins,
        ]);
    }

    /**
     * @Name: configure
     * @Description: 展示选定云存储平台的单组配置字段，并读取当前站点已保存配置。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 17:03:13
     * @UpdateTime: 2026-11-12 17:16:45
     * @Param Request $request 当前站点配置页面请求
     * @Param PluginRegistry $registry 系统插件注册表
     * @Return View 当前站点云存储配置页面
     * @Throws HttpException 用户缺少插件查看权限时返回 403
     */
    public function configure(Request $request, PluginRegistry $registry): View
    {
        $context = $this->context($request);
        abort_unless($context->allows('plugins.read') || $context->allows('plugins.configure'), 403);

        $manifest = $registry->get('cloud-storage');
        $records = $this->recordsForSite((int) $context->site->id, $manifest);
        $providerId = $this->resolveProvider($manifest, $records, $request->query('provider'));
        $provider = $manifest['providers'][$providerId];
        $canonical = $records->get('cloud-storage');
        $storedSettings = $this->providerSettings($providerId, $canonical, $records);
        $settings = [];
        $savedSecrets = [];

        foreach ($provider['fields'] as $fieldKey => $field) {
            $value = trim((string) ($storedSettings[$fieldKey] ?? ''));
            if ($field['type'] === 'password') {
                $savedSecrets[$fieldKey] = $value !== '';
            } elseif ($value !== '') {
                $settings[$fieldKey] = $value;
            }
        }

        $enabled = $this->providerEnabled($providerId, $records);

        return view('website-admin.plugin-cloud-storage', [
            'pageTitle' => '云存储配置',
            'context' => $context,
            'manifest' => $manifest,
            'providers' => $manifest['providers'],
            'providerId' => $providerId,
            'provider' => $provider,
            'settings' => $settings,
            'savedSecrets' => $savedSecrets,
            'enabled' => $enabled,
        ]);
    }

    /**
     * @Name: update
     * @Description: 校验并加密保存单个平台配置，同时停用旧格式的供应商记录。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 16:13:54
     * @UpdateTime: 2026-11-12 17:16:45
     * @Param Request $request 云存储插件配置表单
     * @Param PluginRegistry $registry 系统插件注册表
     * @Return RedirectResponse 返回当前站点的插件列表
     * @Throws HttpException 用户无配置权限时返回 403
     * @Throws ValidationException 配置字段无效或启用时必填配置不完整
     */
    public function update(Request $request, PluginRegistry $registry): RedirectResponse
    {
        $context = $this->context($request);
        $this->authorize($context, 'plugins.configure');

        $manifest = $registry->get('cloud-storage');
        $providerIds = array_keys($manifest['providers']);
        $safeInput = $this->inputWithoutSecrets($request, $manifest['providers']);
        $providerValidator = Validator::make($request->all(), [
            'provider' => ['required', 'string', Rule::in($providerIds)],
            'settings' => ['nullable', 'array'],
            'enabled' => ['nullable', 'boolean'],
        ]);
        if ($providerValidator->fails()) {
            return back()->withErrors($providerValidator)->withInput($safeInput);
        }

        $providerId = (string) $providerValidator->validated()['provider'];
        $provider = $manifest['providers'][$providerId];
        $rules = ['settings' => ['nullable', 'array']];
        foreach ($provider['fields'] as $fieldKey => $field) {
            $fieldRules = ['nullable', 'string', 'max:1024'];
            if ($field['type'] === 'url') {
                $fieldRules[] = 'url';
                $fieldRules[] = static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }
                    $parts = parse_url($value);
                    if (! is_array($parts)
                        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
                        || ! isset($parts['host'])
                        || isset($parts['user'])
                        || isset($parts['pass'])
                        || isset($parts['query'])
                        || isset($parts['fragment'])) {
                        $fail('公开访问地址必须是无账号信息、无查询参数的 HTTPS 地址。');
                    }
                };
            } elseif ($field['type'] === 'select') {
                $fieldRules[] = Rule::in(array_keys($field['options']));
            }
            $rules['settings.'.$fieldKey] = $fieldRules;
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput($safeInput);
        }

        $submittedSettings = $validator->validated()['settings'] ?? [];
        $enabled = $request->boolean('enabled');
        $result = DB::transaction(function () use (
            $context,
            $provider,
            $providerId,
            $providerIds,
            $submittedSettings,
            $enabled
        ): array {
            $siteId = (int) $context->site->id;
            Site::query()->whereKey($siteId)->lockForUpdate()->firstOrFail();

            $canonical = WebsitePlugin::query()
                ->where('site_id', $siteId)
                ->where('plugin_id', 'cloud-storage')
                ->first();
            $legacyRecords = WebsitePlugin::query()
                ->where('site_id', $siteId)
                ->whereIn('plugin_id', $providerIds)
                ->get()
                ->keyBy('plugin_id');
            $canonicalSettings = $canonical instanceof WebsitePlugin && is_array($canonical->settings)
                ? $canonical->settings
                : [];
            $providerSettings = is_array($canonicalSettings['providers'] ?? null)
                ? $canonicalSettings['providers']
                : [];

            foreach ($providerIds as $legacyProviderId) {
                if (array_key_exists($legacyProviderId, $providerSettings)) {
                    continue;
                }
                $legacy = $legacyRecords->get($legacyProviderId);
                if ($legacy instanceof WebsitePlugin && is_array($legacy->settings)) {
                    $providerSettings[$legacyProviderId] = $legacy->settings;
                }
            }

            $credentials = is_array($providerSettings[$providerId] ?? null)
                ? $providerSettings[$providerId]
                : [];
            foreach ($provider['fields'] as $fieldKey => $field) {
                if (! array_key_exists($fieldKey, $submittedSettings)) {
                    continue;
                }
                $value = trim((string) ($submittedSettings[$fieldKey] ?? ''));
                if ($field['type'] === 'password' && $value === '') {
                    continue;
                }
                if ($value === '') {
                    unset($credentials[$fieldKey]);
                } else {
                    $credentials[$fieldKey] = $value;
                }
            }

            if ($enabled) {
                $errors = [];
                foreach ($provider['fields'] as $fieldKey => $field) {
                    if (($field['required'] ?? false) && trim((string) ($credentials[$fieldKey] ?? '')) === '') {
                        $errors['settings.'.$fieldKey] = '启用云存储前请填写'.$field['label'].'。';
                    }
                }
                if ($errors !== []) {
                    return ['errors' => $errors];
                }
            }

            $providerSettings[$providerId] = $credentials;
            WebsitePlugin::query()->updateOrCreate(
                ['site_id' => $siteId, 'plugin_id' => 'cloud-storage'],
                [
                    'settings' => [
                        'provider' => $providerId,
                        'providers' => $providerSettings,
                    ],
                    'enabled' => $enabled,
                ]
            );
            WebsitePlugin::query()
                ->where('site_id', $siteId)
                ->whereIn('plugin_id', $providerIds)
                ->update(['enabled' => false]);

            return ['errors' => []];
        });

        if ($result['errors'] !== []) {
            return back()->withErrors($result['errors'])->withInput($safeInput);
        }

        return redirect()->route('website-admin.plugins')->with(
            'status',
            $enabled ? '云存储配置已保存并启用' : '云存储配置已保存，当前处于停用状态'
        );
    }

    /**
     * @Name: context
     * @Description: 读取认证中间件绑定的当前站点和网站后台权限。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 16:13:54
     * @UpdateTime: 2026-11-12 17:16:45
     * @Param Request $request 当前站点后台请求
     * @Return WebsiteAdminContext 当前站点后台权限上下文
     * @Throws HttpException 缺少站点上下文时返回 403
     */
    private function context(Request $request): WebsiteAdminContext
    {
        $context = $request->attributes->get('website_admin');
        abort_unless($context instanceof WebsiteAdminContext, 403);

        return $context;
    }

    /**
     * @Name: authorize
     * @Description: 校验网站管理员是否具备指定插件管理权限。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 16:13:54
     * @UpdateTime: 2026-11-12 17:16:45
     * @Param WebsiteAdminContext $context 当前站点后台权限上下文
     * @Param string $permission 所需插件权限键
     * @Return void 通过校验后继续处理
     * @Throws HttpException 权限不足时返回 403
     */
    private function authorize(WebsiteAdminContext $context, string $permission): void
    {
        abort_unless($context->allows($permission), 403);
    }

    /**
     * @Name: recordsForSite
     * @Description: 仅读取当前站点的统一插件记录及旧版云存储驱动记录。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 17:03:13
     * @UpdateTime: 2026-11-12 17:16:45
     * @Param int $siteId 当前站点 ID
     * @Param array $manifest 云存储插件清单
     * @Return EloquentCollection<string,WebsitePlugin> 按插件标识索引的站点插件记录
     * @Throws 无
     */
    private function recordsForSite(int $siteId, array $manifest): EloquentCollection
    {
        return WebsitePlugin::query()
            ->where('site_id', $siteId)
            ->whereIn('plugin_id', array_merge([$manifest['id']], array_keys($manifest['providers'] ?? [])))
            ->get()
            ->keyBy('plugin_id');
    }

    /**
     * @Name: resolveProvider
     * @Description: 按请求选择、当前启用记录和既有配置确定唯一显示的平台。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 17:03:13
     * @UpdateTime: 2026-11-12 17:16:45
     * @Param array $manifest 云存储插件清单
     * @Param EloquentCollection $records 当前站点插件记录
     * @Param mixed $requestedProvider 页面请求的平台标识
     * @Return string 已安装的平台标识
     * @Throws LogicException 插件清单中没有可用平台
     */
    private function resolveProvider(array $manifest, EloquentCollection $records, mixed $requestedProvider): string
    {
        $providers = $manifest['providers'];
        if (is_string($requestedProvider) && isset($providers[$requestedProvider])) {
            return $requestedProvider;
        }

        $canonical = $records->get('cloud-storage');
        $settings = $canonical instanceof WebsitePlugin && is_array($canonical->settings)
            ? $canonical->settings
            : [];
        if (is_string($settings['provider'] ?? null) && isset($providers[$settings['provider']])) {
            return $settings['provider'];
        }

        foreach ($providers as $providerId => $provider) {
            $record = $records->get($providerId);
            if ($record instanceof WebsitePlugin && $record->enabled) {
                return $providerId;
            }
        }

        foreach ($providers as $providerId => $provider) {
            $storedSettings = $this->providerSettings($providerId, $canonical, $records);
            $configured = true;
            foreach ($provider['fields'] as $fieldKey => $field) {
                if (($field['required'] ?? false) && trim((string) ($storedSettings[$fieldKey] ?? '')) === '') {
                    $configured = false;
                    break;
                }
            }
            if ($configured && $storedSettings !== []) {
                return $providerId;
            }
        }

        return (string) array_key_first($providers);
    }

    /**
     * @Name: providerSettings
     * @Description: 优先读取统一加密配置，不存在时兼容旧版单平台记录。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 17:03:13
     * @UpdateTime: 2026-11-12 17:16:45
     * @Param string $providerId 云存储平台标识
     * @Param WebsitePlugin|null $canonical 统一格式插件记录
     * @Param EloquentCollection $records 当前站点插件记录
     * @Return array<string,mixed> 指定平台的配置
     * @Throws 无
     */
    private function providerSettings(
        string $providerId,
        ?WebsitePlugin $canonical,
        EloquentCollection $records
    ): array {
        $settings = $canonical instanceof WebsitePlugin && is_array($canonical->settings)
            ? $canonical->settings
            : [];
        $providers = is_array($settings['providers'] ?? null) ? $settings['providers'] : [];
        if (array_key_exists($providerId, $providers) && is_array($providers[$providerId])) {
            return $providers[$providerId];
        }

        $legacy = $records->get($providerId);

        return $legacy instanceof WebsitePlugin && is_array($legacy->settings) ? $legacy->settings : [];
    }

    /**
     * @Name: legacyEnabled
     * @Description: 在统一配置尚未建立时检查当前站点是否已有旧格式启用记录。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 17:03:13
     * @UpdateTime: 2026-11-12 17:16:45
     * @Param EloquentCollection $records 当前站点插件记录
     * @Param array $manifest 云存储插件清单
     * @Return bool 任一旧格式云存储记录是否启用
     * @Throws 无
     */
    private function legacyEnabled(EloquentCollection $records, array $manifest): bool
    {
        foreach (array_keys($manifest['providers']) as $providerId) {
            $record = $records->get($providerId);
            if ($record instanceof WebsitePlugin && $record->enabled) {
                return true;
            }
        }

        return false;
    }

    /**
     * @Name: providerEnabled
     * @Description: 仅当当前下拉选中的平台就是活动平台时显示启用状态。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 17:15:40
     * @UpdateTime: 2026-11-12 17:16:45
     * @Param string $providerId 当前配置页面选择的平台
     * @Param EloquentCollection $records 当前站点插件记录
     * @Return bool 当前选中平台是否启用
     * @Throws 无
     */
    private function providerEnabled(string $providerId, EloquentCollection $records): bool
    {
        $canonical = $records->get('cloud-storage');
        if ($canonical instanceof WebsitePlugin) {
            $settings = is_array($canonical->settings) ? $canonical->settings : [];

            return $canonical->enabled && ($settings['provider'] ?? null) === $providerId;
        }

        $legacy = $records->get($providerId);

        return $legacy instanceof WebsitePlugin && $legacy->enabled;
    }

    /**
     * @Name: inputWithoutSecrets
     * @Description: 清除所有云平台密钥字段后再闪存输入，避免密钥进入会话旧输入。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 17:03:13
     * @UpdateTime: 2026-11-12 17:16:45
     * @Param Request $request 当前插件配置请求
     * @Param array $providers 云存储平台字段清单
     * @Return array<string,mixed> 可安全回填的非密钥表单数据
     * @Throws 无
     */
    private function inputWithoutSecrets(Request $request, array $providers): array
    {
        $input = $request->all();
        if (! is_array($input['settings'] ?? null)) {
            return $input;
        }
        foreach ($providers as $provider) {
            foreach ($provider['fields'] as $fieldKey => $field) {
                if (($field['type'] ?? '') === 'password') {
                    unset($input['settings'][$fieldKey]);
                }
            }
        }

        return $input;
    }
}
