<?php

/**
 * Created by Codex.
 * @Date: 2026-10-30
 * @Time: 01:13:11
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： AiWorkspaceRuntimeStatus.php
 * @Description: 管理 AI 工作台运行开关并按站点隔离保存状态。
 */

namespace App\Services\AiWorkspace;

use App\Models\SiteSetting;
use App\Support\CurrentSite;
use Illuminate\Support\Facades\Schema;

final class AiWorkspaceRuntimeStatus
{
    public const SETTING_KEY = 'ai_workspace_runtime_enabled';

    public function __construct(private readonly CurrentSite $currentSite) {}

    /**
     * @return array{
     *   preferred_enabled:bool,
     *   effective_enabled:bool,
     *   forced_disabled:bool,
     *   source:'site_setting'|'environment_default'
     * }
     */
    public function snapshot(): array
    {
        $stored = $this->storedPreference();
        $preferredEnabled = $stored['exists']
            ? $stored['enabled']
            : (bool) config('ai-workspace.runtime_enabled', false);
        $forcedDisabled = $this->forcedDisabled();

        return [
            'preferred_enabled' => $preferredEnabled,
            'effective_enabled' => $preferredEnabled && ! $forcedDisabled,
            'forced_disabled' => $forcedDisabled,
            'source' => $stored['exists'] ? 'site_setting' : 'environment_default',
        ];
    }

    public function enabled(): bool
    {
        return $this->snapshot()['effective_enabled'];
    }

    public function forcedDisabled(): bool
    {
        return (bool) config('ai-workspace.force_disabled', false);
    }

    /**
     * @Name: save
     * @Description: 将运行开关保存到当前站点的复合键记录；无站点上下文时只维护全局兼容记录。
     * @Author: cdkay
     * @CreateTime: 2026-10-30 01:07:44
     * @UpdateTime: 2026-10-30 01:13:11
     * @Param: bool $enabled 是否启用 AI 工作台
     * @Return: void
     * @Throws: QueryException 数据库写入失败时抛出
     */
    public function save(bool $enabled): void
    {
        $siteId = $this->currentSite->id();
        $settingValue = $enabled ? '1' : '0';

        if ($siteId === null) {
            $setting = SiteSetting::query()
                ->withoutGlobalScope('current_site')
                ->whereNull('site_id')
                ->where('setting_key', self::SETTING_KEY)
                ->first();

            if ($setting instanceof SiteSetting) {
                $setting->update(['setting_value' => $settingValue]);

                return;
            }

            SiteSetting::query()->create([
                'site_id' => null,
                'setting_key' => self::SETTING_KEY,
                'setting_value' => $settingValue,
            ]);

            return;
        }

        SiteSetting::query()->upsert(
            [[
                'site_id' => $siteId,
                'setting_key' => self::SETTING_KEY,
                'setting_value' => $settingValue,
            ]],
            ['site_id', 'setting_key'],
            ['setting_value'],
        );
    }

    /** @return array{exists:bool,enabled:bool} */
    private function storedPreference(): array
    {
        if (! Schema::hasTable('site_settings')) {
            return ['exists' => false, 'enabled' => false];
        }

        $query = SiteSetting::query()
            ->withoutGlobalScope('current_site')
            ->where('setting_key', self::SETTING_KEY)
            ->when(
                $this->currentSite->id() === null,
                fn ($builder) => $builder->whereNull('site_id'),
                fn ($builder) => $builder->where('site_id', $this->currentSite->id()),
            );
        $setting = $query->first(['setting_value']);
        $value = $setting?->setting_value;

        return [
            'exists' => $setting instanceof SiteSetting,
            'enabled' => filter_var((string) $value, FILTER_VALIDATE_BOOLEAN),
        ];
    }
}
