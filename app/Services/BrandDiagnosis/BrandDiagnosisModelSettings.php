<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-11-20
 *
 * @Time: 16:43:39
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： BrandDiagnosisModelSettings.php
 *
 * @Description: 读取并维护品牌诊断模型的全局运维开关与配置完整性。
 */

namespace App\Services\BrandDiagnosis;

use App\Models\SiteSetting;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class BrandDiagnosisModelSettings
{
    private const SETTING_KEY = 'brand_diagnosis_platform_status';

    /**
     * @Name: statuses
     *
     * @Description: 合并环境默认值与全局后台设置，返回五个平台当前生效的启用状态；数据库读取异常时全部关闭。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-20 16:43:39
     *
     * @UpdateTime: 2026-11-20 16:57:39
     *
     * @Return: array<string,bool> 平台标识到启用状态的映射
     *
     * @Throws: 无；全局设置读取异常时回退到环境默认值
     */
    public function statuses(): array
    {
        $statuses = [];
        foreach (BrandDiagnosisPlatform::keys() as $platform) {
            $statuses[$platform] = (bool) config('brand_diagnosis.'.$platform.'.enabled', false);
        }

        try {
            $stored = SiteSetting::withoutGlobalScope('current_site')
                ->whereNull('site_id')
                ->where('setting_key', self::SETTING_KEY)
                ->value('setting_value');
            $overrides = is_string($stored) ? json_decode($stored, true) : null;
            if (is_array($overrides)) {
                foreach ($statuses as $platform => $default) {
                    if (array_key_exists($platform, $overrides)) {
                        $statuses[$platform] = filter_var($overrides[$platform], FILTER_VALIDATE_BOOLEAN);
                    }
                }
            }
        } catch (Throwable) {
            return array_fill_keys(BrandDiagnosisPlatform::keys(), false);
        }

        return $statuses;
    }

    /**
     * @Name: isEnabled
     *
     * @Description: 检查平台是否允许进入品牌资料检索、问题生成或答案采样调用链。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-20 16:43:39
     *
     * @UpdateTime: 2026-11-20 16:43:39
     *
     * @Param: string $platform 平台标识
     *
     * @Return: bool 平台当前是否启用
     *
     * @Throws: InvalidArgumentException 平台标识不受支持时抛出
     */
    public function isEnabled(string $platform): bool
    {
        $platform = strtolower(trim($platform));
        if (! BrandDiagnosisPlatform::isSupported($platform)) {
            throw new InvalidArgumentException('不支持的诊断模型。');
        }

        return (bool) ($this->statuses()[$platform] ?? false);
    }

    /**
     * @Name: isConfigured
     *
     * @Description: 检查模型接口地址、密钥和模型名称是否齐全；DeepSeek 可沿用火山方舟地址与密钥回退配置。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-20 16:43:39
     *
     * @UpdateTime: 2026-11-20 16:43:39
     *
     * @Param: string $platform 平台标识
     *
     * @Return: bool 接口配置是否完整
     *
     * @Throws: InvalidArgumentException 平台标识不受支持时抛出
     */
    public function isConfigured(string $platform): bool
    {
        $platform = strtolower(trim($platform));
        if (! BrandDiagnosisPlatform::isSupported($platform)) {
            throw new InvalidArgumentException('不支持的诊断模型。');
        }

        $baseUrl = trim((string) config('brand_diagnosis.'.$platform.'.base_url', ''));
        $apiKey = trim((string) config('brand_diagnosis.'.$platform.'.api_key', ''));
        $model = trim((string) config('brand_diagnosis.'.$platform.'.model', ''));
        if ($platform === BrandDiagnosisPlatform::DEEPSEEK) {
            $baseUrl = $baseUrl !== '' ? $baseUrl : trim((string) config('brand_diagnosis.doubao.base_url', ''));
            $apiKey = $apiKey !== '' ? $apiKey : trim((string) config('brand_diagnosis.doubao.api_key', ''));
        }

        return $baseUrl !== '' && $apiKey !== '' && $model !== '';
    }

    /**
     * @Name: setEnabled
     *
     * @Description: 将一个平台的全局运维开关写入现有全局设置，其他平台状态保持不变。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-20 16:43:39
     *
     * @UpdateTime: 2026-11-20 16:43:39
     *
     * @Param: string $platform 平台标识
     *
     * @Param: bool $enabled 是否启用
     *
     * @Return: void 设置写入完成
     *
     * @Throws: InvalidArgumentException 平台标识不受支持时抛出
     *
     * @Throws: RuntimeException 全局设置无法写入时抛出
     */
    public function setEnabled(string $platform, bool $enabled): void
    {
        $platform = strtolower(trim($platform));
        if (! BrandDiagnosisPlatform::isSupported($platform)) {
            throw new InvalidArgumentException('不支持的诊断模型。');
        }

        $statuses = $this->statuses();
        $statuses[$platform] = $enabled;
        $stored = json_encode($statuses, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        try {
            SiteSetting::withoutEvents(function () use ($stored): void {
                SiteSetting::withoutGlobalScope('current_site')->updateOrCreate(
                    [
                        'site_id' => null,
                        'setting_key' => self::SETTING_KEY,
                    ],
                    [
                        'site_id' => null,
                        'owner_admin_id' => null,
                        'setting_value' => $stored,
                    ]
                );
            });
        } catch (Throwable $exception) {
            throw new RuntimeException('模型运维开关保存失败，请稍后重试。', previous: $exception);
        }
    }
}
