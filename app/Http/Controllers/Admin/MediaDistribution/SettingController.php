<?php

namespace App\Http\Controllers\Admin\MediaDistribution;

use App\Http\Controllers\Controller;
use App\Models\MediaApiSetting;
use App\Support\AdminWeb;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingController extends Controller
{
    public function __construct(private readonly ApiKeyCrypto $apiKeyCrypto) {}

    public function index(): View
    {
        $setting = MediaApiSetting::query()->orderByDesc('id')->first();

        return view('admin.media-distribution.settings', [
            'pageTitle' => '分发媒体接口配置',
            'activeMenu' => 'media_distribution',
            'adminSiteName' => AdminWeb::siteName(),
            'setting' => $setting,
            'maskedApiKey' => $setting instanceof MediaApiSetting ? $this->apiKeyCrypto->mask((string) $setting->api_key_ciphertext) : '',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'api_base_url' => ['required', 'url', 'max:500'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $setting = MediaApiSetting::query()->orderByDesc('id')->first() ?? new MediaApiSetting();
        $setting->api_base_url = rtrim((string) $payload['api_base_url'], '/');
        $setting->status = (string) $payload['status'];
        if (filled($payload['api_key'] ?? null)) {
            $setting->api_key_ciphertext = $this->apiKeyCrypto->encrypt((string) $payload['api_key']);
        }
        $setting->save();

        return redirect()->route('admin.media-distribution.settings.index')->with('message', '接口配置已保存');
    }
}
