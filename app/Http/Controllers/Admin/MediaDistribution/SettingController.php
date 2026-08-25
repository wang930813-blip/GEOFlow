<?php

namespace App\Http\Controllers\Admin\MediaDistribution;

use App\Http\Controllers\Controller;
use App\Models\MediaApiSetting;
use App\Support\AdminWeb;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\MediaDistribution\MediaPlatform;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingController extends Controller
{
    public function __construct(private readonly ApiKeyCrypto $apiKeyCrypto) {}

    public function index(): View
    {
        $settings = MediaApiSetting::query()
            ->whereIn('platform_id', MediaPlatform::ids())
            ->orderByDesc('id')
            ->get()
            ->unique('platform_id')
            ->keyBy('platform_id');

        return view('admin.media-distribution.settings', [
            'pageTitle' => '分发媒体接口配置',
            'activeMenu' => 'media_distribution',
            'adminSiteName' => AdminWeb::siteName(),
            'settings' => $settings,
            'platforms' => MediaPlatform::labels(),
            'maskedApiKeys' => $settings->mapWithKeys(fn (MediaApiSetting $setting, int $platformId): array => [
                $platformId => $this->apiKeyCrypto->mask((string) $setting->api_key_ciphertext),
            ]),
            'maskedApiSecrets' => $settings->mapWithKeys(fn (MediaApiSetting $setting, int $platformId): array => [
                $platformId => $this->apiKeyCrypto->mask((string) $setting->api_secret_ciphertext),
            ]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'platform_id' => ['nullable', 'integer', Rule::in(MediaPlatform::ids())],
            'api_base_url' => ['required', 'url', 'max:500'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'app_id' => ['nullable', 'string', 'max:120'],
            'api_secret' => ['nullable', 'string', 'max:500'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);
        $platformId = (int) ($payload['platform_id'] ?? MediaPlatform::CEYING_MEDIA_1);

        $setting = MediaApiSetting::query()
            ->where('platform_id', $platformId)
            ->orderByDesc('id')
            ->first() ?? new MediaApiSetting(['platform_id' => $platformId]);
        $setting->platform_id = $platformId;
        $setting->api_base_url = rtrim((string) $payload['api_base_url'], '/');
        $setting->app_id = trim((string) ($payload['app_id'] ?? ''));
        $setting->status = (string) $payload['status'];
        if (filled($payload['api_key'] ?? null)) {
            $setting->api_key_ciphertext = $this->apiKeyCrypto->encrypt((string) $payload['api_key']);
        }
        if (filled($payload['api_secret'] ?? null)) {
            $setting->api_secret_ciphertext = $this->apiKeyCrypto->encrypt((string) $payload['api_secret']);
        }
        $setting->save();

        return redirect()->route('admin.media-distribution.settings.index')->with('message', MediaPlatform::label($platformId).'接口配置已保存');
    }
}
